<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * El modo de prueba del timbraje (LIBREDTE_TIMBRAJE_FAKE) está activo donde no
 * debe, o no se puede armar con seguridad. Las rutas /caf/* responden 503.
 */
final class ErrorModoPrueba extends \RuntimeException
{
}
