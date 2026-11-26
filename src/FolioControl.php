<?php

declare(strict_types=1);

namespace App;

use libredte\lib\Core\Application;

/**
 * Conciliación de folios: cruza los rangos autorizados por los CAF de
 * {cafDir} con lo que FolioRepository registró como consumido. Es de solo
 * lectura y no necesita certificado ni conexión al SII.
 */
final class FolioControl
{
    /** Tipos cuyo CAF vence 180 días después de FA (igual que Caf::hasVencimiento). */
    private const TIPOS_CON_VENCIMIENTO = [33, 43, 46, 56, 61];

    public function __construct(
        private readonly Config $config,
        private readonly FolioRepository $folios,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> uno por tipo de DTE con CAF o con folios usados
     */
    public function estado(): array
    {
        $rangosPorTipo = $this->rangosDeCaf();
        $resumen = $this->folios->resumen();

        $tipos = array_unique(array_merge(array_keys($rangosPorTipo), array_keys($resumen)));
        sort($tipos);

        $out = [];
        foreach ($tipos as $tipo) {
            $rangos = $rangosPorTipo[$tipo] ?? [];
            $ultimo = $resumen[$tipo]['ultimoFolioUsado'] ?? 0;

            // Mismo criterio que Caf::isVigente() de la librería: vence a las 00:00 del día "vence".
            $hoy = date('Y-m-d');
            $autorizados = 0;
            $disponibles = 0;
            $disponiblesVencidos = 0;
            foreach ($rangos as &$r) {
                $r['vencido'] = $r['vence'] !== null && $hoy >= $r['vence'];
                $autorizados += $r['hasta'] - $r['desde'] + 1;
                $sinUsar = max(0, $r['hasta'] - max($ultimo, $r['desde'] - 1));
                if ($r['vencido']) {
                    $disponiblesVencidos += $sinUsar;
                } else {
                    $disponibles += $sinUsar;
                }
            }
            unset($r);

            $out[] = [
                'tipoDte' => $tipo,
                // fa/vence/idk/archivo se agregan a desde/hasta (campos nuevos, retrocompatibles):
                // vence = FA + 180 días para 33/43/46/56/61 y null para el resto (p. ej. 39).
                'rangos' => array_map(static fn (array $r): array => [
                    'desde' => $r['desde'],
                    'hasta' => $r['hasta'],
                    'fa' => $r['fa'],
                    'vence' => $r['vence'],
                    'idk' => $r['idk'],
                    'archivo' => $r['archivo'],
                    // true si el CAF ya venció: la emisión lo descarta y sus folios no cuentan como disponibles.
                    'vencido' => $r['vencido'],
                ], $rangos),
                'autorizados' => $autorizados,
                'ultimoFolioUsado' => $ultimo,
                // Solo rangos no vencidos.
                'disponibles' => $disponibles,
                // Folios sin usar de rangos vencidos (hay que anularlos en el SII).
                'disponiblesVencidos' => $disponiblesVencidos,
                'timbradosConClave' => $resumen[$tipo]['timbrados'] ?? 0,
                // Folios reservados que no llegaron a timbrarse: el SII no los conoce.
                'reservadosSinTimbrar' => $resumen[$tipo]['reservadosSinTimbrar'] ?? [],
                // Hay folios usados sin CAF que los cubra: algo se movió a mano.
                'sinCaf' => $rangos === [] && $ultimo > 0,
            ];
        }
        return $out;
    }

    /**
     * Mayor folio ya comprometido localmente para el tipo: el más alto entre
     * el "hasta" de los CAF cargados y el último folio usado (0 si no hay
     * nada). El flujo de solicitud de CAF lo usa para rechazar un rango que
     * pise folios ya cargados o usados (TimbrajeClient::validarPropuesta).
     */
    public function maxFolioLocal(int $tipo): int
    {
        $max = $this->folios->resumen()[$tipo]['ultimoFolioUsado'] ?? 0;
        foreach ($this->rangosDeCaf()[$tipo] ?? [] as $r) {
            $max = max($max, $r['hasta']);
        }
        return (int) $max;
    }

    /**
     * Lee {tipo}.xml y {tipo}-*.xml (los mismos que usa DteEmisor) del
     * directorio de CAF del ambiente actual.
     *
     * @return array<int, array<int, array{desde: int, hasta: int, fa: ?string, vence: ?string, idk: ?int, archivo: string}>>
     */
    private function rangosDeCaf(): array
    {
        $dir = rtrim($this->config->cafDir, '/');
        $loader = null;
        try {
            $loader = Application::getInstance()
                ->getPackageRegistry()
                ->getBillingPackage()
                ->getIdentifierComponent()
                ->getCafLoaderWorker();
        } catch (\Throwable) {
            // Sin la librería se cae a leer FA/IDK directo del XML.
        }

        $out = [];
        foreach (glob("{$dir}/*.xml") ?: [] as $ruta) {
            $archivo = basename($ruta);
            if (preg_match('/^\d+(-[^\/]+)?\.xml$/', $archivo) !== 1) {
                continue;
            }
            $xml = @simplexml_load_file($ruta);
            if ($xml === false || !isset($xml->CAF->DA->TD, $xml->CAF->DA->RNG->D, $xml->CAF->DA->RNG->H)) {
                continue;
            }
            $tipo = (int) $xml->CAF->DA->TD;
            $fa = isset($xml->CAF->DA->FA) ? trim((string) $xml->CAF->DA->FA) : null;
            $idk = isset($xml->CAF->DA->IDK) ? (int) $xml->CAF->DA->IDK : null;
            $vence = null;
            $resuelto = false;
            if ($loader !== null) {
                try {
                    $caf = $loader->load((string) file_get_contents($ruta));
                    $fa = $caf->getFechaAutorizacion();
                    $idk = $caf->getIdk();
                    $vence = $caf->getFechaVencimiento();
                    $resuelto = true;
                } catch (\Throwable) {
                    // Se usan los valores leídos directo del XML (abajo).
                }
            }
            if (!$resuelto) {
                $vence = ($fa !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fa) === 1 && in_array($tipo, self::TIPOS_CON_VENCIMIENTO, true))
                    ? date('Y-m-d', strtotime($fa . ' + 180 days'))
                    : null;
            }
            $out[$tipo][] = [
                'desde' => (int) $xml->CAF->DA->RNG->D,
                'hasta' => (int) $xml->CAF->DA->RNG->H,
                'fa' => $fa === '' ? null : $fa,
                'vence' => $vence,
                'idk' => $idk,
                'archivo' => $archivo,
            ];
        }
        foreach ($out as &$rangos) {
            usort($rangos, static fn (array $a, array $b): int => $a['desde'] <=> $b['desde']);
        }
        return $out;
    }
}
