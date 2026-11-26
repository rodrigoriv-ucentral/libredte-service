<?php

declare(strict_types=1);

namespace App;

/**
 * Certificado rechazado antes de guardarlo. `codigo` ∈ NO_ES_PKCS12,
 * CLAVE_INCORRECTA, VENCIDO. Extiende InvalidArgumentException para que
 * cualquier llamador antiguo que la atrape siga funcionando.
 */
final class CertificadoInvalidoException extends \InvalidArgumentException
{
    public function __construct(public readonly string $codigo, string $message)
    {
        parent::__construct($message);
    }
}
