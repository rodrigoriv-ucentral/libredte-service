<?php

declare(strict_types=1);

namespace App;

/**
 * Monto en palabras para la línea "SON: ... PESOS" de la factura (CLP, sin
 * decimales). Cubre hasta 999.999.999.999; más allá no hay montos reales y se
 * devuelve null para que la plantilla omita la línea en vez de imprimir basura.
 */
final class NumeroEnPalabras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ',
        'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS',
        'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];
    private const DECENAS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];
    private const CENTENAS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    /** "TRES MILLONES QUINIENTOS DIECINUEVE MIL NOVECIENTOS NOVENTA PESOS". */
    public static function pesos(int $monto): ?string
    {
        if ($monto < 0 || $monto > 999_999_999_999) {
            return null;
        }
        if ($monto === 0) {
            return 'CERO PESOS';
        }
        if ($monto === 1) {
            return 'UN PESO';
        }
        // "tres millones DE pesos": los millones redondos llevan "de".
        $de = $monto % 1_000_000 === 0 ? ' DE' : '';

        return self::texto($monto) . $de . ' PESOS';
    }

    /** Texto de un entero positivo < 10^12, con "UNO" apocopado ("UN MIL" no existe: "MIL"). */
    private static function texto(int $n): string
    {
        $partes = [];

        if ($n >= 1_000_000) {
            $millones = intdiv($n, 1_000_000);
            $partes[] = $millones === 1 ? 'UN MILLÓN' : self::texto($millones) . ' MILLONES';
            $n %= 1_000_000;
        }
        if ($n >= 1_000) {
            $miles = intdiv($n, 1_000);
            $partes[] = $miles === 1 ? 'MIL' : self::texto($miles) . ' MIL';
            $n %= 1_000;
        }
        if ($n > 0) {
            $partes[] = self::centenas($n);
        }

        $texto = implode(' ', $partes);

        // Apócope delante de un sustantivo: "VEINTIÚN MILLONES", "TREINTA Y UN MIL", "UN PESO".
        return preg_replace(['/VEINTIUNO$/u', '/\bUNO$/u'], ['VEINTIÚN', 'UN'], $texto) ?? $texto;
    }

    /** 1..999. */
    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $texto = '';
        $c = intdiv($n, 100);
        if ($c > 0) {
            $texto = self::CENTENAS[$c];
        }
        $resto = $n % 100;
        if ($resto === 0) {
            return $texto;
        }
        $texto .= $texto === '' ? '' : ' ';
        if ($resto < 30) {
            return $texto . self::UNIDADES[$resto];
        }
        $d = intdiv($resto, 10);
        $u = $resto % 10;
        return $texto . self::DECENAS[$d] . ($u > 0 ? ' Y ' . self::UNIDADES[$u] : '');
    }
}
