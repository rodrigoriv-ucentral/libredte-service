<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Problema de la sesión con el SII que obliga a abortar TODA la operación
 * (sin relogin): LOGIN_FALLIDO, NO_AUTENTICADO, MAX_SESIONES, SIN_SESION,
 * SESION_INVALIDA.
 */
final class ErrorSesionSii extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $detalle)
    {
        parent::__construct($detalle);
    }
}
