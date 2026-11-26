<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Bitácora SQLite de solicitudes de CAF y de sesiones abiertas en el SII
 * (mismo patrón que FolioRepository: PDO sqlite, tablas creadas al vuelo).
 * Por defecto /data/db/caf_solicitudes.sqlite, compartida entre ambientes
 * porque el presupuesto de sesiones es por el RUT del dueño del certificado.
 *
 * Solo METADATOS: nunca cookies, bytes del CAF, claves ni datos del
 * certificado. Todo texto libre pasa por sanear(). No hay DELETE: solo se
 * agregan filas o se avanza el estado.
 *
 * Máquina de estados de caf_solicitudes (diseño §6):
 *
 *   pendiente → consultado → confirmado → incierto* → generado → instalado
 *       │            │            │          │  │          └→ generado_sin_descargar → instalado
 *       └────────────┴────────────┴→ error_antes_de_generar (sin efecto en el SII)
 *                                            │  └→ error_sii (el SII rechazó en of_genera_folio)
 *                                            └→ generado_sin_descargar | instalado (recuperar; desde 'incierto' NO)
 *       incierto | generado_sin_descargar → descartada (resolución manual con motivo)
 *
 *   * 'incierto' se graba JUSTO ANTES de enviar of_genera_folio (con
 *     genera_enviado_at). Si el proceso muere a mitad, la fila queda
 *     'incierto' y nunca parece "nada enviado".
 */
final class CafSolicitudRepository
{
    public const RUTA_POR_DEFECTO = '/data/db/caf_solicitudes.sqlite';

    public const ESTADOS = [
        'pendiente', 'consultado', 'confirmado', 'incierto', 'generado',
        'generado_sin_descargar', 'instalado', 'error_antes_de_generar', 'error_sii', 'descartada',
    ];

    /** Estados desde los que se puede volver a descargar con of_genera_archivo (rango confirmado por el SII). */
    public const RECUPERABLES = ['generado', 'generado_sin_descargar'];

    /** Solicitudes abiertas tras el punto irreversible: bloquean pedir de nuevo el mismo tipo hasta resolverlas. */
    public const ABIERTAS = ['incierto', 'generado', 'generado_sin_descargar'];

    /** Estados desde los que el operador puede marcarla resuelta a mano (-> 'descartada'). */
    public const DESCARTABLES = ['incierto', 'generado_sin_descargar'];

    /** Error de sii_sesiones con el que se registra un NO_AUTENTICADO visto tras of_genera_folio (bloquea como MAX_SESIONES). */
    public const ERROR_BLOQUEO_POST_GENERA = 'BLOQUEO_POST_GENERA';

    private const TRANSICIONES = [
        'pendiente' => ['consultado', 'error_antes_de_generar'],
        'consultado' => ['confirmado', 'error_antes_de_generar'],
        'confirmado' => ['incierto', 'error_antes_de_generar'],
        'incierto' => ['incierto', 'generado', 'generado_sin_descargar', 'instalado', 'error_sii', 'descartada'],
        'generado' => ['instalado', 'generado_sin_descargar'],
        'generado_sin_descargar' => ['generado_sin_descargar', 'instalado', 'descartada'],
        'instalado' => [],
        'error_antes_de_generar' => [],
        'error_sii' => [],
        'descartada' => [],
    ];

    private const COLUMNAS_EDITABLES = [
        'sesion_id', 'max_autor', 'folios_disp_sii', 'cantidad_autorizada', 'folio_ini', 'folio_fin',
        'fecha_sii', 'archivo', 'sha256', 'idk', 'error_codigo', 'error_codigo_sii', 'error_detalle', 'genera_enviado_at',
    ];

    /** Eventos por GET /caf/eventos (y por línea NDJSON): tope duro, aparte del $limite que pida el llamador. */
    public const EVENTOS_MAXIMO = 200;

    private const TEXTO_OMITIDO = '[detalle omitido: podía contener datos sensibles]';

    private \PDO $pdo;

    /**
     * @param array{max?: int, ventana?: int, espaciado?: int, bloqueo?: int} $presupuesto
     *   max sesiones por ventana (2 en 2 h), espaciado mínimo entre inicios (10 min)
     *   y bloqueo tras "máximo de sesiones" (2 h). Segundos.
     */
    public function __construct(string $dbPath = self::RUTA_POR_DEFECTO, private readonly array $presupuesto = [])
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sii_sesiones (
                id TEXT PRIMARY KEY,
                motivo TEXT NOT NULL,
                servidor TEXT NOT NULL,
                inicio TEXT NOT NULL,
                inicio_ts INTEGER NOT NULL,
                fin TEXT,
                login_ok INTEGER,
                logout_ok INTEGER,
                error TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS caf_solicitudes (
                id TEXT PRIMARY KEY,
                lote_id TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                sesion_id TEXT,
                ambiente TEXT NOT NULL,
                servidor TEXT NOT NULL,
                tipo_dte INTEGER NOT NULL,
                cantidad_pedida INTEGER NOT NULL,
                permitir_menos INTEGER NOT NULL DEFAULT 0,
                cantidad_autorizada INTEGER,
                estado TEXT NOT NULL,
                max_autor INTEGER,
                folios_disp_sii INTEGER,
                folio_ini INTEGER,
                folio_fin INTEGER,
                fecha_sii TEXT,
                genera_enviado_at TEXT,
                archivo TEXT,
                sha256 TEXT,
                idk INTEGER,
                error_codigo TEXT,
                error_codigo_sii TEXT,
                error_detalle TEXT,
                evidencia_path TEXT,
                usuario_email TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (idempotency_key, tipo_dte)
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_caf_sol_idem ON caf_solicitudes (idempotency_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sii_ses_inicio ON sii_sesiones (inicio_ts)');
        // v1.1 transparencia: traza paso a paso de cada sesión (persistida y, además,
        // enviada en vivo a quien escuche vía el listener inyectable) y consultas de
        // solo lectura (cupo) previas a una solicitud real.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS caf_eventos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sesion_id TEXT NOT NULL,
                lote_id TEXT,
                consulta_id TEXT,
                ts TEXT NOT NULL,
                paso TEXT NOT NULL,
                estado TEXT NOT NULL,
                tipo_dte INTEGER,
                mensaje TEXT NOT NULL,
                codigo_sii TEXT,
                segundos REAL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_caf_eventos_lote ON caf_eventos (lote_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_caf_eventos_consulta ON caf_eventos (consulta_id)');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS caf_consultas (
                id TEXT PRIMARY KEY,
                sesion_id TEXT,
                ambiente TEXT NOT NULL,
                servidor TEXT NOT NULL,
                ts TEXT NOT NULL,
                resultados TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_caf_consultas_ambiente ON caf_consultas (ambiente, ts)');
        // Migración idempotente (igual criterio que las tablas CREATE TABLE IF NOT
        // EXISTS de arriba): agrega la columna a una BD creada antes de v1.1.
        self::migrarColumna($this->pdo, 'caf_solicitudes', 'error_codigo_sii', 'TEXT');
    }

    /** ALTER TABLE ... ADD COLUMN solo si la columna no existe; seguro de llamar en cada arranque. */
    private static function migrarColumna(\PDO $pdo, string $tabla, string $columna, string $tipo): void
    {
        $cols = $pdo->query("PRAGMA table_info({$tabla})")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array($columna, $cols, true)) {
            $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$columna} {$tipo}");
        }
    }

    // ------------------------------------------------------------------
    // Solicitudes
    // ------------------------------------------------------------------

    /**
     * Crea las filas de un lote en estado 'pendiente' (transacción única).
     *
     * @param list<array{tipo: int, cantidad: int}> $lote
     * @return list<string> ids, en el orden del lote
     */
    public function crearLote(
        string $loteId,
        string $idempotencyKey,
        string $ambiente,
        string $servidor,
        array $lote,
        bool $permitirMenos,
        ?string $usuarioEmail,
        \DateTimeImmutable $ahora,
    ): array {
        $ts = self::iso($ahora);
        $ids = [];
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO caf_solicitudes (id, lote_id, idempotency_key, ambiente, servidor, tipo_dte, cantidad_pedida,
                    permitir_menos, estado, usuario_email, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\', ?, ?, ?)'
            );
            foreach ($lote as $item) {
                $id = self::nuevoId();
                $st->execute([
                    $id, $loteId, $idempotencyKey, $ambiente, $servidor, (int) $item['tipo'], (int) $item['cantidad'],
                    $permitirMenos ? 1 : 0, self::sanear($usuarioEmail, 200), $ts, $ts,
                ]);
                $ids[] = $id;
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        return $ids;
    }

    /**
     * Avanza una solicitud a $estado validando la transición y actualiza las
     * columnas permitidas. Los textos se sanean.
     *
     * @param array<string, scalar|null> $datos
     */
    public function avanzar(string $id, string $estado, array $datos, \DateTimeImmutable $ahora): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new \InvalidArgumentException("Estado desconocido: {$estado}");
        }
        foreach (array_keys($datos) as $col) {
            if (!in_array($col, self::COLUMNAS_EDITABLES, true)) {
                throw new \InvalidArgumentException("Columna no editable: {$col}");
            }
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $this->pdo->prepare('SELECT estado FROM caf_solicitudes WHERE id = ?');
            $st->execute([$id]);
            $actual = $st->fetchColumn();
            if ($actual === false) {
                throw new \InvalidArgumentException("Solicitud inexistente: {$id}");
            }
            if (!in_array($estado, self::TRANSICIONES[$actual] ?? [], true)) {
                throw new \LogicException("Transición no permitida: {$actual} → {$estado}");
            }
            $sets = ['estado = ?', 'updated_at = ?'];
            $valores = [$estado, self::iso($ahora)];
            foreach ($datos as $col => $v) {
                $sets[] = "{$col} = ?";
                $valores[] = is_string($v) ? self::sanear($v, $col === 'error_detalle' ? 500 : 200) : $v;
            }
            $valores[] = $id;
            $this->pdo->prepare('UPDATE caf_solicitudes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Filas sin resolver (incierto, generado, generado_sin_descargar) del
     * ambiente para alguno de los tipos dados.
     *
     * @param list<int> $tipos
     * @return list<array<string, mixed>>
     */
    public function abiertas(string $ambiente, array $tipos): array
    {
        if ($tipos === []) {
            return [];
        }
        $estados = implode(',', array_fill(0, count(self::ABIERTAS), '?'));
        $tp = implode(',', array_fill(0, count($tipos), '?'));
        $st = $this->pdo->prepare("SELECT * FROM caf_solicitudes WHERE ambiente = ? AND estado IN ({$estados}) AND tipo_dte IN ({$tp}) ORDER BY rowid");
        $st->execute([$ambiente, ...self::ABIERTAS, ...array_map('intval', $tipos)]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Resolución manual: 'incierto' o 'generado_sin_descargar' → 'descartada'.
     * Conserva el código de error previo en el detalle (no hay columnas nuevas:
     * el motivo va en error_detalle y la fecha en updated_at).
     */
    public function marcarResuelta(string $id, string $motivo, \DateTimeImmutable $ahora): void
    {
        $fila = $this->obtener($id);
        if ($fila === null) {
            throw new \InvalidArgumentException("Solicitud inexistente: {$id}");
        }
        if (!in_array($fila['estado'], self::DESCARTABLES, true)) {
            throw new \LogicException("Transición no permitida: {$fila['estado']} → descartada");
        }
        $previo = $fila['estado'] . ($fila['error_codigo'] !== null ? '/' . $fila['error_codigo'] : '');
        $this->avanzar($id, 'descartada', [
            'error_codigo' => 'DESCARTADA_MANUAL',
            'error_detalle' => "Resuelta a mano el " . self::iso($ahora) . " (antes: {$previo}). Motivo: " . (self::sanear($motivo, 250) ?? ''),
        ], $ahora);
    }

    /** Asocia las filas aún 'pendiente' a la sesión que las va a procesar. @param list<string> $ids */
    public function asignarSesion(array $ids, string $sesionId, \DateTimeImmutable $ahora): void
    {
        $st = $this->pdo->prepare("UPDATE caf_solicitudes SET sesion_id = ?, updated_at = ? WHERE id = ? AND estado = 'pendiente'");
        foreach ($ids as $id) {
            $st->execute([$sesionId, self::iso($ahora), $id]);
        }
    }

    /** @return array<string, mixed>|null */
    public function obtener(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_solicitudes WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch(\PDO::FETCH_ASSOC);
        return $f === false ? null : $f;
    }

    /** @return list<array<string, mixed>> filas de esa clave, en el orden en que se crearon */
    public function porIdempotencia(string $clave): array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_solicitudes WHERE idempotency_key = ? ORDER BY rowid');
        $st->execute([$clave]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> filas de un lote, en orden */
    public function porLote(string $loteId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_solicitudes WHERE lote_id = ? ORDER BY rowid');
        $st->execute([$loteId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> las más recientes primero */
    public function listar(int $limite = 50): array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_solicitudes ORDER BY rowid DESC LIMIT ?');
        $st->bindValue(1, max(1, min(500, $limite)), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Sesiones y presupuesto
    // ------------------------------------------------------------------

    /** Registra el inicio de una sesión (cuenta para el presupuesto aunque el login falle). */
    public function iniciarSesion(string $motivo, string $servidor, \DateTimeImmutable $ahora): string
    {
        $id = self::nuevoId();
        $this->pdo->prepare('INSERT INTO sii_sesiones (id, motivo, servidor, inicio, inicio_ts) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, self::sanear($motivo, 40), $servidor, self::iso($ahora), $ahora->getTimestamp()]);
        return $id;
    }

    public function cerrarSesion(string $id, bool $loginOk, bool $logoutOk, ?string $error, \DateTimeImmutable $ahora): void
    {
        $this->pdo->prepare('UPDATE sii_sesiones SET fin = ?, login_ok = ?, logout_ok = ?, error = ? WHERE id = ?')
            ->execute([self::iso($ahora), $loginOk ? 1 : 0, $logoutOk ? 1 : 0, self::sanear($error, 200), $id]);
    }

    /** @return array<string, mixed>|null */
    public function sesion(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM sii_sesiones WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch(\PDO::FETCH_ASSOC);
        return $f === false ? null : $f;
    }

    /**
     * ¿Se puede abrir otra sesión ahora? Rechaza si hay ≥ max inicios en la
     * ventana, un inicio hace menos del espaciado, o un "máximo de sesiones"
     * del SII dentro del bloqueo.
     *
     * @return array{permitido: bool, motivo: ?string, ultimasVentana: int, disponibleDesde: ?string, bloqueadoHasta: ?string}
     */
    public function presupuesto(\DateTimeImmutable $ahora): array
    {
        // v1.1: máximo 4 sesiones por ventana (antes 2) y 120 s de espaciado
        // (antes 600 s = 10 min); cuenta toda sii_sesiones, así que incluye
        // tanto solicitudes como consultas de cupo (motivo 'consultar_cupo').
        $max = (int) ($this->presupuesto['max'] ?? 4);
        $ventana = (int) ($this->presupuesto['ventana'] ?? 7200);
        $espaciado = (int) ($this->presupuesto['espaciado'] ?? 120);
        $bloqueo = (int) ($this->presupuesto['bloqueo'] ?? 7200);
        $t = $ahora->getTimestamp();

        $st = $this->pdo->prepare('SELECT inicio_ts, error FROM sii_sesiones WHERE inicio_ts > ? ORDER BY inicio_ts');
        $st->execute([$t - max($ventana, $bloqueo)]);
        $filas = $st->fetchAll(\PDO::FETCH_ASSOC);

        $enVentana = array_values(array_filter($filas, static fn (array $f): bool => (int) $f['inicio_ts'] > $t - $ventana));
        $bloqueadoHasta = null;
        foreach ($filas as $f) {
            if (in_array($f['error'], ['MAX_SESIONES', self::ERROR_BLOQUEO_POST_GENERA], true) && (int) $f['inicio_ts'] + $bloqueo > $t) {
                $bloqueadoHasta = max($bloqueadoHasta ?? 0, (int) $f['inicio_ts'] + $bloqueo);
            }
        }

        $disponible = null;
        $motivo = null;
        if ($bloqueadoHasta !== null) {
            $motivo = 'El SII informó "máximo de sesiones" hace poco; hay que esperar a que se liberen.';
            $disponible = $bloqueadoHasta;
        } elseif (count($enVentana) >= $max) {
            $motivo = "Ya se abrieron {$max} sesiones con el SII en las últimas " . intdiv($ventana, 60) . ' min.';
            $disponible = (int) $enVentana[count($enVentana) - $max]['inicio_ts'] + $ventana;
        } elseif ($enVentana !== [] && (int) end($enVentana)['inicio_ts'] + $espaciado > $t) {
            $motivo = 'Hay que esperar ' . intdiv($espaciado, 60) . ' min entre sesiones con el SII.';
            $disponible = (int) end($enVentana)['inicio_ts'] + $espaciado;
        }
        $tz = $ahora->getTimezone();
        $fmt = static fn (?int $x): ?string => $x === null ? null : self::iso((new \DateTimeImmutable('@' . $x))->setTimezone($tz));
        return [
            'permitido' => $motivo === null,
            'motivo' => $motivo,
            'ultimasVentana' => count($enVentana),
            'disponibleDesde' => $fmt($disponible),
            'bloqueadoHasta' => $fmt($bloqueadoHasta),
        ];
    }

    // ------------------------------------------------------------------
    // Eventos (traza paso a paso) y consultas de cupo
    // ------------------------------------------------------------------

    /**
     * Persiste un evento de la traza. El mensaje y codigo_sii ya vienen
     * saneados por el llamador (CafSolicitudService::emitir); acá solo se
     * acota por defensa en profundidad.
     *
     * @param array{sesionId:string,loteId:?string,consultaId:?string,ts:string,paso:string,estado:string,
     *   tipoDte:?int,mensaje:string,codigoSii:?string,segundos:?float} $evento
     */
    public function registrarEvento(array $evento): void
    {
        $this->pdo->prepare(
            'INSERT INTO caf_eventos (sesion_id, lote_id, consulta_id, ts, paso, estado, tipo_dte, mensaje, codigo_sii, segundos)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $evento['sesionId'], $evento['loteId'], $evento['consultaId'], $evento['ts'], $evento['paso'], $evento['estado'],
            $evento['tipoDte'], self::sanear($evento['mensaje'], 500), self::sanear($evento['codigoSii'], 60), $evento['segundos'],
        ]);
    }

    /** @return list<array<string, mixed>> eventos de un lote, en orden, hasta EVENTOS_MAXIMO */
    public function eventosDeLote(string $loteId, int $limite = self::EVENTOS_MAXIMO): array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_eventos WHERE lote_id = ? ORDER BY id LIMIT ?');
        $st->bindValue(1, $loteId);
        $st->bindValue(2, max(1, min(self::EVENTOS_MAXIMO, $limite)), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> eventos de una consulta, en orden, hasta EVENTOS_MAXIMO */
    public function eventosDeConsulta(string $consultaId, int $limite = self::EVENTOS_MAXIMO): array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_eventos WHERE consulta_id = ? ORDER BY id LIMIT ?');
        $st->bindValue(1, $consultaId);
        $st->bindValue(2, max(1, min(self::EVENTOS_MAXIMO, $limite)), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Guarda el resultado de una consulta de cupo (solo lectura). $resultados
     * es {tipo: {maxAutor,foliosDisp} | {error:{codigo,detalle,codigoSii}}}.
     */
    public function crearConsulta(string $id, ?string $sesionId, string $ambiente, string $servidor, array $resultados, \DateTimeImmutable $ahora): void
    {
        $this->pdo->prepare('INSERT INTO caf_consultas (id, sesion_id, ambiente, servidor, ts, resultados) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $sesionId, $ambiente, $servidor, self::iso($ahora), json_encode($resultados, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array{id:string,ambiente:string,servidor:string,ts:string,resultados:array<string,mixed>}|null */
    public function obtenerConsulta(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_consultas WHERE id = ?');
        $st->execute([$id]);
        $f = $st->fetch(\PDO::FETCH_ASSOC);
        if ($f === false) {
            return null;
        }
        return [
            'id' => $f['id'], 'ambiente' => $f['ambiente'], 'servidor' => $f['servidor'], 'ts' => $f['ts'],
            'resultados' => json_decode((string) $f['resultados'], true) ?? [],
        ];
    }

    /** La consulta más reciente de un ambiente (para GET /caf/estado), o null. */
    public function ultimaConsulta(string $ambiente): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM caf_consultas WHERE ambiente = ? ORDER BY rowid DESC LIMIT 1');
        $st->execute([$ambiente]);
        $f = $st->fetch(\PDO::FETCH_ASSOC);
        if ($f === false) {
            return null;
        }
        return ['id' => $f['id'], 'ts' => $f['ts'], 'resultados' => json_decode((string) $f['resultados'], true) ?? []];
    }

    // ------------------------------------------------------------------

    /**
     * Texto apto para BD/logs: colapsa espacios, corta y descarta por
     * completo cualquier cosa que parezca XML/HTML, claves, cookies o rutas
     * del certificado.
     */
    public static function sanear(?string $texto, int $max = 500): ?string
    {
        if ($texto === null) {
            return null;
        }
        if (preg_match('/RSASK|RSAPK|PRIVATE KEY|BEGIN CERT|NETSCAPE_LIVEWIRE|TOKEN|Cookie|<\s*[\/!?A-Za-z]|\.pfx|\.p12|\.pem|\.pass\b|\/certs?\//i', $texto) === 1) {
            return self::TEXTO_OMITIDO;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', $texto));
        return mb_substr($t, 0, $max);
    }

    private static function iso(\DateTimeImmutable $t): string
    {
        return $t->format(DATE_ATOM);
    }

    private static function nuevoId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
