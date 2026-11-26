<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Parser puro del HTML del portal de timbraje del SII (cvc_cgi/dte/of_*).
 *
 * Funciones estáticas, sin red y sin estado. El portal responde en ISO-8859-1
 * con HTML sucio (etiquetas sin cerrar, atributos sin comillas, mayúsculas
 * mezcladas), por eso se usa un analizador con expresiones regulares en lugar
 * de DOMDocument: no depende de cómo libxml reacomode tablas y formularios mal
 * anidados, y no agrega extensiones ni dependencias.
 *
 * Forma de un formulario devuelto:
 *   [
 *     'nombre'   => 'form1',
 *     'action'   => '/cvc_cgi/dte/of_confirma_folio',
 *     'method'   => 'POST',
 *     'campos'   => ['RUT_EMP' => '12345678', 'COD_DOCTO' => '33', ...],
 *     'opciones' => ['COD_DOCTO' => ['-1' => 'NINGUNO', '33' => 'FACTURA ...']],
 *   ]
 *
 * Los campos van en orden de aparición. Si un nombre se repite (el SII repite
 * CON_AJUSTE en of_genera_folio, con el mismo valor) gana el último.
 */
final class FormularioSii
{
    /** Atributos de una etiqueta: respeta comillas y tolera '>' dentro de ellas. */
    private const ATRIBUTOS = '((?:"[^"]*"|\'[^\']*\'|[^>])*+)';

    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Decodificación y texto
    // ------------------------------------------------------------------

    /**
     * Devuelve el HTML en UTF-8. El portal envía ISO-8859-1; si el texto ya es
     * UTF-8 válido (con caracteres no ASCII) se deja tal cual.
     */
    public static function decodificar(string $html): string
    {
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }
        if (preg_match('//u', $html) === 1) {
            return $html; // ASCII puro o UTF-8 válido
        }
        return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }

    /** Texto visible: sin tags, scripts, estilos ni comentarios; espacios colapsados. */
    public static function textoLimpio(string $html): string
    {
        $t = self::sinScriptsNiComentarios(self::decodificar($html));
        $t = preg_replace('/<[^>]*>?/s', ' ', $t) ?? $t;
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    // ------------------------------------------------------------------
    // Formularios
    // ------------------------------------------------------------------

    /**
     * Todos los <form> de la página, con sus campos.
     *
     * - input text/hidden/password/number/etc.: valor tal cual ('' si no tiene).
     * - input submit/button/reset/image/file: se ignoran (no los envía el SII
     *   en sus POST).
     * - checkbox/radio: solo si vienen marcados (checked).
     * - select: la opción con `selected` (si hay varias, la última, como hace
     *   el navegador; el portal marca NINGUNO y el tipo a la vez) o la primera.
     *
     * @return list<array{nombre:string,action:string,method:string,campos:array<string,string>,opciones:array<string,array<string,string>>}>
     */
    public static function extraerFormularios(string $html): array
    {
        $h = self::sinScriptsNiComentarios(self::decodificar($html));

        $forms = [];
        $n = preg_match_all('/<form\b' . self::ATRIBUTOS . '>?/i', $h, $m, PREG_OFFSET_CAPTURE);
        if ($n === false || $n === 0) {
            return [];
        }
        foreach ($m[0] as $i => [$tagCompleto, $pos]) {
            $inicio = $pos + strlen($tagCompleto);
            $cuerpo = substr($h, $inicio);
            // El cuerpo termina en </form>, en el siguiente <form o al final.
            if (preg_match('/<\/form\s*>|<form\b/i', $cuerpo, $fin, PREG_OFFSET_CAPTURE) === 1) {
                $cuerpo = substr($cuerpo, 0, $fin[0][1]);
            }
            $attrs = self::atributos($m[1][$i][0]);
            [$campos, $opciones] = self::camposDe($cuerpo);
            $forms[] = [
                'nombre' => $attrs['name'] ?? '',
                'action' => $attrs['action'] ?? '',
                'method' => strtoupper($attrs['method'] ?? 'GET') ?: 'GET',
                'campos' => $campos,
                'opciones' => $opciones,
            ];
        }
        return $forms;
    }

    /**
     * Primer formulario cuyo action contiene $fragmentoAccion
     * (p. ej. 'of_confirma_folio'), o null.
     */
    public static function formularioPorAccion(string $html, string $fragmentoAccion): ?array
    {
        if ($fragmentoAccion === '') {
            return null;
        }
        foreach (self::extraerFormularios($html) as $form) {
            if (stripos($form['action'], $fragmentoAccion) !== false) {
                return $form;
            }
        }
        return null;
    }

    /** Valor de un campo del formulario; null si el formulario o el campo no existen. */
    public static function valorCampo(?array $form, string $nombre): ?string
    {
        if ($form === null || !isset($form['campos']) || !array_key_exists($nombre, $form['campos'])) {
            return null;
        }
        return $form['campos'][$nombre];
    }

    /**
     * true si DIA/MES/ANO/HORA/MINUTO existen y son numéricos no vacíos.
     * Una fecha vacía puede hacer que el SII firme un CAF con <FA>AAAA-MM-</FA>.
     */
    public static function fechaCompleta(?array $form): bool
    {
        foreach (['DIA', 'MES', 'ANO', 'HORA', 'MINUTO'] as $c) {
            $v = self::valorCampo($form, $c);
            if ($v === null || !ctype_digit(trim($v))) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Detección de errores
    // ------------------------------------------------------------------

    /**
     * Reconoce páginas de error del portal. Devuelve null si la página no es
     * un error conocido y (cuando se indica $accionEsperada) trae el
     * formulario esperado.
     *
     * Códigos: MAX_SESIONES, MAX_AUTORIZADO, SIN_VERIFICACION_ACTIVIDADES,
     * DTE_OFGA (incluye 'codigo_sii'), SII_ERROR (falla genérica del portal,
     * incluye 'codigo_sii' si trae un código tipo ST-SEG-DTE-21-3),
     * NO_AUTENTICADO, DESCONOCIDO (también intenta 'codigo_sii').
     *
     * Textos observados en el portal del SII:
     * "ha superado el máximo de sesiones autenticadas" (01.01.215.500.720.27),
     * "debe ser menor o igual al máximo autorizado", "no registra
     * Verificación de Actividades", "no se encuentra autenticado con
     * certificado digital", rebote a homer.sii.cl y DTE-OFGA-S39-5-5. El
     * portal también puede responder "No ha sido posible completar su
     * solicitud. Inténtelo más tarde... código ST-SEG-DTE-21-3"
     * (SII_ERROR). No hay captura del HTML exacto de esas páginas, solo del
     * texto: la detección es por texto, no por estructura.
     *
     * @param string|null $accionEsperada Fragmento de action que debería traer la página.
     * @param string|null $urlEfectiva    URL final tras redirecciones (si el transporte la conoce).
     * @return array{codigo:string,detalle:string,codigo_sii?:string}|null
     */
    public static function detectarError(string $html, ?string $accionEsperada = null, ?string $urlEfectiva = null): ?array
    {
        $texto = self::textoLimpio($html);

        if (preg_match('/m[aá]ximo de sesiones|sesi[oó]n activa|01\.01\.215\.500\.720\.27/iu', $texto, $mm) === 1) {
            return ['codigo' => 'MAX_SESIONES', 'detalle' => self::fragmento($texto, $mm[0])];
        }
        if (preg_match('/debe ser menor o igual al m[aá]ximo autorizado/iu', $texto, $mm) === 1) {
            return ['codigo' => 'MAX_AUTORIZADO', 'detalle' => self::fragmento($texto, $mm[0])];
        }
        if (preg_match('/no registra Verificaci[oó]n de Actividades/iu', $texto, $mm) === 1) {
            return ['codigo' => 'SIN_VERIFICACION_ACTIVIDADES', 'detalle' => self::fragmento($texto, $mm[0])];
        }
        if (preg_match('/DTE-OFGA-[A-Z0-9]+(?:-[A-Z0-9]+)*/', $texto, $mm) === 1) {
            return [
                'codigo' => 'DTE_OFGA',
                'detalle' => self::fragmento($texto, $mm[0]),
                'codigo_sii' => $mm[0],
            ];
        }
        // Falla genérica del portal ("No ha sido posible completar su solicitud.
        // Inténtelo más tarde..."): el detalle es desde esa frase hasta el final
        // (así se deja fuera el encabezado repetitivo de la página) y se intenta
        // capturar un código de la Mesa de Ayuda (p. ej. ST-SEG-DTE-21-3).
        if (preg_match('/No ha sido posible completar su solicitud|Int[eé]ntelo m[aá]s tarde/iu', $texto, $mm, PREG_OFFSET_CAPTURE) === 1) {
            $detalle = trim(substr($texto, $mm[0][1]));
            $r = ['codigo' => 'SII_ERROR', 'detalle' => $detalle];
            $cod = self::capturarCodigoSii($detalle);
            if ($cod !== null) {
                $r['codigo_sii'] = $cod;
            }
            return $r;
        }

        // No autenticado: texto del portal, formulario de login o rebote a homer.
        $noAuth = preg_match('/no se encuentra autenticado|vuelva a autenticarse con certificado/iu', $texto, $mm) === 1;
        $fragmento = $noAuth ? self::fragmento($texto, $mm[0]) : '';
        if (!$noAuth && $urlEfectiva !== null && stripos($urlEfectiva, 'homer.sii.cl') !== false) {
            $noAuth = true;
            $fragmento = 'Redirigido a ' . $urlEfectiva;
        }
        $h = self::sinScriptsNiComentarios(self::decodificar($html));
        if (!$noAuth && preg_match('/<meta\b[^>]*http-equiv\s*=\s*["\']?refresh[^>]*homer\.sii\.cl/i', $h) === 1) {
            $noAuth = true;
            $fragmento = 'Redirección (meta refresh) a homer.sii.cl';
        }
        if (!$noAuth && preg_match('/<form\b[^>]*action\s*=\s*["\']?[^"\'>\s]*CAutInicio/i', $h) === 1) {
            $noAuth = true;
            $fragmento = 'Formulario de autenticación (CAutInicio)';
        }
        if (!$noAuth && preg_match('/(?:location(?:\.href)?\s*=|location\.replace\()\s*["\'][^"\']*homer\.sii\.cl/i', self::decodificar($html)) === 1) {
            $noAuth = true;
            $fragmento = 'Redirección (JavaScript) a homer.sii.cl';
        }
        if ($noAuth) {
            return ['codigo' => 'NO_AUTENTICADO', 'detalle' => $fragmento];
        }

        // Sin error reconocido: es error solo si falta el formulario esperado.
        $faltaForm = $accionEsperada !== null
            ? self::formularioPorAccion($html, $accionEsperada) === null
            : self::extraerFormularios($html) === [];
        if ($faltaForm) {
            // Se quita el encabezado repetitivo (título + mismo título en
            // mayúsculas) ANTES de cortar a 500: así no se pierde contenido
            // útil por el relleno de cabecera de la página.
            $limpio = self::quitarEncabezadoRepetido($html, $texto);
            $r = ['codigo' => 'DESCONOCIDO', 'detalle' => mb_substr($limpio, 0, 500)];
            $cod = self::capturarCodigoSii($limpio);
            if ($cod !== null) {
                $r['codigo_sii'] = $cod;
            }
            return $r;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private static function sinScriptsNiComentarios(string $h): string
    {
        $h = preg_replace('/<script\b.*?(?:<\/script\s*>|\z)/is', ' ', $h) ?? $h;
        $h = preg_replace('/<style\b.*?(?:<\/style\s*>|\z)/is', ' ', $h) ?? $h;
        return preg_replace('/<!--.*?(?:-->|\z)/s', ' ', $h) ?? $h;
    }

    /** Código tipo ST-SEG-DTE-21-3 (Mesa de Ayuda SII): 2+ letras y 2+ segmentos más, separados por guion. */
    private static function capturarCodigoSii(string $texto): ?string
    {
        return preg_match('/\b[A-Z]{2,}(?:-[A-Z0-9]+){2,}\b/', $texto, $m) === 1 ? $m[0] : null;
    }

    /** Texto del <title> de la página, decodificado y con espacios colapsados; null si no hay. */
    private static function tituloPagina(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) !== 1) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return $t === '' ? null : $t;
    }

    /**
     * El portal repite el título de cada página dos veces: el del <title> y,
     * justo debajo, el mismo texto en mayúsculas y sin acentos (el que se ve
     * en pantalla). Se quita ese encabezado repetitivo cuando está al
     * comienzo del texto, para no duplicar ruido en el detalle de un error.
     */
    private static function quitarEncabezadoRepetido(string $html, string $texto): string
    {
        $titulo = self::tituloPagina(self::decodificar($html));
        if ($titulo === null) {
            return $texto;
        }
        $mayus = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($titulo, 'UTF-8'));
        $mayus = is_string($mayus) && $mayus !== '' ? $mayus : mb_strtoupper($titulo, 'UTF-8');
        $prefijo = $titulo . ' ' . $mayus;
        if (stripos($texto, $prefijo) === 0) {
            return trim(substr($texto, strlen($prefijo)));
        }
        return $texto;
    }

    /** Hasta ~120 caracteres antes y ~250 después de la coincidencia. */
    private static function fragmento(string $texto, string $coincidencia): string
    {
        $pos = mb_stripos($texto, $coincidencia);
        if ($pos === false) {
            return mb_substr($texto, 0, 400);
        }
        $desde = max(0, $pos - 120);
        return trim(mb_substr($texto, $desde, ($pos - $desde) + mb_strlen($coincidencia) + 250));
    }

    /**
     * Atributos de una etiqueta (nombres en minúscula, valores decodificados).
     * Acepta comillas dobles, simples o ausentes, "a = b" con espacios y
     * atributos sin valor (selected, checked, readonly → '').
     *
     * @return array<string,string>
     */
    private static function atributos(string $cadena): array
    {
        $res = [];
        $re = '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/';
        if (preg_match_all($re, $cadena, $m, PREG_SET_ORDER) === false) {
            return $res;
        }
        foreach ($m as $a) {
            $nombre = strtolower($a[1]);
            $valor = ($a[2] ?? '') !== '' ? $a[2] : (($a[3] ?? '') !== '' ? $a[3] : ($a[4] ?? ''));
            if (!array_key_exists($nombre, $res)) { // el primero gana, como en HTML
                $res[$nombre] = html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $res;
    }

    /**
     * Campos y opciones de un cuerpo de formulario, en orden de aparición.
     *
     * @return array{0:array<string,string>,1:array<string,array<string,string>>}
     */
    private static function camposDe(string $cuerpo): array
    {
        $items = []; // [posición => [nombre, valor]]
        $opciones = [];

        // Selects primero; se reemplazan por un marcador para no leer sus <option> como inputs.
        $reSelect = '/<select\b' . self::ATRIBUTOS . '>?(.*?)(?:<\/select\s*>|(?=<select\b|<form\b|<\/form\b)|\z)/is';
        $sinSelects = preg_replace_callback(
            $reSelect,
            function (array $m) use (&$items, &$opciones): string {
                $attrs = self::atributos($m[1]);
                $nombre = $attrs['name'] ?? '';
                if ($nombre === '') {
                    return ' ';
                }
                [$valor, $lista] = self::resolverSelect($m[2]);
                $opciones[$nombre] = $lista;
                $items[] = [$nombre, $valor];
                return "\x00" . (count($items) - 1) . "\x00";
            },
            $cuerpo
        ) ?? $cuerpo;

        // Inputs y marcadores de select, en orden de aparición.
        $campos = [];
        $re = '/<input\b' . self::ATRIBUTOS . '>?|\x00(\d+)\x00/i';
        if (preg_match_all($re, $sinSelects, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $x) {
                if (isset($x[2]) && $x[2] !== '') {
                    [$nombre, $valor] = $items[(int) $x[2]];
                    $campos[$nombre] = $valor;
                    continue;
                }
                $a = self::atributos($x[1]);
                $nombre = $a['name'] ?? '';
                if ($nombre === '') {
                    continue;
                }
                $tipo = strtolower($a['type'] ?? 'text');
                if (in_array($tipo, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                    continue;
                }
                if (in_array($tipo, ['checkbox', 'radio'], true) && !array_key_exists('checked', $a)) {
                    continue;
                }
                $campos[$nombre] = $a['value'] ?? '';
            }
        }
        return [$campos, $opciones];
    }

    /**
     * Valor seleccionado y lista valor => texto de un <select>.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function resolverSelect(string $cuerpo): array
    {
        $lista = [];
        $primera = null;
        $seleccionada = null;
        $re = '/<option\b' . self::ATRIBUTOS . '>?([^<]*)/i';
        if (preg_match_all($re, $cuerpo, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $o) {
                $a = self::atributos($o[1]);
                $texto = trim(preg_replace(
                    '/\s+/u',
                    ' ',
                    str_replace("\u{00A0}", ' ', html_entity_decode($o[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                ) ?? '');
                $valor = array_key_exists('value', $a) ? $a['value'] : $texto;
                $lista[$valor] = $texto;
                $primera ??= $valor;
                if (array_key_exists('selected', $a)) {
                    $seleccionada = $valor; // varias selected: gana la última
                }
            }
        }
        return [$seleccionada ?? $primera ?? '', $lista];
    }
}
