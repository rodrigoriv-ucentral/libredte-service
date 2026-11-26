<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiEnvironment;
use libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest;
use Derafu\L10n\Cl\Rut\Rut;

/**
 * Registro de Aceptación o Reclamo de DTE ante el SII (Ley 19.983, modificada
 * por Ley 20.956): es el mecanismo que tiene valor legal para aceptar o
 * reclamar un documento recibido de un proveedor. El XML de intercambio que
 * genera RespuestaDteBuilder (RespuestaDTE/EnvioRecibos, vía /dte/respuesta)
 * NO lo tiene ante el SII — solo informa al proveedor.
 *
 * Web service: RegistroReclamoDTE.
 *   - Producción:    https://ws1.sii.cl/WSREGISTRORECLAMODTE/registroreclamodteservice
 *   - Certificación: https://ws2.sii.cl/WSREGISTRORECLAMODTECERT/registroreclamodteservice
 * Confirmado contra el WSDL real (GET .../registroreclamodteservice?wsdl en
 * certificación: define exactamente ingresarAceptacionReclamoDoc,
 * listarEventosHistDoc, consultarFechaRecepcionSii y consultarDocDteCedible
 * con los parámetros usados más abajo) y contra libredte-lib-core, que YA
 * trae un worker completo para este mismo WS:
 *   vendor/libredte/libredte-lib-core/src/Package/Billing/Component/
 *     Integration/Worker/SiiRcvWorker.php
 *     Integration/Worker/SiiRcv/Job/{SubmitDocumentAcceptanceJob,
 *       ListDocumentEventsJob, GetDocumentSiiReceptionDateJob,
 *       CheckDocumentAssignabilityJob}.php
 *
 * Esta clase es una fachada liviana sobre ese worker: no reimplementa SOAP ni
 * la firma de la semilla (CrSeed/GetTokenFromSeed) — el worker la obtiene vía
 * SiiLazyWorker::authenticate(), el mismo mecanismo que SiiService reutiliza
 * para DTEUpload/QueryEstDte.
 *
 * Acciones válidas de accionDoc (las exige el SII; no son una convención
 * nuestra, ver enum en SubmitDocumentAcceptanceJob::VALID_ACTIONS):
 *   - ACD: acepta el contenido del documento.
 *   - RCD: reclama el contenido del documento.
 *   - ERM: otorga recibo de mercaderías o servicios (hace la factura cedible).
 *   - RFP: reclama falta parcial de mercaderías.
 *   - RFT: reclama falta total de mercaderías.
 *
 * IMPORTANTE: a diferencia de RespuestaDteBuilder, este WS no tiene un campo
 * de glosa/motivo libre — `ingresarAceptacionReclamoDoc` solo recibe el
 * código de acción. Un motivo que el usuario escriba en la UI queda como
 * evidencia interna de la app, nunca se envía al SII.
 *
 * RUT DEL DOCUMENTO: los tres métodos del WS
 * identifican el DTE por (rutEmisor, dvEmisor, tipoDoc, folio), donde
 * rutEmisor es el RUT de QUIEN EMITIÓ la factura — el PROVEEDOR —, no el
 * nuestro. Lo confirma la librería:
 *   vendor/libredte/libredte-lib-core/src/Package/Billing/Component/
 *     Integration/Worker/SiiRcv/Job/SubmitDocumentAcceptanceJob.php:65
 *     ("@param string $company RUT del emisor del DTE (formato RUT-DV)") y
 *     :87-98 (Rut::toArray($company) → 'rutEmisor'/'dvEmisor').
 * Pasar $config->emisorRut (el RUT propio) haría que el SII buscara una
 * factura NUESTRA con ese tipo/folio. Por eso el RUT del proveedor es un
 * parámetro obligatorio, se valida el DV y se rechaza explícitamente nuestro
 * propio RUT (no existe un DTE recibido que hayamos emitido nosotros).
 */
final class RegistroReclamoService
{
    private const ACCIONES_VALIDAS = ['ACD', 'RCD', 'ERM', 'RFP', 'RFT'];

    /**
     * @param object|null $worker Doble del SiiRcvWorker SOLO para pruebas
     *   offline: permite verificar el mapeo
     *   de parámetros sin abrir conexión con el SII. En producción es null y
     *   se usa el worker real de libredte-lib-core.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ?object $worker = null,
    ) {
    }

    /**
     * Valida y normaliza el RUT del EMISOR del documento recibido (el
     * proveedor) a "NNNNNNNN-D". Lanza InvalidArgumentException si falta, si
     * el DV no cuadra o si es nuestro propio RUT.
     */
    public function rutEmisorDocumento(string $rut): string
    {
        $rut = strtoupper(trim($rut));
        if ($rut === '') {
            throw new \InvalidArgumentException(
                'Falta el RUT del emisor del documento (el proveedor que emitió el DTE).'
            );
        }
        try {
            Rut::validate($rut);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf(
                'RUT del emisor del documento inválido ("%s"): %s',
                $rut,
                $e->getMessage()
            ));
        }
        [$numero, $dv] = Rut::toArray($rut);
        $normalizado = $numero . '-' . $dv;

        [$numeroPropio, $dvPropio] = Rut::toArray($this->config->emisorRut);
        if ($normalizado === $numeroPropio . '-' . $dvPropio) {
            throw new \InvalidArgumentException(
                'El RUT del emisor del documento es el de nuestra empresa: el Registro de Aceptación o Reclamo '
                . 'se aplica a documentos RECIBIDOS de proveedores, con el RUT del proveedor.'
            );
        }

        return $normalizado;
    }

    /** Certificado digital cargado desde disco (mismo criterio que SiiService::cargarCertificado()). */
    public function cargarCertificado(): CertificateInterface
    {
        if (!is_file($this->config->certPath)) {
            throw new \RuntimeException(
                'No hay certificado digital cargado. Súbelo desde /configuracion antes de usar el Registro de Reclamo DTE.'
            );
        }

        return (new CertificateLoader())->loadFromFile(
            $this->config->certPath,
            $this->config->resolveCertPass()
        );
    }

    /** Ambiente del SII según la configuración (igual criterio que SiiService::ambiente()). */
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
        if ($this->worker !== null) {
            return $this->worker;
        }

        return Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getIntegrationComponent()
            ->getSiiRcvWorker();
    }

    /**
     * Ingresa ante el SII la aceptación o reclamo de un DTE recibido.
     * IRREVERSIBLE: el SII no ofrece una forma de deshacer esta acción.
     *
     * @param string $rutEmisorDocumento RUT-DV del PROVEEDOR que emitió el DTE.
     * @return array{codigo:int, glosa:string, aceptado:bool}
     */
    public function registrarAccion(
        CertificateInterface $certificate,
        string $rutEmisorDocumento,
        int $tipoDoc,
        int $folio,
        string $accion
    ): array {
        if (!in_array($accion, self::ACCIONES_VALIDAS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'accion inválida "%s". Válidas: %s.',
                $accion,
                implode(', ', self::ACCIONES_VALIDAS)
            ));
        }
        $rutDocumento = $this->rutEmisorDocumento($rutEmisorDocumento);

        $respuesta = $this->worker()->submitDocumentAcceptance(
            $this->request($certificate),
            $rutDocumento,
            $tipoDoc,
            $folio,
            $accion
        );

        return [
            'codigo' => $respuesta->getCode(),
            'glosa' => $respuesta->getDescription(),
            'aceptado' => $respuesta->isAccepted(),
        ];
    }

    /**
     * Historial de eventos del documento en el Registro de Reclamo del SII.
     * Solo lectura.
     *
     * @return array<int, array{codigo:string, glosa:string, responsable:string, fecha:string}>
     */
    public function listarEventos(CertificateInterface $certificate, string $rutEmisorDocumento, int $tipoDoc, int $folio): array
    {
        $rutDocumento = $this->rutEmisorDocumento($rutEmisorDocumento);
        $respuesta = $this->worker()->listDocumentEvents(
            $this->request($certificate),
            $rutDocumento,
            $tipoDoc,
            $folio
        );

        return $respuesta->getEvents();
    }

    /**
     * Fecha real en que el SII recibió el documento (no la fecha de emisión
     * ni la del correo de intercambio): es la que exige la Ley 19.983 art. 3
     * como inicio de los 8 días corridos para aceptar o reclamar. Solo
     * lectura.
     */
    public function consultarFechaRecepcion(CertificateInterface $certificate, string $rutEmisorDocumento, int $tipoDoc, int $folio): string
    {
        $rutDocumento = $this->rutEmisorDocumento($rutEmisorDocumento);
        $respuesta = $this->worker()->getDocumentSiiReceptionDate(
            $this->request($certificate),
            $rutDocumento,
            $tipoDoc,
            $folio
        );

        return $respuesta->getReceptionDate();
    }
}
