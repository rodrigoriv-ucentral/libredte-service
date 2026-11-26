<?php

declare(strict_types=1);

namespace App\Sii;

use App\Timbraje\ErrorSesionSii;
use App\Timbraje\LectorCookies;
use App\Timbraje\SiiPortalSesion;

/**
 * Lectura en SOLO LECTURA del Registro de Compras y Ventas (RCV) del SII
 * dentro de UNA sesión ya abierta (SiiPortalSesion::ejecutar con destino
 * URL_APP). Contrato de salida: RcvPeriodo (ver normalizarResumen/normalizarDetalle).
 *
 * Flujo:
 *   GET https://www4.sii.cl/consdcvinternetui/  (entra a la app tras el login)
 *   por período × COMPRA/VENTA × REGISTRO/PENDIENTE:
 *     POST facadeService/getResumen
 *     por cada tipo con documentos: POST facadeService/getDetalleCompra|getDetalleVenta
 *
 * - Solo se llaman los métodos de METODOS_PERMITIDOS (nada de aceptar,
 *   reclamar ni enviar): cualquier otro lanza LogicException ANTES de salir.
 * - conversationId = valor de la cookie TOKEN. Es un secreto de sesión: solo
 *   viaja en el cuerpo JSON hacia www4.sii.cl; nunca se loguea ni se devuelve.
 * - Sin reintentos: cualquier respuesta inutilizable lanza ErrorRcv y se
 *   aborta toda la lectura (un RCV parcial daría un F29 equivocado).
 */
final class RcvLector
{
    public const URL_APP = 'https://www4.sii.cl/consdcvinternetui/';
    public const URL_SERVICIO = 'https://www4.sii.cl/consdcvinternetui/services/data/facadeService/';
    public const NAMESPACE_SERVICIO = 'cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/';
    public const METODOS_PERMITIDOS = ['getResumen', 'getDetalleCompra', 'getDetalleVenta'];
    public const OPERACIONES = ['COMPRA', 'VENTA'];
    public const ESTADOS = ['REGISTRO', 'PENDIENTE'];
    public const MAX_PERIODOS = 3;
    /** El RCV existe desde agosto de 2017. */
    public const PRIMER_PERIODO = '2017-08';

    private readonly string $rut;
    private readonly string $dv;
    private readonly \Closure $reloj;
    private readonly \Closure $sleeper;
    private int $peticiones = 0;

    /**
     * @param \Closure(): \DateTimeImmutable $reloj
     * @param ?\Closure(int $ms): void $sleeper Pausa entre peticiones (los tests inyectan uno que no duerme).
     */
    public function __construct(
        private readonly LectorCookies $cookies,
        string $rutEmisor,
        \Closure $reloj,
        private readonly int $pausaMs = 0,
        ?\Closure $sleeper = null,
    ) {
        if (preg_match('/^(\d{1,8})-([\dkK])$/', $rutEmisor, $m) !== 1) {
            throw new \InvalidArgumentException('rutEmisor debe tener la forma 12345678-9.');
        }
        $this->rut = $m[1];
        $this->dv = strtoupper($m[2]);
        $this->reloj = $reloj;
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /**
     * Valida la lista de períodos pedida: 1 a MAX_PERIODOS, "AAAA-MM", sin
     * repetir, desde PRIMER_PERIODO y no futuros (respecto de $ahora).
     *
     * @return list<string>
     * @throws \InvalidArgumentException con un mensaje apto para el usuario.
     */
    public static function validarPeriodos(mixed $periodos, \DateTimeImmutable $ahora): array
    {
        if (!is_array($periodos) || !array_is_list($periodos) || $periodos === []) {
            throw new \InvalidArgumentException('periodos debe ser una lista no vacía de "AAAA-MM".');
        }
        if (count($periodos) > self::MAX_PERIODOS) {
            throw new \InvalidArgumentException('Máximo ' . self::MAX_PERIODOS . ' períodos por lectura.');
        }
        $actual = $ahora->format('Y-m');
        $limpios = [];
        foreach ($periodos as $p) {
            if (!is_string($p) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p) !== 1) {
                throw new \InvalidArgumentException('Período inválido: use "AAAA-MM".');
            }
            if ($p > $actual) {
                throw new \InvalidArgumentException("El período {$p} es futuro.");
            }
            if ($p < self::PRIMER_PERIODO) {
                throw new \InvalidArgumentException("El período {$p} es anterior al RCV (" . self::PRIMER_PERIODO . ').');
            }
            if (in_array($p, $limpios, true)) {
                throw new \InvalidArgumentException("El período {$p} viene repetido.");
            }
            $limpios[] = $p;
        }
        return $limpios;
    }

    /**
     * Lee los períodos (ya validados) dentro de la sesión abierta.
     *
     * @param list<string> $periodos
     * @return list<array{periodo: string, documentos: list<array<string, mixed>>, resumen: list<array<string, mixed>>, leidoAt: string}>
     * @throws ErrorRcv|ErrorSesionSii|\App\Timbraje\ErrorTransporte
     */
    public function leer(SiiPortalSesion $sesion, array $periodos): array
    {
        $this->pausar();
        $inicio = $sesion->get(self::URL_APP);
        if ($inicio->estado !== 200) {
            throw new ErrorRcv('RCV_NO_DISPONIBLE', "El SII respondió HTTP {$inicio->estado} al abrir el Registro de Compras y Ventas.");
        }
        $token = $this->cookies->valorCookie(SiiPortalSesion::COOKIE_TOKEN);
        if ($token === null || $token === '') {
            throw new ErrorSesionSii('SIN_SESION', 'La sesión del SII no trae el identificador necesario para consultar el RCV.');
        }

        $salida = [];
        foreach ($periodos as $periodo) {
            $documentos = [];
            $resumen = [];
            $ptributario = str_replace('-', '', $periodo);
            foreach (self::OPERACIONES as $operacion) {
                foreach (self::ESTADOS as $estado) {
                    $base = [
                        'rutEmisor' => $this->rut,
                        'dvEmisor' => $this->dv,
                        'ptributario' => $ptributario,
                        'estadoContab' => $estado,
                        'operacion' => $operacion,
                        'busquedaInicial' => true,
                    ];
                    foreach ($this->llamar($sesion, 'getResumen', $base, $token) as $fila) {
                        if (!is_array($fila)) {
                            throw new ErrorRcv('RCV_RESPUESTA_INVALIDA', "Fila de resumen inesperada ({$periodo} {$operacion} {$estado}).");
                        }
                        $r = self::normalizarResumen($operacion, $estado, $fila);
                        if ($r['tipoDte'] <= 0) {
                            throw new ErrorRcv('RCV_RESPUESTA_INVALIDA', "Resumen sin tipo de documento ({$periodo} {$operacion} {$estado}).");
                        }
                        $resumen[] = $r;
                        if ($r['cantidad'] <= 0 && $r['total'] === 0) {
                            continue;
                        }
                        $esResumenTipo = strtoupper((string) ($fila['dcvTipoIngresoDoc'] ?? '')) === 'RESUMEN';
                        $metodo = $operacion === 'COMPRA' ? 'getDetalleCompra' : 'getDetalleVenta';
                        $detalle = $this->llamar($sesion, $metodo, $base + ['codTipoDoc' => (string) $r['tipoDte']], $token);
                        $docs = [];
                        foreach ($detalle as $d) {
                            if (!is_array($d)) {
                                throw new ErrorRcv('RCV_RESPUESTA_INVALIDA', "Fila de detalle inesperada (tipo {$r['tipoDte']}, {$periodo}).");
                            }
                            $docs[] = self::normalizarDetalle($operacion, $estado, $r['tipoDte'], $esResumenTipo, $d);
                        }
                        if ($esResumenTipo && $docs === []) {
                            // Tipo informado solo resumido y sin filas de detalle: el total mensual es la fila.
                            $docs[] = self::documentoDesdeResumen($r);
                        }
                        if (!$esResumenTipo && count($docs) < $r['cantidad']) {
                            throw new ErrorRcv(
                                'RCV_DETALLE_INCOMPLETO',
                                "El detalle del tipo {$r['tipoDte']} ({$periodo} {$operacion} {$estado}) trae " . count($docs)
                                . " documentos y el resumen dice {$r['cantidad']}."
                            );
                        }
                        array_push($documentos, ...$docs);
                    }
                }
            }
            $salida[] = [
                'periodo' => $periodo,
                'documentos' => $documentos,
                'resumen' => $resumen,
                'leidoAt' => ($this->reloj)()->format(DATE_ATOM),
            ];
        }
        return $salida;
    }

    /** Fila de getResumen → ResumenRcvTipo. */
    public static function normalizarResumen(string $operacion, string $estado, array $f): array
    {
        return [
            'operacion' => $operacion,
            'estado' => $estado,
            'tipoDte' => self::monto($f['rsmnTipoDocInteger'] ?? null),
            'nombre' => self::texto($f['dcvNombreTipoDoc'] ?? null) ?? '',
            'cantidad' => self::monto($f['rsmnTotDoc'] ?? null),
            'exento' => self::monto($f['rsmnMntExe'] ?? null),
            'neto' => self::monto($f['rsmnMntNeto'] ?? null),
            'iva' => self::monto($f['rsmnMntIVA'] ?? null),
            'total' => self::monto($f['rsmnMntTotal'] ?? null),
        ];
    }

    /** Fila de getDetalleCompra/getDetalleVenta → DocumentoRcv. */
    public static function normalizarDetalle(string $operacion, string $estado, int $tipoDte, bool $esResumenTipo, array $f): array
    {
        $rut = self::rut($f['detRutDoc'] ?? null, $f['detDvDoc'] ?? null);
        $esResumen = $esResumenTipo || $rut === null;
        $folio = self::monto($f['detNroDoc'] ?? null);
        return [
            'operacion' => $operacion,
            'estado' => $estado,
            'tipoDte' => $tipoDte,
            'rutContraparte' => $esResumen ? null : $rut,
            'razonSocial' => self::texto($f['detRznSoc'] ?? null),
            'folio' => $folio > 0 ? (string) $folio : null,
            'fechaDocumento' => self::fecha($f['detFchDoc'] ?? null),
            'fechaRecepcion' => self::fecha($f['detFecRecepcion'] ?? null),
            'montoExento' => self::monto($f['detMntExe'] ?? null),
            'montoNeto' => self::monto($f['detMntNeto'] ?? null),
            'montoIva' => self::monto($f['detMntIVA'] ?? null),
            'montoTotal' => self::monto($f['detMntTotal'] ?? null),
            'montoActivoFijo' => self::monto($f['detMntActFijo'] ?? null),
            'ivaNoRecuperable' => self::monto($f['detMntIVANoRec'] ?? null),
            'esResumen' => $esResumen,
        ];
    }

    /** @param array<string, mixed> $r ResumenRcvTipo */
    private static function documentoDesdeResumen(array $r): array
    {
        return [
            'operacion' => $r['operacion'],
            'estado' => $r['estado'],
            'tipoDte' => $r['tipoDte'],
            'rutContraparte' => null,
            'razonSocial' => null,
            'folio' => null,
            'fechaDocumento' => null,
            'fechaRecepcion' => null,
            'montoExento' => $r['exento'],
            'montoNeto' => $r['neto'],
            'montoIva' => $r['iva'],
            'montoTotal' => $r['total'],
            'montoActivoFijo' => 0,
            'ivaNoRecuperable' => 0,
            'esResumen' => true,
        ];
    }

    /** Pesos enteros; null, vacío o no numérico → 0. */
    public static function monto(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) || (is_string($v) && is_numeric(trim($v)))) {
            return (int) round((float) $v);
        }
        return 0;
    }

    /** "12345678" + "9" → "12345678-9"; RUT 0/vacío → null. */
    public static function rut(mixed $numero, mixed $dv): ?string
    {
        $n = ltrim(preg_replace('/\D/', '', (string) ($numero ?? '')) ?? '', '0');
        $d = strtoupper(trim((string) ($dv ?? '')));
        if ($n === '' || strlen($n) > 8 || preg_match('/^[\dK]$/', $d) !== 1) {
            return null;
        }
        return $n . '-' . $d;
    }

    /** "DD/MM/AAAA[ HH:MM:SS]" o "AAAA-MM-DD..." → "AAAA-MM-DD"; otra cosa → null. */
    public static function fecha(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?:\s|$)/', $v, $m) === 1) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) === 1) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    private static function texto(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        return $t === '' ? null : mb_substr($t, 0, 200);
    }

    /**
     * Una llamada al facadeService. Devuelve la lista `data` ([] si null).
     *
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function llamar(SiiPortalSesion $sesion, string $metodo, array $data, string $token): array
    {
        if (!in_array($metodo, self::METODOS_PERMITIDOS, true)) {
            throw new \LogicException("Método del RCV no permitido: {$metodo} (solo lectura).");
        }
        $cuerpo = json_encode([
            'metaData' => [
                'namespace' => self::NAMESPACE_SERVICIO . $metodo,
                'conversationId' => $token,
                'transactionId' => bin2hex(random_bytes(16)),
                'page' => null,
            ],
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->pausar();
        $r = $sesion->postJson(self::URL_SERVICIO . $metodo, $cuerpo, [
            'Accept: application/json, text/plain, */*',
            'Origin: https://www4.sii.cl',
            'Referer: ' . self::URL_APP,
        ]);
        if ($r->estado !== 200) {
            throw new ErrorRcv('RCV_HTTP', "El SII respondió HTTP {$r->estado} en {$metodo}.");
        }
        $j = json_decode($r->cuerpo, true);
        if (!is_array($j) || !array_key_exists('data', $j)) {
            throw new ErrorRcv('RCV_RESPUESTA_INVALIDA', "Respuesta no reconocida del SII en {$metodo}.");
        }
        $d = $j['data'];
        if ($d === null) {
            return [];
        }
        if (!is_array($d) || !array_is_list($d)) {
            throw new ErrorRcv('RCV_RESPUESTA_INVALIDA', "Respuesta no reconocida del SII en {$metodo} (data).");
        }
        return $d;
    }

    private function pausar(): void
    {
        if ($this->peticiones++ > 0) {
            ($this->sleeper)($this->pausaMs);
        }
    }
}
