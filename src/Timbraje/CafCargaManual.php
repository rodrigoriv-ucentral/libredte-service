<?php

declare(strict_types=1);

namespace App\Timbraje;

use App\Config;

/**
 * Lógica común de la carga manual de un CAF descargado del portal del SII,
 * usada por POST /caf/instalar (y por un script CLI equivalente). SIN red.
 *
 * Lee TD, RNG D/H, FA, RE e IDK del propio XML, exige RE == RUT emisor de la
 * instalación y delega en CafInstalador::instalar() (firma del SII, IDK por
 * ambiente, cruces de rango, cuarentena y respaldo). Con una solicitud,
 * valida que sea coherente y la avanza a 'instalado'.
 *
 * Nunca devuelve ni registra el XML (lleva la llave privada RSASK): solo
 * metadatos.
 */
final class CafCargaManual
{
    /**
     * Estados desde los que CafSolicitudRepository permite avanzar a
     * 'instalado' (espejo de su TRANSICIONES, que es privada; avanzar() sigue
     * siendo la autoridad final).
     */
    public const ESTADOS_INSTALABLES = ['incierto', 'generado', 'generado_sin_descargar'];

    public static function normalizarRut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }

    /**
     * Lee los metadatos mínimos del XML sin instalar nada.
     *
     * @return array{meta: ?array{td: string, d: string, h: string, fa: string, re: string, idk: string}, error: ?string}
     *   error es el mensaje (el mismo que imprimía el script) si no es un
     *   AUTORIZACION o faltan metadatos.
     */
    public static function leerMetadatos(string $bytes): array
    {
        $dom = new \DOMDocument();
        $prevLibxml = libxml_use_internal_errors(true);
        $cargo = $dom->loadXML($bytes, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);
        if (!$cargo || $dom->documentElement === null || $dom->documentElement->nodeName !== 'AUTORIZACION') {
            return ['meta' => null, 'error' => 'El archivo no es un XML con raíz AUTORIZACION (¿HTML de error del SII?).'];
        }
        $xp = new \DOMXPath($dom);
        $leer = static fn (string $xpath): string => trim((string) $xp->evaluate("string({$xpath})"));
        $meta = [
            'td' => $leer('/AUTORIZACION/CAF/DA/TD'),
            'd' => $leer('/AUTORIZACION/CAF/DA/RNG/D'),
            'h' => $leer('/AUTORIZACION/CAF/DA/RNG/H'),
            'fa' => $leer('/AUTORIZACION/CAF/DA/FA'),
            're' => $leer('/AUTORIZACION/CAF/DA/RE'),
            'idk' => $leer('/AUTORIZACION/CAF/DA/IDK'),
        ];
        $dom = null;

        if (
            $meta['td'] === '' || !ctype_digit($meta['td']) || $meta['d'] === '' || !ctype_digit($meta['d'])
            || $meta['h'] === '' || !ctype_digit($meta['h'])
            || $meta['fa'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['fa'])
            || $meta['re'] === '' || $meta['idk'] === '' || !ctype_digit($meta['idk'])
        ) {
            return ['meta' => null, 'error' => 'No se pudieron leer los metadatos mínimos del CAF (TD/RNG/FA/RE/IDK).'];
        }
        return ['meta' => $meta, 'error' => null];
    }

    /**
     * "Esperado" para CafInstalador::instalar(), tomado del propio XML.
     *
     * @param array{td: string, d: string, h: string, fa: string, re: string, idk: string} $meta
     * @return array{tipo: int, rutEmisor: string, folioIni: int, folioFin: int, fecha: string}
     */
    public static function esperado(array $meta, Config $config): array
    {
        return [
            'tipo' => (int) $meta['td'],
            'rutEmisor' => $config->emisorRut,
            'folioIni' => (int) $meta['d'],
            'folioFin' => (int) $meta['h'],
            'fecha' => $meta['fa'],
        ];
    }

    /** true si el RE del CAF es el RUT emisor configurado (normalizado). */
    public static function reCoincide(string $re, Config $config): bool
    {
        return self::normalizarRut($re) === self::normalizarRut($config->emisorRut);
    }

    /**
     * Revisa la solicitud contra el CAF leído (existe, mismo ambiente y tipo,
     * estado que permite pasar a 'instalado', rango coincidente).
     *
     * @param array{tipo: int, folioIni: int, folioFin: int} $esperado
     * @return array{fila: ?array<string, mixed>, error: ?string}
     */
    public static function verificarSolicitud(CafSolicitudRepository $repo, string $solicitudId, Config $config, array $esperado): array
    {
        $fila = $repo->obtener($solicitudId);
        if ($fila === null) {
            return ['fila' => null, 'error' => "No existe la solicitud {$solicitudId}."];
        }
        $error = null;
        if ($fila['ambiente'] !== $config->ambiente) {
            $error = "La solicitud es de ambiente {$fila['ambiente']}, pero este servicio corre en {$config->ambiente}.";
        } elseif ((int) $fila['tipo_dte'] !== $esperado['tipo']) {
            $error = "La solicitud es de tipo {$fila['tipo_dte']}, el CAF leído es de tipo {$esperado['tipo']}.";
        } elseif (!in_array($fila['estado'], self::ESTADOS_INSTALABLES, true)) {
            $error = "La solicitud está en estado '{$fila['estado']}'; no se puede avanzar a 'instalado' desde ahí.";
        } elseif (
            ($fila['folio_ini'] !== null && (int) $fila['folio_ini'] !== $esperado['folioIni'])
            || ($fila['folio_fin'] !== null && (int) $fila['folio_fin'] !== $esperado['folioFin'])
        ) {
            $error = "El rango de la solicitud ({$fila['folio_ini']}-{$fila['folio_fin']}) no coincide con el del CAF.";
        }
        return ['fila' => $fila, 'error' => $error];
    }

    /**
     * Instala con los directorios de respaldo y cuarentena que cuelgan de la
     * carpeta de la BD de solicitudes (igual que el script histórico).
     *
     * @param array{tipo: int, rutEmisor: string, folioIni: int, folioFin: int, fecha: string} $esperado
     * @return array<string, mixed> resultado de CafInstalador::instalar()
     */
    public static function instalar(Config $config, string $bytes, array $esperado, ?CafInstalador $instalador = null): array
    {
        if ($instalador === null) {
            $base = dirname($config->cafSolicitudesDb);
            $instalador = new CafInstalador($config, $base . '/caf-respaldo', $base . '/caf-cuarentena');
        }
        return $instalador->instalar($bytes, $esperado, $config->ambiente);
    }

    /** ¿El resultado deja el CAF instalado (nuevo o ya presente e idéntico en rango)? */
    public static function quedoInstalado(array $r): bool
    {
        return $r['estado'] === 'instalado' || $r['estado'] === 'archivo_existente';
    }

    /** Avanza la solicitud a 'instalado' con los datos del CAF instalado. */
    public static function avanzarSolicitud(CafSolicitudRepository $repo, string $solicitudId, array $r): void
    {
        $repo->avanzar($solicitudId, 'instalado', [
            'archivo' => $r['archivo'],
            'sha256' => $r['sha256'],
            'idk' => $r['idk'],
            'error_codigo' => null,
            'error_codigo_sii' => null,
            'error_detalle' => null,
        ], new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')));
    }

    /**
     * Respuesta HTTP de POST /caf/instalar a partir del resultado de
     * CafInstalador (nunca incluye el XML): 201 instalado, 409 YA_INSTALADO,
     * 422 por rechazo de validación, 500 si fue un fallo de escritura.
     *
     * @param array<string, mixed> $r
     * @param array{fa: string} $meta
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function respuestaHttp(array $r, array $meta): array
    {
        if ($r['estado'] === 'instalado') {
            return [201, [
                'estado' => 'instalado',
                'tipo' => $r['tipo'],
                'folioIni' => $r['folioIni'],
                'folioFin' => $r['folioFin'],
                'fa' => $meta['fa'],
                'idk' => $r['idk'],
                'archivo' => $r['archivo'],
                'sha256' => $r['sha256'],
                'respaldo' => $r['respaldo'],
            ]];
        }
        if ($r['estado'] === 'archivo_existente') {
            return [409, [
                'error' => 'Ya hay un CAF instalado con ese rango.',
                'codigo' => 'YA_INSTALADO',
                'tipo' => $r['tipo'],
                'folioIni' => $r['folioIni'],
                'folioFin' => $r['folioFin'],
            ]];
        }
        $codigo = (string) ($r['codigo'] ?? 'ERROR_INSTALACION');
        $http = in_array($codigo, ['XML_INVALIDO', 'TIPO_NO_SOPORTADO', 'RUT_NO_COINCIDE', 'AMBIENTE_NO_COINCIDE', 'FIRMA_INVALIDA', 'RANGO_SE_CRUZA', 'CAF_NO_COINCIDE'], true)
            ? 422 : 500;
        return [$http, [
            'error' => CafSolicitudRepository::sanear((string) $r['motivo']) ?: 'Error',
            'codigo' => $codigo,
        ]];
    }
}
