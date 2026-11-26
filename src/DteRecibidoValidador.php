<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\AsymmetricKeyHelper;
use Derafu\L10n\Cl\Rut\Rut;
use Derafu\Signature\Service\SignatureGenerator;
use Derafu\Signature\Service\SignatureValidator;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Derafu\Xml\Service\XmlService;
use Derafu\Xml\Service\XmlValidator;
use Derafu\Xml\XmlDocument;

/**
 * Validación de SOLO LECTURA de un DTE RECIBIDO de un proveedor. No toca
 * folios, CAF propios, certificado propio, emisión
 * ni el SII: solo mira el XML que llegó por correo.
 *
 * Qué verifica:
 *  1. Firma XMLDSig de cada <DTE> (referencia al <Documento ID=...>) y del
 *     sobre <EnvioDTE> (referencia al <SetDTE ID=...>), con el validador de
 *     derafu/signature que trae libredte-lib-core
 *     (SignatureValidator::validateXml: DigestValue + SignatureValue con el
 *     X509 embebido). OJO: esto prueba INTEGRIDAD (nadie alteró el XML
 *     después de firmarlo), no que el certificado pertenezca al proveedor:
 *     no hay cadena de confianza de certificados de firma en la librería.
 *  2. TED (timbre electrónico) de cada documento:
 *     - FRMA del CAF incluido en el TED, con la clave pública del SII según
 *       IDK (resources/certificates/{IDK}.cer de libredte-lib-core, mismo
 *       algoritmo que CafValidatorWorker::validate): prueba que el CAF lo
 *       autorizó el SII para ese emisor/tipo/rango.
 *     - FRMT del TED sobre <DD>, con la clave pública RSAPK (M/E) del CAF,
 *       canonicalizado igual que AbstractBuilderStrategy al timbrar
 *       (ISO-8859-1, C14N aplanado de /TED/DD, SHA1).
 *     - Coherencia DD ↔ Encabezado (RE, TD, F, RR, MNT) y CAF ↔ DD (RE, TD,
 *       folio dentro del rango).
 *  3. RUT receptor de cada documento contra el nuestro (Config::emisorRut).
 *
 * Nunca lanza por un XML "malo": todo problema queda en `errores` para que la
 * app lo muestre y bloquee ACEPTAR. Solo un XML ilegible devuelve
 * firmaValida=false con el error correspondiente.
 */
final class DteRecibidoValidador
{
    /** IDK de los CAF de certificación (Maullín). Mismo valor que DteEmisor::IDK_CERTIFICACION. */
    private const IDK_CERTIFICACION = 100;

    /**
     * @param string $rutPropio RUT-DV de nuestra empresa (receptor esperado).
     * @param string $ambiente "produccion" o "certificacion".
     * @param bool $aceptarCafSinVerificarSii SOLO pruebas: acepta CAF cuyo
     *   IDK no tiene certificado del SII (p. ej. CafFaker::IDK=666).
     */
    public function __construct(
        private readonly string $rutPropio,
        private readonly string $ambiente,
        private readonly bool $aceptarCafSinVerificarSii = false,
    ) {
    }

    /**
     * @return array{
     *   firmaValida: bool,
     *   firmaSobreValida: ?bool,
     *   tedValido: bool,
     *   rutEmisor: ?string,
     *   rutReceptor: ?string,
     *   receptorEsPropio: bool,
     *   documentos: list<array{tipoDte:int, folio:int, rutEmisor:?string, rutReceptor:?string,
     *     firmaValida:bool, tedValido:bool, receptorEsPropio:bool, errores:list<string>}>,
     *   errores: list<string>
     * }
     */
    public function validar(string $xml): array
    {
        $resultado = [
            'firmaValida' => false,
            'firmaSobreValida' => null,
            'tedValido' => false,
            'rutEmisor' => null,
            'rutReceptor' => null,
            'receptorEsPropio' => false,
            'documentos' => [],
            'errores' => [],
        ];

        $dom = new \DOMDocument();
        $previo = libxml_use_internal_errors(true);
        // LIBXML_NONET: nunca resolver nada por red (XML de un tercero).
        $cargado = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$cargado || $dom->documentElement === null) {
            $resultado['errores'][] = 'XML ilegible o mal formado.';
            return $resultado;
        }

        $raiz = $dom->documentElement->localName;
        if ($raiz !== 'EnvioDTE' && $raiz !== 'DTE') {
            $resultado['errores'][] = sprintf('Raíz "%s" no es EnvioDTE ni DTE.', $raiz);
            return $resultado;
        }

        // ─── Carátula del sobre ───────────────────────────────────────────
        if ($raiz === 'EnvioDTE') {
            $resultado['rutEmisor'] = $this->rutNormalizado($this->texto($dom, 'Caratula', 'RutEmisor'));
            $resultado['rutReceptor'] = $this->rutNormalizado($this->texto($dom, 'Caratula', 'RutReceptor'));
        }

        // ─── 1. Firmas XMLDSig (todas: DTE y sobre) ───────────────────────
        $firmasPorPadre = $this->validarFirmas($xml, $dom, $resultado['errores']);

        // ─── 2. Documentos ────────────────────────────────────────────────
        $todosFirmados = true;
        $todosTed = true;
        $todosNuestros = true;
        $documentos = $dom->getElementsByTagName('Documento');
        if ($documentos->length === 0) {
            $resultado['errores'][] = 'El XML no trae ningún <Documento>.';
            $todosFirmados = $todosTed = $todosNuestros = false;
        }

        foreach ($documentos as $documento) {
            /** @var \DOMElement $documento */
            $erroresDoc = [];
            $tipo = (int) $this->hijo($documento, ['Encabezado', 'IdDoc', 'TipoDTE']);
            $folio = (int) $this->hijo($documento, ['Encabezado', 'IdDoc', 'Folio']);
            $rutEmisorDoc = $this->rutNormalizado($this->hijo($documento, ['Encabezado', 'Emisor', 'RUTEmisor']));
            $rutReceptorDoc = $this->rutNormalizado($this->hijo($documento, ['Encabezado', 'Receptor', 'RUTRecep']));

            // Firma del DTE: la Signature hermana de <Documento>, dentro de <DTE>.
            $dteNodo = $documento->parentNode;
            $firmaDoc = $dteNodo instanceof \DOMElement ? ($firmasPorPadre[(string) $dteNodo->getNodePath()] ?? null) : null;
            if ($firmaDoc === null) {
                $erroresDoc[] = 'El DTE no trae firma (Signature).';
                $firmaOk = false;
            } else {
                $firmaOk = $firmaDoc['valida'];
                if (!$firmaOk) {
                    $erroresDoc[] = 'Firma del DTE inválida: ' . $firmaDoc['error'];
                }
            }

            $tedOk = $this->validarTed($documento, $erroresDoc);

            $receptorOk = $rutReceptorDoc !== null && $rutReceptorDoc === $this->rutNormalizado($this->rutPropio);
            if (!$receptorOk) {
                $erroresDoc[] = sprintf(
                    'El receptor del documento (%s) no es nuestra empresa (%s).',
                    $rutReceptorDoc ?? 'sin RUTRecep',
                    $this->rutPropio
                );
            }

            $todosFirmados = $todosFirmados && $firmaOk;
            $todosTed = $todosTed && $tedOk;
            $todosNuestros = $todosNuestros && $receptorOk;

            $resultado['documentos'][] = [
                'tipoDte' => $tipo,
                'folio' => $folio,
                'rutEmisor' => $rutEmisorDoc,
                'rutReceptor' => $rutReceptorDoc,
                'firmaValida' => $firmaOk,
                'tedValido' => $tedOk,
                'receptorEsPropio' => $receptorOk,
                'errores' => $erroresDoc,
            ];
        }

        // Firma del sobre (Signature hija directa de <EnvioDTE>).
        if ($raiz === 'EnvioDTE') {
            $firmaSobre = $firmasPorPadre[(string) $dom->documentElement->getNodePath()] ?? null;
            if ($firmaSobre === null) {
                $resultado['firmaSobreValida'] = false;
                $resultado['errores'][] = 'El sobre EnvioDTE no trae firma (Signature).';
            } else {
                $resultado['firmaSobreValida'] = $firmaSobre['valida'];
                if (!$firmaSobre['valida']) {
                    $resultado['errores'][] = 'Firma del sobre inválida: ' . $firmaSobre['error'];
                }
            }
        }

        if ($raiz === 'DTE' && $resultado['documentos'] !== []) {
            $resultado['rutEmisor'] = $resultado['documentos'][0]['rutEmisor'];
            $resultado['rutReceptor'] = $resultado['documentos'][0]['rutReceptor'];
        }

        $resultado['firmaValida'] = $todosFirmados && ($resultado['firmaSobreValida'] ?? true);
        $resultado['tedValido'] = $todosTed;
        $resultado['receptorEsPropio'] = $todosNuestros;

        return $resultado;
    }

    /**
     * Valida todas las <Signature> del XML con derafu/signature y las indexa
     * por la ruta XPath del nodo padre (getNodePath(), estable; no se usa
     * spl_object_id porque los wrappers DOM de PHP pueden recrearse), para
     * saber cuál es la del sobre y cuál la de cada DTE.
     *
     * @param list<string> $errores
     * @return array<string, array{valida: bool, error: ?string}>
     */
    private function validarFirmas(string $xml, \DOMDocument $dom, array &$errores): array
    {
        $nodos = $dom->getElementsByTagName('Signature');
        if ($nodos->length === 0) {
            return [];
        }

        $xmlService = new XmlService(new XmlEncoder(), new XmlDecoder(), new XmlValidator());
        $validador = new SignatureValidator(new SignatureGenerator($xmlService), $xmlService);

        try {
            $doc = new XmlDocument();
            $doc->loadXml($xml, LIBXML_NONET);
            // Mismo recorrido (getElementsByTagName en orden de documento) que
            // hace SignatureValidator::validateXml: los índices coinciden.
            $resultados = $validador->validateXml($doc);
        } catch (\Throwable $e) {
            $errores[] = 'No se pudieron validar las firmas: ' . $e->getMessage();
            return [];
        }

        $porPadre = [];
        foreach ($nodos as $i => $nodo) {
            $padre = $nodo->parentNode;
            $r = $resultados[$i] ?? null;
            if (!$padre instanceof \DOMElement || $r === null) {
                continue;
            }
            $porPadre[(string) $padre->getNodePath()] = [
                'valida' => $r->isValid(),
                'error' => $r->getError()?->getMessage(),
            ];
        }

        return $porPadre;
    }

    /**
     * Verifica el TED de un <Documento>. Agrega a $errores lo que falle.
     *
     * @param list<string> $errores
     */
    private function validarTed(\DOMElement $documento, array &$errores): bool
    {
        $ted = $this->primerHijo($documento, 'TED');
        if ($ted === null) {
            $errores[] = 'El documento no trae TED (timbre electrónico).';
            return false;
        }

        $ok = true;
        $dd = $this->primerHijo($ted, 'DD');
        $frmt = trim((string) $this->primerHijo($ted, 'FRMT')?->textContent);
        $caf = $dd ? $this->primerHijo($dd, 'CAF') : null;
        $da = $caf ? $this->primerHijo($caf, 'DA') : null;
        if ($dd === null || $frmt === '' || $caf === null || $da === null) {
            $errores[] = 'TED incompleto (falta DD, FRMT o CAF/DA).';
            return false;
        }

        // ─── Coherencia DD ↔ Encabezado ──────────────────────────────────
        $esperado = [
            'RE' => $this->rutNormalizado($this->hijo($documento, ['Encabezado', 'Emisor', 'RUTEmisor'])),
            'TD' => (string) (int) $this->hijo($documento, ['Encabezado', 'IdDoc', 'TipoDTE']),
            'F' => (string) (int) $this->hijo($documento, ['Encabezado', 'IdDoc', 'Folio']),
            'RR' => $this->rutNormalizado($this->hijo($documento, ['Encabezado', 'Receptor', 'RUTRecep'])),
            'MNT' => (string) (int) $this->hijo($documento, ['Encabezado', 'Totales', 'MntTotal']),
        ];
        foreach ($esperado as $campo => $valor) {
            $enTed = trim((string) $this->primerHijo($dd, $campo)?->textContent);
            $enTed = in_array($campo, ['RE', 'RR'], true) ? $this->rutNormalizado($enTed) : (string) (int) $enTed;
            if ($enTed !== $valor) {
                $errores[] = sprintf('TED: %s del timbre (%s) no coincide con el documento (%s).', $campo, $enTed ?? '—', $valor ?? '—');
                $ok = false;
            }
        }

        // ─── Coherencia CAF ↔ DD ─────────────────────────────────────────
        $cafRe = $this->rutNormalizado(trim((string) $this->primerHijo($da, 'RE')?->textContent));
        $cafTd = (int) trim((string) $this->primerHijo($da, 'TD')?->textContent);
        $rng = $this->primerHijo($da, 'RNG');
        $desde = (int) trim((string) ($rng ? $this->primerHijo($rng, 'D')?->textContent : ''));
        $hasta = (int) trim((string) ($rng ? $this->primerHijo($rng, 'H')?->textContent : ''));
        $folio = (int) $esperado['F'];
        if ($cafRe !== $esperado['RE'] || $cafTd !== (int) $esperado['TD']) {
            $errores[] = 'TED: el CAF del timbre es de otro emisor o de otro tipo de documento.';
            $ok = false;
        }
        if ($folio < $desde || $folio > $hasta) {
            $errores[] = sprintf('TED: el folio %d está fuera del rango autorizado por el CAF (%d-%d).', $folio, $desde, $hasta);
            $ok = false;
        }

        // ─── FRMA del CAF con la clave pública del SII (por IDK) ─────────
        $idk = (int) trim((string) $this->primerHijo($da, 'IDK')?->textContent);
        if ($this->ambiente === 'produccion' && $idk === self::IDK_CERTIFICACION) {
            $errores[] = 'TED: el CAF es de certificación (IDK=100), no válido en producción.';
            $ok = false;
        }
        $frma = trim((string) $this->primerHijo($caf, 'FRMA')?->textContent);
        $certSii = $this->certificadoSii($idk);
        if ($certSii === null) {
            if (!$this->aceptarCafSinVerificarSii) {
                $errores[] = sprintf('TED: no hay certificado del SII para el IDK %d; no se puede verificar el CAF.', $idk);
                $ok = false;
            }
        } else {
            $daCanonico = $this->canonicoAislado($da, 'DA');
            if ($daCanonico === null || openssl_verify($daCanonico, (string) base64_decode($frma, true), $certSii, OPENSSL_ALGO_SHA1) !== 1) {
                $errores[] = 'TED: la firma del SII sobre el CAF (FRMA) no es válida.';
                $ok = false;
            }
        }

        // ─── FRMT del timbre con la clave pública del CAF (RSAPK) ────────
        $rsapk = $this->primerHijo($da, 'RSAPK');
        $m = trim((string) ($rsapk ? $this->primerHijo($rsapk, 'M')?->textContent : ''));
        $e = trim((string) ($rsapk ? $this->primerHijo($rsapk, 'E')?->textContent : ''));
        $ddCanonico = $this->canonicoAislado($dd, 'DD');
        try {
            $clave = AsymmetricKeyHelper::generatePublicKeyFromModulusExponent($m, $e);
            $verificado = $ddCanonico !== null
                && openssl_verify($ddCanonico, (string) base64_decode($frmt, true), $clave, OPENSSL_ALGO_SHA1) === 1;
        } catch (\Throwable $ex) {
            $verificado = false;
        }
        if (!$verificado) {
            $errores[] = 'TED: el timbre (FRMT) no corresponde a los datos del documento (DD) con la clave del CAF.';
            $ok = false;
        }

        return $ok;
    }

    /**
     * Canonicaliza un nodo (DD o DA) igual que la librería al timbrar/al
     * validar el CAF: el nodo se aísla SIN el namespace heredado del DTE
     * (http://www.sii.cl/SiiDte, que no existía cuando se firmó), se carga en
     * ISO-8859-1 y se aplana con C14NEncodedFlattened.
     */
    private function canonicoAislado(\DOMElement $nodo, string $nombre): ?string
    {
        try {
            $aislado = new \DOMDocument('1.0', 'ISO-8859-1');
            $aislado->appendChild($this->copiarSinNamespace($aislado, $nodo));
            $doc = new XmlDocument();
            $doc->loadXml($aislado->saveXML());

            return $doc->setEncoding('ISO-8859-1')->C14NEncodedFlattened('/' . $nombre);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Copia profunda de un elemento descartando namespaces (los DD/DA del SII no usan atributos con prefijo). */
    private function copiarSinNamespace(\DOMDocument $destino, \DOMNode $origen): \DOMNode
    {
        if ($origen instanceof \DOMElement) {
            $copia = $destino->createElement($origen->localName);
            foreach ($origen->attributes as $atributo) {
                if ($atributo->prefix === 'xmlns' || $atributo->nodeName === 'xmlns') {
                    continue;
                }
                $copia->setAttribute($atributo->localName, $atributo->value);
            }
            foreach ($origen->childNodes as $hijo) {
                $copia->appendChild($this->copiarSinNamespace($destino, $hijo));
            }

            return $copia;
        }

        return $destino->createTextNode($origen->textContent ?? '');
    }

    private function certificadoSii(int $idk): ?string
    {
        if ($idk <= 0) {
            return null;
        }
        $base = (new \ReflectionClass(\libredte\lib\Core\Application::class))->getFileName();
        // .../libredte-lib-core/src/Application.php → .../libredte-lib-core/resources/certificates/{idk}.cer
        $ruta = dirname((string) $base, 2) . '/resources/certificates/' . $idk . '.cer';

        return is_file($ruta) ? (string) file_get_contents($ruta) : null;
    }

    private function primerHijo(\DOMElement $padre, string $nombre): ?\DOMElement
    {
        foreach ($padre->childNodes as $hijo) {
            if ($hijo instanceof \DOMElement && $hijo->localName === $nombre) {
                return $hijo;
            }
        }

        return null;
    }

    /** @param list<string> $ruta */
    private function hijo(\DOMElement $desde, array $ruta): string
    {
        $nodo = $desde;
        foreach ($ruta as $nombre) {
            $nodo = $this->primerHijo($nodo, $nombre);
            if ($nodo === null) {
                return '';
            }
        }

        return trim($nodo->textContent);
    }

    private function texto(\DOMDocument $dom, string $padre, string $hijo): string
    {
        $p = $dom->getElementsByTagName($padre)->item(0);

        return $p instanceof \DOMElement ? $this->hijo($p, [$hijo]) : '';
    }

    private function rutNormalizado(?string $rut): ?string
    {
        $rut = strtoupper(trim((string) $rut));
        if ($rut === '') {
            return null;
        }
        try {
            Rut::validate($rut);
        } catch (\Throwable) {
            return $rut; // se devuelve tal cual: no coincidirá con el esperado
        }
        [$numero, $dv] = Rut::toArray($rut);

        return $numero . '-' . $dv;
    }
}
