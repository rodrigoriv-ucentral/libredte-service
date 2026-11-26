<?php

declare(strict_types=1);

namespace App;

/** Otro proceso tiene reservado el folio de esta clave de idempotencia (lease vigente). */
final class EmisionEnCursoException extends \RuntimeException
{
    public function __construct(
        string $message = 'Ya hay una emisión en curso para este documento. Espera un momento y recarga antes de reintentar.'
    ) {
        parent::__construct($message);
    }
}
