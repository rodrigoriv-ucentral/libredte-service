<?php

declare(strict_types=1);

namespace App\Sii;

/**
 * El RCV respondió algo que no se puede usar (HTTP != 200, cuerpo no JSON,
 * forma inesperada, detalle incompleto). Aborta toda la lectura: un RCV
 * parcial daría un F29 equivocado. El mensaje nunca incluye cookies, el
 * TOKEN ni cuerpos de respuesta.
 */
final class ErrorRcv extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $detalle)
    {
        parent::__construct($detalle);
    }
}
