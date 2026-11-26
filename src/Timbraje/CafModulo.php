<?php

declare(strict_types=1);

namespace App\Timbraje;

use App\Config;
use App\FolioControl;
use App\FolioRepository;

/**
 * Cableado de la solicitud de CAF para las rutas /caf/*: arma el servicio con
 * el transporte real (TransporteCurl con el MISMO .pfx y clave que usa el
 * resto del microservicio, vía Config), el CafInstalador sobre Config, la
 * bitácora SQLite, FolioControl como fuente de maxFolioLocal y el servidor del
 * SII según el ambiente. Con LIBREDTE_TIMBRAJE_FAKE (solo certificación y
 * rutas explícitas) usa ModoPrueba en vez de red real.
 *
 * Nada de lo que sale de aquí trae XML de CAF, cookies, rutas del certificado
 * ni claves: el servicio solo entrega metadatos y las excepciones se sanean.
 */
final class CafModulo
{
    public const CONFIRMACION = CafSolicitudService::CONFIRMACION;

    private readonly CafSolicitudRepository $repo;
    private readonly FolioControl $folios;
    private readonly CafSolicitudService $servicio;
    private readonly \Closure $reloj;

    /**
     * @param ?\Closure(array<string, mixed> $evento): void $eventoListener Para el streaming NDJSON
     *   (POST /caf/consultar|solicitar|recuperar con Accept: application/x-ndjson); null fuera de esas rutas.
     * @throws ErrorModoPrueba
     */
    public function __construct(private readonly Config $config, ?\Closure $eventoListener = null)
    {
        ModoPrueba::exigirSeguro($config);

        $base = dirname($config->cafSolicitudesDb);
        $prueba = ModoPrueba::activo() ? ModoPrueba::componentes($config) : null;

        $this->repo = new CafSolicitudRepository($config->cafSolicitudesDb, $prueba['presupuesto'] ?? []);
        $this->folios = new FolioControl($config, new FolioRepository($config->dbPath));
        $this->reloj = $prueba['reloj'] ?? static fn (): \DateTimeImmutable
            => new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));

        // La clave del .pfx se pide recién al abrir sesión (TransporteCurl::reiniciar):
        // GET /caf/estado y /caf/solicitudes nunca tocan el certificado.
        $transporte = $prueba['transporte']
            ?? new TransporteCurl($config->certPath, static fn (): string => $config->resolveCertPass());
        $instalador = $prueba['instalador']
            ?? new CafInstalador($config, $base . '/caf-respaldo', $base . '/caf-cuarentena');
        // Pausa entre peticiones al SII dentro de una sesión (v1.1 transparencia):
        // 3 s por defecto, configurable por LIBREDTE_CAF_PAUSA_MS; 0 en ModoPrueba.
        $pausaMs = $prueba['pausaMs'] ?? self::pausaMsDeEntorno();

        $this->servicio = new CafSolicitudService($transporte, $instalador, $this->repo, $this->reloj, [
            'ambiente' => $config->ambiente,
            'rutEmisor' => $config->emisorRut,
            'lockPath' => $base . '/caf_solicitud.lock',
            // Rechaza un rango que pise folios ya cargados (CAF) o ya usados.
            'maxFolioLocal' => fn (int $tipo, string $ambiente): int => $this->folios->maxFolioLocal($tipo),
            'log' => static function (string $evento, array $contexto): void {
                // El servicio ya sanea el contexto: solo ids, tipos, estados y sha256.
                error_log('[caf] ' . $evento . ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            },
            'pausaMs' => $pausaMs,
            'eventoListener' => $eventoListener,
        ]);
    }

    private static function pausaMsDeEntorno(): int
    {
        $v = getenv('LIBREDTE_CAF_PAUSA_MS');
        return $v !== false && $v !== '' && ctype_digit($v) ? (int) $v : 3000;
    }

    public function servicio(): CafSolicitudService
    {
        return $this->servicio;
    }

    /** Cuerpo de GET /caf/estado (§3.1). */
    public function estado(): array
    {
        $info = [];
        foreach ($this->repo->listar(500) as $fila) { // las más recientes primero
            $tipo = (int) $fila['tipo_dte'];
            if ($fila['ambiente'] !== $this->config->ambiente || $fila['max_autor'] === null || isset($info[$tipo])) {
                continue;
            }
            $info[$tipo] = [
                'maxAutor' => (int) $fila['max_autor'],
                'foliosDispSii' => $fila['folios_disp_sii'] === null ? null : (int) $fila['folios_disp_sii'],
                'fecha' => $fila['updated_at'],
            ];
        }
        $s = $this->servicio->estadoSesiones();
        $uc = $this->repo->ultimaConsulta($this->config->ambiente);

        return [
            'ambiente' => $this->config->ambiente,
            'servidor' => $this->config->servidorTimbraje($this->config->ambiente),
            'tipos' => $this->folios->estado(),
            'ultimaInfoSii' => (object) $info,
            'ultimaConsulta' => $uc === null ? null : ['consultaId' => $uc['id'], 'ts' => $uc['ts'], 'tipos' => (object) $uc['resultados']],
            'sesiones' => [
                'ultimas2h' => $s['ultimasVentana'],
                'bloqueadoHasta' => $s['bloqueadoHasta'],
                'enCurso' => $s['enCurso'],
                'permitido' => $s['permitido'],
                'motivo' => $s['motivo'],
                'disponibleDesde' => $s['disponibleDesde'],
            ],
        ];
    }

    /** Cuerpo de GET /caf/solicitudes. */
    public function solicitudes(int $limite): array
    {
        return ['ambiente' => $this->config->ambiente, 'solicitudes' => $this->servicio->solicitudes($limite)];
    }

    /** Cuerpo de GET /caf/eventos?loteId=…: formato de evento sin "t" (mismo del streaming NDJSON). */
    public function eventosDeLote(string $loteId): array
    {
        return ['eventos' => array_map(self::formatoEvento(...), $this->repo->eventosDeLote($loteId))];
    }

    /** Cuerpo de GET /caf/eventos?consultaId=…. */
    public function eventosDeConsulta(string $consultaId): array
    {
        return ['eventos' => array_map(self::formatoEvento(...), $this->repo->eventosDeConsulta($consultaId))];
    }

    /** @param array<string, mixed> $f fila de caf_eventos */
    private static function formatoEvento(array $f): array
    {
        return [
            'ts' => $f['ts'],
            'paso' => $f['paso'],
            'estado' => $f['estado'],
            'tipoDte' => $f['tipo_dte'] === null ? null : (int) $f['tipo_dte'],
            'mensaje' => $f['mensaje'],
            'codigoSii' => $f['codigo_sii'],
            'segundos' => $f['segundos'] === null ? null : (float) $f['segundos'],
        ];
    }

    /**
     * Resultado de CafSolicitudService::solicitar/recuperar → [http, cuerpo JSON].
     * Siempre incluye `resultados` (con `fa`, alias de `fecha`): ante un fallo
     * tras el punto irreversible (incierto, generado_sin_descargar) el cliente
     * tiene que ver el estado por tipo aunque el http sea 4xx/5xx.
     *
     * @param array<string, mixed> $r
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function respuesta(array $r): array
    {
        $resultados = array_map(static fn (array $x): array => $x + ['fa' => $x['fecha'] ?? null], $r['resultados']);
        $cuerpo = [
            'loteId' => $r['loteId'],
            'estado' => $r['estado'],
            'idempotente' => $r['estado'] === 'idempotente',
            'sesion' => $r['sesion'],
            'resultados' => $resultados,
        ];
        if (isset($r['presupuesto'])) {
            $cuerpo['presupuesto'] = $r['presupuesto'];
        }
        if ($r['http'] >= 400) {
            $cuerpo = ['error' => $r['motivo'] ?? $r['codigo'] ?? 'Error', 'codigo' => $r['codigo']] + $cuerpo;
        }
        return [$r['http'], $cuerpo];
    }

    /**
     * Resultado de CafSolicitudService::consultar() → [http, cuerpo JSON]
     * (contrato de POST /caf/consultar).
     *
     * @param array<string, mixed> $r
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function respuestaConsulta(array $r): array
    {
        $cuerpo = [
            'ok' => $r['estado'] === 'completado',
            'consultaId' => $r['consultaId'] ?? null,
            'ambiente' => $r['ambiente'] ?? null,
            'servidor' => $r['servidor'] ?? null,
            'sesion' => $r['sesion'],
            'tipos' => (object) ($r['tipos'] ?? []),
        ];
        if (isset($r['presupuesto'])) {
            $cuerpo['presupuesto'] = $r['presupuesto'];
        }
        if ($r['http'] >= 400) {
            $cuerpo = ['error' => $r['motivo'] ?? $r['codigo'] ?? 'Error', 'codigo' => $r['codigo']] + $cuerpo;
        }
        return [$r['http'], $cuerpo];
    }
}
