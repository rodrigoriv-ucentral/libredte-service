<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Orquesta la solicitud de CAF al SII en UNA sesión por operación:
 *
 *   validar → idempotencia → lock → presupuesto de sesiones → filas 'pendiente'
 *   → login → por tipo: consultar (p5) → confirmar (p6, validado) →
 *     generar (UNA vez; 'incierto' grabado antes del envío) → descargar (p7)
 *     → CafInstalador::instalar
 *   → logout (siempre, en finally de SiiPortalSesion) → resultado.
 *
 * - Sin reintentos automáticos de nada. of_genera_folio jamás se repite: ante
 *   falla o respuesta ambigua la fila queda 'incierto' y el lote se detiene;
 *   recuperar() vuelve a descargar con of_genera_archivo (sin generar).
 * - Errores antes de generar dejan la fila en 'error_antes_de_generar' y se
 *   sigue con el tipo siguiente (no se comprometió nada). 'incierto' y
 *   'error_sii' detienen el lote.
 * - Resultados, bitácora y log: solo metadatos (nunca cookies, XML ni claves).
 *
 * Dependencias inyectadas: transporte, instalador (CafInstalador o un
 * Closure con su misma firma), repositorio y reloj.
 *
 * v1.1 transparencia: además de lo anterior, el servicio
 *   - hace una PAUSA configurable (opción pausaMs, ms) entre cada petición al
 *     SII dentro de una sesión (incluida la de consultar()), para no
 *     saturarlo; usa el "sleeper" inyectado (opción sleeper) para no dormir
 *     de verdad en las pruebas.
 *   - emite un evento por paso (ver EventoSii::PASOS) a un listener inyectable
 *     (opción eventoListener, para el streaming NDJSON) y además lo persiste
 *     en CafSolicitudRepository::registrarEvento (tabla caf_eventos).
 *   - ofrece consultar(): UNA sesión de solo lectura (consultarCupo por tipo,
 *     sin confirmar/generar jamás) que deja constancia en caf_consultas y
 *     puede referenciarse desde solicitar() vía $pedido['consultaId'].
 *
 * Opciones:
 *   ambiente         'certificacion' | 'produccion' (obligatorio; fija servidor maullin|palena e IDK)
 *   rutEmisor        '12345678-9' (obligatorio)
 *   tiposHabilitados subconjunto de TimbrajeClient::TIPOS_V1 (por defecto 33, 39, 56, 61)
 *   topes            [tipo => máximo por solicitud] (por defecto 33≤200, 39≤50, 56≤50, 61≤50)
 *   lockPath         archivo de lock (por defecto /data/db/caf_solicitud.lock)
 *   maxFolioLocal    Closure(int $tipo, string $ambiente): int — mayor "hasta" de los CAF locales
 *   log              Closure(string $evento, array $contexto): void — recibe solo metadatos saneados
 *   pausaMs          espera mínima entre peticiones al SII dentro de una sesión (por defecto 3000)
 *   sleeper          Closure(int $ms): void — por defecto usleep(); los tests inyectan uno que no duerme
 *   eventoListener   Closure(array $evento): void — recibe cada evento (streaming); también se persiste
 */
final class CafSolicitudService
{
    public const CONFIRMACION = 'SOLICITAR';
    public const CONFIRMACION_CONSULTA = 'CONSULTAR';
    public const TOPES_POR_DEFECTO = [33 => 200, 39 => 50, 56 => 50, 61 => 50];
    public const LOCK_POR_DEFECTO = '/data/db/caf_solicitud.lock';
    /** La referencia a una consulta previa (§caf/solicitar consultaId) deja de servir a los 30 min. */
    public const CONSULTA_VIGENCIA_SEGUNDOS = 1800;

    private const ABIERTOS_ANTES_DE_GENERAR = ['pendiente', 'consultado', 'confirmado'];

    private readonly string $ambiente;
    private readonly string $servidor;
    private readonly string $rutEmisor;
    private readonly string $lockPath;
    /** @var list<int> */
    private readonly array $tipos;
    /** @var array<int, int> */
    private readonly array $topes;
    private readonly ?\Closure $maxFolioLocal;
    private readonly ?\Closure $log;
    private readonly \Closure $instalar;
    private readonly \Closure $reloj;
    private readonly int $pausaMs;
    private readonly \Closure $sleeper;
    private readonly ?\Closure $eventoListener;
    /** @var array{codigo: string, detalle: string}|null Error de sesión del SII visto DESPUÉS de of_genera_folio (queda 'incierto'). */
    private ?array $sesionPostGenera = null;
    /** Contexto del evento en curso (una llamada pública = un "intento": agrupa sus eventos en caf_eventos). */
    private string $eventoSesionId = '';
    private ?string $eventoLoteId = null;
    private ?string $eventoConsultaId = null;
    private bool $loginEmitido = false;

    /**
     * @param CafInstalador|\Closure(string $bytes, array $esperado, string $ambiente): array $instalador
     * @param \Closure(): \DateTimeImmutable $reloj
     * @param array<string, mixed> $opciones
     */
    public function __construct(
        private readonly TransporteHttp $transporte,
        CafInstalador|\Closure $instalador,
        private readonly CafSolicitudRepository $repo,
        \Closure $reloj,
        array $opciones,
    ) {
        $amb = $opciones['ambiente'] ?? null;
        if ($amb !== 'certificacion' && $amb !== 'produccion') {
            throw new \InvalidArgumentException('opción ambiente debe ser "certificacion" o "produccion"');
        }
        $this->ambiente = $amb;
        $this->servidor = $amb === 'produccion' ? 'palena' : 'maullin';
        $rut = $opciones['rutEmisor'] ?? null;
        if (!is_string($rut) || $rut === '') {
            throw new \InvalidArgumentException('opción rutEmisor es obligatoria');
        }
        $this->rutEmisor = $rut;

        $tipos = $opciones['tiposHabilitados'] ?? TimbrajeClient::TIPOS_V1;
        foreach ($tipos as $t) {
            if (!in_array($t, TimbrajeClient::TIPOS_V1, true)) {
                throw new \InvalidArgumentException("Tipo {$t} no soportado (solo " . implode(', ', TimbrajeClient::TIPOS_V1) . ').');
            }
        }
        $this->tipos = array_values($tipos);
        $topes = ($opciones['topes'] ?? []) + self::TOPES_POR_DEFECTO;
        foreach ($this->tipos as $t) {
            if (!is_int($topes[$t] ?? null) || $topes[$t] < 1) {
                throw new \InvalidArgumentException("Tope inválido para el tipo {$t}.");
            }
        }
        $this->topes = $topes;
        $this->lockPath = (string) ($opciones['lockPath'] ?? self::LOCK_POR_DEFECTO);
        $this->maxFolioLocal = $opciones['maxFolioLocal'] ?? null;
        $this->log = $opciones['log'] ?? null;
        $this->instalar = $instalador instanceof CafInstalador
            ? static fn (string $b, array $e, string $a): array => $instalador->instalar($b, $e, $a)
            : $instalador;
        $this->reloj = $reloj;
        $this->pausaMs = max(0, (int) ($opciones['pausaMs'] ?? 3000));
        $this->sleeper = $opciones['sleeper'] ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
        $this->eventoListener = $opciones['eventoListener'] ?? null;
    }

    /**
     * @param array{confirmacion?: string, ambiente?: string, lote?: list<array{tipo: int, cantidad: int}>,
     *   permitirMenos?: bool, idempotencyKey?: string, usuarioEmail?: string} $pedido
     * @return array{estado: string, http: int, codigo: ?string, motivo: ?string, loteId: ?string,
     *   sesion: ?array, resultados: list<array<string, mixed>>}
     *   estado: completado | error_sesion | idempotente | rechazado | ocupado | sin_presupuesto | error
     */
    public function solicitar(array $pedido): array
    {
        $v = $this->validarPedido($pedido);
        if (isset($v['rechazo'])) {
            return $v['rechazo'];
        }
        $previas = $this->repo->porIdempotencia($v['clave']);
        if ($previas !== []) {
            return $this->respuesta('idempotente', 200, null, 'Esta solicitud ya se procesó; se devuelve su resultado.', $previas[0]['lote_id'], null, $previas);
        }

        return $this->conLock(function () use ($v): array {
            $previas = $this->repo->porIdempotencia($v['clave']);
            if ($previas !== []) {
                return $this->respuesta('idempotente', 200, null, 'Esta solicitud ya se procesó; se devuelve su resultado.', $previas[0]['lote_id'], null, $previas);
            }
            // consultaId (si vino): existencia, ambiente, vigencia (30 min) y
            // cantidades contra lo consultado — TODO antes de abrir sesión.
            if (($rechazoConsulta = $this->validarConsultaPrevia($v)) !== null) {
                return $rechazoConsulta;
            }
            // Antes de gastar una sesión: una solicitud 'incierto'/'generado*' sin resolver puede haber
            // consumido folios en el SII; pedir de nuevo del mismo tipo los duplicaría.
            $abiertas = $this->repo->abiertas($this->ambiente, array_column($v['lote'], 'tipo'));
            if ($abiertas !== []) {
                $a = $abiertas[0];
                return $this->respuesta(
                    'rechazado', 409, 'SOLICITUD_ABIERTA',
                    "Hay una solicitud abierta del tipo {$a['tipo_dte']} (id {$a['id']}, estado {$a['estado']}). "
                    . "Resuélvela con 'Recuperar' (si está generada) o descártala manualmente tras verificar en el portal del SII, antes de pedir de nuevo.",
                    $a['lote_id'], null, $abiertas
                );
            }
            $this->eventoSesionId = self::nuevoId();
            $this->eventoLoteId = null;
            $this->eventoConsultaId = null;
            $p = $this->repo->presupuesto($this->ahora());
            if (!$p['permitido']) {
                $this->emitir('presupuesto', 'error', null, (string) $p['motivo']);
                return $this->respuesta('sin_presupuesto', 423, 'PRESUPUESTO_SESIONES', $p['motivo'], null, null, [], ['presupuesto' => $p]);
            }

            $loteId = self::nuevoId();
            $this->eventoLoteId = $loteId;
            $this->emitir('presupuesto', 'ok', null, "Presupuesto de sesiones con el SII: {$p['ultimasVentana']} en las últimas 2 h; sesión permitida.");
            $ids = $this->repo->crearLote(
                $loteId, $v['clave'], $this->ambiente, $this->servidor, $v['lote'], $v['permitirMenos'], $v['email'], $this->ahora()
            );
            $this->registrar('caf_lote_creado', ['loteId' => $loteId, 'tipos' => array_column($v['lote'], 'tipo')]);

            $sesion = $this->conSesion('solicitar', $ids, function (TimbrajeClient $c) use ($ids, $v): void {
                foreach ($v['lote'] as $i => $item) {
                    if ($this->procesarTipo($c, $ids[$i], $item['tipo'], $item['cantidad'], $v['permitirMenos']) === 'detener') {
                        break;
                    }
                }
            });

            // Lo que no alcanzó a pasar el punto irreversible queda cerrado sin efecto en el SII.
            foreach ($ids as $id) {
                $fila = $this->repo->obtener($id);
                if ($fila !== null && in_array($fila['estado'], self::ABIERTOS_ANTES_DE_GENERAR, true)) {
                    $this->repo->avanzar($id, 'error_antes_de_generar', [
                        'error_codigo' => $sesion['error'] ?? 'LOTE_DETENIDO',
                        'error_detalle' => $sesion['detalle'] ?? 'No se procesó: el lote se detuvo antes de llegar a este tipo.',
                    ], $this->ahora());
                }
            }
            $filas = $this->repo->porLote($loteId);
            $this->emitir('fin', $sesion['error'] === null ? 'ok' : 'error', null, self::mensajeFin($filas, $sesion));
            return $this->respuestaDeSesion($sesion, $loteId, $filas);
        });
    }

    /** @param list<array<string, mixed>> $filas */
    private static function mensajeFin(array $filas, array $sesion): string
    {
        if ($sesion['error'] !== null) {
            return 'Solicitud terminada con error de sesión (' . $sesion['error'] . ').';
        }
        $instalados = count(array_filter($filas, static fn (array $f): bool => $f['estado'] === 'instalado'));
        return "Solicitud terminada: {$instalados} de " . count($filas) . ' tipo(s) con CAF instalado.';
    }

    /**
     * Vuelve a descargar (of_genera_archivo) el CAF de una solicitud
     * 'generado' o 'generado_sin_descargar' (rango y fecha confirmados por el
     * SII) con lo guardado, e intenta instalarlo. Nunca llama a of_genera_folio.
     * Una fila 'incierto' NO se recupera: su rango es el propuesto (p6), no uno
     * asignado, y no se sabe qué hace el SII con un rango no asignado.
     */
    public function recuperar(string $solicitudId, string $confirmacion): array
    {
        if ($confirmacion !== self::CONFIRMACION) {
            return $this->respuesta('rechazado', 428, 'CONFIRMACION_REQUERIDA', 'Falta escribir ' . self::CONFIRMACION . '.', null, null, []);
        }
        $chequeo = function () use ($solicitudId): ?array {
            $fila = $this->repo->obtener($solicitudId);
            if ($fila === null) {
                return $this->respuesta('rechazado', 404, 'NO_EXISTE', 'No existe esa solicitud.', null, null, []);
            }
            if ($fila['ambiente'] !== $this->ambiente) {
                return $this->respuesta('rechazado', 400, 'AMBIENTE_DISTINTO', 'La solicitud es de otro ambiente.', $fila['lote_id'], null, [$fila]);
            }
            if ($fila['estado'] === 'incierto') {
                return $this->respuesta('rechazado', 409, 'RECUPERAR_INCIERTO_BLOQUEADO', 'estado incierto: verifica en el portal del SII si los folios fueron asignados y descarta o resuelve manualmente', $fila['lote_id'], null, [$fila]);
            }
            if (!in_array($fila['estado'], CafSolicitudRepository::RECUPERABLES, true)) {
                return $this->respuesta('rechazado', 409, 'ESTADO_NO_RECUPERABLE', "Una solicitud en estado {$fila['estado']} no se recupera.", $fila['lote_id'], null, [$fila]);
            }
            if ($fila['folio_ini'] === null || $fila['folio_fin'] === null || $fila['fecha_sii'] === null) {
                return $this->respuesta('rechazado', 409, 'SIN_DATOS_RECUPERACION', 'No se conoce el rango o la fecha: hay que revisarlo a mano en el portal del SII.', $fila['lote_id'], null, [$fila]);
            }
            return null;
        };
        if (($r = $chequeo()) !== null) {
            return $r;
        }

        return $this->conLock(function () use ($solicitudId, $chequeo): array {
            if (($r = $chequeo()) !== null) {
                return $r;
            }
            $this->eventoSesionId = self::nuevoId();
            $this->eventoLoteId = $this->repo->obtener($solicitudId)['lote_id'] ?? null;
            $this->eventoConsultaId = null;
            $p = $this->repo->presupuesto($this->ahora());
            if (!$p['permitido']) {
                $this->emitir('presupuesto', 'error', null, (string) $p['motivo']);
                return $this->respuesta('sin_presupuesto', 423, 'PRESUPUESTO_SESIONES', $p['motivo'], null, null, [], ['presupuesto' => $p]);
            }
            $this->emitir('presupuesto', 'ok', null, "Presupuesto de sesiones con el SII: {$p['ultimasVentana']} en las últimas 2 h; sesión permitida.");
            $fila = $this->repo->obtener($solicitudId);
            $estadoPrevio = $fila['estado'];

            $sesion = $this->conSesion('recuperar', [], function (TimbrajeClient $c) use ($fila): void {
                $this->descargarEInstalar(
                    $c, $fila['id'], (int) $fila['tipo_dte'], (int) $fila['folio_ini'], (int) $fila['folio_fin'],
                    (string) $fila['fecha_sii'], null, 'generado_sin_descargar'
                );
            });

            $fila = $this->repo->obtener($solicitudId);
            if ($sesion['error'] !== null && $fila['estado'] === $estadoPrevio) {
                $this->repo->avanzar($solicitudId, 'generado_sin_descargar', [
                    'error_codigo' => $sesion['error'],
                    'error_detalle' => $sesion['detalle'],
                ], $this->ahora());
            }
            $this->registrar('caf_recuperacion', ['solicitudId' => $solicitudId, 'estado' => $this->repo->obtener($solicitudId)['estado']]);
            $fila = $this->repo->obtener($solicitudId);
            $this->emitir('fin', $sesion['error'] === null ? 'ok' : 'error', null, $sesion['error'] === null
                ? "Recuperación terminada: estado {$fila['estado']}."
                : 'Recuperación terminada con error de sesión (' . $sesion['error'] . ').');
            return $this->respuestaDeSesion($sesion, $fila['lote_id'], [$fila]);
        });
    }

    /**
     * Consulta de SOLO LECTURA del cupo (MAX_AUTOR, FOLIOS_DISP) por tipo:
     * login → abrir() → consultarCupo(t) por cada tipo (con pausas) → logout.
     * Nunca llama a confirmar() ni a generar(): no tiene ningún efecto en el
     * SII. Cuenta para el presupuesto de sesiones igual que solicitar().
     * Un error en un tipo no aborta los demás (sí lo hace un error de
     * sesión del SII, que corta toda la consulta). Deja constancia en
     * caf_consultas para que /caf/solicitar pueda referenciarla (consultaId).
     *
     * @param array{confirmacion?: string, tipos?: list<int>} $pedido
     * @return array{estado: string, http: int, codigo: ?string, motivo: ?string, sesion: ?array,
     *   consultaId?: string, ambiente?: string, servidor?: string, tipos?: array, presupuesto?: array}
     */
    public function consultar(array $pedido): array
    {
        if (($pedido['confirmacion'] ?? null) !== self::CONFIRMACION_CONSULTA) {
            return $this->respuesta('rechazado', 428, 'CONFIRMACION_REQUERIDA', 'Falta escribir ' . self::CONFIRMACION_CONSULTA . '.', null, null, []);
        }
        $pedidos = $pedido['tipos'] ?? null;
        if (!is_array($pedidos) || $pedidos === [] || !array_is_list($pedidos) || count($pedidos) > count($this->tipos)) {
            return $this->respuesta('rechazado', 400, 'ENTRADA_INVALIDA', 'tipos debe ser una lista no vacía (subconjunto de los tipos habilitados).', null, null, []);
        }
        $limpios = [];
        foreach ($pedidos as $t) {
            $tipo = self::entero($t);
            if ($tipo === null || !in_array($tipo, $this->tipos, true)) {
                return $this->respuesta('rechazado', 400, 'TIPO_NO_HABILITADO', 'Alguno de los tipos pedidos no está habilitado (habilitados: ' . implode(', ', $this->tipos) . ').', null, null, []);
            }
            if (in_array($tipo, $limpios, true)) {
                return $this->respuesta('rechazado', 400, 'TIPO_REPETIDO', "El tipo {$tipo} viene repetido.", null, null, []);
            }
            $limpios[] = $tipo;
        }

        return $this->conLock(function () use ($limpios): array {
            $this->eventoSesionId = self::nuevoId();
            $this->eventoLoteId = null;
            $this->eventoConsultaId = null;
            $p = $this->repo->presupuesto($this->ahora());
            if (!$p['permitido']) {
                $this->emitir('presupuesto', 'error', null, (string) $p['motivo']);
                return $this->respuesta('sin_presupuesto', 423, 'PRESUPUESTO_SESIONES', $p['motivo'], null, null, [], ['presupuesto' => $p]);
            }
            $this->emitir('presupuesto', 'ok', null, "Presupuesto de sesiones con el SII: {$p['ultimasVentana']} en las últimas 2 h; sesión permitida.");

            $consultaId = self::nuevoId();
            $this->eventoConsultaId = $consultaId;
            $resultados = [];
            $sesion = $this->conSesion('consultar_cupo', [], function (TimbrajeClient $c) use ($limpios, &$resultados): void {
                foreach ($limpios as $tipo) {
                    $this->pausar("consultar el cupo del tipo {$tipo}", $tipo);
                    $this->emitir('consultar_cupo', 'en_curso', $tipo, "Consultando al SII el cupo disponible para el tipo {$tipo}...");
                    try {
                        $cupo = $c->consultarCupo($tipo);
                    } catch (ErrorTimbraje $e) {
                        $this->emitir('consultar_cupo', 'error', $tipo, $e->getMessage(), $e->codigoSii);
                        $resultados[(string) $tipo] = ['error' => [
                            'codigo' => $e->codigo, 'detalle' => CafSolicitudRepository::sanear($e->getMessage()), 'codigoSii' => $e->codigoSii,
                        ]];
                        continue; // un error por tipo no aborta los demás (uno de sesión sí: no se atrapa aquí)
                    }
                    $this->emitir(
                        'consultar_cupo', 'ok', $tipo,
                        self::textoCupo($tipo, $cupo)
                    );
                    $resultados[(string) $tipo] = ['maxAutor' => $cupo['maxAutor'], 'foliosDisp' => $cupo['foliosDisp']];
                }
            });
            $this->repo->crearConsulta($consultaId, $sesion['id'], $this->ambiente, $this->servidor, $resultados, $this->ahora());
            $this->registrar('caf_consulta', ['consultaId' => $consultaId, 'tipos' => $limpios]);
            $this->emitir(
                'fin', $sesion['error'] === null ? 'ok' : 'error', null,
                $sesion['error'] === null ? 'Consulta de cupo terminada.' : 'Consulta terminada con error de sesión (' . $sesion['error'] . ').'
            );

            $estado = $sesion['error'] === null ? 'completado' : 'error_sesion';
            $http = match (true) {
                $sesion['error'] === null => 200,
                $sesion['error'] === 'MAX_SESIONES' => 423,
                default => 502,
            };
            return $this->respuesta($estado, $http, $sesion['error'], $sesion['detalle'], null, $sesion, [], [
                'consultaId' => $consultaId, 'ambiente' => $this->ambiente, 'servidor' => $this->servidor,
                'tipos' => $resultados, 'presupuesto' => $this->repo->presupuesto($this->ahora()),
            ]);
        });
    }

    /**
     * Salida manual de una solicitud abierta: la marca 'descartada' con el
     * motivo y la fecha. Solo desde 'incierto' o 'generado_sin_descargar'. No
     * habla con el SII (cero sesiones); el operador verifica en el portal antes.
     */
    public function marcarResuelta(string $solicitudId, string $motivo, string $confirmacion): array
    {
        if ($confirmacion !== self::CONFIRMACION) {
            return $this->respuesta('rechazado', 428, 'CONFIRMACION_REQUERIDA', 'Falta escribir ' . self::CONFIRMACION . '.', null, null, []);
        }
        if (trim($motivo) === '') {
            return $this->respuesta('rechazado', 400, 'ENTRADA_INVALIDA', 'Falta el motivo de la resolución manual.', null, null, []);
        }
        return $this->conLock(function () use ($solicitudId, $motivo): array {
            $fila = $this->repo->obtener($solicitudId);
            if ($fila === null) {
                return $this->respuesta('rechazado', 404, 'NO_EXISTE', 'No existe esa solicitud.', null, null, []);
            }
            if ($fila['ambiente'] !== $this->ambiente) {
                return $this->respuesta('rechazado', 400, 'AMBIENTE_DISTINTO', 'La solicitud es de otro ambiente.', $fila['lote_id'], null, [$fila]);
            }
            if (!in_array($fila['estado'], CafSolicitudRepository::DESCARTABLES, true)) {
                return $this->respuesta('rechazado', 409, 'ESTADO_NO_DESCARTABLE', "Una solicitud en estado {$fila['estado']} no se descarta.", $fila['lote_id'], null, [$fila]);
            }
            $this->repo->marcarResuelta($solicitudId, $motivo, $this->ahora());
            $this->registrar('caf_descartada', ['solicitudId' => $solicitudId, 'tipo' => (int) $fila['tipo_dte'], 'estadoPrevio' => $fila['estado']]);
            return $this->respuesta('completado', 200, null, null, $fila['lote_id'], null, [$this->repo->obtener($solicitudId)]);
        });
    }

    /** Estado del presupuesto de sesiones y si hay una tanda en curso (para GET /caf/estado). */
    public function estadoSesiones(): array
    {
        $enCurso = false;
        $fh = @fopen($this->lockPath, 'c');
        if ($fh !== false) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                flock($fh, LOCK_UN);
            } else {
                $enCurso = true;
            }
            fclose($fh);
        }
        return $this->repo->presupuesto($this->ahora()) + ['enCurso' => $enCurso];
    }

    /** @return list<array<string, mixed>> */
    public function solicitudes(int $limite = 50): array
    {
        return array_map(fn (array $f): array => $this->resultadoFila($f), $this->repo->listar($limite));
    }

    // ------------------------------------------------------------------
    // Flujo
    // ------------------------------------------------------------------

    /** @return 'seguir'|'detener' */
    private function procesarTipo(TimbrajeClient $c, string $id, int $tipo, int $cantidad, bool $permitirMenos): string
    {
        $this->pausar("consultar el cupo del tipo {$tipo}", $tipo);
        $this->emitir('consultar_cupo', 'en_curso', $tipo, "Consultando al SII el cupo disponible para el tipo {$tipo}...");
        try {
            $cupo = $c->consultarCupo($tipo);
        } catch (ErrorTimbraje $e) {
            $this->emitir('consultar_cupo', 'error', $tipo, $e->getMessage(), $e->codigoSii);
            $this->fallarAntes($id, $e->codigo, $e->getMessage(), $e->codigoSii);
            return 'seguir';
        }
        $this->emitir('consultar_cupo', 'ok', $tipo, self::textoCupo($tipo, $cupo));
        $this->repo->avanzar($id, 'consultado', ['max_autor' => $cupo['maxAutor'], 'folios_disp_sii' => $cupo['foliosDisp']], $this->ahora());

        // maxAutor null: el SII no informó tope para el tipo (boleta 39); valida él al confirmar.
        if ($cupo['maxAutor'] !== null && $cantidad > $cupo['maxAutor']) {
            if ($permitirMenos && $cupo['maxAutor'] >= 1) {
                $cantidad = $cupo['maxAutor'];
            } else {
                $this->fallarAntes($id, 'MAX_AUTOR', "Se pidieron {$cantidad} folios y el SII autoriza como máximo {$cupo['maxAutor']}.");
                return 'seguir';
            }
        }

        $this->pausar("confirmar el rango del tipo {$tipo}", $tipo);
        $this->emitir('confirmar_rango', 'en_curso', $tipo, "Pidiendo al SII el rango de {$cantidad} folios para el tipo {$tipo}...");
        try {
            $conf = $c->confirmar($cupo, $cantidad);
        } catch (ErrorTimbraje $e) {
            $this->emitir('confirmar_rango', 'error', $tipo, $e->getMessage(), $e->codigoSii);
            $this->fallarAntes($id, $e->codigo, $e->getMessage(), $e->codigoSii);
            return 'seguir';
        }
        $this->emitir('confirmar_rango', 'ok', $tipo, "SII propone el rango {$conf['folioIni']}-{$conf['folioFin']} ({$cantidad} folios) para el tipo {$tipo}.");
        $this->repo->avanzar($id, 'confirmado', [
            'cantidad_autorizada' => $cantidad,
            'folio_ini' => $conf['folioIni'],
            'folio_fin' => $conf['folioFin'],
            'fecha_sii' => $conf['fecha'],
        ], $this->ahora());

        // ---- Punto irreversible ----
        $this->pausar("generar los folios del tipo {$tipo} (paso irreversible)", $tipo);
        $this->emitir(
            'generar', 'en_curso', $tipo,
            "PASO IRREVERSIBLE: enviando al SII la generación de {$cantidad} folios ({$conf['folioIni']}-{$conf['folioFin']}) para el tipo {$tipo}. Esto no se puede deshacer."
        );
        try {
            $gen = $c->generar($conf, function () use ($id): void {
                // Se graba ANTES de enviar: si el proceso muere, la fila no parece "nada enviado".
                $this->repo->avanzar($id, 'incierto', [
                    'genera_enviado_at' => $this->ahora()->format(DATE_ATOM),
                    'error_codigo' => 'EN_CURSO',
                    'error_detalle' => 'Se envió la generación de folios al SII; falta su respuesta.',
                ], $this->ahora());
            });
        } catch (ErrorTimbraje $e) {
            // Solo se lanza antes de enviar (validación defensiva).
            $this->emitir('generar', 'error', $tipo, $e->getMessage(), $e->codigoSii);
            $this->fallarAntes($id, $e->codigo, $e->getMessage(), $e->codigoSii);
            return 'seguir';
        }
        $this->registrar('caf_genera', ['solicitudId' => $id, 'tipo' => $tipo, 'estado' => $gen['estado'], 'codigo' => $gen['error']['codigo'] ?? null]);

        if ($gen['estado'] === 'incierto') {
            if (in_array($gen['error']['codigo'], ['MAX_SESIONES', 'NO_AUTENTICADO'], true)) {
                // La fila sigue 'incierto' (no se pierde el rango); la sesión del SII quedó inutilizable.
                $this->sesionPostGenera = ['codigo' => $gen['error']['codigo'], 'detalle' => $gen['error']['detalle']];
            }
            $this->emitir('generar', 'error', $tipo, 'Respuesta incierta del SII: ' . $gen['error']['detalle'] . ' No se reintenta.', $gen['error']['codigoSii'] ?? null);
            $this->repo->avanzar($id, 'incierto', [
                'error_codigo' => $gen['error']['codigo'],
                'error_codigo_sii' => $gen['error']['codigoSii'] ?? null,
                'error_detalle' => $gen['error']['detalle'],
            ], $this->ahora());
            return 'detener';
        }
        if ($gen['estado'] === 'error_sii') {
            $this->emitir('generar', 'error', $tipo, 'El SII rechazó la generación: ' . $gen['error']['detalle'], $gen['error']['codigoSii'] ?? null);
            $this->repo->avanzar($id, 'error_sii', [
                'error_codigo' => $gen['error']['codigo'],
                'error_codigo_sii' => $gen['error']['codigoSii'] ?? null,
                'error_detalle' => $gen['error']['detalle'],
            ], $this->ahora());
            return 'detener';
        }
        $this->emitir('generar', 'ok', $tipo, "SII generó el rango {$gen['folioIni']}-{$gen['folioFin']} para el tipo {$tipo}.");

        $datos = [
            'folio_ini' => $gen['folioIni'],
            'folio_fin' => $gen['folioFin'],
            'fecha_sii' => $gen['fecha'],
            'error_codigo' => null,
            'error_codigo_sii' => null,
            'error_detalle' => $gen['advertencia'],
        ];
        if (!$gen['fechaValida']) {
            $this->repo->avanzar($id, 'generado_sin_descargar', [
                'error_codigo' => $gen['error']['codigo'] ?? 'FECHA_P7_INVALIDA',
                'error_detalle' => $gen['error']['detalle'] ?? null,
            ] + $datos, $this->ahora());
            return 'seguir';
        }
        $this->repo->avanzar($id, 'generado', $datos, $this->ahora());
        $this->descargarEInstalar($c, $id, $tipo, $gen['folioIni'], $gen['folioFin'], $gen['fecha'], $gen['form'], 'generado_sin_descargar');
        return 'seguir';
    }

    /**
     * of_genera_archivo → instalador. Los bytes nunca salen de este método.
     *
     * @param 'generado_sin_descargar' $estadoSiFalla
     */
    private function descargarEInstalar(
        TimbrajeClient $c,
        string $id,
        int $tipo,
        int $ini,
        int $fin,
        string $fecha,
        ?array $formP7,
        string $estadoSiFalla,
    ): void {
        $this->pausar("descargar el CAF del tipo {$tipo}", $tipo);
        $this->emitir('descargar', 'en_curso', $tipo, "Descargando del SII el CAF del tipo {$tipo} (rango {$ini}-{$fin})...");
        try {
            $bytes = $formP7 !== null ? $c->descargarConFormulario($formP7) : $c->descargar($tipo, $ini, $fin, $fecha);
        } catch (ErrorSesionSii $e) {
            $this->emitir('descargar', 'error', $tipo, $e->getMessage());
            $this->fallarDescarga($id, $estadoSiFalla, $e->codigo, $e->getMessage());
            throw $e; // la sesión ya no sirve: aborta el resto
        } catch (\Throwable $e) {
            $codigo = $e instanceof ErrorTimbraje ? $e->codigo : ($e instanceof ErrorTransporte && $e->tiempoAgotado ? 'TIEMPO_AGOTADO' : 'DESCARGA_FALLIDA');
            $codigoSii = $e instanceof ErrorTimbraje ? $e->codigoSii : null;
            $this->emitir('descargar', 'error', $tipo, $e->getMessage(), $codigoSii);
            $this->fallarDescarga($id, $estadoSiFalla, $codigo, $e->getMessage(), null, $codigoSii);
            return;
        }
        $this->emitir('descargar', 'ok', $tipo, "CAF del tipo {$tipo} descargado del SII.");

        $this->emitir('instalar', 'en_curso', $tipo, "Validando e instalando el CAF del tipo {$tipo}...");
        $esperado = ['tipo' => $tipo, 'rutEmisor' => $this->rutEmisor, 'folioIni' => $ini, 'folioFin' => $fin, 'fecha' => $fecha];
        try {
            $r = ($this->instalar)($bytes, $esperado, $this->ambiente);
        } catch (\Throwable $e) {
            $bytes = '';
            $this->emitir('instalar', 'error', $tipo, 'El instalador falló.');
            $this->fallarDescarga($id, $estadoSiFalla, 'INSTALACION_FALLIDA', 'El instalador falló (' . get_class($e) . ').');
            return;
        }
        $detalleSii = null;
        $codigoSiiInstalar = null;
        if (($r['estado'] ?? null) === 'no_es_caf' && !self::pareceCaf($bytes)) {
            $errSii = FormularioSii::detectarError($bytes, 'of_genera_archivo');
            $detalleSii = $errSii['detalle'] ?? null;
            $codigoSiiInstalar = $errSii['codigo_sii'] ?? null;
        }
        $bytes = '';

        $estado = $r['estado'] ?? null;
        $this->registrar('caf_instalacion', ['solicitudId' => $id, 'tipo' => $tipo, 'estado' => $estado, 'sha256' => $r['sha256'] ?? null]);
        if ($estado === 'instalado') {
            $this->emitir('instalar', 'ok', $tipo, "CAF del tipo {$tipo} instalado (archivo {$r['archivo']}).");
            $this->repo->avanzar($id, 'instalado', [
                'archivo' => $r['archivo'] ?? null,
                'sha256' => $r['sha256'] ?? null,
                'idk' => $r['idk'] ?? null,
                'error_codigo' => null,
                'error_codigo_sii' => null,
                'error_detalle' => null,
            ], $this->ahora());
            return;
        }
        [$codigo, $detalle] = match ($estado) {
            'no_es_caf' => ['NO_ES_CAF', 'El SII no devolvió un CAF' . ($detalleSii ? ': ' . $detalleSii : '.')],
            'rechazado' => ['CAF_RECHAZADO', (string) ($r['motivo'] ?? 'El CAF no pasó la validación.')],
            'archivo_existente' => ['ARCHIVO_EXISTENTE', (string) ($r['motivo'] ?? 'Ya existe un CAF con ese rango.')],
            default => ['INSTALACION_FALLIDA', 'Resultado inesperado del instalador.'],
        };
        $this->emitir('instalar', 'error', $tipo, $detalle, $codigoSiiInstalar);
        $this->fallarDescarga($id, $estadoSiFalla, $codigo, $detalle, $r['sha256'] ?? null, $codigoSiiInstalar);
    }

    /**
     * Abre UNA sesión, ejecuta $op y la cierra (logout en finally dentro de
     * SiiPortalSesion::ejecutar). Nunca lanza: devuelve el estado de la sesión.
     *
     * @param list<string> $ids
     * @return array{id: string, loginOk: bool, logoutOk: bool, error: ?string, detalle: ?string}
     */
    private function conSesion(string $motivo, array $ids, \Closure $op): array
    {
        $sesionId = $this->repo->iniciarSesion($motivo, $this->servidor, $this->ahora());
        if ($ids !== []) {
            $this->repo->asignarSesion($ids, $sesionId, $this->ahora());
        }
        $this->registrar('sii_sesion_inicio', ['sesionId' => $sesionId, 'motivo' => $motivo, 'servidor' => $this->servidor]);
        $this->loginEmitido = false;
        $this->emitir('login', 'en_curso', null, 'Iniciando sesión en el portal del SII con el certificado digital...');

        $sesion = new SiiPortalSesion($this->transporte, $this->reloj);
        $this->sesionPostGenera = null;
        $error = null;
        $detalle = null;
        try {
            $max = $this->maxFolioLocal;
            $cliente = new TimbrajeClient(
                $sesion,
                $this->servidor,
                $this->rutEmisor,
                $this->reloj,
                $max === null ? null : fn (int $tipo): int => (int) $max($tipo, $this->ambiente)
            );
            $sesion->ejecutar($cliente->urlInicio(), function () use ($cliente, $op): void {
                // Si llegamos aquí, el login ya fue exitoso (SiiPortalSesion::ejecutar lo hace antes de esta clausura).
                $this->loginEmitido = true;
                $this->emitir('login', 'ok', null, 'Sesión iniciada en el portal del SII.');
                $this->pausar('abrir el formulario de solicitud de folios');
                $this->emitir('abrir_formulario', 'en_curso', null, 'Abriendo el formulario de solicitud de folios...');
                $cliente->abrir();
                $this->emitir('abrir_formulario', 'ok', null, 'Formulario de solicitud de folios abierto.');
                $op($cliente);
            });
        } catch (ErrorSesionSii $e) {
            $error = $e->codigo;
            $detalle = $e->getMessage();
        } catch (ErrorTimbraje $e) {
            // Dentro de $op, procesarTipo() atrapa sus propios ErrorTimbraje (consultar/confirmar/generar/
            // descargar); el único que puede llegar hasta aquí sin atrapar es el de $cliente->abrir().
            $error = $e->codigo;
            $detalle = $e->getMessage();
            if ($this->loginEmitido) {
                $this->emitir('abrir_formulario', 'error', null, $detalle, $e->codigoSii);
            }
        } catch (\Throwable $e) {
            $error = 'ERROR_INTERNO';
            $detalle = get_class($e) . ': ' . $e->getMessage();
        }
        if (!$this->loginEmitido && $error !== null) {
            $this->emitir('login', 'error', null, CafSolicitudRepository::sanear($detalle) ?? 'No se pudo iniciar sesión en el SII.');
        }

        // Error de sesión tragado tras of_genera_folio: se registra igual (bloqueo de 2 h del presupuesto).
        $errorBitacora = $error;
        if ($error === null && $this->sesionPostGenera !== null) {
            $error = $this->sesionPostGenera['codigo'];
            $detalle = $this->sesionPostGenera['detalle'];
            $errorBitacora = $error === 'MAX_SESIONES' ? 'MAX_SESIONES' : CafSolicitudRepository::ERROR_BLOQUEO_POST_GENERA;
        }

        $st = $sesion->estado();
        $this->pausar('cerrar la sesión en el SII');
        $this->emitir('logout', $st['logoutOk'] ? 'ok' : 'aviso', null, $st['logoutOk'] ? 'Sesión cerrada en el SII.' : 'No se pudo confirmar el cierre de sesión en el SII (se reintentó).');
        try {
            $this->repo->cerrarSesion($sesionId, $st['loginOk'], $st['logoutOk'], $errorBitacora, $this->ahora());
        } catch (\Throwable) {
            $this->registrar('sii_sesion_bitacora_fallida', ['sesionId' => $sesionId]);
        }
        $this->registrar('sii_sesion_fin', [
            'sesionId' => $sesionId, 'loginOk' => $st['loginOk'], 'logoutOk' => $st['logoutOk'], 'error' => $error,
        ]);
        return [
            'id' => $sesionId,
            'loginOk' => $st['loginOk'],
            'logoutOk' => $st['logoutOk'],
            'error' => $error,
            'detalle' => CafSolicitudRepository::sanear($detalle),
        ];
    }

    private function fallarAntes(string $id, string $codigo, string $detalle, ?string $codigoSii = null): void
    {
        $this->repo->avanzar($id, 'error_antes_de_generar', [
            'error_codigo' => $codigo, 'error_codigo_sii' => $codigoSii, 'error_detalle' => $detalle,
        ], $this->ahora());
    }

    private function fallarDescarga(string $id, string $estado, string $codigo, string $detalle, ?string $sha = null, ?string $codigoSii = null): void
    {
        $datos = ['error_codigo' => $codigo, 'error_codigo_sii' => $codigoSii, 'error_detalle' => $detalle];
        if ($sha !== null) {
            $datos['sha256'] = $sha;
        }
        $this->repo->avanzar($id, $estado, $datos, $this->ahora());
    }

    // ------------------------------------------------------------------
    // Validación, lock, respuestas
    // ------------------------------------------------------------------

    /**
     * @return array{rechazo: array}|array{clave: string, lote: list<array{tipo: int, cantidad: int}>,
     *   permitirMenos: bool, email: ?string, consultaId: ?string}
     */
    private function validarPedido(array $p): array
    {
        $rechazo = fn (int $http, string $codigo, string $motivo): array => ['rechazo' => $this->respuesta('rechazado', $http, $codigo, $motivo, null, null, [])];

        if (($p['confirmacion'] ?? null) !== self::CONFIRMACION) {
            return $rechazo(428, 'CONFIRMACION_REQUERIDA', 'Falta escribir ' . self::CONFIRMACION . '.');
        }
        if (($p['ambiente'] ?? null) !== $this->ambiente) {
            return $rechazo(400, 'AMBIENTE_DISTINTO', "Este servicio solicita CAF solo para {$this->ambiente}.");
        }
        $clave = $p['idempotencyKey'] ?? null;
        if (!is_string($clave) || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $clave) !== 1) {
            return $rechazo(400, 'ENTRADA_INVALIDA', 'idempotencyKey inválida.');
        }
        $permitirMenos = $p['permitirMenos'] ?? false;
        if (!is_bool($permitirMenos)) {
            return $rechazo(400, 'ENTRADA_INVALIDA', 'permitirMenos debe ser booleano.');
        }
        $email = $p['usuarioEmail'] ?? null;
        if ($email !== null && (!is_string($email) || strlen($email) > 200)) {
            return $rechazo(400, 'ENTRADA_INVALIDA', 'usuarioEmail inválido.');
        }
        $lote = $p['lote'] ?? null;
        if (!is_array($lote) || $lote === [] || !array_is_list($lote) || count($lote) > count($this->tipos)) {
            return $rechazo(400, 'ENTRADA_INVALIDA', 'El lote debe ser una lista no vacía de {tipo, cantidad}.');
        }
        $limpio = [];
        foreach ($lote as $item) {
            $tipo = is_array($item) ? self::entero($item['tipo'] ?? null) : null;
            $cant = is_array($item) ? self::entero($item['cantidad'] ?? null) : null;
            if ($tipo === null || $cant === null) {
                return $rechazo(400, 'ENTRADA_INVALIDA', 'Cada ítem del lote necesita tipo y cantidad enteros.');
            }
            if (!in_array($tipo, $this->tipos, true)) {
                return $rechazo(400, 'TIPO_NO_HABILITADO', "El tipo {$tipo} no está habilitado (habilitados: " . implode(', ', $this->tipos) . ').');
            }
            if (isset($limpio[$tipo])) {
                return $rechazo(400, 'TIPO_REPETIDO', "El tipo {$tipo} viene repetido en el lote.");
            }
            if ($cant < 1 || $cant > $this->topes[$tipo]) {
                return $rechazo(400, 'CANTIDAD_FUERA_DE_RANGO', "La cantidad del tipo {$tipo} debe estar entre 1 y {$this->topes[$tipo]}.");
            }
            $limpio[$tipo] = ['tipo' => $tipo, 'cantidad' => $cant];
        }
        $consultaId = $p['consultaId'] ?? null;
        if ($consultaId !== null && (!is_string($consultaId) || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $consultaId) !== 1)) {
            return $rechazo(400, 'ENTRADA_INVALIDA', 'consultaId inválido.');
        }
        return [
            'clave' => $clave, 'lote' => array_values($limpio), 'permitirMenos' => $permitirMenos, 'email' => $email,
            'consultaId' => $consultaId,
        ];
    }

    /**
     * Si $v['consultaId'] viene: exige que la consulta exista, sea del mismo
     * ambiente, tenga ≤ 30 min y que cada cantidad pedida no supere el
     * maxAutor visto para su tipo. Devuelve el rechazo (404/400/422) o null si
     * pasa (o si no vino consultaId: v1 sin consulta previa, de siempre).
     */
    private function validarConsultaPrevia(array $v): ?array
    {
        $consultaId = $v['consultaId'] ?? null;
        if ($consultaId === null) {
            return null;
        }
        $c = $this->repo->obtenerConsulta($consultaId);
        if ($c === null) {
            return $this->respuesta('rechazado', 404, 'NO_EXISTE', 'No existe esa consulta de cupo.', null, null, []);
        }
        if ($c['ambiente'] !== $this->ambiente) {
            return $this->respuesta('rechazado', 400, 'AMBIENTE_DISTINTO', 'La consulta indicada es de otro ambiente.', null, null, []);
        }
        $edad = $this->ahora()->getTimestamp() - (new \DateTimeImmutable($c['ts']))->getTimestamp();
        if ($edad > self::CONSULTA_VIGENCIA_SEGUNDOS) {
            return $this->respuesta('rechazado', 422, 'CONSULTA_VENCIDA', 'La consulta de cupo tiene más de 30 minutos; vuelve a consultar antes de solicitar.', null, null, []);
        }
        foreach ($v['lote'] as $item) {
            $info = $c['resultados'][(string) $item['tipo']] ?? null;
            if (!is_array($info) || isset($info['error'])) {
                return $this->respuesta(
                    'rechazado', 422, 'CONSULTA_SIN_TIPO',
                    "La consulta no incluyó el tipo {$item['tipo']} (o tuvo un error); vuelve a consultarlo antes de solicitar.",
                    null, null, []
                );
            }
            // Un maxAutor null (registrado como tal) es "el SII no informó tope" (boleta 39):
            // no hay máximo contra el cual comparar; la cantidad ya se acotó al tope de la plataforma.
            if (array_key_exists('maxAutor', $info) && $info['maxAutor'] === null
                && in_array((int) $item['tipo'], TimbrajeClient::TIPOS_SIN_MAX_AUTOR, true)) {
                continue;
            }
            $max = (int) ($info['maxAutor'] ?? 0);
            if ($item['cantidad'] > $max) {
                return $this->respuesta(
                    'rechazado', 422, 'CANTIDAD_SOBRE_MAXIMO',
                    "La consulta vio un máximo autorizado de {$max} para el tipo {$item['tipo']} y se pidieron {$item['cantidad']}.",
                    null, null, []
                );
            }
        }
        return null;
    }

    /** Texto de la traza para el cupo leído en p5 (maxAutor null = el SII no informó tope). */
    private static function textoCupo(int $tipo, array $cupo): string
    {
        $max = $cupo['maxAutor'] === null ? 'sin tope informado por el SII' : "máximo autorizado {$cupo['maxAutor']}";
        return "SII informa para el tipo {$tipo}: {$max}, folios disponibles " . ($cupo['foliosDisp'] ?? 'desconocido') . '.';
    }

    private function conLock(\Closure $fn): array
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $fh = @fopen($this->lockPath, 'c');
        if ($fh === false) {
            return $this->respuesta('error', 500, 'LOCK_NO_DISPONIBLE', 'No se pudo tomar el lock de solicitudes de CAF.', null, null, []);
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return $this->respuesta('ocupado', 409, 'EN_CURSO', 'Hay otra solicitud de CAF en curso.', null, null, []);
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function respuestaDeSesion(array $sesion, ?string $loteId, array $filas): array
    {
        $estado = $sesion['error'] === null ? 'completado' : 'error_sesion';
        $http = match (true) {
            $sesion['error'] === null => 200,
            $sesion['error'] === 'MAX_SESIONES' => 423,
            default => 502,
        };
        return $this->respuesta($estado, $http, $sesion['error'], $sesion['detalle'], $loteId, $sesion, $filas);
    }

    /** @param list<array<string, mixed>> $filas */
    private function respuesta(string $estado, int $http, ?string $codigo, ?string $motivo, ?string $loteId, ?array $sesion, array $filas, array $extra = []): array
    {
        return [
            'estado' => $estado,
            'http' => $http,
            'codigo' => $codigo,
            'motivo' => CafSolicitudRepository::sanear($motivo),
            'loteId' => $loteId,
            'sesion' => $sesion === null ? null : [
                'loginOk' => $sesion['loginOk'],
                'logoutOk' => $sesion['logoutOk'],
                'error' => $sesion['error'],
            ],
            'resultados' => array_map(fn (array $f): array => $this->resultadoFila($f), $filas),
        ] + $extra;
    }

    /** Solo metadatos de una fila de caf_solicitudes. */
    private function resultadoFila(array $f): array
    {
        $int = static fn ($v): ?int => $v === null ? null : (int) $v;
        return [
            'solicitudId' => $f['id'],
            'loteId' => $f['lote_id'],
            'ambiente' => $f['ambiente'],
            'servidor' => $f['servidor'],
            'tipo' => (int) $f['tipo_dte'],
            'cantidadPedida' => (int) $f['cantidad_pedida'],
            'cantidadAutorizada' => $int($f['cantidad_autorizada']),
            'estado' => $f['estado'],
            'maxAutor' => $int($f['max_autor']),
            'foliosDispSii' => $int($f['folios_disp_sii']),
            'folioIni' => $int($f['folio_ini']),
            'folioFin' => $int($f['folio_fin']),
            'fecha' => $f['fecha_sii'],
            'archivo' => $f['archivo'],
            'sha256' => $f['sha256'],
            'idk' => $int($f['idk']),
            'error' => $f['error_codigo'] === null ? null : [
                'codigo' => $f['error_codigo'], 'detalle' => $f['error_detalle'], 'codigoSii' => $f['error_codigo_sii'] ?? null,
            ],
            'creada' => $f['created_at'],
            'actualizada' => $f['updated_at'],
        ];
    }

    // ------------------------------------------------------------------
    // v1.1 transparencia: eventos (traza) y pausas
    // ------------------------------------------------------------------

    /**
     * Emite un evento de la traza: se persiste SIEMPRE (repo->registrarEvento)
     * y, si hay un listener inyectado (streaming NDJSON), se le entrega
     * también. $mensaje ya debe venir en español y sin datos sensibles; de
     * todos modos pasa por CafSolicitudRepository::sanear al persistir.
     */
    private function emitir(string $paso, string $estado, ?int $tipoDte, string $mensaje, ?string $codigoSii = null, ?float $segundos = null): void
    {
        $evento = [
            'sesionId' => $this->eventoSesionId,
            'loteId' => $this->eventoLoteId,
            'consultaId' => $this->eventoConsultaId,
            'ts' => $this->ahora()->format(DATE_ATOM),
            'paso' => $paso,
            'estado' => $estado,
            'tipoDte' => $tipoDte,
            'mensaje' => CafSolicitudRepository::sanear($mensaje, 500) ?? '',
            'codigoSii' => CafSolicitudRepository::sanear($codigoSii, 60),
            'segundos' => $segundos,
        ];
        try {
            $this->repo->registrarEvento($evento);
        } catch (\Throwable) {
            // la traza no interrumpe la operación
        }
        if ($this->eventoListener !== null) {
            try {
                ($this->eventoListener)($evento);
            } catch (\Throwable) {
                // un listener roto (streaming cortado) no interrumpe la operación
            }
        }
    }

    /** Espera $this->pausaMs (con el sleeper inyectado) y emite el evento 'pausa'. Sin efecto si pausaMs es 0. */
    private function pausar(string $antesDe, ?int $tipoDte = null): void
    {
        if ($this->pausaMs <= 0) {
            return;
        }
        $segundos = $this->pausaMs / 1000;
        $this->emitir('pausa', 'espera', $tipoDte, sprintf('Pausa de %.1f s antes de %s, para no saturar al SII.', $segundos, $antesDe), null, $segundos);
        ($this->sleeper)($this->pausaMs);
    }

    private function registrar(string $evento, array $contexto): void
    {
        if ($this->log === null) {
            return;
        }
        array_walk_recursive($contexto, static function (&$v): void {
            if (is_string($v)) {
                $v = CafSolicitudRepository::sanear($v, 300);
            }
        });
        try {
            ($this->log)($evento, $contexto);
        } catch (\Throwable) {
            // un log roto no interrumpe la operación
        }
    }

    private function ahora(): \DateTimeImmutable
    {
        return ($this->reloj)()->setTimezone(new \DateTimeZone('America/Santiago'));
    }

    private static function entero(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d{1,6}$/', $v) === 1) {
            return (int) $v;
        }
        return null;
    }

    private static function pareceCaf(string $bytes): bool
    {
        return stripos($bytes, 'AUTORIZACION') !== false || stripos($bytes, 'RSASK') !== false
            || str_starts_with(ltrim($bytes), '<?xml');
    }

    private static function nuevoId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
