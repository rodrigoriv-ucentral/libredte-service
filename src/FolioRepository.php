<?php

declare(strict_types=1);

namespace App;

/**
 * libredte-lib-core no gestiona el estado de los folios: cada emisión requiere
 * indicarle qué folio del CAF usar. Esta clase persiste en SQLite cuál es el
 * último folio consumido por tipo de DTE, usando una transacción inmediata
 * para evitar que dos emisiones concurrentes reutilicen el mismo folio.
 */
final class FolioRepository
{
    /** Una reserva con menos de esto no se toma: otro proceso puede estar emitiendo. */
    private const LEASE_SEGUNDOS = 600;

    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Varios procesos (PHP_CLI_SERVER_WORKERS): esperar el candado en vez de fallar con "database is locked".
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS folios (
                tipo_dte INTEGER PRIMARY KEY,
                ultimo_folio_usado INTEGER NOT NULL,
                rango_desde INTEGER NOT NULL,
                rango_hasta INTEGER NOT NULL
            )'
        );
        // Libro de emisiones: cada folio reservado con la clave de idempotencia
        // de quien lo pidió. Permite que un reintento (tras un corte entre
        // timbrar y guardar en la app) recupere el MISMO documento en vez de
        // consumir otro folio.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS emisiones (
                clave TEXT PRIMARY KEY,
                tipo_dte INTEGER NOT NULL,
                folio INTEGER NOT NULL,
                estado TEXT NOT NULL,
                xml TEXT,
                pdf TEXT,
                totales TEXT,
                track_id INTEGER,
                envio_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (tipo_dte, folio)
            )'
        );
    }

    /**
     * Reserva el próximo folio disponible para el tipo de DTE indicado,
     * inicializando el rango la primera vez a partir del propio CAF cargado.
     */
    public function siguienteFolio(int $tipoDte, int $rangoDesde, int $rangoHasta): int
    {
        return $this->siguienteFolioEnRangos($tipoDte, [[$rangoDesde, $rangoHasta]]);
    }

    /**
     * Igual que siguienteFolio(), pero con varios CAF vigentes para el mismo
     * tipo (p. ej. 61.xml 1-15 y 61-16.xml 16-16): al agotarse un rango salta
     * al siguiente, saltando los huecos entre CAF.
     *
     * Con $clave, la reserva queda registrada en `emisiones` dentro de la misma
     * transacción: si esa clave ya tiene folio (reserva previa que no llegó a
     * timbrarse, o documento ya timbrado) se devuelve el mismo, sin consumir otro.
     *
     * @param array<int, array{0: int, 1: int}> $rangos [desde, hasta] por CAF
     */
    public function siguienteFolioEnRangos(int $tipoDte, array $rangos, ?string $clave = null): int
    {
        usort($rangos, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $rangoDesde = $rangos[0][0];
        $rangoHasta = max(array_column($rangos, 1));

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($clave !== null) {
                $previa = $this->pdo->prepare('SELECT tipo_dte, folio, estado, updated_at FROM emisiones WHERE clave = :c');
                $previa->execute(['c' => $clave]);
                $fila = $previa->fetch(\PDO::FETCH_ASSOC);
                if ($fila !== false) {
                    if ((int) $fila['tipo_dte'] !== $tipoDte) {
                        throw new \InvalidArgumentException(
                            "La clave de idempotencia '{$clave}' ya se usó para otro tipo de DTE"
                        );
                    }
                    $tomar = false;
                    if ($fila['estado'] === 'liberado') {
                        // (a) Reserva liberada de esta misma clave: se retoma su folio.
                        $tomar = true;
                    } elseif ($fila['estado'] === 'reservado') {
                        // (b) Otro proceso la tiene reservada: lease.
                        $antiguedad = time() - (int) strtotime((string) $fila['updated_at']);
                        if ($antiguedad < self::LEASE_SEGUNDOS) {
                            throw new EmisionEnCursoException();
                        }
                        // Lease vencido: el proceso anterior murió antes de guardar.
                        $tomar = true;
                    }
                    if ($tomar) {
                        if ($this->enRangos((int) $fila['folio'], $rangos)) {
                            $this->renovarReserva($clave);
                            $this->pdo->exec('COMMIT');
                            return (int) $fila['folio'];
                        }
                        // Su CAF venció o ya no está cargado: se descarta y se sigue como clave nueva.
                        $this->pdo->prepare(
                            "UPDATE emisiones SET clave = :c || '#descartado#' || folio, estado = 'descartado', updated_at = :t
                             WHERE clave = :c"
                        )->execute(['c' => $clave, 't' => gmdate('c')]);
                    } else {
                        // (c) timbrado: se devuelve su folio tal cual.
                        $this->pdo->exec('COMMIT');
                        return (int) $fila['folio'];
                    }
                }

                // (d) Clave nueva: reutilizar el folio liberado más bajo dentro de los rangos.
                $liberados = $this->pdo->prepare(
                    "SELECT clave, folio FROM emisiones
                     WHERE tipo_dte = :tipo AND estado = 'liberado' ORDER BY folio"
                );
                $liberados->execute(['tipo' => $tipoDte]);
                foreach ($liberados->fetchAll(\PDO::FETCH_ASSOC) as $lib) {
                    if ($this->enRangos((int) $lib['folio'], $rangos)) {
                        $this->pdo->prepare(
                            "UPDATE emisiones SET clave = :nueva, estado = 'reservado', updated_at = :t
                             WHERE clave = :vieja"
                        )->execute(['nueva' => $clave, 't' => gmdate('c'), 'vieja' => $lib['clave']]);
                        $this->pdo->exec('COMMIT');
                        return (int) $lib['folio'];
                    }
                }
            }

            $stmt = $this->pdo->prepare('SELECT ultimo_folio_usado FROM folios WHERE tipo_dte = :tipo');
            $stmt->execute(['tipo' => $tipoDte]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                $folio = $rangoDesde;
                $insert = $this->pdo->prepare(
                    'INSERT INTO folios (tipo_dte, ultimo_folio_usado, rango_desde, rango_hasta)
                     VALUES (:tipo, :folio, :desde, :hasta)'
                );
                $insert->execute([
                    'tipo' => $tipoDte,
                    'folio' => $folio,
                    'desde' => $rangoDesde,
                    'hasta' => $rangoHasta,
                ]);
            } else {
                $folio = null;
                foreach ($rangos as [$desde, $hasta]) {
                    $candidato = max((int) $row['ultimo_folio_usado'] + 1, $desde);
                    if ($candidato <= $hasta) {
                        $folio = $candidato;
                        break;
                    }
                }
                if ($folio === null) {
                    throw new \RuntimeException(
                        "Se agotaron los folios del CAF para el tipo DTE {$tipoDte}. Carga un nuevo CAF."
                    );
                }
                $update = $this->pdo->prepare(
                    'UPDATE folios SET ultimo_folio_usado = :folio, rango_hasta = :hasta WHERE tipo_dte = :tipo'
                );
                $update->execute(['folio' => $folio, 'hasta' => $rangoHasta, 'tipo' => $tipoDte]);
            }

            if ($clave !== null) {
                $ahora = gmdate('c');
                $this->pdo->prepare(
                    "INSERT INTO emisiones (clave, tipo_dte, folio, estado, created_at, updated_at)
                     VALUES (:c, :tipo, :folio, 'reservado', :t, :t)"
                )->execute(['c' => $clave, 'tipo' => $tipoDte, 'folio' => $folio, 't' => $ahora]);
            }

            $this->pdo->exec('COMMIT');
            return $folio;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @param array<int, array{0: int, 1: int}> $rangos */
    private function enRangos(int $folio, array $rangos): bool
    {
        foreach ($rangos as [$desde, $hasta]) {
            if ($folio >= $desde && $folio <= $hasta) {
                return true;
            }
        }
        return false;
    }

    private function renovarReserva(string $clave): void
    {
        $this->pdo->prepare("UPDATE emisiones SET estado = 'reservado', updated_at = :t WHERE clave = :c")
            ->execute(['t' => gmdate('c'), 'c' => $clave]);
    }

    /**
     * Libera una reserva que nunca llegó a timbrarse (el documento no salió de
     * la máquina). No toca `folios.ultimo_folio_usado`; solo afecta a 'reservado'.
     */
    public function liberarReserva(string $clave): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->prepare(
                "UPDATE emisiones SET estado = 'liberado', updated_at = :t WHERE clave = :c AND estado = 'reservado'"
            )->execute(['t' => gmdate('c'), 'c' => $clave]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function buscarEmision(string $clave): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM emisiones WHERE clave = :c');
        $stmt->execute(['c' => $clave]);
        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $fila === false ? null : $fila;
    }

    /** Guarda el XML/PDF recién timbrados, ANTES de intentar el envío al SII. */
    public function registrarTimbrado(string $clave, string $xmlBase64, string $pdfBase64, array $totales): void
    {
        $this->pdo->prepare(
            "UPDATE emisiones SET estado = 'timbrado', xml = :xml, pdf = :pdf, totales = :tot, updated_at = :t
             WHERE clave = :c"
        )->execute([
            'xml' => $xmlBase64,
            'pdf' => $pdfBase64,
            'tot' => json_encode($totales),
            't' => gmdate('c'),
            'c' => $clave,
        ]);
    }

    public function registrarEnvio(string $clave, ?int $trackId, ?string $envioError): void
    {
        $this->pdo->prepare(
            'UPDATE emisiones SET track_id = :tid, envio_error = :err, updated_at = :t WHERE clave = :c'
        )->execute(['tid' => $trackId, 'err' => $envioError, 't' => gmdate('c'), 'c' => $clave]);
    }

    /**
     * Estado de los folios consumidos por tipo, para conciliar contra los CAF.
     *
     * @return array<int, array{ultimoFolioUsado: int, reservadosSinTimbrar: int[], liberados: int[], descartados: int[], timbrados: int}>
     */
    public function resumen(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT tipo_dte, ultimo_folio_usado FROM folios') as $f) {
            $out[(int) $f['tipo_dte']] = [
                'ultimoFolioUsado' => (int) $f['ultimo_folio_usado'],
                'reservadosSinTimbrar' => [],
                'liberados' => [], 'descartados' => [],
                'timbrados' => 0,
            ];
        }
        foreach ($this->pdo->query('SELECT tipo_dte, folio, estado FROM emisiones ORDER BY folio') as $e) {
            $t = (int) $e['tipo_dte'];
            $out[$t] ??= ['ultimoFolioUsado' => 0, 'reservadosSinTimbrar' => [], 'liberados' => [], 'descartados' => [], 'timbrados' => 0];
            if ($e['estado'] === 'timbrado') {
                $out[$t]['timbrados']++;
            } elseif ($e['estado'] === 'liberado') {
                $out[$t]['liberados'][] = (int) $e['folio'];
            } elseif ($e['estado'] === 'descartado') {
                $out[$t]['descartados'][] = (int) $e['folio'];
            } else {
                $out[$t]['reservadosSinTimbrar'][] = (int) $e['folio'];
            }
        }
        return $out;
    }
}
