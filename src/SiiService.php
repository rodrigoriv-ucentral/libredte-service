<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use Derafu\Xml\Contract\XmlDocumentInterface;
use Derafu\Xml\XmlDocument;
use App\Sii\DteUploadClient;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiEnvironment;
use libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest;

/**
 * Comunicación con el SII: envío de documentos, consulta del estado de un
 * envío y reenvío de un XML ya timbrado.
 *
 * Punto crítico: TIMBRAR y ENVIAR son dos cosas distintas.
 *  - Timbrar consume un folio del CAF y es irreversible.
 *  - Enviar entrega el XML al SII y devuelve un trackId.
 * Si el envío falla, el folio YA se gastó. Por eso el reenvío toma el XML
 * guardado y lo reenvía tal cual, sin volver a timbrar: re-timbrar duplicaría
 * el documento y quemaría otro folio.
 */
final class SiiService
{
    public function __construct(private readonly Config $config)
    {
    }

    /** Certificado digital cargado desde disco (el que se sube por /certificado). */
    public function cargarCertificado(): CertificateInterface
    {
        if (!is_file($this->config->certPath)) {
            throw new \RuntimeException(
                'No hay certificado digital cargado. Súbelo desde /configuracion antes de emitir.'
            );
        }

        return (new CertificateLoader())->loadFromFile(
            $this->config->certPath,
            $this->config->resolveCertPass()
        );
    }

    /**
     * Ambiente del SII según la configuración.
     * STAGING = Maullín (certificación) · PRODUCTION = Palena (producción).
     */
    public function ambiente(): SiiEnvironment
    {
        return $this->config->ambiente === 'produccion'
            ? SiiEnvironment::PRODUCTION
            : SiiEnvironment::STAGING;
    }

    private function request(CertificateInterface $certificate): SiiRequest
    {
        return new SiiRequest($certificate, ['environment' => $this->ambiente()]);
    }

    private function worker()
    {
        return Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getIntegrationComponent()
            ->getSiiDteWorker();
    }

    /**
     * Envía un XML al SII y devuelve su trackId.
     * El RUT del emisor va sin puntos, tal como lo exige el servicio del SII.
     *
     * Ruta según el sobre:
     *  - EnvioBOLETA (39/41) → API REST de boletas (pangal en certificación,
     *    rahue en producción). El SII separó la recepción de boletas de la de
     *    facturas: Palena es para DTE y RVD, no para boletas.
     *  - EnvioDTE (33/56/61…), ConsumoFolios (RVD), libros → DTEUpload (sin cambios).
     */
    public function enviar(XmlDocumentInterface $doc, CertificateInterface $certificate): int
    {
        if (self::esEnvioBoleta($doc)) {
            return $this->enviarBoleta($doc, $certificate);
        }

        // Cliente propio con timeouts, solo si LIBREDTE_UPLOAD_PROPIO=1 (por defecto APAGADO:
        // se usa la librería, igual que siempre). Activar solo tras probarlo en certificación.
        if (getenv('LIBREDTE_UPLOAD_PROPIO') === '1') {
            return $this->enviarConClientePropio($doc, $certificate);
        }

        try {
            return $this->worker()
                // retries: 1 -> sin reintentos a ciegas: la librería reintenta hasta 10 veces ante
                // respuesta vacía, y reenviar un sobre que el SII sí recibió deja estado inconsistente.
                ->sendXmlDocument($this->request($certificate), $doc, $this->rutEmisor(), false, 1)
                ->getTrackId();
        } catch (\Throwable $e) {
            // STATUS 99 del SII: el envío ya estaba recibido. No es un fallo.
            if (self::esYaRecibido($e->getMessage())) {
                throw new EnvioYaRecibidoException('El SII ya había recibido este envío (STATUS 99).', 0, $e);
            }
            throw $e;
        }
    }

    /** DTEUpload con DteUploadClient (timeouts de curl). El token es el mismo de la librería (SiiLazyWorker). */
    private function enviarConClientePropio(XmlDocumentInterface $doc, CertificateInterface $certificate): int
    {
        $request = $this->request($certificate);
        $token = Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getIntegrationComponent()
            ->getSiiLazyWorker()
            ->authenticate($request);
        $xml = $doc->setEncoding('ISO-8859-1')->saveXml();
        $cliente = new DteUploadClient(
            $request->getEnvironment()->getUrl('/cgi_dte/UPL/DTEUpload'),
            $token,
            $request->getVerifySsl(),
        );

        return $cliente->enviar($xml, $certificate->getId(), $this->rutEmisor());
    }

    /**
     * ¿El mensaje de la librería corresponde al STATUS 99 del SII? El texto viene de
     * SendXmlDocumentJob ("El XML enviado ya fue previamente recibido por el SII."):
     * revisar este detector al actualizar libredte-lib-core.
     */
    public static function esYaRecibido(string $mensaje): bool
    {
        return str_contains($mensaje, 'previamente recibido');
    }

    public static function esEnvioBoleta(XmlDocumentInterface $doc): bool
    {
        return $doc->getDocumentElement()?->localName === 'EnvioBOLETA';
    }

    /** Cliente REST de boletas para el ambiente configurado (LIBREDTE_AMBIENTE). */
    public function boletaRest(CertificateInterface $certificate): BoletaRestClient
    {
        return new BoletaRestClient($this->config->ambiente, $certificate);
    }

    private function enviarBoleta(XmlDocumentInterface $doc, CertificateInterface $certificate): int
    {
        // Mismo tratamiento que DTEUpload: el SII recibe ISO-8859-1.
        $xml = $doc->setEncoding('ISO-8859-1')->saveXml();
        if (!str_contains($xml, '<?xml')) {
            $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $xml;
        }

        $respuesta = $this->boletaRest($certificate)->enviar($xml, $this->rutEmisor());

        return (int) $respuesta['trackid'];
    }

    /**
     * Reenvía un XML ya timbrado (base64) sin consumir un folio nuevo.
     *
     * El XML guardado es el `<DTE>` individual (lo que devuelve `bill()`), no
     * el sobre — hay que reconstruirlo con SobreBuilder antes de enviarlo, o
     * el SII lo rechaza por no tener la raíz `EnvioDTE`/`EnvioBOLETA` que
     * exige el esquema (mismo bug que ya se corrigió en DteEmisor::emitir()).
     */
    public function reenviar(string $xmlBase64, CertificateInterface $certificate): int
    {
        $xml = base64_decode($xmlBase64, true);
        if ($xml === false || $xml === '') {
            throw new \InvalidArgumentException('El XML no es un base64 válido');
        }

        // Solo para validar que decodificó a XML legible antes de intentar
        // reconstruirlo como sobre — un error más claro que el que daría
        // SobreBuilder ante un XML corrupto.
        if (!(new XmlDocument())->loadXml($xml)) {
            throw new \InvalidArgumentException('El XML está malformado y no se pudo cargar');
        }

        $sobre = (new SobreBuilder($this->config))->construirDesdeXml([$xml], $certificate);

        return $this->enviar($sobre->getXmlDocument(), $certificate);
    }

    /**
     * Consulta el estado de un envío. El estado puede no ser final: mientras
     * sea 'enviado' hay que volver a consultar más tarde.
     *
     * Devuelve el estado normalizado para la app:
     *  no_enviado | enviado | aceptado | aceptado_reparos | rechazado
     */
    public function consultarEstado(int $trackId, CertificateInterface $certificate, bool $boleta = false): array
    {
        // Envíos de boletas: estado por la API REST (su trackId no existe en
        // QueryEstUp). Si la API no conoce el trackId (404), se cae a SOAP:
        // cubre boletas enviadas por DTEUpload antes de este cambio (p. ej. el
        // set de certificación).
        if ($boleta) {
            try {
                $respuesta = $this->boletaRest($certificate)->estadoEnvio($this->rutEmisor(), $trackId);

                return BoletaRestClient::normalizarEstadoEnvio($respuesta, $trackId);
            } catch (RecursoNoEncontradoException) {
                // continúa por SOAP
            }
        }

        $respuesta = $this->worker()->checkXmlDocumentSentStatus(
            $this->request($certificate),
            $trackId,
            $this->rutEmisor()
        );

        $estadoSii = $respuesta->getEstadoEnvioSii();

        // E=enviado (en proceso) · A=aceptado · R=aceptado con reparos · X=rechazado
        $estado = match ($estadoSii?->value) {
            'A' => 'aceptado',
            'R' => 'aceptado_reparos',
            'X' => 'rechazado',
            'E' => 'enviado',
            default => 'enviado',
        };

        return [
            'trackId' => $trackId,
            'estado' => $estado,
            'final' => $estadoSii?->isFinal() ?? false,
            'glosa' => $estadoSii?->getLabel() ?? $respuesta->getReviewStatus(),
            'detalle' => $respuesta->getReviewDetail(),
            // Respuesta cruda: es la evidencia que se guarda para auditoría.
            'respuestaSii' => $respuesta->toArray(),
        ];
    }

    /**
     * QueryEstDte: ¿el SII conoce este documento (tipo + folio) y con qué datos?
     * Sirve para decidir con seguridad si reenviar un XML: FAU = el SII nunca
     * recibió ese folio; DOK/DNK = ya lo tiene (reenviar lo duplicaría).
     */
    public function consultarDocumento(
        CertificateInterface $certificate,
        int $tipoDte,
        int $folio,
        string $fechaEmision,
        int $montoTotal,
        string $rutReceptor
    ): array {
        // Boletas: QueryEstDte (SOAP) no las cubre; el SII publica el mismo
        // juego de códigos (DOK, DNK, FAU…) en la API REST de boletas.
        if (in_array($tipoDte, [39, 41], true)) {
            $respuesta = $this->boletaRest($certificate)->estadoBoleta(
                $this->rutEmisor(),
                $tipoDte,
                $folio,
                $rutReceptor,
                $montoTotal,
                $fechaEmision
            );

            return self::resultadoConsultaDocumento(
                (string) ($respuesta['codigo'] ?? ''),
                $respuesta['descripcion'] ?? null,
                $respuesta
            );
        }

        $respuesta = $this->worker()->validateDocument(
            $this->request($certificate),
            $this->rutEmisor(),
            $tipoDte,
            $folio,
            $fechaEmision,
            $montoTotal,
            $rutReceptor
        );

        return self::resultadoConsultaDocumento(
            (string) $respuesta->getStatus(),
            $respuesta->getDescription(),
            $respuesta->toArray()
        );
    }

    /** Formato común de la consulta de un documento (SOAP para DTE, REST para boletas). */
    public static function resultadoConsultaDocumento(string $codigo, ?string $glosa, array $cruda): array
    {
        return [
            'codigo' => $codigo,
            'glosa' => $glosa,
            // El SII ya tiene el documento (aunque los datos consultados no coincidan).
            'recibido' => in_array($codigo, ['DOK', 'DNK', 'TMD', 'TMC', 'MMD', 'MMC', 'AND', 'ANC'], true),
            // El SII confirma que NO recibió ese folio: es seguro reenviar.
            'noRecibido' => $codigo === 'FAU',
            'respuestaSii' => $cruda,
        ];
    }

    private function rutEmisor(): string
    {
        return str_replace('.', '', $this->config->emisorRut);
    }
}
