<?php

declare(strict_types=1);

namespace App;

/**
 * Gestiona el certificado digital (.pfx/.p12) usado para firmar los DTE:
 * guardarlo en disco (junto a su clave), y leer su fecha de vencimiento para
 * poder alertar antes de que expire.
 */
final class CertificadoService
{
    /** @var \Closure(string, string): bool */
    private readonly \Closure $renombrar;

    /**
     * @param \Closure(string, string): bool|null $renombrar Solo para pruebas
     *   (simular un fallo a mitad del reemplazo); por defecto rename().
     */
    public function __construct(private readonly Config $config, ?\Closure $renombrar = null)
    {
        $this->renombrar = $renombrar ?? static fn (string $de, string $a): bool => rename($de, $a);
    }

    /**
     * Guarda el certificado recibido en base64 + su clave, y devuelve los
     * datos del certificado (vencimiento, sujeto, rut) leídos con OpenSSL,
     * más `reemplazo` (había uno antes) y `rutAnterior`.
     *
     * Rechaza ANTES de tocar el disco: base64/archivo que no es PKCS#12
     * (NO_ES_PKCS12), clave incorrecta (CLAVE_INCORRECTA) y certificado
     * vencido (VENCIDO), con CertificadoInvalidoException.
     *
     * Reemplazo seguro: el par nuevo se escribe en temporales 0600 junto al
     * definitivo y se verifica leyéndolo del disco; el par vigente se copia
     * a certificado.pfx.prev + certificado.pfx.pass.prev (0600) y luego se
     * renombran los temporales. Si algo falla a mitad, se restaura el par
     * anterior (o se borra lo nuevo si no había). Aviso: son dos renames, no
     * una operación atómica; entre ellos hay una ventana de milisegundos en
     * que el .pfx nuevo convive con la clave vieja (irrelevante si la clave
     * no cambió).
     */
    public function guardar(string $archivoBase64, string $clave): array
    {
        $contenido = base64_decode($archivoBase64, true);
        if ($contenido === false || $contenido === '') {
            throw new CertificadoInvalidoException('NO_ES_PKCS12', 'El archivo del certificado no es un base64 válido');
        }

        $datosCert = $this->leerDatos($contenido, $clave);
        if ($datosCert['diasRestantes'] < 0 || $datosCert['_expira'] < time()) {
            throw new CertificadoInvalidoException('VENCIDO', 'El certificado está vencido (' . $datosCert['vencimiento'] . ')');
        }
        unset($datosCert['_expira']);

        $pfx = $this->config->certPath;
        $pass = $this->config->certPassPath();
        $dir = dirname($pfx);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Par vigente (si lo hay) y su RUT, para el aviso de reemplazo.
        $habiaAnterior = is_file($pfx);
        $rutAnterior = null;
        $pfxAnterior = null;
        $claveAnterior = null;
        if ($habiaAnterior) {
            $pfxAnterior = (string) file_get_contents($pfx);
            $claveAnterior = $this->config->resolveCertPass();
            try {
                $rutAnterior = $this->leerDatos($pfxAnterior, $claveAnterior)['rut'];
            } catch (\Throwable) {
                $rutAnterior = null; // el vigente ya era ilegible; igual se conserva como previo
            }
        }

        $sufijo = '.nuevo-' . bin2hex(random_bytes(6));
        $tmpPfx = $pfx . $sufijo;
        $tmpPass = $pass . $sufijo;
        $prevPfx = $pfx . '.prev';
        $prevPass = $pass . '.prev';
        $tmpPrevPfx = $prevPfx . $sufijo;
        $tmpPrevPass = $prevPass . $sufijo;
        $temporales = [$tmpPfx, $tmpPass, $tmpPrevPfx, $tmpPrevPass];
        $swap1 = false;
        $swap2 = false;

        try {
            self::escribirPrivado($tmpPfx, $contenido);
            self::escribirPrivado($tmpPass, $clave);
            // El par que quedó en disco debe abrir.
            if (!openssl_pkcs12_read((string) file_get_contents($tmpPfx), $verif, (string) file_get_contents($tmpPass))) {
                throw new \RuntimeException('El certificado escrito en disco no se pudo verificar');
            }
            unset($verif);

            if ($habiaAnterior) {
                self::escribirPrivado($tmpPrevPfx, (string) $pfxAnterior);
                self::escribirPrivado($tmpPrevPass, (string) $claveAnterior);
                if (!($this->renombrar)($tmpPrevPfx, $prevPfx) || !($this->renombrar)($tmpPrevPass, $prevPass)) {
                    throw new \RuntimeException('No se pudo conservar la versión previa del certificado');
                }
            }

            if (!($this->renombrar)($tmpPfx, $pfx)) {
                throw new \RuntimeException('No se pudo instalar el certificado');
            }
            $swap1 = true;
            if (!($this->renombrar)($tmpPass, $pass)) {
                throw new \RuntimeException('No se pudo instalar la clave del certificado');
            }
            $swap2 = true;
        } catch (\Throwable $e) {
            // Nunca debe quedar un .pfx con una clave que no le corresponde.
            if ($swap1 || $swap2) {
                if ($habiaAnterior) {
                    self::restaurar($pfx, (string) $pfxAnterior, $pass, (string) $claveAnterior);
                } else {
                    @unlink($pfx);
                    @unlink($pass);
                }
            }
            throw $e;
        } finally {
            foreach ($temporales as $t) {
                if (file_exists($t)) {
                    @unlink($t);
                }
            }
        }

        return $datosCert + ['reemplazo' => $habiaAnterior, 'rutAnterior' => $rutAnterior];
    }

    /**
     * Borra el certificado, su clave y la versión previa. Idempotente.
     *
     * @return array{cargado: false}
     */
    public function borrar(): array
    {
        $pfx = $this->config->certPath;
        $pass = $this->config->certPassPath();
        foreach ([$pfx, $pass, $pfx . '.prev', $pass . '.prev'] as $f) {
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        return ['cargado' => false];
    }

    /** Estado del certificado actualmente cargado en disco (si existe). */
    public function estado(): array
    {
        if (!is_file($this->config->certPath)) {
            return ['cargado' => false, 'vencimiento' => null, 'diasRestantes' => null, 'sujeto' => null, 'rut' => null];
        }

        try {
            $contenido = (string) file_get_contents($this->config->certPath);
            $datos = $this->leerDatos($contenido, $this->config->resolveCertPass());
            unset($datos['_expira']);
            return array_merge(['cargado' => true], $datos);
        } catch (\Throwable $e) {
            // El archivo existe pero no se pudo leer (clave incorrecta, corrupto, etc.).
            return [
                'cargado' => true,
                'vencimiento' => null,
                'diasRestantes' => null,
                'sujeto' => null,
                'rut' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * RUT del titular (`12345678-9`) de un certificado PEM, o null. Primero
     * lo que expone derafu/certificate (Certificate::getId: subject
     * serialNumber o SAN otherName), validado como RUT; si no sirve, busca
     * el OID 1.3.6.1.4.1.8321.1 (RUT del titular en certificados chilenos)
     * en el DER.
     */
    public static function extraerRut(string $certPem): ?string
    {
        try {
            $id = (new \Derafu\Certificate\Certificate($certPem))->getId();
            $rut = self::normalizarRutTitular($id);
            if ($rut !== null) {
                return $rut;
            }
        } catch (\Throwable) {
        }
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $certPem);
        $der = is_string($b64) ? base64_decode($b64, true) : false;
        if ($der === false) {
            return null;
        }
        $pos = strpos($der, "\x06\x08\x2B\x06\x01\x04\x01\xC1\x41\x01");
        if ($pos !== false && preg_match('/\d{1,2}\.?\d{3}\.?\d{3}-[\dkK]/', substr($der, $pos + 10, 40), $m) === 1) {
            return self::normalizarRutTitular($m[0]);
        }
        return null;
    }

    private static function normalizarRutTitular(string $valor): ?string
    {
        $v = strtoupper(str_replace(['.', ' '], '', trim($valor)));
        $v = ltrim($v, '0');
        return preg_match('/^\d{6,8}-[\dK]$/', $v) === 1 ? $v : null;
    }

    /** Crea el archivo (0600 desde el inicio), lo escribe completo y lo fuerza a disco. */
    private static function escribirPrivado(string $ruta, string $bytes): void
    {
        $umask = umask(0077);
        try {
            $fh = @fopen($ruta, 'xb');
        } finally {
            umask($umask);
        }
        if ($fh === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal del certificado');
        }
        $ok = fwrite($fh, $bytes) === strlen($bytes) && fflush($fh) && (!function_exists('fsync') || fsync($fh));
        fclose($fh);
        if (!$ok || !chmod($ruta, 0600)) {
            @unlink($ruta);
            throw new \RuntimeException('No se pudo escribir un archivo temporal del certificado');
        }
    }

    /** Restaura el par anterior (best effort, sin pasar por el closure inyectable). */
    private static function restaurar(string $pfx, string $pfxBytes, string $pass, string $claveBytes): void
    {
        foreach ([[$pfx, $pfxBytes], [$pass, $claveBytes]] as [$ruta, $bytes]) {
            $tmp = $ruta . '.restaurando-' . bin2hex(random_bytes(4));
            try {
                self::escribirPrivado($tmp, $bytes);
                rename($tmp, $ruta);
            } catch (\Throwable) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Lee el PKCS#12. Si falla, lanza CertificadoInvalidoException con
     * NO_ES_PKCS12 (el archivo no es ASN.1/PKCS#12) o CLAVE_INCORRECTA (falla
     * la verificación MAC / descifrado). OpenSSL no siempre deja distinguir:
     * ante un error ambiguo se usa CLAVE_INCORRECTA.
     */
    private function leerDatos(string $contenidoPfx, string $clave): array
    {
        $certs = [];
        while (openssl_error_string() !== false) {
            // vacía la cola de errores de OpenSSL
        }
        if (!openssl_pkcs12_read($contenidoPfx, $certs, $clave)) {
            $errores = [];
            while (($e = openssl_error_string()) !== false) {
                $errores[] = $e;
            }
            $texto = implode(' | ', $errores);
            $noEsAsn1 = $contenidoPfx === '' || $contenidoPfx[0] !== "\x30"
                || (preg_match('/asn1 encoding|wrong tag|not enough data|header too long|nested asn1/i', $texto) === 1
                    && preg_match('/mac verify|bad decrypt|PKCS12 routines/i', $texto) !== 1);
            if ($noEsAsn1) {
                throw new CertificadoInvalidoException('NO_ES_PKCS12', 'El archivo no es un certificado PKCS#12 (.pfx/.p12)');
            }
            throw new CertificadoInvalidoException('CLAVE_INCORRECTA', 'No se pudo leer el certificado: archivo corrupto o clave incorrecta');
        }

        $info = openssl_x509_parse($certs['cert']);
        if ($info === false) {
            throw new \RuntimeException('No se pudo interpretar el certificado X.509');
        }

        $vencimiento = (new \DateTimeImmutable('@' . $info['validTo_time_t']))->setTimezone(new \DateTimeZone('America/Santiago'));
        $hoy = new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));
        $diasRestantes = (int) $hoy->diff($vencimiento)->format('%r%a');

        return [
            'vencimiento' => $vencimiento->format('Y-m-d'),
            'diasRestantes' => $diasRestantes,
            'sujeto' => $info['subject']['CN'] ?? null,
            'rut' => self::extraerRut($certs['cert']),
            '_expira' => (int) $info['validTo_time_t'],
        ];
    }
}
