<?php

declare(strict_types=1);

namespace App\Timbraje;

/**
 * Pasos del portal de timbraje (https://{maullin|palena}.sii.cl/cvc_cgi/dte/)
 * dentro de una sesión YA abierta (SiiPortalSesion::ejecutar):
 *
 *   abrir()          GET  of_solicita_folios        → form of_solicita_folios_dcto (p4)
 *   consultarCupo()  POST of_solicita_folios_dcto   → form of_confirma_folio (p5): MAX_AUTOR, FOLIOS_DISP
 *   confirmar()      POST of_confirma_folio         → form of_genera_folio (p6): rango propuesto
 *                    + validaciones ANTES del punto irreversible
 *   generar()        POST of_genera_folio           → form of_genera_archivo (p7)   ** IRREVERSIBLE **
 *                    una sola vez por tipo y sesión; nunca se reintenta; ante
 *                    falla/respuesta ambigua devuelve 'incierto'.
 *   descargar*()     POST of_genera_archivo         → XML del CAF (repetible)
 *
 * Los formularios del SII se reenvían EXACTOS (los campos ocultos tal como
 * vienen); solo se completan RUT_EMP/DV_EMP/COD_DOCTO en p4 y CANT_DOCTOS en p5.
 * Las URL se arman con el servidor configurado y el nombre de la acción (no se
 * sigue el action del HTML a otro host).
 *
 * Este cliente nunca devuelve ni registra cookies; el XML del CAF solo sale de
 * descargar*() como bytes para el instalador.
 */
final class TimbrajeClient
{
    /**
     * Tipos habilitados para solicitar CAF. El 39 (boleta) exige que el SII haya autorizado
     * a la empresa a emitir boletas con sistema propio; el flujo del portal es el mismo que
     * para 33/56/61 (of_solicita_folios → … → of_genera_archivo).
     */
    public const TIPOS_V1 = [33, 39, 56, 61];

    /**
     * Tipos para los que el portal puede no informar MAX_AUTOR en p5 (consulta de solo lectura
     * en Maullín: "39 sin tope visible (solo cantidad)"). Si viene
     * legible se usa igual; si no, maxAutor = null y el único tope es el de la plataforma
     * (el SII sigue validando la cantidad en of_confirma_folio/of_genera_folio).
     */
    public const TIPOS_SIN_MAX_AUTOR = [39];

    /** Códigos de detectarError que, tras of_genera_folio, son un rechazo explícito del SII. */
    private const RECHAZOS_SII = ['MAX_AUTORIZADO', 'DTE_OFGA', 'SIN_VERIFICACION_ACTIVIDADES'];

    private ?array $formP4 = null;
    /** @var array<int, true> tipos para los que ya se envió of_genera_folio */
    private array $generados = [];
    private string $rut;
    private string $dv;
    private \Closure $reloj;

    /**
     * @param (\Closure(): \DateTimeImmutable) $reloj
     * @param (\Closure(int $tipo): int)|null $maxFolioLocal Mayor folio "hasta" de los CAF locales del tipo (0 si no hay).
     */
    public function __construct(
        private readonly SiiPortalSesion $sesion,
        private readonly string $servidor,
        string $rutEmisor,
        \Closure $reloj,
        private readonly ?\Closure $maxFolioLocal = null,
    ) {
        if ($servidor !== 'maullin' && $servidor !== 'palena') {
            throw new \InvalidArgumentException('servidor debe ser "maullin" o "palena"');
        }
        if (preg_match('/^(\d{6,9})-([\dkK])$/', str_replace('.', '', trim($rutEmisor)), $m) !== 1) {
            throw new \InvalidArgumentException('RUT emisor inválido (formato 12345678-9)');
        }
        $this->rut = $m[1];
        $this->dv = strtoupper($m[2]);
        $this->reloj = $reloj;
    }

    public static function urlPara(string $servidor, string $accion): string
    {
        return "https://{$servidor}.sii.cl/cvc_cgi/dte/{$accion}";
    }

    public function urlInicio(): string
    {
        return self::urlPara($this->servidor, 'of_solicita_folios');
    }

    /** Entra al portal de timbraje y guarda el formulario p4. @throws ErrorTimbraje|ErrorSesionSii */
    public function abrir(): void
    {
        $r = $this->sesion->get($this->urlInicio());
        $this->formP4 = $this->formEsperado($r, 'of_solicita_folios_dcto');
    }

    /**
     * @return array{tipo: int, maxAutor: ?int, foliosDisp: ?int, form: array} maxAutor null solo en TIPOS_SIN_MAX_AUTOR.
     * @throws ErrorTimbraje|ErrorSesionSii
     */
    public function consultarCupo(int $tipo): array
    {
        self::exigirTipo($tipo);
        if ($this->formP4 === null) {
            throw new \LogicException('Primero abrir().');
        }
        $campos = $this->formP4['campos'];
        $campos['RUT_EMP'] = $this->rut;
        $campos['DV_EMP'] = $this->dv;
        $campos['COD_DOCTO'] = (string) $tipo;
        $r = $this->sesion->post($this->url('of_solicita_folios_dcto'), $campos);
        $p5 = $this->formEsperado($r, 'of_confirma_folio');

        if (FormularioSii::valorCampo($p5, 'COD_DOCTO') !== (string) $tipo) {
            throw new ErrorTimbraje('TIPO_DISTINTO', "El SII devolvió el formulario de otro tipo de documento (se pidió {$tipo}).");
        }
        $this->exigirRut($p5);
        $max = trim((string) FormularioSii::valorCampo($p5, 'MAX_AUTOR'));
        if (!ctype_digit($max) && !in_array($tipo, self::TIPOS_SIN_MAX_AUTOR, true)) {
            throw new ErrorTimbraje('MAX_AUTOR_ILEGIBLE', 'No se pudo leer el máximo autorizado (MAX_AUTOR).');
        }
        $disp = trim((string) FormularioSii::valorCampo($p5, 'FOLIOS_DISP'));
        return [
            'tipo' => $tipo,
            'maxAutor' => ctype_digit($max) ? (int) $max : null,
            'foliosDisp' => ctype_digit($disp) ? (int) $disp : null,
            'form' => $p5,
        ];
    }

    /**
     * Pide el rango (of_confirma_folio) y valida la propuesta ANTES del punto
     * irreversible. Cualquier diferencia aborta con ErrorTimbraje.
     *
     * @param array{tipo: int, maxAutor: ?int, form: array} $cupo Resultado de consultarCupo().
     * @return array{tipo: int, cantidad: int, folioIni: int, folioFin: int, fecha: string, form: array}
     * @throws ErrorTimbraje|ErrorSesionSii
     */
    public function confirmar(array $cupo, int $cantidad): array
    {
        $tipo = (int) $cupo['tipo'];
        self::exigirTipo($tipo);
        if ($cantidad < 1) {
            throw new ErrorTimbraje('CANTIDAD_INVALIDA', 'La cantidad debe ser al menos 1.');
        }
        if ($cupo['maxAutor'] !== null && $cantidad > (int) $cupo['maxAutor']) {
            throw new ErrorTimbraje('MAX_AUTOR', "Se pidieron {$cantidad} folios y el SII autoriza como máximo {$cupo['maxAutor']}.");
        }
        $campos = $cupo['form']['campos'];
        $campos['CANT_DOCTOS'] = (string) $cantidad;
        $r = $this->sesion->post($this->url('of_confirma_folio'), $campos);
        $p6 = $this->formEsperado($r, 'of_genera_folio');

        [$ini, $fin, $fecha] = $this->validarPropuesta($p6, $tipo, $cantidad);
        return ['tipo' => $tipo, 'cantidad' => $cantidad, 'folioIni' => $ini, 'folioFin' => $fin, 'fecha' => $fecha, 'form' => $p6];
    }

    /**
     * PUNTO IRREVERSIBLE. Envía of_genera_folio UNA vez con los campos de p6
     * exactos. Nunca reintenta. $antesDeEnviar se llama justo antes del POST
     * (para persistir que el envío va a ocurrir); si lanza, no se envía nada.
     *
     * @param array{tipo: int, cantidad: int, folioIni: int, folioFin: int, fecha: string, form: array} $confirmacion
     * @return array{estado: 'generado'|'error_sii'|'incierto', folioIni: ?int, folioFin: ?int, fecha: ?string,
     *   fechaValida: bool, form: ?array, error: ?array{codigo: string, detalle: string}, advertencia: ?string}
     */
    public function generar(array $confirmacion, ?\Closure $antesDeEnviar = null): array
    {
        $tipo = (int) $confirmacion['tipo'];
        self::exigirTipo($tipo);
        if (isset($this->generados[$tipo])) {
            throw new \LogicException("of_genera_folio ya se envió para el tipo {$tipo} en esta sesión: no se reintenta.");
        }
        $form = $confirmacion['form'] ?? null;
        if (!is_array($form) || !is_array($form['campos'] ?? null) || !FormularioSii::fechaCompleta($form)) {
            throw new ErrorTimbraje('FECHA_INCOMPLETA', 'El formulario de of_genera_folio no trae fecha y hora completas.');
        }
        if ($antesDeEnviar !== null) {
            $antesDeEnviar();
        }
        $this->generados[$tipo] = true; // marcado antes del envío: jamás un segundo POST

        try {
            $r = $this->sesion->post($this->url('of_genera_folio'), $form['campos']);
        } catch (ErrorTransporte $e) {
            return self::incierto($e->tiempoAgotado ? 'TIEMPO_AGOTADO' : 'ERROR_RED', $e->getMessage(), $confirmacion);
        } catch (ErrorSesionSii $e) {
            return self::incierto($e->codigo, $e->getMessage(), $confirmacion);
        } catch (\Throwable $e) {
            return self::incierto('ERROR_DESCONOCIDO', get_class($e), $confirmacion);
        }

        $p7 = $r->estado < 400 ? FormularioSii::formularioPorAccion($r->cuerpo, 'of_genera_archivo') : null;
        if ($p7 !== null) {
            $ini = trim((string) FormularioSii::valorCampo($p7, 'FOLIO_INI'));
            $fin = trim((string) FormularioSii::valorCampo($p7, 'FOLIO_FIN'));
            $cod = trim((string) FormularioSii::valorCampo($p7, 'COD_DOCTO'));
            $rutOk = trim((string) FormularioSii::valorCampo($p7, 'RUT_EMP')) === $this->rut
                && strtoupper(trim((string) FormularioSii::valorCampo($p7, 'DV_EMP'))) === $this->dv;
            if (!ctype_digit($ini) || !ctype_digit($fin) || (int) $fin < (int) $ini || $cod !== (string) $tipo || !$rutOk) {
                return self::incierto('P7_INESPERADO', 'El SII respondió el formulario de descarga con datos que no corresponden a lo pedido.', $confirmacion);
            }
            $fecha = trim((string) FormularioSii::valorCampo($p7, 'FECHA'));
            $fechaValida = self::fechaIsoValida($fecha);
            $advertencia = null;
            if ((int) $ini !== $confirmacion['folioIni'] || (int) $fin !== $confirmacion['folioFin']) {
                $advertencia = "El SII asignó {$ini}-{$fin} y había propuesto {$confirmacion['folioIni']}-{$confirmacion['folioFin']}.";
            }
            return [
                'estado' => 'generado',
                'folioIni' => (int) $ini,
                'folioFin' => (int) $fin,
                // Si la FECHA de p7 viene mal (p. ej. <FA>AAAA-MM-</FA>) se guarda la de p6 para recuperar.
                'fecha' => $fechaValida ? $fecha : $confirmacion['fecha'],
                'fechaValida' => $fechaValida,
                'form' => $p7,
                'error' => $fechaValida ? null : ['codigo' => 'FECHA_P7_INVALIDA', 'detalle' => 'La fecha del formulario de descarga viene incompleta; no se descarga automáticamente.'],
                'advertencia' => $advertencia,
            ];
        }

        $e = FormularioSii::detectarError($r->cuerpo, 'of_genera_archivo', $r->urlEfectiva());
        if ($e !== null && in_array($e['codigo'], self::RECHAZOS_SII, true)) {
            return [
                'estado' => 'error_sii', 'folioIni' => null, 'folioFin' => null, 'fecha' => null, 'fechaValida' => false,
                'form' => null,
                'error' => ['codigo' => $e['codigo'], 'detalle' => $e['detalle'], 'codigoSii' => $e['codigo_sii'] ?? null],
                'advertencia' => null,
            ];
        }
        // SII_ERROR y cualquier otro código no listado arriba (p. ej. "No ha
        // sido posible completar su solicitud...") son ambiguos: no dicen si
        // el SII asignó folios, así que quedan 'incierto' (nunca 'error_sii').
        return self::incierto($e['codigo'] ?? 'RESPUESTA_AMBIGUA', ($e['detalle'] ?? '') . " (HTTP {$r->estado})", $confirmacion, $e['codigo_sii'] ?? null);
    }

    /**
     * Descarga el CAF reenviando EXACTO el formulario p7 que devolvió of_genera_folio.
     *
     * @return string Bytes tal cual (XML del CAF o HTML de error). No registrarlos nunca.
     * @throws ErrorTimbraje|ErrorTransporte|ErrorSesionSii
     */
    public function descargarConFormulario(array $formP7): string
    {
        if (!is_array($formP7['campos'] ?? null) || stripos((string) ($formP7['action'] ?? ''), 'of_genera_archivo') === false) {
            throw new \InvalidArgumentException('Se esperaba el formulario of_genera_archivo.');
        }
        return $this->postArchivo($formP7['campos']);
    }

    /**
     * Descarga (o vuelve a descargar) un CAF ya generado con RUT, tipo, rango y
     * FECHA: los mismos 6 campos de p7. No genera nada nuevo en el SII.
     *
     * @throws ErrorTimbraje|ErrorTransporte|ErrorSesionSii
     */
    public function descargar(int $tipo, int $folioIni, int $folioFin, string $fecha): string
    {
        self::exigirTipo($tipo);
        if ($folioIni < 1 || $folioFin < $folioIni || !self::fechaIsoValida($fecha)) {
            throw new \InvalidArgumentException('Rango o fecha inválidos para descargar el CAF.');
        }
        return $this->postArchivo([
            'RUT_EMP' => $this->rut,
            'DV_EMP' => $this->dv,
            'COD_DOCTO' => (string) $tipo,
            'FOLIO_INI' => (string) $folioIni,
            'FOLIO_FIN' => (string) $folioFin,
            'FECHA' => $fecha,
        ]);
    }

    // ------------------------------------------------------------------

    /** @param array<string, string> $campos */
    private function postArchivo(array $campos): string
    {
        $r = $this->sesion->post($this->url('of_genera_archivo'), $campos);
        if ($r->estado >= 400) {
            throw new ErrorTimbraje('HTTP_' . $r->estado, 'El SII respondió HTTP ' . $r->estado . ' al descargar el CAF.');
        }
        return $r->cuerpo;
    }

    /** @return array{0: int, 1: int, 2: string} folioIni, folioFin, fecha Y-m-d */
    private function validarPropuesta(array $p6, int $tipo, int $cantidad): array
    {
        // 1. Fecha y hora completas (con fechas vacías el SII puede firmar un CAF con <FA>AAAA-MM-</FA>).
        if (!FormularioSii::fechaCompleta($p6)) {
            throw new ErrorTimbraje('FECHA_INCOMPLETA', 'La propuesta del SII no trae fecha y hora completas (DIA/MES/ANO/HORA/MINUTO); no se genera.');
        }
        // 2. Tipo, RUT, cantidad y rango.
        if (trim((string) FormularioSii::valorCampo($p6, 'COD_DOCTO')) !== (string) $tipo) {
            throw new ErrorTimbraje('CONFIRMACION_INESPERADA', "La propuesta del SII es para otro tipo de documento (se pidió {$tipo}).");
        }
        $this->exigirRut($p6);
        $cant = trim((string) FormularioSii::valorCampo($p6, 'CANT_DOCTOS'));
        $ini = trim((string) FormularioSii::valorCampo($p6, 'FOLIO_INI'));
        $fin = trim((string) FormularioSii::valorCampo($p6, 'FOLIO_FIN'));
        if (!ctype_digit($cant) || !ctype_digit($ini) || !ctype_digit($fin) || (int) $ini < 1 || (int) $fin < (int) $ini) {
            throw new ErrorTimbraje('CONFIRMACION_INESPERADA', 'La propuesta del SII no trae cantidad o rango legibles.');
        }
        $largo = (int) $fin - (int) $ini + 1;
        if ((int) $cant < $cantidad || $largo < $cantidad) {
            throw new ErrorTimbraje('AUTORIZA_MENOS', "Se pidieron {$cantidad} folios y el SII propone {$cant} ({$ini}-{$fin}).");
        }
        if ((int) $cant !== $cantidad || $largo !== $cantidad) {
            throw new ErrorTimbraje('CONFIRMACION_INESPERADA', "Se pidieron {$cantidad} folios y el SII propone {$cant} ({$ini}-{$fin}).");
        }
        $max = trim((string) FormularioSii::valorCampo($p6, 'MAX_AUTOR'));
        if (ctype_digit($max) && (int) $max < $cantidad) {
            throw new ErrorTimbraje('MAX_AUTOR', "Se pidieron {$cantidad} folios y el SII autoriza como máximo {$max}.");
        }
        // 3. La fecha propuesta es hoy (America/Santiago).
        $hoy = ($this->reloj)()->setTimezone(new \DateTimeZone('America/Santiago'));
        $d = (int) FormularioSii::valorCampo($p6, 'DIA');
        $m = (int) FormularioSii::valorCampo($p6, 'MES');
        $a = (int) FormularioSii::valorCampo($p6, 'ANO');
        $h = (int) FormularioSii::valorCampo($p6, 'HORA');
        $mi = (int) FormularioSii::valorCampo($p6, 'MINUTO');
        if (!checkdate($m, $d, $a) || $h > 23 || $mi > 59) {
            throw new ErrorTimbraje('FECHA_INCOMPLETA', 'La fecha propuesta por el SII no es una fecha válida.');
        }
        $fecha = sprintf('%04d-%02d-%02d', $a, $m, $d);
        if ($fecha !== $hoy->format('Y-m-d')) {
            throw new ErrorTimbraje('FECHA_DISTINTA', "La fecha propuesta por el SII ({$fecha}) no es la de hoy ({$hoy->format('Y-m-d')}).");
        }
        // 4. El rango no pisa folios de CAF ya instalados.
        if ($this->maxFolioLocal !== null) {
            $maxLocal = (int) ($this->maxFolioLocal)($tipo);
            if ((int) $ini <= $maxLocal) {
                throw new ErrorTimbraje('FOLIO_SOLAPADO', "El SII propone empezar en {$ini} y ya hay CAF locales del tipo {$tipo} hasta el folio {$maxLocal}.");
            }
        }
        return [(int) $ini, (int) $fin, $fecha];
    }

    private function formEsperado(RespuestaHttp $r, string $accion): array
    {
        $f = $r->estado < 400 ? FormularioSii::formularioPorAccion($r->cuerpo, $accion) : null;
        if ($f !== null) {
            return $f;
        }
        $e = FormularioSii::detectarError($r->cuerpo, $accion, $r->urlEfectiva())
            ?? ['codigo' => 'DESCONOCIDO', 'detalle' => ''];
        if ($r->estado >= 400) {
            $e['detalle'] = trim('HTTP ' . $r->estado . '. ' . $e['detalle']);
        }
        throw new ErrorTimbraje($e['codigo'], $e['detalle'], $e['codigo_sii'] ?? null);
    }

    private function exigirRut(array $form): void
    {
        $rut = trim((string) FormularioSii::valorCampo($form, 'RUT_EMP'));
        $dv = strtoupper(trim((string) FormularioSii::valorCampo($form, 'DV_EMP')));
        if ($rut !== $this->rut || $dv !== $this->dv) {
            throw new ErrorTimbraje('RUT_DISTINTO', 'El formulario del SII corresponde a otro contribuyente.');
        }
    }

    private function url(string $accion): string
    {
        return self::urlPara($this->servidor, $accion);
    }

    private static function exigirTipo(int $tipo): void
    {
        if (!in_array($tipo, self::TIPOS_V1, true)) {
            throw new \InvalidArgumentException("Tipo de documento {$tipo} no habilitado para solicitar CAF.");
        }
    }

    private static function fechaIsoValida(string $f): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Resultado 'incierto': se conserva el rango y la fecha PROPUESTOS (p6) para intentar recuperar. */
    private static function incierto(string $codigo, string $detalle, array $confirmacion, ?string $codigoSii = null): array
    {
        return [
            'estado' => 'incierto',
            'folioIni' => $confirmacion['folioIni'] ?? null,
            'folioFin' => $confirmacion['folioFin'] ?? null,
            'fecha' => $confirmacion['fecha'] ?? null,
            'fechaValida' => false,
            'form' => null,
            'error' => ['codigo' => $codigo, 'detalle' => $detalle, 'codigoSii' => $codigoSii],
            'advertencia' => null,
        ];
    }
}
