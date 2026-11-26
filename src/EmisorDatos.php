<?php

declare(strict_types=1);

namespace App;

/**
 * Datos del emisor editables desde el panel (Configuración → SII). Se guardan
 * en un JSON dentro del volumen persistente y pisan a las variables de entorno.
 *
 * RUT, razón social y actividades económicas NO están aquí: van atados al
 * certificado/CAF y a la inscripción en el SII, se siguen leyendo del .env.
 * Giro, dirección, comuna, ciudad, teléfono y correo sí van dentro del XML
 * firmado, pero son texto libre del documento (deben coincidir con lo
 * declarado en el SII, eso lo controla quien los edita).
 */
final class EmisorDatos
{
    /** Largos máximos del esquema DTE del SII (los datos bancarios solo se imprimen). */
    private const CAMPOS = [
        'giro' => 80,
        'direccion' => 70,
        'comuna' => 20,
        'ciudad' => 20,
        'telefono' => 20,
        'email' => 80,
    ];
    private const CAMPOS_BANCO = [
        'banco' => 60,
        'tipo' => 40,
        'numero' => 30,
        'email' => 80,
    ];

    public static function path(): string
    {
        $path = getenv('LIBREDTE_EMISOR_DATOS_PATH');
        return $path !== false && $path !== '' ? $path : '/data/db/emisor-datos.json';
    }

    /**
     * Lo guardado desde el panel, o [] si nunca se editó.
     *
     * @return array<string, mixed>
     */
    public static function cargar(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $datos = json_decode((string) file_get_contents($path), true);
        return is_array($datos) ? $datos : [];
    }

    /**
     * Valida y persiste. Devuelve lo guardado.
     *
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    public static function guardar(array $entrada): array
    {
        $datos = [];
        foreach (self::CAMPOS as $campo => $max) {
            $datos[$campo] = self::texto($entrada[$campo] ?? '', $campo, $max);
        }
        foreach (['giro', 'direccion', 'comuna'] as $obligatorio) {
            if ($datos[$obligatorio] === '') {
                throw new \InvalidArgumentException("El campo {$obligatorio} es obligatorio");
            }
        }
        if ($datos['email'] !== '' && filter_var($datos['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('El e-mail del emisor no es válido');
        }

        $banco = is_array($entrada['cuentaBancaria'] ?? null) ? $entrada['cuentaBancaria'] : [];
        $datos['cuentaBancaria'] = [];
        foreach (self::CAMPOS_BANCO as $campo => $max) {
            $datos['cuentaBancaria'][$campo] = self::texto($banco[$campo] ?? '', "cuentaBancaria.{$campo}", $max);
        }
        $correoBanco = $datos['cuentaBancaria']['email'];
        if ($correoBanco !== '' && filter_var($correoBanco, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('El e-mail de los datos bancarios no es válido');
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio {$dir}");
        }
        // Escritura atómica: un DTE emitido en paralelo nunca lee un JSON a medias.
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $path);

        return $datos;
    }

    private static function texto(mixed $valor, string $campo, int $max): string
    {
        if (!is_string($valor) && !is_numeric($valor)) {
            throw new \InvalidArgumentException("El campo {$campo} debe ser texto");
        }
        $valor = trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
        if (mb_strlen($valor) > $max) {
            throw new \InvalidArgumentException("El campo {$campo} admite como máximo {$max} caracteres");
        }
        return $valor;
    }
}
