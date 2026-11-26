<?php

declare(strict_types=1);

namespace App\Sii;

use App\Config;
use App\Timbraje\CafSolicitudRepository;
use App\Timbraje\ErrorModoPrueba;
use App\Timbraje\ErrorSesionSii;
use App\Timbraje\ErrorTransporte;
use App\Timbraje\LectorCookies;
use App\Timbraje\ModoPrueba;
use App\Timbraje\SiiPortalSesion;
use App\Timbraje\TransporteCurl;
use App\Timbraje\TransporteHttp;

/**
 * POST /sii/rcv: lectura en SOLO LECTURA del RCV en UNA sesión con el SII.
 *
 *   confirmación → períodos → lock → presupuesto de sesiones → bitácora
 *   (sii_sesiones, motivo 'leer_rcv') → login → RcvLector::leer → logout
 *   (siempre, en finally de SiiPortalSesion) → cierre de bitácora → respuesta.
 *
 * Presupuesto: usa la MISMA bitácora y el MISMO cálculo que la solicitud de
 * CAF (CafSolicitudRepository::presupuesto / iniciarSesion / cerrarSesion),
 * porque el límite de sesiones del SII es por el RUT del dueño del
 * certificado: una lectura del RCV gasta una sesión del mismo cupo y un
 * MAX_SESIONES visto aquí bloquea también /caf/* (y viceversa). Toma el
 * mismo lock que /caf/* para que nunca haya dos sesiones abiertas a la vez.
 *
 * Respuesta: [http, RespuestaRcv]. Nunca incluye
 * cookies, el TOKEN ni cuerpos del SII; los mensajes pasan por sanear().
 */
final class RcvServicio
{
    public const CONFIRMACION = 'LEER_RCV';
    public const MOTIVO_SESION = 'leer_rcv';
    public const SERVIDOR = 'www4';
    public const PAUSA_POR_DEFECTO_MS = 1000;

    private readonly string $rutEmisor;
    private readonly string $lockPath;
    private readonly int $pausaMs;
    private readonly ?\Closure $sleeper;
    private readonly ?\Closure $log;

    /**
     * @param \Closure(): \DateTimeImmutable $reloj
     * @param array{rutEmisor: string, lockPath: string, pausaMs?: int, sleeper?: \Closure, log?: \Closure} $opciones
     */
    public function __construct(
        private readonly TransporteHttp&LectorCookies $transporte,
        private readonly CafSolicitudRepository $repo,
        private readonly \Closure $reloj,
        array $opciones,
    ) {
        $rut = $opciones['rutEmisor'] ?? null;
        if (!is_string($rut) || $rut === '') {
            throw new \InvalidArgumentException('opción rutEmisor es obligatoria');
        }
        $lock = $opciones['lockPath'] ?? null;
        if (!is_string($lock) || $lock === '') {
            throw new \InvalidArgumentException('opción lockPath es obligatoria');
        }
        $this->rutEmisor = $rut;
        $this->lockPath = $lock;
        $this->pausaMs = max(0, (int) ($opciones['pausaMs'] ?? self::PAUSA_POR_DEFECTO_MS));
        $this->sleeper = $opciones['sleeper'] ?? null;
        $this->log = $opciones['log'] ?? null;
    }

    /**
     * Cableado real: TransporteCurl con el .pfx de Config, bitácora y lock de
     * /caf/*. No disponible en ModoPrueba: el transporte falso no sabe del RCV
     * y su presupuesto relajado no debe usarse contra el SII real.
     *
     * @throws ErrorModoPrueba
     */
    public static function desdeConfig(Config $config): self
    {
        ModoPrueba::exigirSeguro($config);
        if (ModoPrueba::activo()) {
            throw new ErrorModoPrueba('La lectura del RCV no está disponible en el modo de prueba del timbraje.');
        }
        $base = dirname($config->cafSolicitudesDb);
        $v = getenv('LIBREDTE_RCV_PAUSA_MS');
        return new self(
            new TransporteCurl($config->certPath, static fn (): string => $config->resolveCertPass()),
            new CafSolicitudRepository($config->cafSolicitudesDb),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')),
            [
                'rutEmisor' => $config->emisorRut,
                'lockPath' => $base . '/caf_solicitud.lock', // el mismo de CafModulo
                'pausaMs' => $v !== false && $v !== '' && ctype_digit($v) ? (int) $v : self::PAUSA_POR_DEFECTO_MS,
                'log' => static function (string $evento, array $contexto): void {
                    error_log('[rcv] ' . $evento . ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                },
            ]
        );
    }

    /**
     * @param array<string, mixed> $pedido {confirmacion: "LEER_RCV", periodos: ["AAAA-MM", ...]}
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function leer(array $pedido): array
    {
        if (($pedido['confirmacion'] ?? null) !== self::CONFIRMACION) {
            return self::fallo(428, 'CONFIRMACION_REQUERIDA', 'Falta escribir ' . self::CONFIRMACION . '.');
        }
        try {
            $periodos = RcvLector::validarPeriodos($pedido['periodos'] ?? null, $this->ahora());
        } catch (\InvalidArgumentException $e) {
            return self::fallo(400, 'ENTRADA_INVALIDA', $e->getMessage());
        }
        return $this->conLock(fn (): array => $this->leerConLock($periodos));
    }

    /** @param list<string> $periodos */
    private function leerConLock(array $periodos): array
    {
        $p = $this->repo->presupuesto($this->ahora());
        if (!$p['permitido']) {
            $this->registrar('rcv_sin_presupuesto', ['motivo' => $p['motivo']]);
            return self::fallo(423, 'PRESUPUESTO_SESIONES', (string) $p['motivo'], null, ['presupuesto' => $p]);
        }

        $sesionId = $this->repo->iniciarSesion(self::MOTIVO_SESION, self::SERVIDOR, $this->ahora());
        $this->registrar('sii_sesion_inicio', ['sesionId' => $sesionId, 'motivo' => self::MOTIVO_SESION, 'periodos' => $periodos]);

        $sesion = new SiiPortalSesion($this->transporte, $this->reloj);
        $lector = new RcvLector($this->transporte, $this->rutEmisor, $this->reloj, $this->pausaMs, $this->sleeper);
        $resultado = null;
        $error = null;     // código para sii_sesiones (MAX_SESIONES bloquea el presupuesto 2 h)
        $codigo = null;    // código de la respuesta
        $detalle = null;
        $http = 200;
        try {
            $resultado = $sesion->ejecutar(RcvLector::URL_APP, static fn (SiiPortalSesion $s): array => $lector->leer($s, $periodos));
        } catch (ErrorSesionSii $e) {
            $error = $e->codigo;
            $codigo = 'ERROR_SESION';
            $detalle = $e->getMessage();
            $http = $e->codigo === 'MAX_SESIONES' ? 423 : 502;
        } catch (ErrorRcv $e) {
            $error = $codigo = $e->codigo;
            $detalle = $e->getMessage();
            $http = 502;
        } catch (ErrorTransporte $e) {
            $error = $codigo = 'SII_NO_RESPONDE';
            $detalle = $e->getMessage();
            $http = $e->tiempoAgotado ? 504 : 502;
        } catch (\Throwable $e) {
            $error = $codigo = 'ERROR_INTERNO';
            $detalle = 'Error interno al leer el RCV (' . get_class($e) . ').';
            $http = 500;
        }

        $st = $sesion->estado();
        try {
            $this->repo->cerrarSesion($sesionId, $st['loginOk'], $st['logoutOk'], $error, $this->ahora());
        } catch (\Throwable) {
            $this->registrar('sii_sesion_bitacora_fallida', ['sesionId' => $sesionId]);
        }
        $this->registrar('sii_sesion_fin', [
            'sesionId' => $sesionId, 'loginOk' => $st['loginOk'], 'logoutOk' => $st['logoutOk'], 'error' => $error,
        ]);

        $estadoSesion = ['loginOk' => $st['loginOk'], 'logoutOk' => $st['logoutOk'], 'error' => $error];
        if ($resultado === null) {
            return self::fallo($http, (string) $codigo, $detalle ?? 'No se pudo leer el RCV.', $estadoSesion);
        }
        return [200, ['ok' => true, 'periodos' => $resultado, 'sesion' => $estadoSesion]];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function fallo(int $http, string $codigo, string $mensaje, ?array $sesion = null, array $extra = []): array
    {
        $cuerpo = [
            'ok' => false,
            'periodos' => [],
            'error' => CafSolicitudRepository::sanear($mensaje) ?? 'Error',
            'codigo' => $codigo,
        ];
        if ($sesion !== null) {
            $cuerpo['sesion'] = $sesion;
        }
        return [$http, $cuerpo + $extra];
    }

    private function conLock(\Closure $fn): array
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $fh = @fopen($this->lockPath, 'c');
        if ($fh === false) {
            return self::fallo(500, 'LOCK_NO_DISPONIBLE', 'No se pudo tomar el lock de sesiones con el SII.');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return self::fallo(409, 'EN_CURSO', 'Hay otra operación con el SII en curso (CAF o RCV). Intenta en unos minutos.');
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function ahora(): \DateTimeImmutable
    {
        return ($this->reloj)();
    }

    /** Solo metadatos (ids, códigos, períodos): nunca cookies, TOKEN ni cuerpos. */
    private function registrar(string $evento, array $contexto): void
    {
        if ($this->log === null) {
            return;
        }
        try {
            ($this->log)($evento, array_map(static fn ($v) => is_string($v) ? CafSolicitudRepository::sanear($v, 200) : $v, $contexto));
        } catch (\Throwable) {
        }
    }
}
