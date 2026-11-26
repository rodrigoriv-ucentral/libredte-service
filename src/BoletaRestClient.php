<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Signature\Service\SignatureGenerator;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Derafu\Xml\Service\XmlService;
use Derafu\Xml\Service\XmlValidator;

/**
 * Cliente de la API REST de Boleta Electrónica del SII.
 *
 * Las boletas (39/41) NO usan los servicios de factura (DTEUpload/SOAP en
 * Palena/Maullín): tienen servidores, token, envío y consultas propios.
 * Fuente: Instructivo Técnico Boleta Electrónica (SII, 28/10/2021) y la
 * especificación OpenAPI oficial "API SII 1.0.5":
 *   https://www4c.sii.cl/bolcoreinternetui/api/  (openapi.yaml)
 *
 *  - GET  {api}/boleta.electronica.semilla                 → XML con SEMILLA (dura 2 min)
 *  - POST {api}/boleta.electronica.token                   → body: getToken firmado; XML con TOKEN
 *  - POST {envio}/boleta.electronica.envio                 → multipart (rutSender, dvSender,
 *                                                            rutCompany, dvCompany, archivo); JSON
 *  - GET  {api}/boleta.electronica.envio/{rut}-{dv}-{trackid}             → estado del envío
 *  - GET  {api}/boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado?...   → estado de una boleta
 *
 * Autenticación: header `Cookie: TOKEN={token}`. El token vale 1 hora y se
 * renueva solo con cada uso; aquí se cachea en disco por menos tiempo y, ante
 * un 401, se descarta y se pide uno nuevo una sola vez.
 */
final class BoletaRestClient
{
    /** Servidores oficiales. `envio` es exclusivo del POST de envío. */
    public const SERVIDORES = [
        'certificacion' => [
            'api' => 'https://apicert.sii.cl/recursos/v1',
            'envio' => 'https://pangal.sii.cl/recursos/v1',
        ],
        'produccion' => [
            'api' => 'https://api.sii.cl/recursos/v1',
            'envio' => 'https://rahue.sii.cl/recursos/v1',
        ],
    ];

    /** El envío exige User-Agent (la especificación lo marca como requerido). */
    private const USER_AGENT = 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';

    /** El SII lo da por 1 h (renovable con el uso); se cachea menos por margen. */
    private const TOKEN_TTL = 45 * 60;

    /** Glosas oficiales del estado de un envío (schema ResultadoEnvioDataRespuesta). */
    public const GLOSAS_ENVIO = [
        'REC' => 'Envío recibido',
        'EPR' => 'Envío procesado',
        'CRT' => 'Carátula OK',
        'FOK' => 'Firma de envío validada',
        'PRD' => 'Envío en proceso',
        'SOK' => 'Schema validado',
        'RCH' => 'Rechazado por errores en información',
        'RCO' => 'Rechazado por consistencia',
        'VOF' => 'No se encontró el archivo .xml',
        'RFR' => 'Rechazado por error en firma',
        'RPR' => 'Aceptado con reparos',
        'RPT' => 'Envío repetido rechazado',
        'RSC' => 'Rechazado por schema',
        'RCT' => 'Rechazado por error en carátula',
    ];

    /** @var callable(string, string, array<int,string>, mixed): array{status:int, body:string} */
    private $http;

    private readonly string $api;
    private readonly string $envio;
    private readonly string $cacheFile;

    /**
     * @param string $ambiente 'certificacion' | 'produccion' (LIBREDTE_AMBIENTE).
     * @param callable|null $http Transporte HTTP inyectable (tests). Recibe
     *   (método, url, headers, body) y devuelve ['status' => int, 'body' => string].
     */
    public function __construct(
        private readonly string $ambiente,
        private readonly CertificateInterface $certificate,
        ?callable $http = null,
        ?string $cacheDir = null,
    ) {
        $servidores = self::SERVIDORES[$ambiente === 'produccion' ? 'produccion' : 'certificacion'];
        $this->api = $servidores['api'];
        $this->envio = $servidores['envio'];
        $this->http = $http ?? self::curl(...);
        $this->cacheFile = rtrim($cacheDir ?? sys_get_temp_dir(), '/')
            . '/sii-boleta-token-' . sha1($this->api . '|' . $certificate->getId()) . '.json';
    }

    public function urlApi(): string
    {
        return $this->api;
    }

    public function urlEnvio(): string
    {
        return $this->envio;
    }

    /* ───────────────────────── Autenticación ───────────────────────── */

    /** Token vigente (de caché) o uno nuevo: semilla → getToken firmado → token. */
    public function token(bool $renovar = false): string
    {
        if (!$renovar) {
            $cache = @json_decode((string) @file_get_contents($this->cacheFile), true);
            if (is_array($cache) && is_string($cache['token'] ?? null) && ($cache['exp'] ?? 0) > time()) {
                return $cache['token'];
            }
        }

        $token = $this->pedirToken($this->pedirSemilla());

        // El token es una credencial: archivo legible solo por el proceso.
        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['token' => $token, 'exp' => time() + self::TOKEN_TTL])) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $this->cacheFile);
        }

        return $token;
    }

    public function olvidarToken(): void
    {
        @unlink($this->cacheFile);
    }

    public function pedirSemilla(): string
    {
        $r = ($this->http)('GET', $this->api . '/boleta.electronica.semilla', [
            'Accept: application/xml',
            'User-Agent: ' . self::USER_AGENT,
        ], null);

        $estado = self::tagXml($r['body'], 'ESTADO');
        $semilla = self::tagXml($r['body'], 'SEMILLA');
        if ($r['status'] !== 200 || $semilla === null || !ctype_digit($semilla) || ($estado !== null && (int) $estado !== 0)) {
            throw new \RuntimeException(sprintf(
                'SII boleta: no se pudo obtener la semilla (HTTP %d, ESTADO %s). %s',
                $r['status'],
                $estado ?? '?',
                self::recortar($r['body'])
            ));
        }

        return $semilla;
    }

    /** XML getToken firmado tal como lo pide la especificación (2 líneas, firma enveloped). */
    public function getTokenFirmado(string $semilla): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<getToken><item><Semilla>' . $semilla . '</Semilla></item></getToken>';

        $generator = new SignatureGenerator(
            new XmlService(new XmlEncoder(), new XmlDecoder(), new XmlValidator())
        );
        $firmado = $generator->signXml($xml, $this->certificate);

        // signXml() calcula el digest sobre el XML compacto pero lo devuelve
        // re-indentado (formatOutput): al quitar la firma ya no sería el mismo
        // documento y el SII rechazaría el digest. Se inserta el nodo
        // <Signature> en el XML original, sin tocar nada más, tal como indica
        // la especificación (Signature pegada a </item> y a </getToken>).
        if (!preg_match('#<Signature\b.*</Signature>#s', $firmado, $m)) {
            throw new \RuntimeException('SII boleta: no se pudo firmar getToken (sin nodo Signature).');
        }

        return str_replace('</item></getToken>', '</item>' . $m[0] . '</getToken>', $xml);
    }

    public function pedirToken(string $semilla): string
    {
        $r = ($this->http)('POST', $this->api . '/boleta.electronica.token', [
            'Content-Type: application/xml',
            'Accept: application/xml',
            'User-Agent: ' . self::USER_AGENT,
        ], $this->getTokenFirmado($semilla));

        $estado = self::tagXml($r['body'], 'ESTADO');
        $token = self::tagXml($r['body'], 'TOKEN');
        if ($r['status'] !== 200 || $token === null || $token === '' || $estado === null || (int) $estado !== 0) {
            throw new \RuntimeException(sprintf(
                'SII boleta: no se pudo obtener el token para %s (HTTP %d, ESTADO %s: %s).',
                $this->certificate->getId(),
                $r['status'],
                $estado ?? '?',
                self::tagXml($r['body'], 'GLOSA') ?? self::recortar($r['body'])
            ));
        }

        return $token;
    }

    /* ─────────────────────────── Servicios ─────────────────────────── */

    /**
     * Sube un sobre EnvioBOLETA. Devuelve el JSON del SII (trackid, estado REC…).
     *
     * Sin reintentos automáticos: si la respuesta se perdió, el SII pudo haber
     * recibido el envío; el reenvío lo decide la app tras consultar el folio.
     *
     * @param string $xml Sobre EnvioBOLETA firmado, ya codificado en ISO-8859-1.
     * @param string $rutEmisor RUT de la empresa con DV, sin puntos.
     */
    public function enviar(string $xml, string $rutEmisor, ?string $nombreArchivo = null): array
    {
        [$rutSender, $dvSender] = self::partirRut($this->certificate->getId());
        [$rutCompany, $dvCompany] = self::partirRut($rutEmisor);
        $nombreArchivo ??= sprintf('EnvioBOLETA_%s_%s.xml', $rutCompany, date('YmdHis'));

        $campos = fn () => [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => new \CURLStringFile($xml, $nombreArchivo, 'application/xml'),
        ];

        // 401 = token vencido/ausente: el SII no recibió nada, reintentar es seguro.
        $r = $this->conToken(fn (string $token) => ($this->http)('POST', $this->envio . '/boleta.electronica.envio', [
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'Cookie: TOKEN=' . $token,
        ], $campos()));

        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($json) || !isset($json['trackid']) || !is_numeric($json['trackid'])) {
            throw new \RuntimeException(sprintf(
                'SII boleta: el envío falló (HTTP %d). %s',
                $r['status'],
                self::recortar($r['body'])
            ));
        }

        return $json;
    }

    /** Estado de un envío por trackId (JSON crudo del SII). 404 → RecursoNoEncontradoException. */
    public function estadoEnvio(string $rutEmisor, int|string $trackId): array
    {
        [$rut, $dv] = self::partirRut($rutEmisor);
        $url = sprintf('%s/boleta.electronica.envio/%s-%s-%s', $this->api, $rut, $dv, $trackId);

        return $this->getJson($url, 'estado del envío ' . $trackId);
    }

    /**
     * Estado de una boleta (tipo + folio) contrastado con receptor, monto y
     * fecha. Devuelve {codigo, descripcion}: los mismos códigos de QueryEstDte
     * (DOK, DNK, FAU, FNA, FAN, EMP, TMD, TMC, MMD, MMC, AND, ANC).
     *
     * @param string $fechaEmision YYYY-MM-DD (se convierte al DD-MM-YYYY que pide el SII).
     */
    public function estadoBoleta(
        string $rutEmisor,
        int $tipo,
        int $folio,
        string $rutReceptor,
        int $monto,
        string $fechaEmision
    ): array {
        [$rut, $dv] = self::partirRut($rutEmisor);
        [$rutRecep, $dvRecep] = self::partirRut($rutReceptor);
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaEmision);
        if ($fecha === false) {
            throw new \InvalidArgumentException('Fecha de emisión inválida (YYYY-MM-DD): ' . $fechaEmision);
        }

        $url = sprintf('%s/boleta.electronica/%s-%s-%d-%d/estado?', $this->api, $rut, $dv, $tipo, $folio)
            . http_build_query([
                'rut_receptor' => $rutRecep,
                'dv_receptor' => $dvRecep,
                'monto' => $monto,
                'fechaEmision' => $fecha->format('d-m-Y'),
            ]);

        return $this->getJson($url, "estado de la boleta {$tipo}-{$folio}");
    }

    /* ────────────────────── Normalización (pura) ────────────────────── */

    /**
     * Traduce la respuesta de estado de envío al formato que ya usa la app
     * para facturas (ver SiiService::consultarEstado):
     *   estado: enviado | aceptado | aceptado_reparos | rechazado
     *
     * La app manda una boleta por envío; si un envío trajera varias, basta un
     * rechazo para marcarlo 'rechazado' (revisión humana con el detalle).
     */
    public static function normalizarEstadoEnvio(array $r, int|string $trackId): array
    {
        $codigo = strtoupper((string) ($r['estado'] ?? ''));
        $glosa = $codigo !== '' ? $codigo . ' - ' . (self::GLOSAS_ENVIO[$codigo] ?? 'Estado desconocido') : null;

        $tot = ['informados' => 0, 'aceptados' => 0, 'rechazados' => 0, 'reparos' => 0];
        foreach ((array) ($r['estadistica'] ?? []) as $e) {
            foreach ($tot as $k => $_) {
                $tot[$k] += (int) ($e[$k] ?? 0);
            }
        }

        [$estado, $final] = match (true) {
            // En proceso: el SII aún no termina de validar.
            in_array($codigo, ['REC', 'SOK', 'FOK', 'CRT', 'PRD'], true) => ['enviado', false],
            // Procesado: el resultado real está en la estadística por documento.
            $codigo === 'EPR' && $tot['rechazados'] > 0 => ['rechazado', true],
            $codigo === 'EPR' && $tot['reparos'] > 0 => ['aceptado_reparos', true],
            $codigo === 'EPR' && $tot['aceptados'] > 0 => ['aceptado', true],
            // EPR sin estadística: no se puede afirmar nada aún; se reconsulta.
            $codigo === 'EPR' => ['enviado', false],
            $codigo === 'RPR' => ['aceptado_reparos', true],
            in_array($codigo, ['RCH', 'RCO', 'RFR', 'RSC', 'RCT', 'VOF'], true) => ['rechazado', true],
            // RPT: ese mismo archivo ya se había recibido. NO significa que la
            // boleta no exista (marcarla 'rechazado' invitaría a re-emitirla y
            // duplicarla): queda en 'enviado' para revisión (alerta de colgado).
            default => ['enviado', false],
        };

        return [
            'trackId' => is_numeric($trackId) ? (int) $trackId : $trackId,
            'estado' => $estado,
            'final' => $final,
            'glosa' => $glosa,
            'detalle' => self::resumirDetalle($r, $tot),
            'respuestaSii' => $r,
        ];
    }

    /** Resumen legible de estadística y errores por documento (o null si no hay nada que decir). */
    private static function resumirDetalle(array $r, array $tot): ?string
    {
        $partes = [];
        if ($tot['informados'] > 0) {
            $partes[] = sprintf(
                'Informados %d · aceptados %d · reparos %d · rechazados %d',
                $tot['informados'],
                $tot['aceptados'],
                $tot['reparos'],
                $tot['rechazados']
            );
        }
        foreach ((array) ($r['detalle_rep_rech'] ?? []) as $d) {
            $errores = array_map(
                static fn ($e) => trim(sprintf(
                    '%s %s: %s%s',
                    $e['seccion'] ?? '',
                    $e['codigo'] ?? '',
                    $e['descripcion'] ?? '',
                    !empty($e['detalle']) ? ' (' . $e['detalle'] . ')' : ''
                )),
                (array) ($d['error'] ?? [])
            );
            $partes[] = sprintf(
                '%s folio %s %s%s%s',
                $d['tipo'] ?? '?',
                $d['folio'] ?? '?',
                $d['estado'] ?? '',
                !empty($d['descripcion']) ? ' ' . $d['descripcion'] : '',
                $errores ? ' — ' . implode('; ', $errores) : ''
            );
        }

        return $partes ? mb_substr(implode(' | ', $partes), 0, 2000) : null;
    }

    /* ─────────────────────────── Internos ─────────────────────────── */

    private function getJson(string $url, string $que): array
    {
        $r = $this->conToken(fn (string $token) => ($this->http)('GET', $url, [
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'Cookie: TOKEN=' . $token,
        ], null));

        if ($r['status'] === 404) {
            throw new RecursoNoEncontradoException(sprintf(
                'SII boleta: %s no encontrado (HTTP 404). %s',
                $que,
                self::recortar($r['body'])
            ));
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($json)) {
            throw new \RuntimeException(sprintf(
                'SII boleta: falló la consulta de %s (HTTP %d). %s',
                $que,
                $r['status'],
                self::recortar($r['body'])
            ));
        }

        return $json;
    }

    /** Ejecuta con el token vigente; ante 401 lo renueva y reintenta una sola vez. */
    private function conToken(callable $llamada): array
    {
        $r = $llamada($this->token());
        if ($r['status'] === 401) {
            $this->olvidarToken();
            $r = $llamada($this->token(true));
        }
        if ($r['status'] === 405) {
            throw new \RuntimeException('SII boleta: límite de solicitudes excedido (HTTP 405). ' . self::recortar($r['body']));
        }

        return $r;
    }

    /** @return array{0:int, 1:string} */
    public static function partirRut(string $rut): array
    {
        $limpio = strtoupper(str_replace(['.', ' '], '', $rut));
        if (!preg_match('/^(\d{1,9})-?([\dK])$/', $limpio, $m)) {
            throw new \InvalidArgumentException('RUT inválido: ' . $rut);
        }

        return [(int) $m[1], $m[2]];
    }

    /** Valor de una etiqueta XML ignorando el prefijo de namespace (SII:ESTADO, ESTADO). */
    private static function tagXml(string $xml, string $tag): ?string
    {
        return preg_match('#<(?:\w+:)?' . $tag . '>\s*([^<]*?)\s*</(?:\w+:)?' . $tag . '>#', $xml, $m)
            ? $m[1]
            : null;
    }

    private static function recortar(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');

        return mb_substr($s, 0, 300);
    }

    /** Transporte por defecto (curl, TLS verificado). */
    private static function curl(string $metodo, string $url, array $headers, mixed $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respuesta = curl_exec($ch);
        if ($respuesta === false) {
            $error = curl_error($ch);
            throw new \RuntimeException("SII boleta: sin respuesta de {$url}: {$error}");
        }

        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $respuesta];
    }
}
