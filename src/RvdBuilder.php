<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Book\Contract\ResumenVentasDiariasInterface;
use libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro;
use libredte\lib\Core\Package\Billing\Component\Book\Support\BookBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;

/**
 * Construye y firma el Resumen de Ventas Diarias (RVD, "Consumo de Folios")
 * usando el BookComponent real de libredte-lib-core.
 *
 * OJO: desde agosto 2022 el envío del RVD ya NO es obligatorio (Res. Ex. SII
 * N° 53 de 2022; el SII lo responde con el reparo informativo 250). Se
 * mantiene porque el formulario de Declaración de Cumplimiento de boletas
 * aún lo lista; el envío diario en la app cliente queda tras un flag.
 *
 * `FchInicio`/`FchFinal` de la carátula NO se pasan aquí: el
 * `BuilderStrategy` real de la librería los calcula solo, a partir del mínimo
 * y máximo `FchDoc` de los detalles (verificado contra el código fuente de
 * `ResumenVentasDiarias\BuilderStrategy`).
 */
final class RvdBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array $detalle Un ítem por boleta: TpoDoc, NroDoc (folio),
     *   FchDoc ('Y-m-d'), MntNeto, MntIVA, MntExe, MntTotal.
     * @param int $correlativo Correlativo del envío del RVD (empieza en 1,
     *   se incrementa en cada envío nuevo).
     * @param int $secEnvio Correlativo secundario del envío; normalmente 1.
     */
    public function construir(array $detalle, int $correlativo, int $secEnvio = 1): string
    {
        return $this->armar($detalle, ['Correlativo' => $correlativo, 'SecEnvio' => $secEnvio], false);
    }

    /**
     * RVD de UN día de operación real (cron diario de la app).
     *
     * Diferencias con construir():
     *  - Exige que todos los detalles sean boletas (39/41) de `$fecha`: un RVD
     *    que mezcla días o tipos no se arma.
     *  - Día sin ventas: la librería no genera ningún <Resumen> con detalle
     *    vacío (y el XSD exige al menos uno) y además pondría la fecha de HOY.
     *    Se arma con una línea centinela de la fecha (sin folio, total 0) y se
     *    corrigen los contadores a 0 antes de firmar (mismo patrón de
     *    re-firma que LibroBuilder).
     *  - Correlativo fijo en 1 (un solo RVD por día); las correcciones del
     *    mismo día suben `SecEnvio` (el SII da reparo 201 si se repite).
     *
     * @param array $detalle Un ítem por boleta: TpoDoc, NroDoc, FchDoc, MntNeto, MntIVA, MntExe, MntTotal.
     */
    public function construirDia(string $fecha, array $detalle, int $secEnvio): string
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($dia === false || $dia->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException("fecha inválida: {$fecha} (YYYY-MM-DD)");
        }
        if ($secEnvio < 1 || $secEnvio > 999) {
            throw new \InvalidArgumentException('secEnvio debe estar entre 1 y 999');
        }

        $folios = [];
        foreach ($detalle as $i => $d) {
            if (!in_array((int) ($d['TpoDoc'] ?? 0), [39, 41], true)) {
                throw new \InvalidArgumentException("Detalle {$i}: el RVD solo admite boletas (39/41)");
            }
            if (($d['FchDoc'] ?? null) !== $fecha) {
                throw new \InvalidArgumentException("Detalle {$i}: FchDoc distinta de {$fecha}");
            }
            $folio = (int) ($d['NroDoc'] ?? 0);
            if ($folio < 1 || isset($folios[$d['TpoDoc'] . '-' . $folio])) {
                throw new \InvalidArgumentException("Detalle {$i}: folio inválido o repetido ({$folio})");
            }
            $folios[$d['TpoDoc'] . '-' . $folio] = true;
            if (!is_int($d['MntTotal'] ?? null) || $d['MntTotal'] < 0) {
                throw new \InvalidArgumentException("Detalle {$i}: MntTotal debe ser entero >= 0");
            }
        }

        $sinVentas = $detalle === [];
        if ($sinVentas) {
            $detalle = [['TpoDoc' => 39, 'FchDoc' => $fecha, 'MntTotal' => 0]];
        }

        return $this->armar($detalle, ['Correlativo' => 1, 'SecEnvio' => $secEnvio], $sinVentas);
    }

    /** @param bool $enCero Día sin ventas: el único <Resumen> es una línea centinela que se deja en 0. */
    private function armar(array $detalle, array $caratula, bool $enCero): string
    {
        $certificate = $this->cargarCertificado();
        $emisor = $this->cargarEmisor();

        $bag = new BookBag(
            tipo: TipoLibro::RVD,
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
        $resumen = $book->getBuilderWorker()->build($bag);
        \assert($resumen instanceof ResumenVentasDiariasInterface);

        if ($enCero) {
            $xml = preg_replace('#<Signature\b.*?</Signature>#s', '', $resumen->getXml());
            $xml = preg_replace('#<(FoliosEmitidos|FoliosUtilizados)>1</\1>#', '<$1>0</$1>', $xml, -1, $cambios);
            if ($cambios !== 2 || str_contains($xml, '<RangoUtilizados>')) {
                throw new \RuntimeException('No se pudo armar el RVD en cero: la librería cambió el formato del <Resumen>.');
            }
            $resumen->getXmlDocument()->loadXml($xml);
            $worker = $book->getBuilderWorker();
            $firma = (new \ReflectionProperty($worker, 'signatureService'))->getValue($worker);
            $resumen->getXmlDocument()->loadXml($firma->signXml(
                $resumen->getXml(),
                $certificate,
                $resumen->getId(),
                $resumen->getSignatureNamespace()
            ));
        }

        // Falla rápido si el XML no cumple el esquema o la firma no valida:
        // mejor descubrirlo aquí que en el rechazo del SII.
        $book->getValidatorWorker()->validateSchema($bag);
        $resultado = $book->getValidatorWorker()->validateSignature($bag);
        if (!$resultado->isValid()) {
            throw new \RuntimeException(
                'El RVD no pasó la validación de firma: '
                . ($resultado->getError()?->getMessage() ?? 'error desconocido')
            );
        }

        return $resumen->getXml();
    }

    private function cargarCertificado(): CertificateInterface
    {
        return (new CertificateLoader())->loadFromFile(
            $this->config->certPath,
            $this->config->resolveCertPass()
        );
    }

    /** Mismo patrón que LibroBuilder::cargarEmisor() — ver ese comentario. */
    private function cargarEmisor(): mixed
    {
        return (new EmisorFactory())->create([
            'RUTEmisor' => $this->config->emisorRut,
            'RznSoc' => $this->config->emisorRazonSocial,
            'GiroEmis' => $this->config->emisorGiro,
            'Acteco' => $this->config->emisorActeco,
            'DirOrigen' => $this->config->emisorDireccion,
            'CmnaOrigen' => $this->config->emisorComuna,
            'autorizacion_dte' => [
                'fecha_resolucion' => $this->config->resolucionFecha,
                'numero_resolucion' => $this->config->resolucionNumero,
            ],
        ]);
    }
}
