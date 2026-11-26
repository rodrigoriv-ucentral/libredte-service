<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Document\Contract\DocumentBagInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Contract\DocumentEnvelopeInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;

/**
 * Arma y firma el "sobre" (`EnvioDTE`/`EnvioBOLETA`) que exige el webservice
 * real del SII — un `<DTE>` suelto no es un payload válido para
 * `sendXmlDocument()`.
 *
 * Por qué: enviar el XML del documento individual tal cual, sin envolver,
 * falla con
 * cualquier CAF real, no sólo en el reporte del set de certificación — se
 * encontró revisando `SendXmlDocumentJob::send()` (no arma ningún sobre, sólo
 * valida que el XML no esté vacío y lo postea) y confirmando en
 * `EnvioDTE_v10.xsd` que la raíz esperada por el schema es `EnvioDTE`, nunca
 * `DTE`.
 *
 * Receptor del sobre — decisión de diseño, no copiada literal del
 * instructivo del set de pruebas: el XSD dice que `RutReceptor` es el "RUT al
 * que se le envían los DTE". Nuestro único canal de transmisión es el
 * webservice del SII (`SiiDteWorker::sendXmlDocument()`); no hacemos
 * intercambio directo con el cliente final por este medio (el cliente recibe
 * su copia del DTE aparte, por PDF/email). Por eso el receptor del sobre es
 * **siempre** el RUT del SII (60803000-K), en certificación y en producción
 * — no es una regla exclusiva del set de pruebas.
 */
final class SobreBuilder
{
    private const RUT_SII = '60803000-K';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Arma, normaliza y firma el sobre a partir de documentos YA TIMBRADOS Y
     * FIRMADOS (los bags que devuelve `DocumentComponent::bill()`).
     *
     * Los documentos deben ser todos del mismo "tipo de sobre" (boletas por
     * un lado, 33/56/61 por otro) — mezclarlos lanza una excepción de la
     * propia librería, no hay que validarlo aparte.
     *
     * @param DocumentBagInterface[] $bags En el mismo orden en que deben
     *   reportarse — para el set de certificación, el SII exige literalmente
     *   "el mismo orden en que le fueron entregados".
     */
    public function construir(array $bags, CertificateInterface $certificate): DocumentEnvelopeInterface
    {
        if ($bags === []) {
            throw new \InvalidArgumentException('No hay documentos para armar el sobre');
        }

        $envelope = new DocumentEnvelope();
        foreach ($bags as $bag) {
            $envelope->addDocument($bag);
        }
        $envelope->setCertificate($certificate);
        $envelope->setEmisor($this->emisorConAutorizacion());
        $envelope->setReceptor($this->receptorSii());

        return $this->dispatcher()->normalize($envelope);
    }

    /**
     * Reconstruye los bags a partir de XML ya firmado en disco (para
     * reportar en sobre único documentos que se emitieron por separado, como
     * el set básico de certificación) y arma el sobre.
     *
     * @param string[] $xmlDocuments Contenido de cada archivo .xml, en el
     *   orden en que deben reportarse.
     */
    public function construirDesdeXml(array $xmlDocuments, CertificateInterface $certificate): DocumentEnvelopeInterface
    {
        $bags = [];
        foreach ($xmlDocuments as $xml) {
            foreach ($this->bagsDesdeXml($xml) as $bag) {
                $bags[] = $bag;
            }
        }

        return $this->construir($bags, $certificate);
    }

    /**
     * Arma el sobre "de intercambio" exigido por el SII entre contribuyentes
     * (Ley 19.983): a diferencia de construir()/construirDesdeXml(), donde el
     * receptor del sobre es siempre el SII (es el canal por el que se le
     * envían los documentos), aquí el receptor del sobre (Caratula/
     * RutReceptor) es el CLIENTE. Este sobre no se despacha al webservice del
     * SII: se adjunta al correo que recibe el cliente, junto al PDF.
     *
     * No consume folio: reconstruye el/los documentos desde el XML ya
     * timbrado, igual que construirDesdeXml(). Acepta tanto un `<DTE>` suelto
     * como un `<EnvioDTE>`/`<EnvioBOLETA>` completo (p. ej. una factura
     * externa del Portal Mipyme) — en ese caso se extraen todos los `<DTE>`
     * que contenga y se reportan en el mismo orden.
     */
    public function construirParaReceptor(
        string $xml,
        CertificateInterface $certificate,
        string $rutReceptor,
        string $razonSocialReceptor,
    ): DocumentEnvelopeInterface {
        $envelope = new DocumentEnvelope();
        foreach ($this->bagsDesdeXml($xml) as $bag) {
            $envelope->addDocument($bag);
        }
        $envelope->setCertificate($certificate);
        $envelope->setEmisor($this->emisorConAutorizacion());
        $envelope->setReceptor($this->receptorCliente($rutReceptor, $razonSocialReceptor));

        return $this->dispatcher()->normalize($envelope);
    }

    /**
     * Carga un XML (un `<DTE>` suelto o un `<EnvioDTE>`/`<EnvioBOLETA>`
     * completo) y devuelve los bags de TODOS los documentos que contiene.
     * `DocumentComponent::loadXml()` ya distingue ambos casos: para un `<DTE>`
     * suelto devuelve `[$bag]`; para un sobre, los documentos de su `SetDTE`
     * (verificado contra el código real, no documentado en la firma).
     *
     * @return DocumentBagInterface[]
     */
    private function bagsDesdeXml(string $xml): array
    {
        $documentComponent = Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getDocumentComponent();

        $bags = $documentComponent->loadXml($xml);
        if ($bags === [] || !($bags[0] ?? null) instanceof DocumentBagInterface) {
            throw new \RuntimeException(
                'No se pudo reconstruir ningún documento desde el XML guardado (formato inesperado de loadXml()).'
            );
        }

        return $bags;
    }

    private function receptorCliente(string $rut, string $razonSocial): mixed
    {
        return (new ReceptorFactory())->create([
            'RUTRecep' => $rut,
            'RznSocRecep' => $razonSocial !== '' ? $razonSocial : 'Cliente',
        ]);
    }

    private function dispatcher()
    {
        return Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getDocumentComponent()
            ->getDispatcherWorker();
    }

    /**
     * Emisor CON `autorizacion_dte` (Resolución SII) explícita. Es necesario
     * construirlo aparte: el Emisor que arma `bill()` a partir del array
     * `Encabezado.Emisor` no lleva ese dato (no es parte del esquema DTE, es
     * un dato del SOBRE), y `DispatcherWorker::ensureCaratula()` no genera la
     * carátula del sobre sin él — falla en silencio (`return` temprano), no
     * con una excepción, así que sin esto el sobre quedaría vacío sin avisar.
     */
    private function emisorConAutorizacion(): mixed
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

    private function receptorSii(): mixed
    {
        return (new ReceptorFactory())->create([
            'RUTRecep' => self::RUT_SII,
            'RznSocRecep' => 'SERVICIO DE IMPUESTOS INTERNOS',
        ]);
    }
}
