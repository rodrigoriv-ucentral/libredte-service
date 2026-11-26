<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Respuesta cruda del portal. El cuerpo puede ser un CAF (con la clave
 * privada RSASK): nunca se registra ni se serializa; __debugInfo lo oculta
 * de var_dump/print_r.
 */
final class RespuestaHttp
{
    public function __construct(
        public readonly int $estado,
        public readonly string $cuerpo,
        public readonly string $url,
        public readonly ?string $ubicacion = null,
        public readonly string $tipoContenido = '',
    ) {
    }

    /** URL a la que apunta la respuesta: Location si hubo redirección, si no la pedida. */
    public function urlEfectiva(): string
    {
        return $this->ubicacion ?? $this->url;
    }

    /** true si el cuerpo parece XML (un CAF), para no pasarlo por detectores de HTML. */
    public function esXml(): bool
    {
        $inicio = ltrim(substr($this->cuerpo, 0, 512), "\xEF\xBB\xBF \t\r\n");
        return str_starts_with($inicio, '<?xml') || str_starts_with($inicio, '<AUTORIZACION');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'estado' => $this->estado,
            'url' => $this->url,
            'ubicacion' => $this->ubicacion,
            'tipoContenido' => $this->tipoContenido,
            'bytes' => strlen($this->cuerpo),
        ];
    }
}
