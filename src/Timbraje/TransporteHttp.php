<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Transporte HTTP del portal del SII, inyectable para poder probar todo el
 * flujo sin red (tests/lib/TransporteFalso.php).
 *
 * Reglas que toda implementación debe cumplir:
 * - Las cookies viven SOLO en memoria y se descartan en reiniciar()/cerrar().
 * - No se siguen redirecciones: la respuesta trae la cabecera Location para
 *   que el llamador detecte un rebote a homer.sii.cl (sesión no autenticada).
 * - Un error de red o un tiempo agotado se informa con ErrorTransporte. Su
 *   mensaje nunca incluye cookies, cuerpos, rutas ni claves del certificado.
 * - NUNCA reintenta por su cuenta: quien llama decide (y of_genera_folio no se
 *   reintenta jamás).
 */
interface TransporteHttp
{
    /** Descarta cookies y conexiones previas: deja un tarro de cookies limpio. */
    public function reiniciar(): void;

    /**
     * @param 'GET'|'POST' $metodo
     * @param string|null $cuerpo Cuerpo ya codificado (application/x-www-form-urlencoded).
     * @param list<string> $cabeceras Líneas "Nombre: valor".
     * @throws ErrorTransporte ante error de red o tiempo agotado.
     */
    public function enviar(string $metodo, string $url, ?string $cuerpo = null, array $cabeceras = []): RespuestaHttp;

    /** Agrega una cookie al tarro en memoria (p. ej. NETSCAPE_LIVEWIRE.locexp). */
    public function agregarCookie(string $dominio, string $nombre, string $valor, int $expira, string $ruta = '/', bool $segura = true): void;

    /** true si el tarro tiene una cookie con ese nombre (p. ej. TOKEN tras el login). */
    public function tieneCookie(string $nombre): bool;

    /** Libera la conexión y borra cookies y material del certificado. Idempotente; no lanza. */
    public function cerrar(): void;
}
