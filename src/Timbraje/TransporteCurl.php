<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Transporte real con curl y certificado de cliente TLS (el .pfx de la
 * empresa). NO se usa en las pruebas: ninguna prueba hace red.
 *
 * - El certificado se lee del .pfx al reiniciar() y se entrega a curl como
 *   blob en memoria (CURLOPT_SSLCERT_BLOB / CURLOPT_SSLKEY_BLOB): la llave
 *   nunca se escribe en disco. cerrar() descarta el material.
 * - Cookies solo en memoria (CURLOPT_COOKIEFILE = '').
 * - Solo https y solo hosts *.sii.cl: el certificado no se presenta a nadie más.
 * - Sin seguir redirecciones (igual que los scripts curl que funcionaron el
 *   12/13-10): la cabecera Location se devuelve para detectar rebotes a homer.
 * - Sin reintentos, tampoco los implícitos de libcurl: una conexión nueva por
 *   petición (ver opcionesPeticion).
 * - Los mensajes de error no incluyen la ruta del .pfx, la clave, cookies ni
 *   cuerpos.
 */
final class TransporteCurl implements TransporteHttp, LectorCookies
{
    private ?\CurlHandle $ch = null;
    private string $certPem = '';
    private string $llavePem = '';
    private ?string $ubicacion = null;
    private string $tipoContenido = '';

    /**
     * @param \Closure(): string $clavePfx Entrega la clave del .pfx (p. ej. Config::resolveCertPass).
     */
    public function __construct(
        private readonly string $rutaPfx,
        #[\SensitiveParameter] private readonly \Closure $clavePfx,
        private readonly int $segundosConexion = 10,
        private readonly int $segundosTotal = 30,
    ) {
    }

    public function reiniciar(): void
    {
        $this->cerrar();
        if (!defined('CURLOPT_SSLCERT_BLOB') || !defined('CURLOPT_SSLKEY_BLOB')) {
            throw new ErrorTransporte('Esta versión de PHP/curl no admite certificados en memoria (CURLOPT_SSLCERT_BLOB).');
        }
        $this->cargarCertificado();

        $ch = curl_init();
        if ($ch === false) {
            throw new ErrorTransporte('No se pudo iniciar curl.');
        }
        $protocolos = defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_REDIR_PROTOCOLS_STR => 'https']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];
        $ok = curl_setopt_array($ch, $protocolos + [
            CURLOPT_COOKIEFILE => '',          // motor de cookies en memoria, tarro vacío
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->segundosConexion,
            CURLOPT_TIMEOUT => $this->segundosTotal,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT_BLOB => $this->certPem,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY_BLOB => $this->llavePem,
            CURLOPT_SSLKEYTYPE => 'PEM',
            // Mismo agente que los scripts curl que ya funcionaron con el portal.
            CURLOPT_USERAGENT => 'curl/' . (curl_version()['version'] ?? '8'),
            CURLOPT_HEADERFUNCTION => function ($h, string $linea): int {
                if (preg_match('/^Location:\s*(.+)$/i', trim($linea), $m) === 1) {
                    $this->ubicacion = trim($m[1]);
                } elseif (preg_match('/^Content-Type:\s*(.+)$/i', trim($linea), $m) === 1) {
                    $this->tipoContenido = trim($m[1]);
                }
                return strlen($linea);
            },
        ]);
        if (!$ok) {
            $this->cerrar();
            throw new ErrorTransporte('No se pudo configurar curl con el certificado.');
        }
        $this->ch = $ch;
    }

    public function enviar(string $metodo, string $url, ?string $cuerpo = null, array $cabeceras = []): RespuestaHttp
    {
        if ($this->ch === null) {
            throw new ErrorTransporte('Transporte no iniciado (falta reiniciar()).');
        }
        $partes = parse_url($url);
        $host = strtolower((string) ($partes['host'] ?? ''));
        if (($partes['scheme'] ?? '') !== 'https' || !str_ends_with($host, '.sii.cl')) {
            throw new ErrorTransporte('Destino no permitido: solo https://*.sii.cl.');
        }
        if ($metodo !== 'GET' && $metodo !== 'POST') {
            throw new ErrorTransporte('Método no permitido.');
        }

        $this->ubicacion = null;
        $this->tipoContenido = '';
        curl_setopt_array($this->ch, self::opcionesPeticion($metodo, $url, $cuerpo, $cabeceras));

        $res = curl_exec($this->ch);
        if (!is_string($res)) {
            $errno = curl_errno($this->ch);
            // curl_error() describe la falla de red; no incluye cookies ni cuerpos.
            throw new ErrorTransporte(
                'Falla de red con el SII (curl ' . $errno . ': ' . mb_substr(curl_error($this->ch), 0, 200) . ').',
                $errno === CURLE_OPERATION_TIMEDOUT
            );
        }
        return new RespuestaHttp(
            (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE),
            $res,
            $url,
            $this->ubicacion,
            $this->tipoContenido
        );
    }

    /**
     * Opciones curl de UNA petición. Cada petición va por una conexión NUEVA que
     * no se reutiliza (FRESH_CONNECT + FORBID_REUSE): libcurl reenvía por su
     * cuenta una petición, POST incluido, si la mandó por una conexión
     * keep-alive reutilizada y esta muere sin devolver nada. Con of_genera_folio
     * ese reenvío oculto sería una segunda generación de folios. Las cookies
     * siguen en el handle, así que la sesión del SII no cambia (los scripts
     * curl del 12/13-10 también usaban una conexión por petición).
     *
     * @param list<string> $cabeceras
     * @return array<int, mixed>
     */
    public static function opcionesPeticion(string $metodo, string $url, ?string $cuerpo, array $cabeceras): array
    {
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ];
        if ($metodo === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $cuerpo ?? '';
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        return $opts;
    }

    public function agregarCookie(string $dominio, string $nombre, string $valor, int $expira, string $ruta = '/', bool $segura = true): void
    {
        if ($this->ch === null) {
            throw new ErrorTransporte('Transporte no iniciado (falta reiniciar()).');
        }
        // Formato Netscape: dominio, incluye subdominios, ruta, segura, expira, nombre, valor.
        $linea = implode("\t", [$dominio, 'TRUE', $ruta, $segura ? 'TRUE' : 'FALSE', (string) $expira, $nombre, $valor]);
        curl_setopt($this->ch, CURLOPT_COOKIELIST, $linea);
    }

    public function tieneCookie(string $nombre): bool
    {
        if ($this->ch === null) {
            return false;
        }
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [] as $linea) {
            $p = explode("\t", (string) $linea);
            if (($p[5] ?? null) === $nombre) {
                return true;
            }
        }
        return false;
    }

    /** Valor de la cookie (p. ej. TOKEN). Secreto de sesión: nunca se loguea ni se devuelve en errores. */
    public function valorCookie(string $nombre): ?string
    {
        if ($this->ch === null) {
            return null;
        }
        // Formato Netscape: dominio, subdominios, ruta, segura, expira, nombre (5), valor (6).
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: [] as $linea) {
            $p = explode("\t", (string) $linea);
            if (($p[5] ?? null) === $nombre) {
                return (string) ($p[6] ?? '');
            }
        }
        return null;
    }

    public function cerrar(): void
    {
        try {
            if ($this->ch !== null) {
                @curl_setopt($this->ch, CURLOPT_COOKIELIST, 'ALL'); // borra el tarro
            }
        } catch (\Throwable) {
        }
        $this->ch = null; // curl_close() es no-op desde PHP 8; se libera al soltar la referencia
        $this->certPem = '';
        $this->llavePem = '';
        $this->ubicacion = null;
    }

    private function cargarCertificado(): void
    {
        $pfx = is_file($this->rutaPfx) ? @file_get_contents($this->rutaPfx) : false;
        if ($pfx === false || $pfx === '') {
            throw new ErrorTransporte('No se pudo leer el certificado digital de la empresa.');
        }
        $certs = [];
        if (!@openssl_pkcs12_read($pfx, $certs, ($this->clavePfx)())) {
            while (openssl_error_string() !== false) {
                // vacía la cola de errores de OpenSSL sin exponerla
            }
            throw new ErrorTransporte('No se pudo abrir el certificado digital (¿clave incorrecta o formato no soportado?).');
        }
        $this->certPem = (string) ($certs['cert'] ?? '') . implode('', (array) ($certs['extracerts'] ?? []));
        $this->llavePem = (string) ($certs['pkey'] ?? '');
        if ($this->certPem === '' || $this->llavePem === '') {
            $this->certPem = $this->llavePem = '';
            throw new ErrorTransporte('El certificado digital no trae certificado y llave.');
        }
    }
}
