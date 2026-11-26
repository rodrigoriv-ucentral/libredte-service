<?php

declare(strict_types=1);

namespace App;

/** El SII respondió 404 en la API REST de boletas (trackId o boleta desconocidos). */
final class RecursoNoEncontradoException extends \RuntimeException
{
}
