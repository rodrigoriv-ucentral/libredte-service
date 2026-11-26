<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\AutorizacionDte;

/**
 * Envuelve libredte-lib-core para emitir un DTE (factura, boleta, nota de
 * crédito o nota de débito): reserva el folio, timbra, firma y —salvo que se
 * pida lo contrario— envía al SII.
 *
 * El armado del documento vive en DteBuilder; aquí sólo la orquestación.
 *
 * IMPORTANTE: las claves exactas del arreglo $data que espera bill() deben
 * verificarse contra los "casos de uso" oficiales de libredte-lib-core
 * (core.libredte.cl/docs/lib/casos-de-uso) antes de emitir documentos reales;
 * se usan los nombres estándar del formato DTE del SII, que es lo que la
 * librería normaliza internamente.
 */
final class DteEmisor
{
    public function __construct(
        private readonly Config $config,
        private readonly FolioRepository $folios,
    ) {
    }

    public function emitir(array $payload): array
    {
        $tipoDte = match ($payload['tipoDocumento'] ?? null) {
            'factura' => 33,
            'boleta' => 39,
            'nota_credito' => 61,
            'nota_debito' => 56,
            default => throw new \InvalidArgumentException(
                'tipoDocumento debe ser "factura", "boleta", "nota_credito" o "nota_debito"'
            ),
        };

        // Fecha de emisión: se valida ANTES de reservar folio.
        if (isset($payload['fechaEmision'])) {
            $f = $payload['fechaEmision'];
            if (!is_string($f) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \InvalidArgumentException('fechaEmision debe tener formato AAAA-MM-DD y ser una fecha válida');
            }
            if ($f > date('Y-m-d')) {
                throw new \InvalidArgumentException('fechaEmision no puede ser posterior a hoy');
            }
        }

        if ($tipoDte === 39) {
            $payload = self::receptorBoleta($payload);
        }

        // Clave de idempotencia: identifica la venta (o la NC) que origina el
        // documento. Si ya se timbró con esa clave se devuelve el mismo
        // documento en vez de consumir otro folio; si sólo se alcanzó a
        // reservar el folio, se reutiliza ese folio.
        $clave = $payload['claveIdempotencia'] ?? null;
        if ($clave !== null) {
            if (!is_string($clave) || !preg_match('/^[A-Za-z0-9:_#.\-]{1,100}$/', $clave)) {
                throw new \InvalidArgumentException('claveIdempotencia inválida (1-100 caracteres: letras, números, : _ # . -)');
            }
            $previa = $this->folios->buscarEmision($clave);
            if ($previa !== null && (int) $previa['tipo_dte'] !== $tipoDte) {
                throw new \InvalidArgumentException("La clave de idempotencia '{$clave}' ya se usó para otro tipo de DTE");
            }
            if ($previa !== null && $previa['estado'] === 'timbrado') {
                return self::respuestaDesdeLibro($previa);
            }
        }

        // Receptor incompleto: se rechaza ANTES de reservar folio (y después de
        // la recuperación por clave, para no bloquear la de un documento que ya
        // se timbró). Sin esto la librería completaba lo vacío con los datos
        // del SII (RUT 60803000-K, "Alonso Ovalle 680", "Santiago"...).
        self::validarReceptor($payload, $tipoDte);

        $app = Application::getInstance();

        // Boleta: los totales que va a timbrar la librería se verifican ANTES de
        // reservar folio (ver validarTotalesBoleta()).
        $totalesBoleta = $tipoDte === 39
            ? self::validarTotalesBoleta(
                $app->getPackageRegistry()->getBillingPackage()->getDocumentComponent(),
                $this->config,
                $payload,
            )
            : null;

        // Factura / ND / NC: lo que va a timbrar la librería (totales
        // normalizados, no la cuenta de DteBuilder) debe cuadrar y ser lo que informa
        // la app. Se rechaza ANTES de reservar folio. La NC que anula una boleta va con
        // detalle bruto + IdDoc/MntBruto=1 (idéntica a la boleta) y, con preciosBrutos,
        // exige los montos neto/IVA/total del documento que acredita (validarTotalDocumento()).
        $totalesDocumento = $tipoDte !== 39
            ? self::validarTotalDocumento(
                $app->getPackageRegistry()->getBillingPackage()->getDocumentComponent(),
                $this->config,
                $payload,
                $tipoDte,
            )
            : null;

        $cafLoader = $app
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getIdentifierComponent()
            ->getCafLoaderWorker();

        $cafs = [];
        foreach ($this->cafFilesPara($tipoDte) as $cafFile) {
            $cafContenido = file_get_contents($cafFile);
            if ($cafContenido === false) {
                throw new \RuntimeException("No se pudo leer el CAF: {$cafFile}");
            }
            $caf = $cafLoader->load($cafContenido);
            $this->validarCaf($caf, $tipoDte, $cafFile);
            if (!$caf->isVigente()) {
                continue; // CAF vencido (6 meses; boletas no vencen): no se usa.
            }
            $cafs[] = $caf;
        }
        if ($cafs === []) {
            throw new \RuntimeException(
                "No hay CAF vigente para el tipo DTE {$tipoDte}: todos los cargados están vencidos. Solicita uno nuevo."
            );
        }

        $folio = $this->folios->siguienteFolioEnRangos(
            $tipoDte,
            array_map(static fn ($c): array => [$c->getFolioDesde(), $c->getFolioHasta()], $cafs),
            $clave
        );

        try {
            $caf = null;
            foreach ($cafs as $c) {
                if ($folio >= $c->getFolioDesde() && $folio <= $c->getFolioHasta()) {
                    $caf = $c;
                    break;
                }
            }
            if ($caf === null) {
                throw new \RuntimeException("El folio {$folio} no pertenece a ningún CAF vigente cargado");
            }

            // El SII rechaza "Fecha del documento debe ser mayor o igual a la
            // fecha autorizada por el SII en el CAF": FchEmis no puede ser
            // anterior a la FA (<AUTORIZACION><CAF><DA><FA>) del CAF que cubre
            // este folio. Se valida ANTES de cargar el certificado y timbrar,
            // pero el folio ya quedó reservado más arriba: no hay forma de
            // conocer qué CAF corresponde sin elegir primero el folio. Con
            // clave de idempotencia, un fallo aquí (o en cualquier paso antes
            // de registrarTimbrado) libera el folio para reutilizarlo; sin
            // clave (scripts CLI) el folio queda saltado en la secuencia.
            $fechaAutorizacionCaf = $caf->getFechaAutorizacion();
            $fechaEmision = (string) ($payload['fechaEmision'] ?? date('Y-m-d'));
            if ($fechaEmision < $fechaAutorizacionCaf) {
                throw new \RuntimeException(
                    "FchEmis {$fechaEmision} es anterior a la fecha de autorización del CAF "
                    . "{$fechaAutorizacionCaf} (folio {$folio}): el SII lo rechaza"
                );
            }

            $certificateLoader = new CertificateLoader();
            $certificate = $certificateLoader->loadFromFile($this->config->certPath, $this->config->resolveCertPass());

            // Los totales se calculan a partir del detalle, no se toman del payload:
            // el SII cuadra Totales contra Detalle y rechaza si difieren.
            $data = (new DteBuilder($this->config))->construir($payload, $tipoDte, $folio);
            // Correo y teléfono reales del cliente (33/56/61). DteBuilder no se toca.
            $data = self::conContactoReceptor($data, $payload, $tipoDte);

            $documentComponent = $app->getPackageRegistry()->getBillingPackage()->getDocumentComponent();

            // bill() devuelve el "bag" (contenedor), no el documento directo. El
            // formato del renderizador se pide aquí, en $options: RendererWorker
            // no tiene un parámetro de formato propio, lo lee de
            // $bag->getRendererOptions() (verificado contra el código real de
            // libredte-lib-core — no es la firma que documentan los ejemplos).
            //
            // Para boleta se usa una plantilla propia (templates/boleta.html.twig,
            // copia de la estándar de la librería con el footer cambiado): el
            // SII exige que la URL de consulta de boletas vaya
            // impresa en la representación. `template` acepta una ruta absoluta
            // (AbstractRendererStrategy::createDataAndOptions() la usa tal cual
            // si empieza con "/", verificado contra el código real).
            $opcionesRenderer = ['format' => 'pdf'];
            $opcionesRenderer['template'] = $tipoDte === 39
                ? '/app/templates/boleta.html.twig'
                : '/app/templates/factura.html.twig';

            $bag = self::facturar($documentComponent, $data, $caf, $certificate, [
                'renderer' => $opcionesRenderer,
            ]);

            $document = $bag->getDocument();
            $xml = $document->saveXml();
            // cedible: el PDF lleva el original + la copia cedible (acuse de
            // recibo, Ley 19.983) para ceder la factura a factoring.
            $pdf = self::renderizarPdf($documentComponent, $bag, $this->config, (bool) ($payload['cedible'] ?? false));

            // Los totales de la librería ya están normalizados (MntNeto/MntExe sumados).
            $totales = $bag->getNormalizedData()['Encabezado']['Totales'] ?? $data['Encabezado']['Totales'];

            // Defensa: lo timbrado debe ser exactamente lo validado antes de
            // reservar el folio. Si no, se aborta aquí (antes de registrar y de
            // enviar): con clave de idempotencia el folio se libera abajo.
            if ($totalesDocumento !== null && self::montosTotales($totales) !== $totalesDocumento) {
                throw new \RuntimeException(
                    'Los totales timbrados no coinciden con los validados: no se registra ni se envía'
                );
            }
            if ($totalesBoleta !== null && self::montosTotales($totales) !== $totalesBoleta) {
                throw new \RuntimeException(
                    'Los totales timbrados de la boleta no coinciden con los validados: no se registra ni se envía'
                );
            }

            // El documento queda respaldado en el libro ANTES de intentar el envío:
            // si el proceso muere o la app no alcanza a guardarlo, se recupera por
            // clave sin volver a timbrar.
            if ($clave !== null) {
                $this->folios->registrarTimbrado($clave, base64_encode($xml), base64_encode($pdf), $totales);
            }
        } catch (\Throwable $e) {
            if ($clave !== null) {
                $this->folios->liberarReserva($clave);
            }
            throw $e;
        }

        // El set de pruebas de boletas exige enviar todos los documentos en un
        // solo sobre, así que timbrar y enviar tienen que poder separarse: con
        // enviar=false se devuelve el XML firmado para agruparlo después.
        $trackId = null;
        $envioError = null;
        if ($payload['enviar'] ?? true) {
            try {
                // El webservice del SII exige el documento envuelto en
                // EnvioDTE/EnvioBOLETA — un <DTE> suelto (lo que se enviaba
                // antes de este fix) no es un payload válido para
                // sendXmlDocument(), habría fallado con cualquier CAF real.
                // Ver SobreBuilder.php para el detalle completo del bug.
                $sobre = (new SobreBuilder($this->config))->construir([$bag], $certificate);
                $trackId = (new SiiService($this->config))->enviar(
                    $sobre->getXmlDocument(),
                    $certificate
                );
            } catch (\Throwable $e) {
                // El folio ya fue timbrado localmente; no se revierte. Se informa
                // el error de envío para que el consumidor decida reintentar el
                // envío al SII sin volver a timbrar (evita duplicar folios).
                $envioError = self::mensajeEnvioError($e);
            }
        }

        if ($clave !== null) {
            $this->folios->registrarEnvio($clave, $trackId, $envioError);
        }

        return [
            'tipoDte' => $tipoDte,
            'folio' => $folio,
            'totales' => $totales,
            'xml' => base64_encode($xml),
            'pdf' => base64_encode($pdf),
            'trackId' => $trackId,
            'envioError' => $envioError,
        ];
    }

    /**
     * Texto de envioError. Un STATUS 99 del SII (el envío ya estaba recibido) lleva el prefijo
     * "SII_STATUS_99: " para que la app no lo trate como un fallo del envío.
     */
    public static function mensajeEnvioError(\Throwable $e): string
    {
        return ($e instanceof EnvioYaRecibidoException ? 'SII_STATUS_99: ' : '') . $e->getMessage();
    }

    /**
     * bill() de la librería, pero sin los datos falsos del receptor (todos los
     * tipos: 33, 39, 56, 61).
     *
     * libredte-lib-core completa los campos vacíos del receptor con su
     * FakeReceptorProvider (= el propio SII): Contacto "+56 2 32525575",
     * correo "correo.sii@example.com" (que en boleta convierte en una
     * Referencia "Email receptor: ..."), dirección "Alonso Ovalle 680",
     * comuna "Santiago"… No hay opción para desactivarlo, así que se normaliza
     * primero sin CAF ni firma, se quita lo que el payload no traía y se
     * timbra/firma esos datos ya normalizados (normalize=false).
     */
    public static function facturar(
        object $documentComponent,
        array $data,
        mixed $caf,
        mixed $certificate,
        array $opciones = [],
    ): object {
        $normalizado = $documentComponent->bill($data)->getNormalizedData();
        $limpio = self::sinRellenoReceptor($normalizado, $data);

        $opciones['normalizer'] = ['normalize' => false] + ($opciones['normalizer'] ?? []);

        return $documentComponent->bill($limpio, $caf, $certificate, $opciones);
    }

    /** RUT genérico de consumidor final (boleta sin RUT del comprador). */
    public const RUT_CONSUMIDOR_FINAL = '66666666-6';

    /**
     * Receptor de una boleta (39) sin RUT del comprador: consumidor final
     * 66666666-6, como el set de boletas de certificación. Sin esto
     * la librería rellena RUTRecep/RznSocRecep con su FakeReceptorProvider (el
     * propio SII, 60803000-K: NormalizeDataPreDocumentNormalizationJob::
     * normalizeReceptor) y el SII rechaza así las boletas del set (HED-3-211).
     * Un RUT o una razón social que vengan se respetan.
     */
    private static function receptorBoleta(array $payload): array
    {
        if (trim((string) ($payload['rutCliente'] ?? '')) === '') {
            $payload['rutCliente'] = self::RUT_CONSUMIDOR_FINAL;
        }
        if (trim((string) ($payload['razonSocial'] ?? '')) === '') {
            $payload['razonSocial'] = 'Cliente';
        }
        return $payload;
    }

    /**
     * Control ALREDEDOR del armado de la boleta (no lo cambia): normaliza el
     * documento con la librería, sin CAF ni firma ni folio real (lo mismo que
     * ya hace facturar() en su primer paso), y exige que los totales que se
     * timbrarían cuadren al peso:
     *
     *   MntNeto + IVA + MntExe == MntTotal  (== montoTotal del payload, si viene)
     *
     * Motivo: en boleta la librería toma MntTotal
     * como la suma de los MontoItem (NormalizeDetalleTrait, rama isBoleta) y
     * conserva MntNeto/IVA que le entrega DteBuilder. Con precios NETOS sin
     * `preciosBrutos` el resultado sería MntTotal = neto con IVA aparte (la
     * boleta saldría por el neto). Con precios brutos + preciosBrutos=true
     * (como el set certificado) cuadra. Si no cuadra se lanza
     * InvalidArgumentException (HTTP 400) y NO se reserva folio.
     *
     * @return array{MntNeto: int, IVA: int, MntExe: int, MntTotal: int}
     */
    public static function validarTotalesBoleta(object $documentComponent, Config $config, array $payload): array
    {
        // Folio 1 de relleno: no se reserva nada; solo se normaliza.
        $data = (new DteBuilder($config))->construir($payload, 39, 1);
        $m = self::montosTotales($documentComponent->bill($data)->getNormalizedData()['Encabezado']['Totales'] ?? []);

        if ($m['MntTotal'] <= 0) {
            throw new \InvalidArgumentException('La boleta no tiene monto total: no se emite');
        }
        if ($m['MntNeto'] + $m['IVA'] + $m['MntExe'] !== $m['MntTotal']) {
            throw new \InvalidArgumentException(sprintf(
                'Boleta descuadrada: MntNeto %d + IVA %d + MntExe %d ≠ MntTotal %d. En boleta los precios van '
                . 'con IVA incluido (preciosBrutos=true). No se reservó folio.',
                $m['MntNeto'], $m['IVA'], $m['MntExe'], $m['MntTotal'],
            ));
        }
        if ($m['MntNeto'] > 0 && $m['IVA'] <= 0) {
            throw new \InvalidArgumentException(
                "Boleta con IVA 0 sobre un neto de {$m['MntNeto']}: el SII la rechaza (monto demasiado bajo). No se reservó folio."
            );
        }
        if (isset($payload['montoTotal'])) {
            $esperado = $payload['montoTotal'];
            if (!is_numeric($esperado) || (float) $esperado !== (float) $m['MntTotal']) {
                throw new \InvalidArgumentException(sprintf(
                    'El total de la boleta (MntTotal %d) no coincide con el monto cobrado (%s). No se reservó folio.',
                    $m['MntTotal'],
                    is_scalar($esperado) ? (string) $esperado : gettype($esperado),
                ));
            }
        }

        return $m;
    }

    /**
     * Factura (33), nota de débito (56) y nota de crédito (61): igual que en la
     * boleta, los totales salen de la NORMALIZACIÓN de la librería (lo que de verdad
     * se timbraría), no de la cuenta de DteBuilder::totales(), que no ve lo que hace
     * la librería con el detalle (p. ej. un MntNeto bruto en una NC con detalle bruto).
     * Lanza InvalidArgumentException (HTTP 400) y NO reserva folio si:
     *  - a) MntNeto + IVA + MntExe != MntTotal;
     *  - b) viene `montoTotal` y no es numérico o difiere de MntTotal;
     *  - c) `preciosBrutos` es true (NC que anula una boleta: detalle bruto +
     *       IdDoc/MntBruto=1, idéntica a la boleta) y falta o difiere alguno de
     *       `montoNeto`, `montoIva` y `montoTotal`: son los montos guardados de la
     *       boleta y la NC timbrada debe calzar con los tres.
     * En facturas sin preciosBrutos no se validan `montoNeto`/`montoIva`.
     *
     * @return array{MntNeto: int, IVA: int, MntExe: int, MntTotal: int}  Totales normalizados.
     */
    public static function validarTotalDocumento(object $documentComponent, Config $config, array $payload, int $tipoDte): array
    {
        // Folio 1 de relleno: no se reserva nada; solo se normaliza.
        $data = (new DteBuilder($config))->construir($payload, $tipoDte, 1);
        $m = self::montosTotales($documentComponent->bill($data)->getNormalizedData()['Encabezado']['Totales'] ?? []);
        $etq = "documento tipo {$tipoDte}";

        if ($m['MntNeto'] + $m['IVA'] + $m['MntExe'] !== $m['MntTotal']) {
            throw new \InvalidArgumentException(sprintf(
                'El %s quedaría descuadrado: MntNeto %d + IVA %d + MntExe %d ≠ MntTotal %d. No se reservó folio.',
                $etq, $m['MntNeto'], $m['IVA'], $m['MntExe'], $m['MntTotal'],
            ));
        }

        $campos = ['montoTotal' => 'MntTotal'];
        if ((bool) ($payload['preciosBrutos'] ?? false)) {
            foreach (['montoNeto', 'montoIva', 'montoTotal'] as $k) {
                if (!isset($payload[$k])) {
                    throw new \InvalidArgumentException(
                        "Con preciosBrutos el {$etq} exige {$k} (los montos del documento que se acredita). No se reservó folio."
                    );
                }
            }
            $campos = ['montoNeto' => 'MntNeto', 'montoIva' => 'IVA', 'montoTotal' => 'MntTotal'];
        }
        foreach ($campos as $k => $tag) {
            if (!isset($payload[$k])) {
                continue;
            }
            $esperado = $payload[$k];
            if (!is_numeric($esperado) || (float) $esperado !== (float) $m[$tag]) {
                throw new \InvalidArgumentException(sprintf(
                    'El %s (%s %d, calculado desde el detalle) no coincide con el monto informado %s (%s). No se reservó folio.',
                    $etq,
                    $tag,
                    $m[$tag],
                    $k,
                    is_scalar($esperado) ? (string) $esperado : gettype($esperado),
                ));
            }
        }

        return $m;
    }

    /** @return array{MntNeto: int, IVA: int, MntExe: int, MntTotal: int} */
    private static function montosTotales(array $totales): array
    {
        return [
            'MntNeto' => (int) ($totales['MntNeto'] ?? 0),
            'IVA' => (int) ($totales['IVA'] ?? 0),
            'MntExe' => (int) ($totales['MntExe'] ?? 0),
            'MntTotal' => (int) ($totales['MntTotal'] ?? 0),
        ];
    }

    /**
     * Datos mínimos del receptor fuera de boleta, validados ANTES de reservar
     * folio (InvalidArgumentException → HTTP 400, no se gasta folio):
     *  - 33/56/61: RUT, razón social y giro (si faltan, la librería pone los
     *    del SII: 60803000-K, "Servicio de Impuestos Internos", "Gobierno").
     *  - 33: además dirección y comuna (obligatorias en factura según el
     *    formato DTE del SII; el XSD las deja opcionales solo por la boleta).
     *    En notas no se exige: una NC sobre boleta puede no tener domicilio.
     */
    private static function validarReceptor(array $payload, int $tipoDte): void
    {
        if ($tipoDte === 39) {
            return;
        }
        $campos = ['rutCliente' => 'RUT', 'razonSocial' => 'razón social', 'giro' => 'giro'];
        if ($tipoDte === 33) {
            $campos += ['direccion' => 'dirección', 'comuna' => 'comuna'];
        }
        $faltan = [];
        foreach ($campos as $clave => $nombre) {
            if (trim((string) ($payload[$clave] ?? '')) === '') {
                $faltan[] = $nombre;
            }
        }
        if ($faltan !== []) {
            throw new \InvalidArgumentException(
                'Faltan datos del receptor (' . implode(', ', $faltan) . '): complétalos en la ficha del cliente. '
                . 'No se reservó folio.'
            );
        }
    }

    /**
     * CorreoRecep y Contacto reales del cliente (payload `email` y `telefono`)
     * en 33/56/61. Boleta no cambia (allí el correo se volvería una Referencia
     * y su formato está certificado). Un correo con forma inválida o un dato
     * vacío no se envía: queda vacío y sinRellenoReceptor() impide el relleno.
     */
    public static function conContactoReceptor(array $data, array $payload, int $tipoDte): array
    {
        if ($tipoDte === 39) {
            return $data;
        }
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '' && mb_strlen($email) <= 80 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $data['Encabezado']['Receptor']['CorreoRecep'] = $email;
        }
        $telefono = trim((string) ($payload['telefono'] ?? ''));
        if ($telefono !== '') {
            $data['Encabezado']['Receptor']['Contacto'] = mb_substr($telefono, 0, 80);
        }
        return $data;
    }

    /**
     * PDF del documento. Con cedible=true: un solo PDF con el ORIGINAL y luego
     * la COPIA CEDIBLE (acuse de recibo + leyenda), como la representación
     * impresa del SII. La librería no tiene opción de copias, así que se
     * renderiza dos veces con la plantilla de siempre y se unen las páginas
     * con mPDF/FPDI (ya vienen con la librería). Sin cedible, igual que antes.
     */
    public static function renderizarPdf(object $documentComponent, object $bag, Config $config, bool $cedible): string
    {
        $renderer = $documentComponent->getRendererWorker();
        self::prepararRender($bag, $config, false);
        $original = $renderer->render($bag);
        if (!$cedible) {
            return $original;
        }
        self::prepararRender($bag, $config, true);
        $copiaCedible = $renderer->render($bag);

        return self::unirPdfs([$original, $copiaCedible]);
    }

    /** @param list<string> $pdfs */
    public static function unirPdfs(array $pdfs): string
    {
        $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->SetCompression(true);
        foreach ($pdfs as $pdf) {
            $paginas = $mpdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
            for ($i = 1; $i <= $paginas; $i++) {
                $tpl = $mpdf->importPage($i);
                $size = $mpdf->getTemplateSize($tpl);
                $mpdf->AddPageByArray([
                    'orientation' => $size['orientation'],
                    'sheet-size' => [$size['width'], $size['height']],
                ]);
                $mpdf->useTemplate($tpl);
            }
        }

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Quita del receptor normalizado lo que no venía en los datos de entrada. */
    private static function sinRellenoReceptor(array $normalizado, array $entrada): array
    {
        $receptorEntrada = $entrada['Encabezado']['Receptor'] ?? [];
        $campos = ['Contacto', 'CorreoRecep', 'DirRecep', 'CmnaRecep', 'CiudadRecep'];
        if ((int) ($entrada['Encabezado']['IdDoc']['TipoDTE'] ?? 0) !== 39) {
            // Boleta no lleva giro; en 33/56/61 validarReceptor() ya lo exige.
            $campos[] = 'GiroRecep';
        }
        foreach ($campos as $campo) {
            if (empty($receptorEntrada[$campo])) {
                $normalizado['Encabezado']['Receptor'][$campo] = false;
            }
        }

        // La referencia "Email receptor: ..." la agrega la librería a partir de
        // CorreoRecep; si el payload no traía correo, es el correo falso.
        if (empty($receptorEntrada['CorreoRecep']) && !empty($normalizado['Referencia'])) {
            $refs = isset($normalizado['Referencia'][0]) ? $normalizado['Referencia'] : [$normalizado['Referencia']];
            $refs = array_values(array_filter(
                $refs,
                static fn (array $r): bool => empty($r['TpoDocRef']) && empty($r['CodRef'])
                    ? !str_starts_with((string) ($r['RazonRef'] ?? ''), 'Email receptor:')
                    : true,
            ));
            foreach ($refs as $i => &$r) {
                $r['NroLinRef'] = $i + 1;
            }
            unset($r);
            $normalizado['Referencia'] = $refs !== [] ? $refs : false;
        }

        return $normalizado;
    }

    /**
     * Documento ya timbrado con esa clave (consulta de recuperación). No
     * timbra ni envía nada.
     *
     * @return array<string, mixed>|null
     */
    public function buscarPorClave(string $clave): ?array
    {
        $fila = $this->folios->buscarEmision($clave);
        return $fila !== null && $fila['estado'] === 'timbrado' ? self::respuestaDesdeLibro($fila) : null;
    }

    /** @param array<string, mixed> $fila */
    private static function respuestaDesdeLibro(array $fila): array
    {
        return [
            'tipoDte' => (int) $fila['tipo_dte'],
            'folio' => (int) $fila['folio'],
            'totales' => json_decode((string) $fila['totales'], true) ?? [],
            'xml' => $fila['xml'],
            'pdf' => $fila['pdf'],
            'trackId' => $fila['track_id'] !== null ? (int) $fila['track_id'] : null,
            'envioError' => $fila['envio_error'],
            'reutilizada' => true,
        ];
    }

    /**
     * Datos que la plantilla PDF necesita y la librería no entrega:
     * - resolución bajo el timbre (exigida por el SII; el emisor del bag se
     *   arma desde el XML, sin autorización);
     * - copia cedible, cuenta bancaria, monto en palabras, logo y URL de
     *   consulta de boletas, vía libredteData.extra →
     *   `document_extra` en la plantilla (las opciones del bag se validan y
     *   no admiten claves nuevas).
     */
    public static function prepararRender(object $bag, Config $config, bool $cedible = false): void
    {
        $bag->getEmisor()?->setAutorizacionDte(
            new AutorizacionDte($config->resolucionFecha, $config->resolucionNumero)
        );
        $total = $bag->getNormalizedData()['Encabezado']['Totales']['MntTotal'] ?? null;
        $bag->setLibredteData(array_replace_recursive($bag->getLibredteData() ?? [], [
            'extra' => [
                'cedible' => $cedible,
                'cuenta_bancaria' => $config->cuentaBancaria,
                'monto_palabras' => is_numeric($total) ? NumeroEnPalabras::pesos((int) $total) : null,
                'logo' => $config->pdfLogo !== '' ? $config->pdfLogo : null,
                'url_consulta_boleta' => $config->urlConsultaBoleta !== '' ? $config->urlConsultaBoleta : null,
            ],
        ]));
    }

    /**
     * IDK que el SII asigna a los CAF de certificación/pruebas (servidor
     * Maullín). Ver SiiEnvironment::CAF_IDKS en libredte-lib-core: 100 =
     * STAGING, 300 = PRODUCTION.
     */
    private const IDK_CERTIFICACION = 100;

    /**
     * Guarda contra mezclar CAF entre ambientes o con el emisor/tipo
     * equivocado, antes de reservar folio con él:
     *
     * - El RUT del CAF (DA/RE) debe ser el del emisor configurado, y su tipo
     *   de documento (DA/TD) el que se está pidiendo, en cualquier ambiente.
     * - En producción, un CAF de certificación (IDK=100) queda prohibido: es
     *   el escenario que motivó esta guarda (quemar o timbrar con folios de
     *   prueba en un documento real).
     * - En certificación, un CAF que NO sea de certificación (IDK≠100, p. ej.
     *   uno real de producción) también se rechaza: evita timbrar un folio
     *   real durante pruebas.
     */
    private function validarCaf(CafInterface $caf, int $tipoDteEsperado, string $cafFile): void
    {
        $tipoCaf = $caf->getTipoDocumento();
        if ($tipoCaf !== $tipoDteEsperado) {
            throw new \RuntimeException(
                "El CAF {$cafFile} es de tipo DTE {$tipoCaf}, se esperaba {$tipoDteEsperado}."
            );
        }

        $rutCaf = strtoupper(str_replace('.', '', $caf->getEmisor()['rut']));
        $rutEmisor = strtoupper(str_replace('.', '', $this->config->emisorRut));
        if ($rutCaf !== $rutEmisor) {
            throw new \RuntimeException(
                "El CAF {$cafFile} fue emitido para el RUT {$rutCaf}, no para el emisor configurado ({$rutEmisor})."
            );
        }

        $idk = $caf->getIdk();
        if ($this->config->ambiente === 'produccion' && $idk === self::IDK_CERTIFICACION) {
            throw new \RuntimeException(
                "El CAF {$cafFile} es de certificación (IDK=100): no se puede usar en producción."
            );
        }
        if ($this->config->ambiente !== 'produccion' && $idk !== self::IDK_CERTIFICACION) {
            throw new \RuntimeException(
                "El CAF {$cafFile} no es de certificación (IDK={$idk}): no se puede usar en certificación."
            );
        }
    }

    /**
     * CAF vigentes del tipo: {tipo}.xml y, si el SII entregó más de un rango,
     * {tipo}-*.xml (p. ej. 61-16.xml). FolioRepository salta de uno a otro.
     *
     * @return string[]
     */
    private function cafFilesPara(int $tipoDte): array
    {
        $dir = rtrim($this->config->cafDir, '/');
        $files = array_merge(
            is_file("{$dir}/{$tipoDte}.xml") ? ["{$dir}/{$tipoDte}.xml"] : [],
            glob("{$dir}/{$tipoDte}-*.xml") ?: []
        );
        if ($files === []) {
            throw new \RuntimeException(
                "No hay CAF cargado para el tipo DTE {$tipoDte}. Coloca el archivo en {$dir}/{$tipoDte}.xml."
            );
        }
        return $files;
    }
}
