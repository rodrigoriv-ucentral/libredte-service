<?php

declare(strict_types=1);

namespace App\Sii;

use App\EnvioYaRecibidoException;
use Derafu\L10n\Cl\Rut\Rut;

/**
 * Cliente propio del DTEUpload del SII. Replica SendXmlDocumentJob::send/uploadXml/
 * validateUploadXmlResponse de libredte-lib-core, pero con timeouts de curl: la librería no pone
 * CURLOPT_TIMEOUT y un SII colgado dejaba el PHP esperando mientras Next ya había cortado (90 s).
 *
 *  - CURLOPT_CONNECTTIMEOUT = 15 s, CURLOPT_TIMEOUT = 50 s (antes hay que timbrar y Next corta a los 90 s).
 *  - UN solo intento (sin reintentos a ciegas: reenviar un sobre que el SII sí recibió es lo que duplica).
 *  - STATUS 99 -> EnvioYaRecibidoException (el SII ya lo tenía); el resto de los STATUS con el mismo
 *    texto que la librería.
 *
 * Solo se usa si LIBREDTE_UPLOAD_PROPIO=1 (ver SiiService::enviar); por defecto está APAGADO y se usa la
 * librería. Activarlo solo después de probarlo en certificación (Maullín). URL y token se inyectan
 * (SiiService los resuelve; las pruebas apuntan a un servidor local).
 */
final class DteUploadClient
{
    public const CONNECT_TIMEOUT_S = 15;
    public const TIMEOUT_S = 50;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly bool $verifySsl = true,
        private readonly int $connectTimeout = self::CONNECT_TIMEOUT_S,
        private readonly int $timeout = self::TIMEOUT_S,
    ) {
    }

    /**
     * Sube el XML (ya en ISO-8859-1) y devuelve el TRACKID.
     *
     * @param string $xml     Sobre EnvioDTE/EnvioBOLETA codificado en ISO-8859-1.
     * @param string $sender  RUT del certificado que firma el envío (con guion, sin puntos).
     * @param string $company RUT de la empresa emisora (con guion, sin puntos).
     * @throws EnvioYaRecibidoException STATUS 99.
     * @throws \RuntimeException        cualquier otro fallo (timeout, STATUS distinto de 0, respuesta inválida).
     */
    public function enviar(string $xml, string $sender, string $company): int
    {
        if ($xml === '' || $xml === '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n") {
            throw new \RuntimeException('El XML que se desea enviar al SII no puede ser vacío.');
        }
        if (!str_contains($xml, '<?xml')) {
            $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $xml;
        }

        Rut::validate($sender);
        Rut::validate($company);
        [$rutSender, $dvSender] = Rut::toArray($sender);
        [$rutCompany, $dvCompany] = Rut::toArray($company);

        $filepath = tempnam(sys_get_temp_dir(), 'libredte_xml_document_for_upload_to_sii_');
        if ($filepath === false || file_put_contents($filepath, $xml) === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal con el XML a enviar.');
        }
        try {
            $respuesta = $this->subir([
                'rutSender' => $rutSender,
                'dvSender' => $dvSender,
                'rutCompany' => $rutCompany,
                'dvCompany' => $dvCompany,
                'archivo' => curl_file_create($filepath, 'application/xml', $company . '_' . basename($filepath) . '.xml'),
            ]);
        } finally {
            @unlink($filepath);
        }

        return $this->validar($respuesta, $sender);
    }

    /** POST del formulario al SII: un solo intento, con timeouts. Devuelve el cuerpo de la respuesta. */
    private function subir(array $data): string
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (compatible; PROG 1.0; +https://www.libredte.cl)',
            'Cookie: TOKEN=' . $this->token,
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        if (!$this->verifySsl) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $cuerpo = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new \RuntimeException(
                "El SII no respondió en {$this->timeout} s: el envío puede haber llegado. No reenviar sin consultar el folio."
            );
        }
        if ($cuerpo === false || $cuerpo === '') {
            throw new \RuntimeException('Falló el envío del XML al SII. ' . $error);
        }
        if ($cuerpo === 'Error 500') {
            throw new \RuntimeException('Falló el envío del XML al SII. El SII tiene problemas en sus servidores (Error 500).');
        }

        return (string) $cuerpo;
    }

    /** Interpreta la respuesta del SII (RECEPCIONDTE) y devuelve el TRACKID. */
    private function validar(string $cuerpo, string $sender): int
    {
        $doc = new \DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $cargado = $doc->loadXML($cuerpo);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $xpath = $cargado ? new \DOMXPath($doc) : null;
        $valor = static function (string $ruta) use ($xpath): ?string {
            $nodo = $xpath?->query($ruta)->item(0);
            return $nodo === null ? null : trim($nodo->textContent);
        };

        $status = $valor('/RECEPCIONDTE/STATUS');
        if ($status === null || $status === '') {
            throw new \RuntimeException('La respuesta del envío del XML al SII no trae un código de estado válido.');
        }
        if ((int) $status === 0 && $status === '0') {
            return (int) ($valor('/RECEPCIONDTE/TRACKID') ?? 0);
        }

        switch ((int) $status) {
            case 1:
                $mensaje = sprintf('El usuario %s no tiene permisos para enviar XML al SII.', $sender);
                break;
            case 2:
                $mensaje = 'Error en el tamaño del archivo enviado con el XML, muy grande o muy chico.';
                break;
            case 3:
                $mensaje = 'El archivo enviado está cortado, el tamaño es diferente al parámetro "size".';
                break;
            case 5:
                $mensaje = sprintf('El usuario %s no está autenticado (posible token expirado).', $sender);
                break;
            case 6:
                $mensaje = 'La empresa no está autorizada a enviar archivos XML al SII.';
                break;
            case 7:
                $mensaje = 'El esquema del XML es inválido.';
                break;
            case 8:
                $mensaje = 'Existe un error en la firma del documento XML.';
                break;
            case 9:
                $mensaje = 'Los servidores del SII están con problemas internos.';
                break;
            case 99:
                // El SII ya tenía este envío: no es un fallo (la app lo confirma con QueryEstDte).
                throw new EnvioYaRecibidoException('El SII ya había recibido este envío (STATUS 99).');
            default:
                $mensaje = sprintf('Ocurrió un error con código de estado "%s", que es desconocido por LibreDTE.', $status);
                break;
        }

        $detalles = [];
        foreach ($xpath?->query('/RECEPCIONDTE/DETAIL/ERROR') ?? [] as $nodo) {
            $detalles[] = trim($nodo->textContent);
        }
        if ($detalles) {
            $mensaje .= ' ' . implode(' ', $detalles);
        }

        throw new \RuntimeException($mensaje);
    }
}
