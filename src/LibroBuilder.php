<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Book\Contract\LibroComprasVentasInterface;
use libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro;
use libredte\lib\Core\Package\Billing\Component\Book\Support\BookBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;

/**
 * Construye y firma el Libro de Compra/Venta (`LibroCV_v10.xsd`) a partir de
 * un arreglo de documentos, usando el BookComponent de libredte-lib-core.
 *
 * IMPORTANTE — lo que esta clase NO hace: libredte-lib-core no trae un
 * worker de envío del libro al SII (a diferencia de SiiDteWorker para DTE).
 * El XML que se genera aquí se sube manualmente por el portal de
 * certificación del SII (opción "Registro de Compra y Venta" / upload del
 * set), no por un webservice automatizado.
 *
 * Campos de cada línea de `detalle` verificados contra
 * AbstractLibroComprasVentasArrayLoaderStrategy::normalizeDetalle() dentro de
 * la propia librería (no inventados):
 *
 *   TpoDoc, NroDoc, FchDoc, RUTDoc, RznSoc, MntExe, MntNeto, MntIVA,
 *   MntTotal, IVANoRec[{CodIVANoRec, MntIVANoRec}], FctProp (0-100, "factor
 *   de proporcionalidad" del IVA de uso común — con esto la librería calcula
 *   IVAUsoComun sola), OtrosImp[{CodImp, TasaImp, MntImp}] (CodImp=15 IVA
 *   retenido total, 16 parcial), IVARetTotal, IVARetParcial, IndSinCosto
 *   (entrega gratuita), Anulado ('A').
 */
final class LibroBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Libro de Ventas: documentos propios emitidos en el período.
     *
     * Para el Libro de PRUEBA de certificación, "Instrucciones para
     * Construcción del Set de Prueba" del SII exige en la carátula
     * `TipoLibro: ESPECIAL` (no "MENSUAL", que es el default de la librería
     * fuera de certificación) y `FolioNotificacion: 1`. Para producción, en
     * cambio, corresponde el libro mensual normal — de ahí el parámetro.
     */
    public function construirVentas(
        array $detalle,
        string $periodoTributario,
        bool $esSetDeCertificacion = false,
    ): string {
        return $this->construir(TipoLibro::VENTAS, $detalle, $periodoTributario, $esSetDeCertificacion ? 1 : null);
    }

    /**
     * Libro de Compras: documentos recibidos de terceros en el período.
     * Ver nota de $esSetDeCertificacion en construirVentas() — aquí el Folio
     * Notificación exigido es 2, no 1.
     */
    public function construirCompras(
        array $detalle,
        string $periodoTributario,
        bool $esSetDeCertificacion = false,
    ): string {
        return $this->construir(TipoLibro::COMPRAS, $detalle, $periodoTributario, $esSetDeCertificacion ? 2 : null);
    }

    private function construir(
        TipoLibro $tipo,
        array $detalle,
        string $periodoTributario,
        ?int $folioNotificacionCertificacion,
    ): string {
        $certificate = $this->cargarCertificado();
        $emisor = $this->cargarEmisor();

        $caratula = ['PeriodoTributario' => $periodoTributario];
        $esAjuste = getenv('LIBREDTE_LIBRO_TIPO_ENVIO') === 'AJUSTE';
        if ($folioNotificacionCertificacion !== null) {
            $caratula['TipoLibro'] = 'ESPECIAL';
            // Override para reenviar a un libro ESPECIAL distinto cuando el
            // del folio por defecto ya quedó cerrado (LTC) con reparos.
            $folioEnv = getenv('LIBREDTE_LIBRO_FOLIO_NOTIFICACION');
            $caratula['FolioNotificacion'] = ($folioEnv !== false && $folioEnv !== '')
                ? (int) $folioEnv
                : $folioNotificacionCertificacion;
            if (($tipoEnvio = getenv('LIBREDTE_LIBRO_TIPO_ENVIO')) !== false && $tipoEnvio !== '') {
                $caratula['TipoEnvio'] = $tipoEnvio;
            }
        }

        $bag = new BookBag(
            tipo: $tipo,
            caratula: $caratula,
            detalle: $detalle,
            certificate: $certificate,
            emisor: $emisor,
        );

        $book = Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getBookComponent();

        $bag = $book->getLoaderWorker()->load($bag);
        $libro = $book->getBuilderWorker()->build($bag);
        \assert($libro instanceof LibroComprasVentasInterface);

        // El SII exige en un AJUSTE ResumenSegmento Y ResumenPeriodo (verificado
        // con rechazos LBR-3 en Maullín). La librería solo genera el de
        // período: se duplica como segmento (antes, según el XSD) y se firma
        // después. ResumenSegmento no admite FctProp ni TotCredIVAUsoComun.
        if ($esAjuste) {
            $xml = preg_replace('#<Signature\b.*?</Signature>#s', '', $libro->getXml());
            $xml = preg_replace_callback(
                '#<ResumenPeriodo>.*?</ResumenPeriodo>#s',
                static function (array $m): string {
                    $segmento = str_replace(
                        ['ResumenPeriodo>', 'TotalesPeriodo>'],
                        ['ResumenSegmento>', 'TotalesSegmento>'],
                        $m[0]
                    );
                    $segmento = preg_replace('#\s*<(?:FctProp|TotCredIVAUsoComun)>[^<]*</(?:FctProp|TotCredIVAUsoComun)>#', '', $segmento);

                    return $segmento . "\n    " . $m[0];
                },
                $xml
            );
            $libro->getXmlDocument()->loadXml($xml);
            $signedXml = $this->servicioFirma($book)->signXml(
                $libro->getXml(),
                $certificate,
                $libro->getId(),
                $libro->getSignatureNamespace()
            );
            $libro->getXmlDocument()->loadXml($signedXml);
        }

        // Falla rápido si el XML no cumple el esquema o la firma no valida:
        // mejor descubrirlo aquí que en el rechazo del SII.
        $book->getValidatorWorker()->validateSchema($bag);
        $resultado = $book->getValidatorWorker()->validateSignature($bag);
        if (!$resultado->isValid()) {
            throw new \RuntimeException(
                'El libro no pasó la validación de firma: '
                . ($resultado->getError()?->getMessage() ?? 'error desconocido')
            );
        }

        return $libro->getXml();
    }

    // El servicio de firma es privado en el contenedor; se toma del worker
    // que la librería ya usa para firmar el resto de los libros.
    private function servicioFirma(object $book): object
    {
        $worker = $book->getBuilderWorker();
        $propiedad = new \ReflectionProperty($worker, 'signatureService');

        return $propiedad->getValue($worker);
    }

    private function cargarCertificado(): CertificateInterface
    {
        return (new CertificateLoader())->loadFromFile(
            $this->config->certPath,
            $this->config->resolveCertPass()
        );
    }

    private function cargarEmisor()
    {
        return (new EmisorFactory())->create([
            'RUTEmisor' => $this->config->emisorRut,
            'RznSoc' => $this->config->emisorRazonSocial,
            'GiroEmis' => $this->config->emisorGiro,
            'Acteco' => $this->config->emisorActeco,
            'DirOrigen' => $this->config->emisorDireccion,
            'CmnaOrigen' => $this->config->emisorComuna,
            // FchResol/NroResol son obligatorios en la carátula del libro
            // (el XSD LibroCV_v10.xsd rechaza el XML sin ellos, verificado
            // contra el esquema real). Ver Config::$resolucionFecha para el
            // valor por defecto usado y por qué debe confirmarse.
            'autorizacion_dte' => [
                'fecha_resolucion' => $this->config->resolucionFecha,
                'numero_resolucion' => $this->config->resolucionNumero,
            ],
        ]);
    }
}
