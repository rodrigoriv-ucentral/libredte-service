<?php

declare(strict_types=1);

namespace App\Timbraje;

use App\Config;
use DOMDocument;
use DOMXPath;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafLoaderWorkerInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafValidatorWorkerInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Exception\CafLoaderException;

/**
 * Valida un CAF recién descargado del SII y lo instala sin estados
 * intermedios. SIN red.
 *
 * IMPORTANTE: el XML de un CAF contiene la clave privada RSA (<RSASK>). Esta
 * clase nunca devuelve ni deja en excepciones, mensajes o logs el contenido
 * del XML ni las llaves: el resultado trae solo metadatos (estado, motivo,
 * sha256, idk, nombre de archivo, tipo y rango).
 *
 * Orden de validación (el primer grupo que falla corta, y dentro del grupo de
 * metadatos se informan todas las diferencias):
 *   1. Estructura: XML bien formado con raíz AUTORIZACION (si no: 'no_es_caf',
 *      sin tocar nada) y que libredte-lib-core pueda cargarlo.
 *   2. Metadatos contra lo esperado: TD, RE, IDK (100 certificación / 300
 *      producción), RNG D/H y FA.
 *   3. Firma del SII (100.cer / 300.cer) y par de llaves
 *      (CafValidatorWorker::validate()).
 *   4. Contra el directorio destino, con lock: un CAF con el mismo rango ya
 *      instalado → 'archivo_existente'; rango que se cruza con otro del mismo
 *      tipo → rechazo.
 * Todo rechazo (salvo 'no_es_caf' y 'archivo_existente') deja el XML en
 * cuarentena (0600) y no instala nada.
 *
 * Instalación: archivo temporal en el MISMO directorio con nombre
 * ".instalando-<hex>.tmp" (empieza con punto y no termina en .xml, así que ni
 * el glob {tipo}-*.xml ni {tipo}.xml de DteEmisor/FolioControl lo ven),
 * fwrite+fflush+fsync, chmod 0600 y publicación con link() como
 * {tipo}-{folioIni}.xml (link() no sobrescribe), luego unlink del temporal.
 * Antes de publicar se deja una copia idéntica de respaldo.
 */
final class CafInstalador
{
    public const IDK_CERTIFICACION = 100;
    public const IDK_PRODUCCION = 300;

    /** Un CAF real pesa ~1,2 KB; más de esto no es un CAF. */
    private const MAX_BYTES = 65536;

    /** @var Config|array<string, string>|string */
    private Config|array|string $cafDir;

    /**
     * @param Config|array<string, string>|string $cafDir Config (usa
     *   cafDirPara($ambiente)), o un arreglo ambiente => directorio, o un
     *   directorio único para ambos ambientes (pruebas).
     * @param string $respaldoDir Base; la copia va a {base}/{ambiente}/.
     * @param string $cuarentenaDir Los XML rechazados van aquí.
     * @param CafValidatorWorkerInterface|null $validadorInyectado Solo para
     *   pruebas (permite CAF firmados por una CA de prueba); en producción
     *   queda null y se usa el validador de libredte-lib-core (100.cer/300.cer).
     */
    public function __construct(
        Config|array|string $cafDir,
        private readonly string $respaldoDir = '/data/db/caf-respaldo',
        private readonly string $cuarentenaDir = '/data/db/caf-cuarentena',
        private readonly ?CafValidatorWorkerInterface $validadorInyectado = null,
    ) {
        $this->cafDir = $cafDir;
    }

    /**
     * @param array{tipo: int, rutEmisor: string, folioIni: int, folioFin: int, fecha: string} $esperado
     *   `fecha` es la FA del CAF (hoy en America/Santiago).
     * @return array{estado: string, motivo: string, codigo: ?string, sha256: string, idk: ?int, archivo: ?string, ruta: ?string, tipo: ?int, folioIni: ?int, folioFin: ?int, respaldo: ?string, cuarentena: ?string}
     *   estado: 'instalado' | 'rechazado' | 'no_es_caf' | 'archivo_existente'.
     *   codigo (máquina-legible, null si 'instalado'): XML_INVALIDO,
     *   TIPO_NO_SOPORTADO, RUT_NO_COINCIDE, AMBIENTE_NO_COINCIDE,
     *   CAF_NO_COINCIDE, FIRMA_INVALIDA, RANGO_SE_CRUZA, YA_INSTALADO,
     *   ERROR_INSTALACION.
     */
    public function instalar(string $bytesXml, array $esperado, string $ambiente): array
    {
        if ($ambiente !== 'produccion' && $ambiente !== 'certificacion') {
            throw new \InvalidArgumentException('ambiente debe ser "produccion" o "certificacion"');
        }
        $tipoEsp = $esperado['tipo'] ?? null;
        $iniEsp = $esperado['folioIni'] ?? null;
        $finEsp = $esperado['folioFin'] ?? null;
        $rutEsp = $esperado['rutEmisor'] ?? null;
        $fechaEsp = $esperado['fecha'] ?? null;
        if (!is_int($tipoEsp) || $tipoEsp <= 0 || !is_int($iniEsp) || !is_int($finEsp) || $iniEsp <= 0 || $finEsp < $iniEsp
            || !is_string($rutEsp) || $rutEsp === '' || !is_string($fechaEsp) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEsp)) {
            throw new \InvalidArgumentException('esperado inválido: tipo, rutEmisor, folioIni, folioFin y fecha (YYYY-MM-DD) son obligatorios');
        }

        $sha = hash('sha256', $bytesXml);

        // 1a. Estructura mínima, sin tocar nada si no es un CAF.
        if (!$this->esXmlAutorizacion($bytesXml)) {
            return $this->resultado('no_es_caf', 'El contenido no es un XML con raíz AUTORIZACION (¿HTML de error del SII?).', $sha, null, null, 'XML_INVALIDO');
        }

        // 1b. Carga con libredte-lib-core.
        try {
            $caf = $this->loader()->load($bytesXml);
            $tipo = $caf->getTipoDocumento();
            $rut = $caf->getEmisor()['rut'];
            $idk = $caf->getIdk();
            $desde = $caf->getFolioDesde();
            $hasta = $caf->getFolioHasta();
            $fa = $caf->getFechaAutorizacion();
        } catch (\Throwable $e) {
            $codigoCarga = $e instanceof CafLoaderException ? 'TIPO_NO_SOPORTADO' : 'XML_INVALIDO';
            return $this->rechazar($bytesXml, $sha, $ambiente, null, 'No se pudo leer el CAF: ' . self::mensajeSeguro($e), $codigoCarga);
        }

        // 2. Metadatos contra lo esperado.
        $dif = [];
        $codigoDif = null; // el de la primera diferencia por prioridad: RE, IDK, resto
        if ($tipo !== $tipoEsp) {
            $dif[] = "TD={$tipo}, se esperaba {$tipoEsp}";
            $codigoDif = 'CAF_NO_COINCIDE';
        }
        if (self::normalizarRut($rut) !== self::normalizarRut($rutEsp)) {
            $dif[] = 'RE distinto del RUT emisor esperado';
            $codigoDif = 'RUT_NO_COINCIDE';
        }
        $idkEsp = $ambiente === 'produccion' ? self::IDK_PRODUCCION : self::IDK_CERTIFICACION;
        if ($idk !== $idkEsp) {
            $dif[] = "IDK={$idk}, se esperaba {$idkEsp} para {$ambiente}";
            if ($codigoDif !== 'RUT_NO_COINCIDE') {
                $codigoDif = 'AMBIENTE_NO_COINCIDE';
            }
        }
        if ($desde !== $iniEsp || $hasta !== $finEsp) {
            $dif[] = "RNG={$desde}-{$hasta}, se esperaba {$iniEsp}-{$finEsp}";
        }
        if ($fa !== $fechaEsp) {
            $dif[] = "FA={$fa}, se esperaba {$fechaEsp}";
        }
        if ($desde !== $iniEsp || $hasta !== $finEsp || $fa !== $fechaEsp) {
            $codigoDif ??= 'CAF_NO_COINCIDE';
        }
        if ($dif !== []) {
            return $this->rechazar($bytesXml, $sha, $ambiente, $caf, 'El CAF no corresponde a lo solicitado: ' . implode('; ', $dif) . '.', $codigoDif);
        }

        // 3. Firma del SII y par de llaves.
        try {
            $this->validador()->validate($caf);
        } catch (\Throwable $e) {
            return $this->rechazar($bytesXml, $sha, $ambiente, $caf, 'Validación criptográfica fallida: ' . self::mensajeSeguro($e), 'FIRMA_INVALIDA');
        }

        // 4. Contra el destino, bajo lock, e instalación.
        try {
            $dir = $this->directorioDestino($ambiente);
            self::asegurarDirectorio($dir, 0770);
            $lock = fopen($dir . '/.instalador.lock', 'cb');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('No se pudo tomar el lock del directorio de CAF.');
            }
        } catch (\Throwable $e) {
            return $this->rechazar($bytesXml, $sha, $ambiente, $caf, 'No se pudo preparar el directorio destino: ' . self::mensajeSeguro($e), 'ERROR_INSTALACION');
        }

        try {
            return $this->instalarBajoLock($bytesXml, $sha, $ambiente, $caf, $dir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function instalarBajoLock(string $bytes, string $sha, string $ambiente, CafInterface $caf, string $dir): array
    {
        $tipo = $caf->getTipoDocumento();
        $desde = $caf->getFolioDesde();
        $hasta = $caf->getFolioHasta();
        $idk = $caf->getIdk();
        $nombre = "{$tipo}-{$desde}.xml";
        $destino = $dir . '/' . $nombre;

        // Rango contra los CAF ya presentes del mismo tipo.
        try {
            foreach ($this->cafsDelTipo($dir, $tipo) as $existente) {
                [$archivo, $d, $h] = $existente;
                if ($d === $desde && $h === $hasta) {
                    return $this->resultado('archivo_existente', "Ya hay un CAF con el mismo rango {$desde}-{$hasta} ({$archivo}); no se sobrescribe.", $sha, $caf, $archivo, 'YA_INSTALADO');
                }
                if ($desde <= $h && $hasta >= $d) {
                    return $this->rechazar($bytes, $sha, $ambiente, $caf, "El rango {$desde}-{$hasta} se cruza con el CAF instalado {$archivo} ({$d}-{$h}).", 'RANGO_SE_CRUZA');
                }
            }
        } catch (\Throwable $e) {
            return $this->rechazar($bytes, $sha, $ambiente, $caf, 'No se pudo revisar los CAF ya instalados: ' . self::mensajeSeguro($e), 'ERROR_INSTALACION');
        }

        $tmp = null;
        $respaldo = null;
        try {
            // Respaldo idéntico antes de publicar: un CAF válido nunca queda en un solo lugar.
            $dirRespaldo = rtrim($this->respaldoDir, '/') . '/' . $ambiente;
            self::asegurarDirectorio($dirRespaldo, 0700);
            $hora = (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('His');
            $respaldo = $dirRespaldo . "/{$tipo}-{$desde}-{$hora}.xml";
            self::escribirPrivado($respaldo, $bytes);

            // Temporal en el mismo directorio: ni termina en .xml ni empieza con "{tipo}-".
            $tmp = $dir . '/.instalando-' . bin2hex(random_bytes(8)) . '.tmp';
            self::escribirPrivado($tmp, $bytes);

            if (!@link($tmp, $destino)) {
                if (file_exists($destino)) {
                    return $this->resultado('archivo_existente', "Ya existe {$nombre}; no se sobrescribe.", $sha, $caf, $nombre, 'YA_INSTALADO');
                }
                throw new \RuntimeException('No se pudo publicar el archivo (link falló).');
            }
        } catch (\Throwable $e) {
            return $this->rechazar($bytes, $sha, $ambiente, $caf, 'Error al escribir el CAF: ' . self::mensajeSeguro($e), 'ERROR_INSTALACION');
        } finally {
            if ($tmp !== null && file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $r = $this->resultado('instalado', "CAF instalado como {$nombre}.", $sha, $caf, $nombre);
        $r['ruta'] = $destino;
        $r['respaldo'] = basename((string) $respaldo);
        return $r;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> [archivo, desde, hasta] de {tipo}.xml y {tipo}-*.xml.
     */
    private function cafsDelTipo(string $dir, int $tipo): array
    {
        $nombres = scandir($dir);
        if ($nombres === false) {
            throw new \RuntimeException('No se pudo leer el directorio de CAF.');
        }
        $salida = [];
        foreach ($nombres as $n) {
            if (!preg_match('/^' . $tipo . '(?:-.*)?\.xml$/', $n) || !is_file($dir . '/' . $n)) {
                continue;
            }
            $contenido = file_get_contents($dir . '/' . $n);
            $rango = $contenido === false ? null : self::leerRango($contenido);
            if ($rango === null) {
                // Un CAF ilegible ya rompería las emisiones del tipo; no se agrega otro encima.
                throw new \RuntimeException("El CAF existente {$n} no se pudo leer.");
            }
            $salida[] = [$n, $rango[0], $rango[1]];
        }
        return $salida;
    }

    /** @return array{0: int, 1: int}|null */
    private static function leerRango(string $xml): ?array
    {
        $dom = self::cargarDom($xml);
        if ($dom === null) {
            return null;
        }
        $xp = new DOMXPath($dom);
        $d = $xp->evaluate('string(/AUTORIZACION/CAF/DA/RNG/D)');
        $h = $xp->evaluate('string(/AUTORIZACION/CAF/DA/RNG/H)');
        if (!is_string($d) || !is_string($h) || !ctype_digit(trim($d)) || !ctype_digit(trim($h))) {
            return null;
        }
        return [(int) trim($d), (int) trim($h)];
    }

    private function esXmlAutorizacion(string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return false;
        }
        $dom = self::cargarDom($bytes);
        return $dom !== null && $dom->documentElement !== null && $dom->documentElement->nodeName === 'AUTORIZACION';
    }

    private static function cargarDom(string $xml): ?DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
            // Sin DOCTYPE: un CAF del SII no lo trae y evita entidades externas.
            return $ok && $dom->doctype === null ? $dom : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function rechazar(string $bytes, string $sha, string $ambiente, ?CafInterface $caf, string $motivo, ?string $codigo = null): array
    {
        $cuarentena = null;
        try {
            $dir = rtrim($this->cuarentenaDir, '/');
            self::asegurarDirectorio($dir, 0700);
            $hora = (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Ymd-His');
            $cuarentena = "{$hora}-{$ambiente}-" . substr($sha, 0, 12) . '-' . bin2hex(random_bytes(2)) . '.xml';
            self::escribirPrivado($dir . '/' . $cuarentena, $bytes);
        } catch (\Throwable) {
            // Si la cuarentena también falla, igual no se instala nada.
            $cuarentena = null;
        }
        $r = $this->resultado('rechazado', $motivo, $sha, $caf, null, $codigo);
        $r['cuarentena'] = $cuarentena;
        return $r;
    }

    private function resultado(string $estado, string $motivo, string $sha, ?CafInterface $caf = null, ?string $archivo = null, ?string $codigo = null): array
    {
        $tipo = $idk = $ini = $fin = null;
        if ($caf !== null) {
            try {
                $tipo = $caf->getTipoDocumento();
                $idk = $caf->getIdk();
                $ini = $caf->getFolioDesde();
                $fin = $caf->getFolioHasta();
            } catch (\Throwable) {
            }
        }
        return [
            'estado' => $estado,
            'motivo' => $motivo,
            'codigo' => $codigo,
            'sha256' => $sha,
            'idk' => $idk,
            'archivo' => $archivo,
            'ruta' => null,
            'tipo' => $tipo,
            'folioIni' => $ini,
            'folioFin' => $fin,
            'respaldo' => null,
            'cuarentena' => null,
        ];
    }

    private function directorioDestino(string $ambiente): string
    {
        $c = $this->cafDir;
        if ($c instanceof Config) {
            return rtrim($c->cafDirPara($ambiente), '/');
        }
        if (is_array($c)) {
            if (!isset($c[$ambiente])) {
                throw new \InvalidArgumentException("Sin directorio de CAF para {$ambiente}");
            }
            return rtrim($c[$ambiente], '/');
        }
        return rtrim($c, '/');
    }

    private function loader(): CafLoaderWorkerInterface
    {
        return Application::getInstance()->getPackageRegistry()->getBillingPackage()
            ->getIdentifierComponent()->getCafLoaderWorker();
    }

    private function validador(): CafValidatorWorkerInterface
    {
        if ($this->validadorInyectado !== null) {
            return $this->validadorInyectado;
        }
        return Application::getInstance()->getPackageRegistry()->getBillingPackage()
            ->getIdentifierComponent()->getCafValidatorWorker();
    }

    private static function normalizarRut(string $rut): string
    {
        return strtoupper(str_replace('.', '', trim($rut)));
    }

    /**
     * Mensaje de una excepción de la librería, sin nada que pueda arrastrar
     * el XML o las llaves (se descarta si menciona claves o trae XML).
     */
    private static function mensajeSeguro(\Throwable $e): string
    {
        $m = $e->getMessage();
        if (preg_match('/RSASK|PRIVATE KEY|<|>/i', $m)) {
            return get_class($e);
        }
        return mb_substr($m, 0, 300);
    }

    private static function asegurarDirectorio(string $dir, int $modo): void
    {
        if (!is_dir($dir) && !mkdir($dir, $modo, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio {$dir}");
        }
    }

    /** Crea el archivo (falla si existe) con 0600, lo escribe completo y lo fuerza a disco. */
    private static function escribirPrivado(string $ruta, string $bytes): void
    {
        $umask = umask(0077);
        try {
            $fh = @fopen($ruta, 'xb');
        } finally {
            umask($umask);
        }
        if ($fh === false) {
            throw new \RuntimeException("No se pudo crear {$ruta}");
        }
        try {
            if (fwrite($fh, $bytes) !== strlen($bytes) || !fflush($fh) || !fsync($fh)) {
                throw new \RuntimeException("No se pudo escribir {$ruta}");
            }
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($ruta);
            throw $e;
        }
        fclose($fh);
        if (!chmod($ruta, 0600)) {
            @unlink($ruta);
            throw new \RuntimeException("No se pudo fijar permisos de {$ruta}");
        }
    }
}
