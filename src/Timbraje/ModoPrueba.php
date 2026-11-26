<?php

declare(strict_types=1);

namespace App\Timbraje;

use App\Config;
use Tests\Timbraje\TransporteFalso;

/**
 * Modo de prueba del timbraje (LIBREDTE_TIMBRAJE_FAKE): reemplaza el transporte
 * real por tests/lib/TransporteFalso.php (fixtures anonimizadas del portal) y
 * el CafInstalador por uno que escribe el CAF SINTÉTICO (sin firma) en la
 * carpeta de CAF, para ejercitar las rutas /caf/* con curl SIN red, SIN
 * certificado y SIN hablar con el SII.
 *
 * Seguridad (se exige ANTES de armar nada; si falla → ErrorModoPrueba → 503):
 *  - solo con LIBREDTE_AMBIENTE=certificacion (nunca en producción);
 *  - exige LIBREDTE_CAF_DIR, LIBREDTE_DB_PATH y LIBREDTE_CAF_SOLICITUDES_DB
 *    explícitos y distintos de los valores por defecto del despliegue real, para
 *    que un CAF falso jamás caiga en /data/caf ni la bitácora en /data/db;
 *  - nunca debe figurar en docker-compose*.yml ni en .env*.
 *
 * El reloj queda fijo en 13-10-2026 08:40 (America/Santiago): el día de las
 * capturas, porque TimbrajeClient exige que la fecha propuesta sea la de hoy.
 * El presupuesto de sesiones se relaja (max 1000, sin espaciado) porque con
 * reloj fijo cualquier segunda sesión caería en el espaciado mínimo. La pausa
 * entre peticiones (v1.1) queda en 0 ms: quien prueba a mano con curl no
 * necesita esperar 3 s entre cada paso.
 */
final class ModoPrueba
{
    public const VARIABLE = 'LIBREDTE_TIMBRAJE_FAKE';

    public static function activo(): bool
    {
        $v = getenv(self::VARIABLE);
        return $v !== false && $v !== '' && $v !== '0';
    }

    /** @throws ErrorModoPrueba si está activo donde no debe. No hace I/O. */
    public static function exigirSeguro(Config $config): void
    {
        if (!self::activo()) {
            return;
        }
        if ($config->ambiente !== 'certificacion') {
            throw new ErrorModoPrueba('El modo de prueba del timbraje (' . self::VARIABLE . ') no se permite fuera de certificación.');
        }
        $reales = [
            'LIBREDTE_CAF_DIR' => [$config->cafDir, ['/data/caf', '/data/caf/produccion']],
            'LIBREDTE_DB_PATH' => [$config->dbPath, ['/data/db/folios.sqlite', '/data/db/produccion/folios.sqlite']],
            'LIBREDTE_CAF_SOLICITUDES_DB' => [$config->cafSolicitudesDb, [CafSolicitudRepository::RUTA_POR_DEFECTO]],
        ];
        foreach ($reales as $variable => [$valor, $prohibidos]) {
            $explicita = getenv($variable);
            if ($explicita === false || $explicita === '' || in_array(rtrim($valor, '/'), $prohibidos, true)) {
                throw new ErrorModoPrueba("El modo de prueba del timbraje exige {$variable} explícita y distinta de la ruta real del despliegue.");
            }
        }
    }

    /**
     * @return array{transporte: TransporteHttp, instalador: \Closure, reloj: \Closure, presupuesto: array<string, int>, pausaMs: int}
     * @throws ErrorModoPrueba
     */
    public static function componentes(Config $config): array
    {
        self::exigirSeguro($config);

        $lib = __DIR__ . '/../../tests/lib/TransporteFalso.php';
        $fixtures = __DIR__ . '/../../tests/fixtures/timbraje';
        if (!is_file($lib) || !is_dir($fixtures)) {
            throw new ErrorModoPrueba('Modo de prueba no disponible: faltan tests/lib/TransporteFalso.php o las fixtures.');
        }
        require_once $lib;

        $transporte = new TransporteFalso($fixtures);
        // p5-33 real dice MAX_AUTOR=8 y p6-33 real propone 38 (capturas de momentos distintos).
        $transporte->cambios['of_solicita_folios_dcto'] = ['MAX_AUTOR' => '38'];

        $ahora = new \DateTimeImmutable('2026-10-13 08:40:00', new \DateTimeZone('America/Santiago'));

        return [
            'transporte' => $transporte,
            'instalador' => static fn (string $bytes, array $esperado, string $ambiente): array
                => self::instalarSintetico($config, $bytes, $esperado, $ambiente),
            'reloj' => static fn (): \DateTimeImmutable => $ahora,
            'presupuesto' => ['max' => 1000, 'espaciado' => 0],
            'pausaMs' => 0,
        ];
    }

    /**
     * Instalador falso: comprueba tipo/rango/fecha contra lo esperado y deja el
     * CAF sintético como {tipo}-{desde}.xml (0600) sin sobrescribir. Mismo
     * contrato de resultado que CafInstalador::instalar. Nunca devuelve bytes.
     *
     * @return array<string, mixed>
     */
    private static function instalarSintetico(Config $config, string $bytes, array $esperado, string $ambiente): array
    {
        $r = [
            'estado' => 'no_es_caf', 'motivo' => 'El contenido no es un CAF.', 'sha256' => hash('sha256', $bytes),
            'idk' => null, 'archivo' => null, 'ruta' => null, 'tipo' => null, 'folioIni' => null, 'folioFin' => null,
            'respaldo' => null, 'cuarentena' => null,
        ];
        if (!str_contains($bytes, '<AUTORIZACION>')) {
            return $r;
        }
        preg_match('/<TD>(\d+)<\/TD>.*<D>(\d+)<\/D><H>(\d+)<\/H><\/RNG><FA>([^<]*)<\/FA>/s', $bytes, $m);
        if (($m[1] ?? '') !== (string) $esperado['tipo'] || (int) ($m[2] ?? 0) !== $esperado['folioIni']
            || (int) ($m[3] ?? 0) !== $esperado['folioFin'] || ($m[4] ?? '') !== $esperado['fecha']) {
            return ['estado' => 'rechazado', 'motivo' => 'El CAF no corresponde a lo solicitado.'] + $r;
        }
        $archivo = "{$m[1]}-{$m[2]}.xml";
        $dir = rtrim($config->cafDirPara($ambiente), '/');
        $destino = "{$dir}/{$archivo}";
        if (file_exists($destino)) {
            return ['estado' => 'archivo_existente', 'motivo' => 'Ya existe un CAF con ese rango.'] + $r;
        }
        $tmp = $dir . '/.instalando-' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false || !chmod($tmp, 0600) || !link($tmp, $destino)) {
            @unlink($tmp);
            return ['estado' => 'rechazado', 'motivo' => 'No se pudo escribir el CAF de prueba.'] + $r;
        }
        unlink($tmp);
        return [
            'estado' => 'instalado', 'motivo' => 'ok (CAF sintético de prueba)', 'idk' => 100, 'archivo' => $archivo,
            'tipo' => (int) $m[1], 'folioIni' => (int) $m[2], 'folioFin' => (int) $m[3],
        ] + $r;
    }
}
