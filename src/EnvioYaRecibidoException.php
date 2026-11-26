<?php

declare(strict_types=1);

namespace App;

/**
 * El SII respondió STATUS 99 al DTEUpload: "el XML enviado ya fue previamente recibido".
 * No es un fallo del envío: el documento YA está en el SII. Se distingue para que la app
 * no lo muestre como "no enviado" (prefijo SII_STATUS_99: en envioError, 409 en /dte/reenviar).
 */
final class EnvioYaRecibidoException extends \RuntimeException
{
}
