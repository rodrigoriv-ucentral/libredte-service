<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Exchange\Enum\TipoDocumentoRespuesta;
use libredte\lib\Core\Package\Billing\Component\Exchange\Support\ExchangeDocumentBag;

/**
 * Genera y firma la mensajería XML que exige el SII para responder a los DTE
 * que nos envían nuestros proveedores — la "etapa 3" de la certificación
 * (intercambio de información entre contribuyentes).
 *
 * Son TRES respuestas distintas, que el SII trata por separado (no son fases
 * de una misma cosa):
 *
 *  1. `recepcionEnvio()` → `RespuestaDTE` con `RecepcionEnvio`.
 *     Acusa recibo del SOBRE: "me llegó tu envío y el XML está (o no) bien
 *     formado y firmado". Es técnico, no comercial.
 *
 *  2. `resultadoDte()` → `RespuestaDTE` con `ResultadoDTE`.
 *     Aceptación o reclamo COMERCIAL del contenido de la factura. Tiene
 *     plazo legal: 8 días corridos (Ley 19.983 art. 3), pasados los cuales
 *     el documento se da por aceptado irrevocablemente.
 *
 *  3. `envioRecibos()` → `EnvioRecibos`.
 *     Acuse de recibo de MERCADERÍAS/servicios (Ley 19.983). Es lo que hace
 *     la factura cedible (habilita el factoring). Distinto de aceptarla
 *     comercialmente: una cosa es "llegó la mercadería", otra "estoy de
 *     acuerdo con lo facturado".
 *
 * Los códigos de estado salen del XSD `RespuestaEnvioDTE_v10.xsd`
 * (verificados contra el esquema real, no supuestos):
 *   EstadoDTE: 0 = aceptado OK · 1 = aceptado con discrepancias · 2 = rechazado
 *   CodRchDsc: entero NEGATIVO, sólo al rechazar o marcar discrepancia.
 */
final class RespuestaDteBuilder
{
    /** EstadoDTE del XSD: aceptación comercial sin observaciones. */
    public const ESTADO_ACEPTADO = 0;
    /** EstadoDTE del XSD: aceptado, pero con discrepancias declaradas. */
    public const ESTADO_ACEPTADO_CON_REPAROS = 1;
    /** EstadoDTE del XSD: rechazado comercialmente. */
    public const ESTADO_RECHAZADO = 2;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Acuse de recibo del envío (¿el sobre venía bien formado?).
     *
     * @param array $envio Datos del envío recibido: nmbEnvio, envioDteId,
     *   rutEmisor, nroDte, y estadoRecepEnv (0 = OK) con su glosa.
     */
    public function recepcionEnvio(array $envio): string
    {
        if (empty($envio['envioDteId'])) {
            throw new \InvalidArgumentException(
                'recepcionEnvio requiere envioDteId: es el atributo ID del nodo SetDTE del sobre recibido, '
                . 'obligatorio en el esquema (EnvioDTEID) para que el proveedor pueda correlacionar la respuesta.'
            );
        }

        // El orden de las claves es el del XSD (NmbEnvio, FchRecep, CodEnvio,
        // EnvioDTEID, Digest, RutEmisor, RutReceptor, EstadoRecepEnv...) — el
        // validador rechaza el XML si se altera, aunque los datos estén bien.
        $recepcion = [
            'NmbEnvio' => $envio['nmbEnvio'] ?? 'envio.xml',
            'FchRecep' => $envio['fchRecep'] ?? date('Y-m-d\TH:i:s'),
            'CodEnvio' => $envio['codEnvio'] ?? 1,
            'EnvioDTEID' => (string) $envio['envioDteId'],
            'Digest' => $envio['digest'] ?? false,
            'RutEmisor' => $this->rutSinPuntos((string) $envio['rutEmisor']),
            'RutReceptor' => $this->rutSinPuntos($this->config->emisorRut),
            'EstadoRecepEnv' => (int) ($envio['estadoRecepEnv'] ?? 0),
            'RecepEnvGlosa' => $envio['recepEnvGlosa'] ?? 'Envio recibido conforme',
            'NroDTE' => (int) ($envio['nroDte'] ?? 1),
        ];

        // Un RecepcionDTE por documento del sobre (TipoDTE, Folio, FchEmis,
        // RUTEmisor, RUTRecep, MntTotal, EstadoRecepDTE, RecepDTEGlosa): el orden es el del XSD.
        if (!empty($envio['documentos'])) {
            $recepcion['RecepcionDTE'] = array_map(fn (array $d): array => [
                'TipoDTE' => (int) $d['tipoDte'],
                'Folio' => (int) $d['folio'],
                'FchEmis' => (string) $d['fchEmis'],
                'RUTEmisor' => $this->rutSinPuntos((string) $d['rutEmisor']),
                'RUTRecep' => $this->rutSinPuntos((string) $d['rutRecep']),
                'MntTotal' => (int) $d['montoTotal'],
                'EstadoRecepDTE' => (int) $d['estadoRecepDte'],
                'RecepDTEGlosa' => mb_substr((string) $d['glosa'], 0, 256),
            ], $envio['documentos']);
        }

        return $this->construir(
            TipoDocumentoRespuesta::RESPUESTA_ENVIO,
            ['RutRecibe' => $this->rutSinPuntos((string) $envio['rutEmisor'])],
            ['recepcion_envio' => [$recepcion]],
        );
    }

    /**
     * Aceptación o reclamo comercial de un DTE recibido.
     *
     * @param array $dte Datos del documento: tipoDte, folio, fchEmis,
     *   rutEmisor, montoTotal.
     * @param int $estado Una de las constantes ESTADO_* de esta clase.
     * @param string $glosa Explicación visible para el proveedor.
     * @param int|null $codRechazo CodRchDsc del SII (negativo); sólo cuando
     *   el estado no es aceptado OK.
     */
    public function resultadoDte(array $dte, int $estado, string $glosa, ?int $codRechazo = null): string
    {
        return $this->resultadoDtes([
            ['dte' => $dte, 'estado' => $estado, 'glosa' => $glosa, 'codRechazo' => $codRechazo],
        ]);
    }

    /**
     * Un solo RespuestaDTE con un ResultadoDTE por documento del mismo envío.
     *
     * @param array<int, array{dte: array, estado: int, glosa: string, codRechazo?: int|null}> $items
     */
    public function resultadoDtes(array $items): string
    {
        if ($items === []) {
            throw new \InvalidArgumentException('resultadoDtes requiere al menos un documento');
        }

        $resultados = [];
        foreach ($items as $item) {
            $dte = $item['dte'];
            $estado = $item['estado'];
            $codRechazo = $item['codRechazo'] ?? null;
            if (!in_array($estado, [self::ESTADO_ACEPTADO, self::ESTADO_ACEPTADO_CON_REPAROS, self::ESTADO_RECHAZADO], true)) {
                throw new \InvalidArgumentException(
                    'estado debe ser 0 (aceptado), 1 (aceptado con reparos) o 2 (rechazado)'
                );
            }
            // El XSD exige que CodRchDsc sea un entero negativo. Se valida acá y
            // no al final para dar un error claro en vez de un rechazo de esquema.
            if ($codRechazo !== null && $codRechazo >= 0) {
                throw new \InvalidArgumentException('codRechazo debe ser un entero negativo (CodRchDsc del SII)');
            }

            $resultado = [
                'TipoDTE' => (int) $dte['tipoDte'],
                'Folio' => (int) $dte['folio'],
                'FchEmis' => (string) $dte['fchEmis'],
                'RUTEmisor' => $this->rutSinPuntos((string) $dte['rutEmisor']),
                'RUTRecep' => $this->rutSinPuntos((string) ($dte['rutRecep'] ?? $this->config->emisorRut)),
                'MntTotal' => (int) $dte['montoTotal'],
                'CodEnvio' => $dte['codEnvio'] ?? 1,
                'EstadoDTE' => $estado,
                'EstadoDTEGlosa' => mb_substr($item['glosa'], 0, 256),
            ];
            if ($codRechazo !== null) {
                $resultado['CodRchDsc'] = $codRechazo;
            }
            $resultados[] = $resultado;
        }

        return $this->construir(
            TipoDocumentoRespuesta::RESPUESTA_ENVIO,
            ['RutRecibe' => $this->rutSinPuntos((string) $items[0]['dte']['rutEmisor'])],
            ['resultado_dte' => $resultados],
        );
    }

    /**
     * Acuse de recibo de mercaderías o servicios (Ley 19.983).
     *
     * La glosa legal de la declaración la pone la propia librería por
     * defecto (texto exacto del art. 4 letra b / art. 5 letra c) — no se
     * sobreescribe.
     *
     * @param array $dte Datos del documento: tipoDte, folio, fchEmis,
     *   rutEmisor, montoTotal, y el recinto donde se recibió.
     */
    public function envioRecibos(array $dte, string $recinto): string
    {
        $recibo = [
            'TipoDoc' => (int) $dte['tipoDte'],
            'Folio' => (int) $dte['folio'],
            'FchEmis' => (string) $dte['fchEmis'],
            'RUTEmisor' => $this->rutSinPuntos((string) $dte['rutEmisor']),
            'RUTRecep' => $this->rutSinPuntos($this->config->emisorRut),
            'MntTotal' => (int) $dte['montoTotal'],
            'Recinto' => mb_substr($recinto, 0, 80),
            'RutFirma' => $this->cargarCertificado()->getId(),
        ];

        return $this->construir(
            TipoDocumentoRespuesta::ENVIO_RECIBOS,
            ['RutRecibe' => $this->rutSinPuntos((string) $dte['rutEmisor'])],
            [$recibo],
        );
    }

    /**
     * Arma la bolsa, genera el XML firmado y lo valida contra su esquema.
     *
     * Ojo: las dos carátulas NO son iguales. `RespuestaDTE` lleva
     * `IdRespuesta` (correlativo de la respuesta dentro de la conversación
     * con el proveedor); `EnvioRecibos` no lo tiene en su esquema y lo
     * rechaza si se envía. Verificado contra `EnvioRecibos_v10.xsd`.
     *
     * `IdRespuesta` va fijo en 1 porque cada respuesta se envía por separado.
     * Si en el futuro se agrupan varias en un mismo envío, hay que
     * correlativarlo.
     */
    private function construir(TipoDocumentoRespuesta $tipo, array $caratulaExtra, array $data): string
    {
        $certificate = $this->cargarCertificado();

        $caratula = array_merge([
            'RutResponde' => $this->rutSinPuntos($this->config->emisorRut),
            'NmbContacto' => $this->config->emisorRazonSocial,
            'MailContacto' => $this->config->mailIntercambio,
        ], $caratulaExtra);

        if ($tipo === TipoDocumentoRespuesta::RESPUESTA_ENVIO) {
            $caratula['IdRespuesta'] = 1;
        }

        $bag = new ExchangeDocumentBag(
            tipo: $tipo,
            caratula: $caratula,
            data: $data,
            certificate: $certificate,
        );

        $exchange = Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getExchangeComponent();

        $worker = $exchange->getDocumentResponseWorker();

        $documento = $tipo === TipoDocumentoRespuesta::ENVIO_RECIBOS
            ? $worker->buildEnvioRecibos($bag)
            : $worker->buildRespuestaEnvio($bag);

        // Falla rápido si el XML no cumple el esquema: mejor descubrirlo aquí
        // que cuando el proveedor (o el SII) lo rechace.
        $worker->validateSchema($documento);

        return $documento->getXml();
    }

    private function cargarCertificado(): CertificateInterface
    {
        return (new CertificateLoader())->loadFromFile(
            $this->config->certPath,
            $this->config->resolveCertPass()
        );
    }

    private function rutSinPuntos(string $rut): string
    {
        return str_replace('.', '', $rut);
    }
}
