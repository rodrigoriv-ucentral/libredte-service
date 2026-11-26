<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Un paso del portal de timbraje no dio lo esperado ANTES del punto
 * irreversible (of_genera_folio): no hay efecto en el SII. Códigos propios
 * (MAX_AUTOR, AUTORIZA_MENOS, CONFIRMACION_INESPERADA, FECHA_INCOMPLETA,
 * FECHA_DISTINTA, FOLIO_SOLAPADO, TIPO_DISTINTO, RUT_DISTINTO, ...) o los de
 * FormularioSii::detectarError (MAX_AUTORIZADO, DTE_OFGA, DESCONOCIDO, ...).
 */
final class ErrorTimbraje extends \RuntimeException
{
    public function __construct(public readonly string $codigo, string $detalle, public readonly ?string $codigoSii = null)
    {
        parent::__construct($detalle);
    }
}
