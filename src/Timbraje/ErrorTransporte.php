<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Falla de red o tiempo agotado. Tras of_genera_folio significa "no se sabe
 * si el SII asignó folios": el llamador lo trata como 'incierto' y NUNCA
 * reintenta.
 */
final class ErrorTransporte extends \RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $tiempoAgotado = false, ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, 0, $previa);
    }
}
