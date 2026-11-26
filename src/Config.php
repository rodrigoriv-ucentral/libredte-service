<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public readonly string $apiToken;
    public readonly string $ambiente;
    public readonly string $emisorRut;
    public readonly string $emisorRazonSocial;
    public readonly string $emisorGiro;
    public readonly string $emisorActeco;
    public readonly string $emisorDireccion;
    public readonly string $emisorComuna;
    public readonly string $emisorCiudad;
    public readonly string $emisorTelefono;
    public readonly string $emisorEmail;
    public readonly string $certPath;
    public readonly string $certPass;
    public readonly string $cafDir;
    public readonly string $dbPath;
    /** Bitácora de solicitudes de CAF (compartida entre ambientes; ver Timbraje\CafSolicitudRepository). */
    public readonly string $cafSolicitudesDb;
    public readonly string $resolucionFecha;
    public readonly int $resolucionNumero;
    public readonly string $mailIntercambio;
    /** @var array{banco: string, tipo: string, numero: string, email: string}|null */
    public readonly ?array $cuentaBancaria;
    /** Ruta absoluta (dentro del contenedor) de un logo PNG/JPG para el PDF; '' = sin logo. */
    public readonly string $pdfLogo;
    /** URL de consulta de boletas impresa en la boleta (exigida por el SII); '' = no se imprime. */
    public readonly string $urlConsultaBoleta;

    public function __construct()
    {
        $this->apiToken = self::env('LIBREDTE_API_TOKEN');
        // El SII rechaza fechas de emisión futuras en hora de Chile; el contenedor corre en UTC.
        date_default_timezone_set('America/Santiago');
        $this->ambiente = self::env('LIBREDTE_AMBIENTE', 'certificacion');
        // Lo editado desde el panel (EmisorDatos) manda sobre el .env; RUT,
        // razón social y acteco solo se configuran por entorno.
        $editado = EmisorDatos::cargar();
        $this->emisorRut = self::env('LIBREDTE_EMISOR_RUT');
        $this->emisorRazonSocial = self::env('LIBREDTE_EMISOR_RAZON_SOCIAL');
        $this->emisorGiro = $editado['giro'] ?? self::env('LIBREDTE_EMISOR_GIRO');
        $this->emisorActeco = self::env('LIBREDTE_EMISOR_ACTECO', '');
        $this->emisorDireccion = $editado['direccion'] ?? self::env('LIBREDTE_EMISOR_DIRECCION');
        $this->emisorComuna = $editado['comuna'] ?? self::env('LIBREDTE_EMISOR_COMUNA');
        $this->emisorCiudad = $editado['ciudad'] ?? self::env('LIBREDTE_EMISOR_CIUDAD', '');
        $this->emisorTelefono = $editado['telefono'] ?? self::env('LIBREDTE_EMISOR_TELEFONO', '');
        $this->certPath = self::env('LIBREDTE_CERT_PATH', '/data/certs/certificado.pfx');
        // La clave puede venir por variable de entorno (setup manual) o, si el
        // certificado se subió desde el panel, queda guardada junto al .pfx.
        $this->certPass = self::env('LIBREDTE_CERT_PASS', '');
        // Ruta de CAF y de la base de folios: en certificación se mantienen las
        // de siempre (/data/caf, /data/db/folios.sqlite). En producción, por
        // defecto, se usan subcarpetas separadas (/data/caf/produccion,
        // /data/db/produccion/folios.sqlite) para que un CAF o un contador de
        // certificación nunca se mezcle con uno real. LIBREDTE_CAF_DIR /
        // LIBREDTE_DB_PATH, si se definen, reemplazan el default en cualquier
        // ambiente (p. ej. para apuntar ambos ambientes al mismo lugar a
        // propósito).
        $this->cafDir = self::env(
            'LIBREDTE_CAF_DIR',
            $this->ambiente === 'produccion' ? '/data/caf/produccion' : '/data/caf'
        );
        $this->dbPath = self::env(
            'LIBREDTE_DB_PATH',
            $this->ambiente === 'produccion' ? '/data/db/produccion/folios.sqlite' : '/data/db/folios.sqlite'
        );
        // La bitácora de solicitudes de CAF es una sola para ambos ambientes
        // (el presupuesto de sesiones con el SII es por el RUT del dueño del
        // certificado, no por ambiente). Su carpeta también aloja el lock, los
        // respaldos y la cuarentena de CAF.
        $this->cafSolicitudesDb = self::env('LIBREDTE_CAF_SOLICITUDES_DB', '/data/db/caf_solicitudes.sqlite');
        // Se crean si no existen (igual que ya hace FolioRepository con el
        // directorio de la BD), con permisos restrictivos: estas carpetas
        // guardan CAF y folios reales, no deben quedar abiertas a "otros".
        self::asegurarDirectorio($this->cafDir);
        self::asegurarDirectorio(dirname($this->dbPath));
        // Resolución SII que autoriza a emitir DTE. Si LIBREDTE_RESOLUCION_FECHA
        // / LIBREDTE_RESOLUCION_NUMERO están definidas, se usan tal cual (así
        // se fija por .env la resolución del set vigente en certificación). Si
        // NO están definidas, el default depende
        // del ambiente:
        // - produccion: Res. Ex. SII N° 80 del 22-08-2014 (confirmada en
        //   sii.cl/factura_electronica/factura_mercado/proceso_certificacion.htm),
        //   la resolución que habilita a operar en producción tras aprobar la
        //   certificación.
        // - certificacion: Resolución Exenta N°0 del 22-08-2014, la
        //   autorización general que el SII otorgó a todos los emisores desde
        //   esa fecha (Ley 20.727), vigente mientras la empresa no tenga una
        //   resolución individual propia.
        $this->resolucionFecha = self::env(
            'LIBREDTE_RESOLUCION_FECHA',
            '2014-08-22'
        );
        $this->resolucionNumero = (int) self::env(
            'LIBREDTE_RESOLUCION_NUMERO',
            $this->ambiente === 'produccion' ? '80' : '0'
        );
        // Casilla de intercambio entre contribuyentes: es la que se declaró
        // ante el SII en la postulación ("Mail Intercambio Empresas") y la que
        // va como contacto en las respuestas de la etapa 3.
        $this->mailIntercambio = self::env('LIBREDTE_MAIL_INTERCAMBIO', '');
        // Correo de contacto impreso en la factura (CorreoEmisor del XML).
        $this->emisorEmail = $editado['email'] ?? self::env('LIBREDTE_EMISOR_EMAIL', $this->mailIntercambio);
        // Datos para transferencia impresos bajo los totales de la factura.
        // Opcionales: sin número de cuenta no se imprime la sección.
        $cuenta = $editado['cuentaBancaria'] ?? [
            'banco' => self::env('LIBREDTE_BANCO_NOMBRE', ''),
            'tipo' => self::env('LIBREDTE_BANCO_TIPO_CUENTA', ''),
            'numero' => self::env('LIBREDTE_BANCO_NUMERO_CUENTA', ''),
            'email' => self::env('LIBREDTE_BANCO_EMAIL', $this->emisorEmail),
        ];
        $this->cuentaBancaria = ($cuenta['numero'] ?? '') === '' ? null : $cuenta;
        // Representación impresa (PDF): logo opcional y URL de consulta de boletas.
        $this->pdfLogo = self::env('LIBREDTE_PDF_LOGO', '');
        $this->urlConsultaBoleta = self::env('LIBREDTE_URL_CONSULTA_BOLETA', '');
    }

    /** Ruta del archivo donde se persiste la clave cuando el certificado se sube por API. */
    public function certPassPath(): string
    {
        return $this->certPath . '.pass';
    }

    public function resolveCertPass(): string
    {
        if (is_file($this->certPassPath())) {
            return trim((string) file_get_contents($this->certPassPath()));
        }
        return $this->certPass;
    }

    /**
     * Directorio de CAF de un ambiente. Para el ambiente activo es el
     * configurado ($cafDir, que LIBREDTE_CAF_DIR puede haber reemplazado);
     * para el otro, el default de ese ambiente. Así el instalador de CAF
     * nunca escribe un CAF de producción en la carpeta de certificación ni
     * al revés.
     */
    public function cafDirPara(string $ambiente): string
    {
        self::exigirAmbiente($ambiente);
        if ($ambiente === $this->ambiente) {
            return $this->cafDir;
        }
        return $ambiente === 'produccion' ? '/data/caf/produccion' : '/data/caf';
    }

    /** Servidor del SII para el timbraje (solicitud de folios) del ambiente. */
    public function servidorTimbraje(string $ambiente): string
    {
        self::exigirAmbiente($ambiente);
        return $ambiente === 'produccion' ? 'palena' : 'maullin';
    }

    private static function exigirAmbiente(string $ambiente): void
    {
        if ($ambiente !== 'produccion' && $ambiente !== 'certificacion') {
            throw new \InvalidArgumentException('ambiente debe ser "produccion" o "certificacion"');
        }
    }

    private static function asegurarDirectorio(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio {$dir}");
        }
    }

    private static function env(string $key, ?string $default = null): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException("Falta la variable de entorno {$key}");
        }
        return $value;
    }
}
