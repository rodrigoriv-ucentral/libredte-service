<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * UNA sesión autenticada con certificado en el portal del SII, para UNA
 * operación. Regla dura: cada login se cierra con logout, pase lo que pase;
 * si no, el SII bloquea el RUT del dueño ~2 h ("máximo de sesiones
 * autenticadas", 01.01.215.500.720.27).
 *
 * Uso único:
 *   $s = new SiiPortalSesion($transporte, $reloj);
 *   $s->ejecutar($urlDestino, function (SiiPortalSesion $s) { ... $s->get/post ... });
 *
 * - login() es privado: solo ocurre dentro de ejecutar(), que hace logout en
 *   `finally` (también si el login o la operación fallan o lanzan cualquier
 *   cosa). logout() nunca lanza.
 * - Un objeto = un login. Llamar ejecutar() otra vez lanza LogicException:
 *   no hay relogin automático.
 * - Si una página dice "no autenticado" o "máximo de sesiones", se lanza
 *   ErrorSesionSii y la sesión queda inválida (sin reintentar).
 *
 * Login (probado con curl 12/13-10): tarro limpio → POST
 * https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?<url> con
 * referencia=<url> y certificado de cliente; luego la cookie
 * NETSCAPE_LIVEWIRE.locexp (+2 h, .sii.cl) que en el navegador crea un JS; el
 * login es válido si el tarro tiene la cookie TOKEN.
 * Logout: GET https://zeus.sii.cl/cgi_AUT2000/autTermino.cgi con el mismo tarro.
 */
final class SiiPortalSesion
{
    public const URL_LOGIN = 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi';
    public const URL_LOGOUT = 'https://zeus.sii.cl/cgi_AUT2000/autTermino.cgi';
    public const COOKIE_LIVEWIRE = 'NETSCAPE_LIVEWIRE.locexp';
    public const COOKIE_TOKEN = 'TOKEN';
    private const DURACION_SEGUNDOS = 7200;
    private const INTENTOS_LOGOUT = 2;

    private \Closure $reloj;
    private bool $usada = false;
    private bool $loginIntentado = false;
    private bool $loginOk = false;
    private bool $logoutIntentado = false;
    private bool $logoutOk = false;
    private bool $invalida = false;
    private ?string $codigoError = null;
    private ?string $ultimaUrl = null;

    /** @param (\Closure(): \DateTimeImmutable)|null $reloj */
    public function __construct(private readonly TransporteHttp $transporte, ?\Closure $reloj = null)
    {
        $this->reloj = $reloj ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /**
     * Login → $operacion($this) → logout (siempre, en finally).
     *
     * @template T
     * @param callable(SiiPortalSesion): T $operacion
     * @return T
     */
    public function ejecutar(string $urlDestino, callable $operacion): mixed
    {
        if ($this->usada) {
            throw new \LogicException('Esta sesión del SII ya se usó: una sola sesión (un login) por operación, sin relogin.');
        }
        $this->usada = true;
        try {
            $this->login($urlDestino);
            return $operacion($this);
        } finally {
            $this->logout();
        }
    }

    public function get(string $url): RespuestaHttp
    {
        return $this->pedir('GET', $url, null);
    }

    /** @param array<string, string> $campos Se envían tal cual, en ISO-8859-1 (como el navegador). */
    public function post(string $url, array $campos): RespuestaHttp
    {
        return $this->pedir('POST', $url, self::codificar($campos));
    }

    /**
     * POST con cuerpo JSON ya serializado (servicios JSON del portal, p. ej.
     * el RCV de www4.sii.cl). $cabeceras son líneas extra "Nombre: valor"
     * (Accept, Origin, Referer...); Content-Type y Cookie no se aceptan aquí:
     * el primero lo fija este método y las cookies viven solo en el tarro.
     * Si trae Referer, reemplaza al automático (última URL pedida).
     *
     * @param list<string> $cabeceras
     */
    public function postJson(string $url, string $json, array $cabeceras = []): RespuestaHttp
    {
        foreach ($cabeceras as $c) {
            if (preg_match('/^\s*(content-type|cookie)\s*:/i', $c) === 1) {
                throw new \LogicException('postJson no acepta cabeceras Content-Type ni Cookie.');
            }
        }
        return $this->pedir('POST', $url, $json, 'application/json', $cabeceras);
    }

    /** @return array{loginIntentado: bool, loginOk: bool, logoutIntentado: bool, logoutOk: bool, error: ?string} */
    public function estado(): array
    {
        return [
            'loginIntentado' => $this->loginIntentado,
            'loginOk' => $this->loginOk,
            'logoutIntentado' => $this->logoutIntentado,
            'logoutOk' => $this->logoutOk,
            'error' => $this->codigoError,
        ];
    }

    /** Cuerpo x-www-form-urlencoded con los valores en ISO-8859-1 (el portal es latin1). */
    public static function codificar(array $campos): string
    {
        $latin1 = [];
        foreach ($campos as $k => $v) {
            $latin1[(string) $k] = mb_convert_encoding((string) $v, 'ISO-8859-1', 'UTF-8');
        }
        return http_build_query($latin1, '', '&', PHP_QUERY_RFC1738);
    }

    private function login(string $urlDestino): void
    {
        try {
            $this->transporte->reiniciar(); // tarro limpio en cada operación
        } catch (\Throwable $e) {
            // No salió ninguna petición: no hay sesión que cerrar.
            $this->codigoError = 'LOGIN_FALLIDO';
            throw new ErrorSesionSii('LOGIN_FALLIDO', 'No se pudo preparar la conexión con el certificado: ' . $e->getMessage());
        }
        $this->loginIntentado = true; // desde aquí el logout es obligatorio
        try {
            $r = $this->transporte->enviar(
                'POST',
                self::URL_LOGIN . '?' . $urlDestino,
                http_build_query(['referencia' => $urlDestino], '', '&', PHP_QUERY_RFC1738),
                ['Content-Type: application/x-www-form-urlencoded']
            );
        } catch (ErrorTransporte $e) {
            $this->invalidar('LOGIN_FALLIDO');
            throw new ErrorSesionSii('LOGIN_FALLIDO', 'Falla de red en el login: ' . $e->getMessage());
        }
        $expira = ($this->reloj)()->getTimestamp() + self::DURACION_SEGUNDOS;
        $this->transporte->agregarCookie('.sii.cl', self::COOKIE_LIVEWIRE, gmdate('D, d M Y H:i:s', $expira) . ' GMT', $expira);

        // En el login solo se mira el bloqueo por sesiones: no hay captura de
        // la respuesta exitosa de CAutInicio y una redirección suya podría
        // confundirse con "no autenticado". La prueba de éxito es TOKEN.
        if (!$r->esXml()) {
            $e = FormularioSii::detectarError($r->cuerpo, null, $r->urlEfectiva());
            if ($e !== null && $e['codigo'] === 'MAX_SESIONES') {
                $this->invalidar('MAX_SESIONES');
                throw new ErrorSesionSii('MAX_SESIONES', $e['detalle']);
            }
        }
        if (!$this->transporte->tieneCookie(self::COOKIE_TOKEN)) {
            $this->invalidar('LOGIN_FALLIDO');
            throw new ErrorSesionSii('LOGIN_FALLIDO', 'El SII no abrió la sesión tras el login con certificado (HTTP ' . $r->estado . ').');
        }
        $this->loginOk = true;
        $this->ultimaUrl = $urlDestino;
    }

    /** Idempotente y nunca lanza. */
    private function logout(): void
    {
        if ($this->logoutIntentado) {
            return;
        }
        $this->logoutIntentado = true;
        try {
            if (!$this->loginIntentado) {
                return;
            }
            for ($i = 0; $i < self::INTENTOS_LOGOUT && !$this->logoutOk; $i++) {
                try {
                    $r = $this->transporte->enviar('GET', self::URL_LOGOUT, null, []);
                    $this->logoutOk = $r->estado > 0 && $r->estado < 500;
                    break; // hubo respuesta: no se insiste
                } catch (\Throwable) {
                    // red caída: un segundo intento (cerrar sesión es idempotente)
                }
            }
        } finally {
            try {
                $this->transporte->cerrar();
            } catch (\Throwable) {
            }
        }
    }

    /** @param list<string> $extra */
    private function pedir(string $metodo, string $url, ?string $cuerpo, string $tipoCuerpo = 'application/x-www-form-urlencoded', array $extra = []): RespuestaHttp
    {
        if (!$this->loginOk || $this->logoutIntentado) {
            throw new ErrorSesionSii('SIN_SESION', 'No hay una sesión del SII abierta.');
        }
        if ($this->invalida) {
            throw new ErrorSesionSii('SESION_INVALIDA', 'La sesión del SII quedó inválida; no se reintenta.');
        }
        $cab = [];
        if ($cuerpo !== null) {
            $cab[] = 'Content-Type: ' . $tipoCuerpo;
        }
        $refererPropio = array_filter($extra, static fn (string $c): bool => preg_match('/^\s*referer\s*:/i', $c) === 1) !== [];
        if ($this->ultimaUrl !== null && !$refererPropio) {
            $cab[] = 'Referer: ' . $this->ultimaUrl;
        }
        foreach ($extra as $c) {
            $cab[] = $c;
        }
        $r = $this->transporte->enviar($metodo, $url, $cuerpo, $cab);
        $this->ultimaUrl = $url;

        if (!$r->esXml()) {
            $e = FormularioSii::detectarError($r->cuerpo, null, $r->urlEfectiva());
            if ($e !== null && ($e['codigo'] === 'NO_AUTENTICADO' || $e['codigo'] === 'MAX_SESIONES')) {
                $this->invalidar($e['codigo']);
                throw new ErrorSesionSii($e['codigo'], $e['detalle']);
            }
        }
        return $r;
    }

    private function invalidar(string $codigo): void
    {
        $this->invalida = true;
        $this->codigoError = $codigo;
    }
}
