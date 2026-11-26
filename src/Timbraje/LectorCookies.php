<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Lectura del VALOR de una cookie del tarro en memoria. Separada de
 * TransporteHttp para no obligar a todos los transportes (p. ej. el de
 * captura de bin/) a implementarla.
 *
 * Uso único hoy: el RCV del SII (App\Sii\RcvLector) manda el valor de la
 * cookie TOKEN como conversationId en el cuerpo JSON. El valor es un secreto
 * de sesión: NUNCA se loguea, imprime, persiste ni se incluye en errores.
 */
interface LectorCookies
{
    /** Valor de la primera cookie con ese nombre en el tarro, o null si no está. */
    public function valorCookie(string $nombre): ?string;
}
