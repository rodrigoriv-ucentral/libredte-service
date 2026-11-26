<?php

declare(strict_types=1);

namespace App;

use Derafu\Support\Hydrator;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorProviderInterface;

/**
 * Datos reales del emisor (los de Config). Reemplaza al FakeEmisorProvider de la
 * librería, que completa lo que falta con datos de SASCO SpA (teléfono, correo,
 * sucursal 123456 y vendedor "libredte") y los deja dentro del XML firmado.
 * Lo que no se conoce queda en null para que no se imprima nada.
 */
final class EmisorProvider implements EmisorProviderInterface
{
    public function __construct(private EmisorFactoryInterface $emisorFactory)
    {
    }

    public function retrieve(int|string|EmisorInterface $emisor): EmisorInterface
    {
        if (is_int($emisor) || is_string($emisor)) {
            $emisor = $this->emisorFactory->create(['rut' => $emisor]);
        }

        $config = new Config();

        return Hydrator::hydrate($emisor, [
            'razon_social' => $config->emisorRazonSocial,
            'giro' => $config->emisorGiro,
            'actividad_economica' => $config->emisorActeco !== '' ? (int) $config->emisorActeco : null,
            'email' => $config->emisorEmail,
            'telefono' => $config->emisorTelefono !== '' ? $config->emisorTelefono : null,
            'direccion' => $config->emisorDireccion,
            'comuna' => $config->emisorComuna,
        ]);
    }
}
