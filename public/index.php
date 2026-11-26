<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\CertificadoInvalidoException;
use App\CertificadoService;
use App\Config;
use App\DteEmisor;
use App\DteRecibidoValidador;
use App\EmisionEnCursoException;
use App\EnvioYaRecibidoException;
use App\EmisorDatos;
use App\FolioControl;
use App\FolioRepository;
use App\RegistroReclamoService;
use App\RespuestaDteBuilder;
use App\RvdBuilder;
use App\Sii\RcvServicio;
use App\SiiService;
use App\SobreBuilder;
use App\Timbraje\CafCargaManual;
use App\Timbraje\CafModulo;
use App\Timbraje\CafSolicitudRepository;
use App\Timbraje\CafSolicitudService;
use App\Timbraje\ErrorModoPrueba;
use App\Timbraje\ModoPrueba;
use Derafu\Xml\XmlDocument;
use libredte\lib\Core\Application;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

function autorizado(Config $config): bool
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return hash_equals("Bearer {$config->apiToken}", $auth);
}

/**
 * Errores de las rutas /caf/*: a diferencia de las demás rutas NO se devuelve
 * $e->getMessage() tal cual; pasa por CafSolicitudRepository::sanear, que
 * descarta todo texto que parezca XML/HTML, clave, cookie o ruta del
 * certificado. En el log solo va la clase de la excepción.
 */
function cafError(\Throwable $e): void
{
    if ($e instanceof ErrorModoPrueba) {
        http_response_code(503);
        echo json_encode(['error' => CafSolicitudRepository::sanear($e->getMessage()), 'codigo' => 'MODO_PRUEBA_NO_PERMITIDO']);
        return;
    }
    error_log('[caf] excepción ' . get_class($e));
    http_response_code($e instanceof \InvalidArgumentException ? 400 : 500);
    echo json_encode(['error' => CafSolicitudRepository::sanear($e->getMessage()) ?: 'Error interno', 'codigo' => 'ERROR_INTERNO']);
}

/** Cuerpo JSON de las rutas POST /caf/* (null si no es un objeto JSON). */
function cafCuerpoJson(): ?array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) && !array_is_list($body) ? $body : null;
}

/**
 * Pasos comunes de validación de POST /caf/solicitar, /caf/recuperar y
 * /caf/consultar, en forma de resultado (nunca escribe la respuesta: eso lo
 * decide cafResponder según haya o no streaming NDJSON). Orden: modo de
 * prueba seguro (503) → JSON válido (400) → palabra de confirmación (428).
 * El guard de Next ya la exige; aquí se repite por defensa en profundidad.
 *
 * @return array{0:true,1:array<string,mixed>}|array{0:false,1:array{0:int,1:array<string,mixed>}}
 */
function cafCuerpoValidado(Config $config, string $confirmacionEsperada): array
{
    try {
        ModoPrueba::exigirSeguro($config);
    } catch (ErrorModoPrueba $e) {
        return [false, [503, ['error' => CafSolicitudRepository::sanear($e->getMessage()), 'codigo' => 'MODO_PRUEBA_NO_PERMITIDO']]];
    }
    $body = cafCuerpoJson();
    if ($body === null) {
        return [false, [400, ['error' => 'Body JSON inválido', 'codigo' => 'ENTRADA_INVALIDA']]];
    }
    if (($body['confirmacion'] ?? null) !== $confirmacionEsperada) {
        return [false, [428, ['error' => 'Falta escribir ' . $confirmacionEsperada . '.', 'codigo' => 'CONFIRMACION_REQUERIDA']]];
    }
    return [true, $body];
}

/** true si el cliente pidió explícitamente la traza en vivo (v1.1 transparencia). */
function cafQuiereNdjson(): bool
{
    return stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/x-ndjson') !== false;
}

/**
 * Ejecuta una operación de POST /caf/consultar|solicitar|recuperar con
 * soporte opcional de streaming NDJSON.
 *
 * Sin el header `Accept: application/x-ndjson`: comportamiento idéntico a
 * antes de v1.1 (un solo JSON, con su status HTTP real).
 *
 * Con ese header: `Content-Type: application/x-ndjson`, una línea por
 * evento (sin sesionId/loteId/consultaId: esos solo sirven para persistir y
 * para GET /caf/eventos) con flush inmediato, y una última línea
 * `{"t":"resultado","status":<http real>,"cuerpo":<mismo JSON de siempre>}`.
 * El status HTTP de la respuesta del stream en sí es siempre 200.
 *
 * @param \Closure(?\Closure $listener): array{0:int,1:array<string,mixed>} $ejecutar
 */
function cafResponder(\Closure $ejecutar): void
{
    if (!cafQuiereNdjson()) {
        [$http, $cuerpo] = $ejecutar(null);
        http_response_code($http);
        echo json_encode($cuerpo);
        return;
    }
    header('Content-Type: application/x-ndjson; charset=utf-8', true);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
    $listener = static function (array $evento): void {
        echo json_encode([
            't' => 'evento', 'ts' => $evento['ts'], 'paso' => $evento['paso'], 'estado' => $evento['estado'],
            'tipoDte' => $evento['tipoDte'], 'mensaje' => $evento['mensaje'], 'codigoSii' => $evento['codigoSii'],
            'segundos' => $evento['segundos'],
        ], JSON_UNESCAPED_UNICODE) . "\n";
        if (function_exists('flush')) {
            flush();
        }
    };
    [$http, $cuerpo] = $ejecutar($listener);
    echo json_encode(['t' => 'resultado', 'status' => $http, 'cuerpo' => $cuerpo], JSON_UNESCAPED_UNICODE) . "\n";
    if (function_exists('flush')) {
        flush();
    }
}

if ($method === 'GET' && $path === '/health') {
    echo json_encode(['ok' => true]);
    return;
}

if ($method === 'GET' && $path === '/emisor') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        echo json_encode([
            'rut' => $config->emisorRut,
            'razonSocial' => $config->emisorRazonSocial,
            'giro' => $config->emisorGiro,
            'acteco' => $config->emisorActeco,
            'direccion' => $config->emisorDireccion,
            'comuna' => $config->emisorComuna,
            'ciudad' => $config->emisorCiudad,
            'telefono' => $config->emisorTelefono,
            'email' => $config->emisorEmail,
            'cuentaBancaria' => $config->cuentaBancaria,
            'ambiente' => $config->ambiente,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

// Datos editables del emisor (ver EmisorDatos). Rigen desde el próximo DTE.
if ($method === 'PUT' && $path === '/emisor') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'Body JSON inválido']);
            return;
        }

        echo json_encode(EmisorDatos::guardar($body));
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

if ($method === 'GET' && $path === '/certificado/estado') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        echo json_encode((new CertificadoService($config))->estado());
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

if ($method === 'POST' && $path === '/certificado') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['archivoBase64']) || !isset($body['clave'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan archivoBase64 o clave']);
            return;
        }

        $resultado = (new CertificadoService($config))->guardar((string) $body['archivoBase64'], (string) $body['clave']);
        echo json_encode($resultado);
    } catch (CertificadoInvalidoException $e) {
        // NO_ES_PKCS12 | CLAVE_INCORRECTA | VENCIDO: nada se guardó; el certificado vigente no se tocó.
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'codigo' => $e->codigo], JSON_UNESCAPED_UNICODE);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * DELETE /certificado: borra el .pfx, su clave y la versión previa. Exige
 * {"confirmacion":"BORRAR"}. Idempotente (200 {cargado:false} aunque no hubiera).
 */
if ($method === 'DELETE' && $path === '/certificado') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        [$ok, $r] = cafCuerpoValidado($config, 'BORRAR');
        if (!$ok) {
            [$http, $cuerpo] = $r;
            http_response_code($http);
            echo json_encode($cuerpo);
            return;
        }
        echo json_encode((new CertificadoService($config))->borrar());
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => CafSolicitudRepository::sanear($e->getMessage()) ?: 'Error interno']);
    }
    return;
}

if ($method === 'POST' && $path === '/dte/emitir') {
    try {
        $config = new Config();

        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'Body JSON inválido']);
            return;
        }

        $emisor = new DteEmisor($config, new FolioRepository($config->dbPath));
        $resultado = $emisor->emitir($body);

        echo json_encode($resultado);
    } catch (EmisionEnCursoException $e) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Recuperación por clave de idempotencia: devuelve el documento ya timbrado
 * (XML/PDF/folio/trackId) sin timbrar ni enviar nada. 404 si no existe.
 */
if ($method === 'GET' && $path === '/dte/emision') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        $clave = (string) ($_GET['clave'] ?? '');
        if ($clave === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta clave']);
            return;
        }
        $resultado = (new DteEmisor($config, new FolioRepository($config->dbPath)))->buscarPorClave($clave);
        if ($resultado === null) {
            http_response_code(404);
            echo json_encode(['error' => 'No hay un documento timbrado con esa clave']);
            return;
        }
        echo json_encode($resultado);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/** Folios por tipo: rangos de los CAF, usados, disponibles y reservas huérfanas. */
if ($method === 'GET' && $path === '/folios') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        echo json_encode(['ambiente' => $config->ambiente, 'tipos' => (new FolioControl($config, new FolioRepository($config->dbPath)))->estado()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Solicitud de CAF al SII. El
 * certificado, las cookies del SII y el XML del CAF (trae la clave privada
 * RSA) nunca salen del contenedor: estas rutas solo devuelven metadatos.
 *
 * GET /caf/estado: folios por tipo (con fa/vence/idk/archivo), última info
 * del SII (MAX_AUTOR, FOLIOS_DISP) y estado del presupuesto de sesiones.
 */
if ($method === 'GET' && $path === '/caf/estado') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        echo json_encode((new CafModulo($config))->estado());
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/** GET /caf/solicitudes?limit=50: bitácora de solicitudes (sin XML ni HTML), las más recientes primero. */
if ($method === 'GET' && $path === '/caf/solicitudes') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        ModoPrueba::exigirSeguro($config);
        $limit = $_GET['limit'] ?? '50';
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'limit debe ser un entero positivo', 'codigo' => 'ENTRADA_INVALIDA']);
            return;
        }
        echo json_encode((new CafModulo($config))->solicitudes(min(500, (int) $limit)));
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /caf/solicitar: UNA sesión con el SII para toda la tanda; síncrono.
 * ACCIÓN REAL ANTE EL SII (folios irreversibles). Exige confirmacion "SOLICITAR".
 * body: { confirmacion, ambiente, lote: [{tipo, cantidad}], permitirMenos?,
 *         idempotencyKey, usuarioEmail? }
 * ignore_user_abort: si Next corta la conexión, la tanda termina y hace logout.
 */
if ($method === 'POST' && $path === '/caf/solicitar') {
    ignore_user_abort(true);
    set_time_limit(300);
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        cafResponder(function (?\Closure $listener) use ($config): array {
            [$ok, $r] = cafCuerpoValidado($config, CafModulo::CONFIRMACION);
            if (!$ok) {
                return $r;
            }
            return CafModulo::respuesta((new CafModulo($config, $listener))->servicio()->solicitar($r));
        });
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /caf/consultar: UNA sesión de SOLO LECTURA (consultarCupo por tipo;
 * nunca confirma ni genera) para ver cupo y folios disponibles antes de
 * pedir de verdad. Cuenta para el presupuesto de sesiones igual que
 * /caf/solicitar. Exige confirmacion "CONSULTAR".
 * body: { confirmacion: "CONSULTAR", tipos: [33, 39, 56, 61] }
 */
if ($method === 'POST' && $path === '/caf/consultar') {
    ignore_user_abort(true);
    set_time_limit(300);
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        cafResponder(function (?\Closure $listener) use ($config): array {
            [$ok, $r] = cafCuerpoValidado($config, CafSolicitudService::CONFIRMACION_CONSULTA);
            if (!$ok) {
                return $r;
            }
            return CafModulo::respuestaConsulta((new CafModulo($config, $listener))->servicio()->consultar($r));
        });
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /sii/rcv: lectura de SOLO LECTURA del Registro de Compras y Ventas del
 * SII (getResumen / getDetalleCompra / getDetalleVenta; nunca acepta, reclama
 * ni envía) en UNA sesión con el certificado. Gasta una sesión del MISMO
 * presupuesto que /caf/* (bitácora sii_sesiones, motivo 'leer_rcv') y toma su
 * mismo lock. Respuesta: RespuestaRcv (ver App\Sii\RcvServicio).
 * body: { confirmacion: "LEER_RCV", periodos: ["2026-11"] }  (1 a 3, no futuros)
 */
if ($method === 'POST' && $path === '/sii/rcv') {
    ignore_user_abort(true);
    set_time_limit(300);
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'periodos' => [], 'error' => 'No autorizado', 'codigo' => 'NO_AUTORIZADO']);
            return;
        }
        $body = cafCuerpoJson();
        if ($body === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'periodos' => [], 'error' => 'Body JSON inválido', 'codigo' => 'ENTRADA_INVALIDA']);
            return;
        }
        [$http, $cuerpo] = RcvServicio::desdeConfig($config)->leer($body);
        http_response_code($http);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (ErrorModoPrueba $e) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'periodos' => [], 'error' => CafSolicitudRepository::sanear($e->getMessage()), 'codigo' => 'MODO_PRUEBA_NO_PERMITIDO']);
    } catch (\Throwable $e) {
        error_log('[rcv] excepción ' . get_class($e));
        http_response_code(500);
        echo json_encode(['ok' => false, 'periodos' => [], 'error' => 'Error interno al leer el RCV', 'codigo' => 'ERROR_INTERNO']);
    }
    return;
}

/**
 * GET /caf/eventos?loteId=…|consultaId=…: traza de una tanda ya terminada
 * (mismo formato de evento que el streaming NDJSON, sin "t"). Exactamente
 * uno de los dos parámetros; hasta CafSolicitudRepository::EVENTOS_MAXIMO filas.
 */
if ($method === 'GET' && $path === '/caf/eventos') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        $loteId = $_GET['loteId'] ?? null;
        $consultaId = $_GET['consultaId'] ?? null;
        if ((!is_string($loteId) || $loteId === '') === (!is_string($consultaId) || $consultaId === '')) {
            http_response_code(400);
            echo json_encode(['error' => 'Pasa exactamente uno de loteId o consultaId', 'codigo' => 'ENTRADA_INVALIDA']);
            return;
        }
        $modulo = new CafModulo($config);
        echo json_encode($loteId !== null && $loteId !== '' ? $modulo->eventosDeLote($loteId) : $modulo->eventosDeConsulta((string) $consultaId));
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /caf/recuperar: vuelve a descargar (of_genera_archivo, sin generar)
 * un CAF ya asignado por el SII. Una sesión. Exige "SOLICITAR".
 * body: { confirmacion, solicitudId }
 */
if ($method === 'POST' && $path === '/caf/recuperar') {
    ignore_user_abort(true);
    set_time_limit(300);
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        cafResponder(function (?\Closure $listener) use ($config): array {
            [$ok, $r] = cafCuerpoValidado($config, CafModulo::CONFIRMACION);
            if (!$ok) {
                return $r;
            }
            $id = $r['solicitudId'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $id) !== 1) {
                return [400, ['error' => 'solicitudId inválido', 'codigo' => 'ENTRADA_INVALIDA']];
            }
            return CafModulo::respuesta((new CafModulo($config, $listener))->servicio()->recuperar($id, (string) $r['confirmacion']));
        });
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /caf/descartar: salida manual de una solicitud abierta ('incierto' o
 * 'generado_sin_descargar') tras verificar en el portal del SII. NO habla con
 * el SII (cero sesiones). Exige "SOLICITAR" y un motivo no vacío.
 * body: { confirmacion, solicitudId, motivo }
 */
if ($method === 'POST' && $path === '/caf/descartar') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        [$ok, $r] = cafCuerpoValidado($config, CafModulo::CONFIRMACION);
        if (!$ok) {
            [$http, $cuerpo] = $r;
            http_response_code($http);
            echo json_encode($cuerpo);
            return;
        }
        $body = $r;
        $id = $body['solicitudId'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $id) !== 1) {
            http_response_code(400);
            echo json_encode(['error' => 'solicitudId inválido', 'codigo' => 'ENTRADA_INVALIDA']);
            return;
        }
        $motivo = $body['motivo'] ?? null;
        if (!is_string($motivo) || trim($motivo) === '' || mb_strlen($motivo) > 300) {
            http_response_code(400);
            echo json_encode(['error' => 'motivo es obligatorio (texto de 1 a 300 caracteres)', 'codigo' => 'ENTRADA_INVALIDA']);
            return;
        }
        [$http, $cuerpo] = CafModulo::respuesta((new CafModulo($config))->servicio()->marcarResuelta($id, $motivo, (string) $body['confirmacion']));
        http_response_code($http);
        echo json_encode($cuerpo);
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * POST /caf/instalar: instala un CAF descargado a mano del portal del SII
 * (lógica en CafCargaManual). NO habla
 * con el SII. El XML lleva la llave privada (RSASK): jamás se registra ni se
 * devuelve; si se rechaza queda en cuarentena (0600) y si se acepta en el
 * directorio de CAF y en el respaldo.
 * body: { xmlBase64, confirmacion: "INSTALAR", solicitudId? }
 * 201 {estado:"instalado", tipo, folioIni, folioFin, fa, idk, archivo, sha256, respaldo}
 * 409 YA_INSTALADO · 422 XML_INVALIDO | FIRMA_INVALIDA | RUT_NO_COINCIDE |
 * AMBIENTE_NO_COINCIDE | RANGO_SE_CRUZA | TIPO_NO_SOPORTADO | SOLICITUD_NO_COINCIDE.
 */
if ($method === 'POST' && $path === '/caf/instalar') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        $maxCuerpo = 131072; // 128 KB: un CAF real pesa ~1,2 KB (CafInstalador rechaza XML > 64 KB)
        if (strlen((string) file_get_contents('php://input', false, null, 0, $maxCuerpo + 1)) > $maxCuerpo) {
            http_response_code(413);
            echo json_encode(['error' => 'Cuerpo demasiado grande', 'codigo' => 'CUERPO_DEMASIADO_GRANDE']);
            return;
        }
        [$ok, $r] = cafCuerpoValidado($config, 'INSTALAR');
        if (!$ok) {
            [$http, $cuerpo] = $r;
            http_response_code($http);
            echo json_encode($cuerpo);
            return;
        }
        $body = $r;
        $solicitudId = $body['solicitudId'] ?? null;
        if ($solicitudId !== null) {
            if (is_int($solicitudId)) {
                $solicitudId = (string) $solicitudId;
            }
            if (!is_string($solicitudId) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $solicitudId) !== 1) {
                http_response_code(400);
                echo json_encode(['error' => 'solicitudId inválido', 'codigo' => 'ENTRADA_INVALIDA']);
                return;
            }
        }
        $xmlB64 = $body['xmlBase64'] ?? null;
        $bytes = is_string($xmlB64) && $xmlB64 !== '' ? base64_decode($xmlB64, true) : false;
        unset($body, $xmlB64);
        $instError = static function (int $http, string $codigo, string $error): void {
            http_response_code($http);
            echo json_encode(['error' => CafSolicitudRepository::sanear($error) ?: 'Error', 'codigo' => $codigo], JSON_UNESCAPED_UNICODE);
        };
        if ($bytes === false || $bytes === '') {
            $instError(422, 'XML_INVALIDO', 'xmlBase64 no es un base64 válido.');
            return;
        }
        ['meta' => $meta, 'error' => $errorMeta] = CafCargaManual::leerMetadatos($bytes);
        if ($meta === null) {
            $bytes = '';
            $instError(422, 'XML_INVALIDO', (string) $errorMeta);
            return;
        }
        $esperado = CafCargaManual::esperado($meta, $config);
        // RE == RUT emisor ANTES de tocar nada (ni cuarentena).
        if (!CafCargaManual::reCoincide($meta['re'], $config)) {
            $bytes = '';
            $instError(422, 'RUT_NO_COINCIDE', 'El RE del CAF no coincide con el RUT del emisor configurado.');
            return;
        }
        $repo = null;
        if ($solicitudId !== null) {
            $repo = new CafSolicitudRepository($config->cafSolicitudesDb);
            ['error' => $errorSol] = CafCargaManual::verificarSolicitud($repo, $solicitudId, $config, $esperado);
            if ($errorSol !== null) {
                $bytes = '';
                $instError(422, 'SOLICITUD_NO_COINCIDE', $errorSol);
                return;
            }
        }
        $res = CafCargaManual::instalar($config, $bytes, $esperado);
        $bytes = '';
        error_log('[caf] caf_instalacion_manual ' . json_encode([
            'solicitudId' => $solicitudId,
            'ambiente' => $config->ambiente,
            'tipo' => $res['tipo'],
            'folioIni' => $res['folioIni'],
            'folioFin' => $res['folioFin'],
            'estado' => $res['estado'],
            'sha256' => $res['sha256'],
            'origen' => 'http',
        ], JSON_UNESCAPED_UNICODE));

        if (CafCargaManual::quedoInstalado($res) && $repo !== null && $solicitudId !== null) {
            CafCargaManual::avanzarSolicitud($repo, $solicitudId, $res);
        }
        [$http, $cuerpo] = CafCargaManual::respuestaHttp($res, $meta);
        http_response_code($http);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        cafError($e);
    }
    return;
}

/**
 * ¿El SII conoce este documento? (QueryEstDte). Solo lectura.
 * query: tipo, folio, fecha (YYYY-MM-DD), total, receptor (RUT con DV).
 */
if ($method === 'GET' && $path === '/dte/estado-documento') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }
        $tipo = $_GET['tipo'] ?? null;
        $folio = $_GET['folio'] ?? null;
        $total = $_GET['total'] ?? null;
        $fecha = (string) ($_GET['fecha'] ?? '');
        $receptor = (string) ($_GET['receptor'] ?? '');
        if (!ctype_digit((string) $tipo) || !ctype_digit((string) $folio) || !ctype_digit((string) $total)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $receptor === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros requeridos: tipo, folio, total (numéricos), fecha (YYYY-MM-DD) y receptor']);
            return;
        }
        $sii = new SiiService($config);
        echo json_encode($sii->consultarDocumento(
            $sii->cargarCertificado(),
            (int) $tipo,
            (int) $folio,
            $fecha,
            (int) $total,
            $receptor
        ));
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Consulta al SII el estado de un envío por su trackId.
 * El estado puede no ser final: mientras devuelva 'enviado' hay que reconsultar.
 * query: trackId, tipo? (39/41 para envíos de boletas: API REST).
 */
if ($method === 'GET' && $path === '/dte/estado') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $trackId = $_GET['trackId'] ?? null;
        if ($trackId === null || !ctype_digit((string) $trackId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta trackId (numérico)']);
            return;
        }

        // tipo (opcional): 39/41 = envío de boletas → API REST de boletas del SII.
        // Sin tipo (facturas, NC, RVD) se consulta por SOAP como siempre.
        $tipo = (string) ($_GET['tipo'] ?? '');
        if ($tipo !== '' && !ctype_digit($tipo)) {
            http_response_code(400);
            echo json_encode(['error' => 'tipo debe ser numérico']);
            return;
        }
        $esBoleta = in_array((int) $tipo, [39, 41], true);

        $sii = new SiiService($config);
        echo json_encode($sii->consultarEstado((int) $trackId, $sii->cargarCertificado(), $esBoleta));
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Reenvía al SII un XML YA TIMBRADO. No consume folio: se usa cuando la
 * emisión funcionó pero el envío falló (SII caído, red), para no duplicar el
 * documento re-timbrándolo.
 */
if ($method === 'POST' && $path === '/dte/reenviar') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['xmlBase64'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta xmlBase64']);
            return;
        }

        $sii = new SiiService($config);
        $trackId = $sii->reenviar((string) $body['xmlBase64'], $sii->cargarCertificado());

        echo json_encode(['trackId' => $trackId]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (EnvioYaRecibidoException $e) {
        // STATUS 99: el SII ya tenía este envío. La app confirma con QueryEstDte.
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage(), 'codigo' => 'SII_STATUS_99']);
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Arma y firma el sobre `EnvioDTE`/`EnvioBOLETA` "de intercambio" exigido por
 * el SII entre contribuyentes (Ley 19.983): a diferencia de /dte/reenviar
 * (receptor = SII, se envía por webservice), aquí el receptor del sobre
 * (Caratula/RutReceptor) es el CLIENTE. No se despacha a ningún lado: solo
 * devuelve el XML firmado en base64 para que la app lo adjunte al correo que
 * recibe el cliente, junto al PDF. No consume folio: reconstruye el
 * documento desde el XML ya timbrado.
 *
 * body: { xmlBase64: string (un <DTE> suelto o un <EnvioDTE>/<EnvioBOLETA>
 *         completo, del que se extraen todos los <DTE>), rutReceptor: string,
 *         razonSocialReceptor?: string }
 */
if ($method === 'POST' && $path === '/dte/sobre-receptor') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['xmlBase64']) || empty($body['rutReceptor'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Requeridos: xmlBase64 y rutReceptor']);
            return;
        }

        $xml = base64_decode((string) $body['xmlBase64'], true);
        if ($xml === false || $xml === '') {
            http_response_code(400);
            echo json_encode(['error' => 'xmlBase64 no es un base64 válido']);
            return;
        }
        if (!(new XmlDocument())->loadXml($xml)) {
            http_response_code(400);
            echo json_encode(['error' => 'El XML está malformado y no se pudo cargar']);
            return;
        }

        $sii = new SiiService($config);
        $certificate = $sii->cargarCertificado();

        $sobre = (new SobreBuilder($config))->construirParaReceptor(
            $xml,
            $certificate,
            (string) $body['rutReceptor'],
            (string) ($body['razonSocialReceptor'] ?? ''),
        );

        // Falla rápido si el sobre no cumple el esquema (EnvioDTE_v10.xsd /
        // EnvioBOLETA_v11.xsd), igual que se valida la mensajería de /dte/respuesta.
        Application::getInstance()
            ->getPackageRegistry()
            ->getBillingPackage()
            ->getDocumentComponent()
            ->getDispatcherWorker()
            ->validateSchema($sobre);

        echo json_encode([
            'xml' => base64_encode($sobre->getXmlDocument()->setEncoding('ISO-8859-1')->saveXml()),
        ]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Genera la mensajería XML de respuesta a un DTE recibido de un proveedor
 * (etapa 3 de la certificación: intercambio entre contribuyentes).
 *
 * No envía nada: devuelve el XML firmado en base64 para que la app lo guarde
 * como evidencia y lo despache por correo a la casilla de intercambio del
 * proveedor. Separar generar de enviar es deliberado — el envío puede fallar
 * o reintentarse sin volver a firmar.
 *
 * body: { tipo: "recepcion_envio"|"resultado_dte"|"envio_recibos", ... }
 */
if ($method === 'POST' && $path === '/dte/respuesta') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['tipo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta "tipo" (recepcion_envio | resultado_dte | envio_recibos)']);
            return;
        }

        $builder = new RespuestaDteBuilder($config);

        $xml = match ($body['tipo']) {
            'recepcion_envio' => $builder->recepcionEnvio($body['envio'] ?? []),
            'resultado_dte' => $builder->resultadoDte(
                $body['dte'] ?? [],
                (int) ($body['estado'] ?? 0),
                (string) ($body['glosa'] ?? ''),
                isset($body['codRechazo']) ? (int) $body['codRechazo'] : null,
            ),
            'envio_recibos' => $builder->envioRecibos(
                $body['dte'] ?? [],
                (string) ($body['recinto'] ?? ''),
            ),
            default => throw new \InvalidArgumentException(
                'tipo debe ser "recepcion_envio", "resultado_dte" o "envio_recibos"'
            ),
        };

        echo json_encode(['xml' => base64_encode($xml)]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * RVD / RCOF (Resumen de Ventas Diarias) de un día: lo arma, firma y valida
 * contra el esquema; y SOLO si `enviar` es true lo sube al SII (DTEUpload).
 * La app cliente decide si enviar según su configuración.
 *
 * En producción, además, el envío exige LIBREDTE_RVD_PRODUCCION=on en el
 * entorno del microservicio: sin eso el XML se genera igual pero no se envía
 * (el RVD no es obligatorio desde 2022-08).
 *
 * body: { fecha: "YYYY-MM-DD", secEnvio: int, enviar: bool,
 *         documentos: [{ tipo?: 39|41, folio, fecha, neto, iva, exento, total }] }
 */
if ($method === 'POST' && $path === '/rvd') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !is_string($body['fecha'] ?? null) || !is_int($body['secEnvio'] ?? null)
            || !is_array($body['documentos'] ?? null)) {
            http_response_code(400);
            echo json_encode(['error' => 'Requeridos: fecha (YYYY-MM-DD), secEnvio (entero) y documentos (lista)']);
            return;
        }

        $detalle = array_map(static fn (array $d): array => [
            'TpoDoc' => (int) ($d['tipo'] ?? 39),
            'NroDoc' => (int) ($d['folio'] ?? 0),
            'FchDoc' => (string) ($d['fecha'] ?? ''),
            'MntNeto' => (int) ($d['neto'] ?? 0),
            'MntIVA' => (int) ($d['iva'] ?? 0),
            'MntExe' => (int) ($d['exento'] ?? 0),
            'MntTotal' => (int) ($d['total'] ?? -1),
        ], $body['documentos']);

        $xml = (new RvdBuilder($config))->construirDia($body['fecha'], $detalle, $body['secEnvio']);

        $trackId = null;
        $envioError = null;
        if (($body['enviar'] ?? false) === true) {
            if ($config->ambiente === 'produccion' && getenv('LIBREDTE_RVD_PRODUCCION') !== 'on') {
                $envioError = 'Envío del RVD en producción bloqueado: falta LIBREDTE_RVD_PRODUCCION=on en el microservicio.';
            } else {
                try {
                    $doc = new XmlDocument();
                    $doc->loadXml($xml);
                    $sii = new SiiService($config);
                    $trackId = $sii->enviar($doc, $sii->cargarCertificado());
                } catch (\Throwable $e) {
                    // El XML ya está armado y se devuelve igual: la app lo guarda
                    // y reintenta el envío sin cambiar la secuencia.
                    $envioError = $e->getMessage();
                }
            }
        }

        echo json_encode([
            'xml' => base64_encode($xml),
            'ambiente' => $config->ambiente,
            'trackId' => $trackId,
            'envioError' => $envioError,
        ]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Registro de Aceptación o Reclamo de DTE ante el SII (Ley 19.983 modificada
 * por Ley 20.956): es, a diferencia de /dte/respuesta, el mecanismo con VALOR
 * LEGAL. Ver RegistroReclamoService para la especificación del WS.
 * IRREVERSIBLE: el SII no permite deshacer esta acción.
 *
 * body: { rutEmisor: "RUT-DV del PROVEEDOR que emitió el DTE", tipoDoc: int,
 *         folio: int, accion: "ACD"|"RCD"|"ERM"|"RFP"|"RFT" }
 * rutEmisor es OBLIGATORIO: el WS identifica el
 * documento por el RUT de quien lo emitió, nunca por el nuestro.
 */
if ($method === 'POST' && $path === '/dte/registro-reclamo') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (
            !is_array($body)
            || !ctype_digit((string) ($body['tipoDoc'] ?? ''))
            || !ctype_digit((string) ($body['folio'] ?? ''))
            || empty($body['accion'])
            || !is_string($body['rutEmisor'] ?? null)
            || trim($body['rutEmisor']) === ''
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'Requeridos: rutEmisor (RUT del proveedor), tipoDoc, folio (enteros) y accion']);
            return;
        }

        $servicio = new RegistroReclamoService($config);
        // Valida el RUT ANTES de cargar el certificado (400 sin tocar nada).
        $rutEmisor = $servicio->rutEmisorDocumento($body['rutEmisor']);
        $resultado = $servicio->registrarAccion(
            $servicio->cargarCertificado(),
            $rutEmisor,
            (int) $body['tipoDoc'],
            (int) $body['folio'],
            (string) $body['accion']
        );

        echo json_encode($resultado);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Historial de eventos de un DTE en el Registro de Reclamo del SII. Solo
 * lectura. query: rutEmisor (RUT-DV del PROVEEDOR), tipo, folio.
 */
if ($method === 'GET' && $path === '/dte/registro-reclamo/eventos') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $tipo = $_GET['tipo'] ?? null;
        $folio = $_GET['folio'] ?? null;
        if (!ctype_digit((string) $tipo) || !ctype_digit((string) $folio)) {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros requeridos: tipo y folio (numéricos)']);
            return;
        }

        $servicio = new RegistroReclamoService($config);
        $rutEmisor = $servicio->rutEmisorDocumento((string) ($_GET['rutEmisor'] ?? ''));
        $eventos = $servicio->listarEventos($servicio->cargarCertificado(), $rutEmisor, (int) $tipo, (int) $folio);

        echo json_encode(['eventos' => $eventos]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Fecha real en que el SII recibió el documento (no la de emisión ni la del
 * correo de intercambio): desde ella corren los 8 días corridos de la Ley
 * 19.983 art. 3. Solo lectura. query: rutEmisor (RUT-DV del PROVEEDOR), tipo, folio.
 */
if ($method === 'GET' && $path === '/dte/registro-reclamo/fecha-recepcion') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $tipo = $_GET['tipo'] ?? null;
        $folio = $_GET['folio'] ?? null;
        if (!ctype_digit((string) $tipo) || !ctype_digit((string) $folio)) {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros requeridos: tipo y folio (numéricos)']);
            return;
        }

        $servicio = new RegistroReclamoService($config);
        $rutEmisor = $servicio->rutEmisorDocumento((string) ($_GET['rutEmisor'] ?? ''));
        $fecha = $servicio->consultarFechaRecepcion($servicio->cargarCertificado(), $rutEmisor, (int) $tipo, (int) $folio);

        echo json_encode(['fechaRecepcionSii' => $fecha]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/**
 * Validación de SOLO LECTURA de un DTE recibido de un proveedor: firma
 * XMLDSig de cada DTE y del sobre, TED (CAF firmado
 * por el SII + timbre FRMT) y RUT receptor. No toca folios, CAF, el
 * certificado propio ni la emisión, y no llama al SII. Ver
 * App\DteRecibidoValidador.
 *
 * body: { xml: base64 del EnvioDTE (o DTE suelto) tal como llegó }
 * resp: { firmaValida, firmaSobreValida, tedValido, rutEmisor, rutReceptor,
 *         receptorEsPropio, documentos: [...], errores: [...] }
 */
if ($method === 'POST' && $path === '/dte/validar-recibido') {
    try {
        $config = new Config();
        if (!autorizado($config)) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        $xml = is_array($body) && is_string($body['xml'] ?? null) ? base64_decode($body['xml'], true) : false;
        if ($xml === false || $xml === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Requerido: xml (base64 del EnvioDTE recibido)']);
            return;
        }
        if (strlen($xml) > 20 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'XML demasiado grande (máx. 20 MB)']);
            return;
        }

        $validador = new DteRecibidoValidador($config->emisorRut, $config->ambiente);
        echo json_encode($validador->validar($xml));
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

http_response_code(404);
echo json_encode(['error' => 'Ruta no encontrada']);
