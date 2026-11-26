<?php

declare(strict_types=1);

namespace App;

/**
 * Construye el arreglo `Encabezado/Detalle/DscRcgGlobal/Referencia` que exige el
 * formato DTE del SII, a partir del payload simple que recibe el microservicio.
 *
 * Se separó de DteEmisor a propósito: el set de pruebas del SII obliga a cuadrar
 * montos al peso (descuentos por línea, descuento global, exentos, precios con
 * IVA incluido en boleta) y eso tiene que poder verificarse sin CAF, sin
 * certificado y sin llamar al SII.
 *
 * REGLA DE ORO: los totales se calculan aquí, nunca se toman del cliente. El SII
 * cuadra Totales contra el Detalle y rechaza el documento si difieren en $1.
 */
final class DteBuilder
{
    /** Tasa de IVA vigente. */
    private const TASA_IVA = 19;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array $payload  Datos del documento (ver README del servicio).
     * @param int   $tipoDte  33, 39, 56 o 61.
     * @param int   $folio    Folio ya reservado del CAF.
     */
    public function construir(array $payload, int $tipoDte, int $folio): array
    {
        // Por defecto los precios del payload son NETOS (facturas, notas de débito y
        // notas de crédito): cambiar ese default en silencio alteraría los totales de
        // los DTE ya en producción. Van con precio BRUTO (IVA incluido) y
        // preciosBrutos=true la boleta 39 (la app cliente y el set de boletas
        // del SII) y la NC 61 que anula una boleta, que además marca
        // IdDoc/MntBruto=1 (ver construirIdDoc()) para replicar el desglose de la boleta.
        $preciosBrutos = (bool) ($payload['preciosBrutos'] ?? false);

        $lineas = $this->construirDetalle($payload['items'] ?? [], $payload, $preciosBrutos);
        $descuentoGlobal = $this->construirDescuentoGlobal($payload['descuentoGlobal'] ?? null);
        $totales = $this->calcularTotales($lineas, $descuentoGlobal, $preciosBrutos, $tipoDte);

        $data = [
            'Encabezado' => [
                'IdDoc' => $this->construirIdDoc($payload, $tipoDte, $folio),
                'Emisor' => [
                    'RUTEmisor' => $this->config->emisorRut,
                    'RznSoc' => $this->config->emisorRazonSocial,
                    'GiroEmis' => $this->config->emisorGiro,
                    // La librería reordena según el esquema y los anula en boleta.
                    'Telefono' => $this->config->emisorTelefono ?: false,
                    'CorreoEmisor' => $this->config->emisorEmail ?: false,
                    'Acteco' => $this->config->emisorActeco,
                    'DirOrigen' => $this->config->emisorDireccion,
                    'CmnaOrigen' => $this->config->emisorComuna,
                    'CiudadOrigen' => $this->config->emisorCiudad ?: false,
                ],
                'Receptor' => $this->construirReceptor($payload, $tipoDte),
                // En 33/56/61 libredte-lib-core suma cada línea sobre MntNeto/MntExe
                // (+=), así que se entregan en cero para que no se dupliquen. IVA y
                // MntTotal se conservan: la librería no los pisa si ya vienen. En
                // boleta la librería suma cada línea sobre MntTotal (y sobre MntExe si
                // es exenta), así que esos dos van en cero; MntNeto e IVA se conservan.
                'Totales' => $tipoDte === 39
                    ? ['MntExe' => false, 'MntTotal' => 0] + $totales
                    : ['MntNeto' => 0, 'MntExe' => false] + $totales,
            ],
            'Detalle' => array_map(
                static fn (array $linea) => $linea['nodo'],
                $lineas,
            ),
        ];

        if ($descuentoGlobal !== null) {
            $data['DscRcgGlobal'] = [$descuentoGlobal['nodo']];
        }

        $referencias = $this->construirReferencias($payload, $tipoDte, $folio);
        if ($referencias !== []) {
            $data['Referencia'] = $referencias;
        }

        return $data;
    }

    /** Totales finales del documento (los de `construir()` llevan MntTotal/MntExe en cero en boleta). */
    public function totales(array $payload, int $tipoDte): array
    {
        $preciosBrutos = (bool) ($payload['preciosBrutos'] ?? false);
        $lineas = $this->construirDetalle($payload['items'] ?? [], $payload, $preciosBrutos);
        $descuentoGlobal = $this->construirDescuentoGlobal($payload['descuentoGlobal'] ?? null);

        return $this->calcularTotales($lineas, $descuentoGlobal, $preciosBrutos, $tipoDte);
    }

    private function construirIdDoc(array $payload, int $tipoDte, int $folio): array
    {
        $idDoc = [
            'TipoDTE' => $tipoDte,
            'Folio' => $folio,
            'FchEmis' => (string) ($payload['fechaEmision'] ?? date('Y-m-d')),
            'FmaPago' => (int) ($payload['formaPago'] ?? 2),
        ];

        // Nota importante sobre MntBruto / IndMntNeto (ver
        // NormalizeIvaMntTotalTrait::normalizeIvaMntTotal en
        // vendor/libredte/libredte-lib-core):
        //  - Boleta 39: no se activa nada. La librería toma MntTotal desde el
        //    detalle y conserva el MntNeto/IVA que entrega DteBuilder.
        //  - 33/56/61 con preciosBrutos (hoy solo la NC que anula una boleta):
        //    SÍ se marca MntBruto=1 (DTE_v10.xsd: "monto de líneas de detalle
        //    corresponde a valores brutos"). La librería suma el detalle bruto en
        //    MntNeto y luego Utils::calculateNetoIVA() lo separa en neto
        //    round(T/1,19) e IVA T-neto: la misma fórmula de calcularTotales() y de
        //    la boleta, así que la NC queda idéntica a la boleta que anula, para
        //    todo T. Sin la marca, MntNeto quedaba bruto (p. ej. 1005) junto a un
        //    IVA de 160, un XML incoherente.
        //  - Con precios netos (resto de 33/56/61) tampoco se marca: la librería
        //    volvería a partir un MntNeto que YA es neto, descontando el IVA dos veces.
        //  - IndMntNeto no se usa nunca.
        if ($tipoDte !== 39 && (bool) ($payload['preciosBrutos'] ?? false)) {
            $idDoc['MntBruto'] = 1;
        }
        if ($tipoDte === 39) {
            // 1=periódico domiciliario · 2=periódico · 3=servicios · 4=venta.
            $idDoc['IndServicio'] = (int) ($payload['indServicio'] ?? 3);
            // La boleta no lleva forma de pago.
            unset($idDoc['FmaPago']);
        } else {
            // Vencimiento y términos de pago: solo 33/56/61 (la boleta no los tiene).
            // La librería reordena las claves según el esquema.
            $vence = trim((string) ($payload['fechaVencimiento'] ?? ''));
            if ($vence !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) {
                    throw new \InvalidArgumentException('fechaVencimiento debe ser YYYY-MM-DD');
                }
                if ($vence < $idDoc['FchEmis']) {
                    throw new \InvalidArgumentException('La fecha de vencimiento no puede ser anterior a la de emisión');
                }
                $idDoc['FchVenc'] = $vence;
            }
            $terminos = trim((string) ($payload['terminosPago'] ?? ''));
            if ($terminos !== '') {
                $idDoc['TermPagoGlosa'] = $this->truncar($terminos, 100);
            }
        }

        return $idDoc;
    }

    private function construirReceptor(array $payload, int $tipoDte): array
    {
        $rut = str_replace('.', '', (string) ($payload['rutCliente'] ?? ''));

        $receptor = [
            'RUTRecep' => $rut,
            'RznSocRecep' => $this->truncar($payload['razonSocial'] ?? '', 100),
            'DirRecep' => $this->truncar($payload['direccion'] ?? '', 70),
            'CmnaRecep' => $this->truncar($payload['comuna'] ?? '', 20),
        ];

        // El giro del receptor es obligatorio en 33/56/61 y no existe en boleta.
        // El SII pide explícitamente no abreviarlo, por eso el límite de 40
        // caracteres se respeta cortando, no acortando palabras a mano.
        if ($tipoDte !== 39) {
            $receptor['GiroRecep'] = $this->truncar($payload['giro'] ?? '', 40);
        }

        return $receptor;
    }

    /**
     * Normaliza cada ítem y devuelve, por línea, el nodo Detalle y los montos ya
     * calculados que necesita el cálculo de totales.
     *
     * @return array<int, array{nodo: array, monto: int, exento: bool}>
     */
    private function construirDetalle(array $items, array $payload, bool $preciosBrutos): array
    {
        // Una nota de crédito que sólo corrige texto (CodRef=2: "corrige giro del
        // receptor") no lleva mercadería, pero el SII exige al menos una línea de
        // detalle. Se emite una línea de monto cero con la razón de la corrección.
        if ($items === []) {
            $razon = $this->razonPrimeraReferencia($payload);
            if ($razon === null) {
                throw new \InvalidArgumentException(
                    'El documento no tiene items ni una referencia de la que derivar el detalle'
                );
            }
            // Sin cantidad ni precio: sólo NmbItem + MontoItem=0. Con QtyItem=1
            // y sin PrcItem el SII rechaza el caso en la revisión del set
            // ("Los Valores de la Linea 1 del Detalle No Cuadran").
            $items = [['name' => $razon, 'qty' => null, 'price' => 0]];
        }

        $lineas = [];
        foreach (array_values($items) as $index => $item) {
            if (empty($item['name'])) {
                throw new \InvalidArgumentException('Cada item requiere "name"');
            }

            $cantidad = (float) ($item['qty'] ?? 1);
            $precio = (float) ($item['price'] ?? 0);
            $exento = (bool) ($item['exento'] ?? false);

            $bruto = (int) round($cantidad * $precio);
            $descuento = $this->descuentoDeLinea($item, $bruto);
            $monto = $bruto - $descuento;

            // El orden de las claves es el orden real del XML (DTE_v10.xsd
            // exige esta secuencia exacta dentro de Detalle; verificado contra
            // el esquema real en resources/schemas/DTE_v10.xsd — MontoItem al
            // final, no junto a NmbItem/PrcItem como parecería natural). El
            // nombre del campo también importa: es "MontoItem", no "MntItem"
            // (que sería el error natural por analogía con MntNeto/MntExe de
            // Totales) — con el nombre equivocado el XSD lo rechaza entero
            // como "elemento no esperado", sin importar en qué posición esté.
            $nodo = ['NroLinDet' => $index + 1];

            if ($exento) {
                // IndExe=1: ítem no afecto o exento de IVA dentro de un documento
                // que sí tiene ítems afectos.
                $nodo['IndExe'] = 1;
            }

            $nodo['NmbItem'] = $this->truncar($item['name'], 80);

            if (!empty($item['descripcion'])) {
                $nodo['DscItem'] = $this->truncar($item['descripcion'], 1000);
            }

            if (!array_key_exists('qty', $item) || $item['qty'] !== null) {
                $nodo['QtyItem'] = $cantidad;
            }

            if (!empty($item['unidad'])) {
                $nodo['UnmdItem'] = $this->truncar($item['unidad'], 4);
            }

            // PrcItem es opcional en el XSD (minOccurs="0") pero, cuando se
            // informa, debe ser estrictamente positivo (minInclusive
            // 0.000001) — verificado contra el rechazo real del validador.
            // La línea de detalle de monto cero de una NC "sin ítems" (ver
            // razonPrimeraReferencia()) usa precio 0, así que aquí se omite
            // en vez de mandar un valor que el esquema rechaza.
            if ($precio > 0) {
                $nodo['PrcItem'] = $precio;
            }

            if (isset($item['descuentoPct'])) {
                $nodo['DescuentoPct'] = (float) $item['descuentoPct'];
            }
            if ($descuento > 0) {
                $nodo['DescuentoMonto'] = $descuento;
            }

            $nodo['MontoItem'] = $monto;

            $lineas[] = ['nodo' => $nodo, 'monto' => $monto, 'exento' => $exento];
        }

        return $lineas;
    }

    /** Descuento de una línea: por porcentaje o por monto fijo, nunca ambos. */
    private function descuentoDeLinea(array $item, int $bruto): int
    {
        if (isset($item['descuentoPct']) && isset($item['descuentoMonto'])) {
            throw new \InvalidArgumentException(
                'Un item no puede tener descuentoPct y descuentoMonto a la vez'
            );
        }
        if (isset($item['descuentoPct'])) {
            return (int) round($bruto * ((float) $item['descuentoPct']) / 100);
        }
        return (int) round((float) ($item['descuentoMonto'] ?? 0));
    }

    /**
     * Descuento (o recargo) global. Por defecto aplica sobre los ítems afectos,
     * que es lo que pide el caso 4 del set básico; con `sobreExentos` se aplica
     * a los exentos.
     *
     * @return array{nodo: array, tipo: string, pct: ?float, monto: ?int, sobreExentos: bool}|null
     */
    private function construirDescuentoGlobal(?array $dr): ?array
    {
        if ($dr === null) {
            return null;
        }

        $porcentaje = isset($dr['pct']) ? (float) $dr['pct'] : null;
        $monto = isset($dr['monto']) ? (int) round((float) $dr['monto']) : null;

        if (($porcentaje === null) === ($monto === null)) {
            throw new \InvalidArgumentException(
                'descuentoGlobal requiere exactamente uno de: pct, monto'
            );
        }

        // TpoMov: D=descuento, R=recargo.
        $tipo = strtoupper((string) ($dr['tipo'] ?? 'D')) === 'R' ? 'R' : 'D';
        $sobreExentos = (bool) ($dr['sobreExentos'] ?? false);

        $nodo = [
            'NroLinDR' => 1,
            'TpoMov' => $tipo,
            'GlosaDR' => $this->truncar($dr['glosa'] ?? 'Descuento global', 45),
            'TpoValor' => $porcentaje !== null ? '%' : '$',
            'ValorDR' => $porcentaje ?? $monto,
        ];

        if ($sobreExentos) {
            $nodo['IndExeDR'] = 1;
        }

        return [
            'nodo' => $nodo,
            'tipo' => $tipo,
            'pct' => $porcentaje,
            'monto' => $monto,
            'sobreExentos' => $sobreExentos,
        ];
    }

    /**
     * Totales del documento.
     *
     * Con precios netos: neto = suma de afectos - descuento global; IVA sobre ese
     * neto. Con precios brutos (boleta): del bruto afecto se despeja el neto y el
     * IVA queda como diferencia, para que neto + IVA sume exactamente el bruto
     * que el cliente pagó.
     */
    private function calcularTotales(array $lineas, ?array $descuentoGlobal, bool $preciosBrutos, int $tipoDte): array
    {
        $afecto = 0;
        $exento = 0;
        foreach ($lineas as $linea) {
            if ($linea['exento']) {
                $exento += $linea['monto'];
            } else {
                $afecto += $linea['monto'];
            }
        }

        if ($descuentoGlobal !== null) {
            $base = $descuentoGlobal['sobreExentos'] ? $exento : $afecto;
            $ajuste = $descuentoGlobal['pct'] !== null
                ? (int) round($base * $descuentoGlobal['pct'] / 100)
                : (int) $descuentoGlobal['monto'];
            $signo = $descuentoGlobal['tipo'] === 'R' ? 1 : -1;

            if ($descuentoGlobal['sobreExentos']) {
                $exento += $signo * $ajuste;
            } else {
                $afecto += $signo * $ajuste;
            }
        }

        if ($preciosBrutos) {
            $neto = (int) round($afecto / (1 + self::TASA_IVA / 100));
            $iva = $afecto - $neto;
            $total = $afecto + $exento;
        } else {
            $neto = $afecto;
            $iva = (int) round($neto * self::TASA_IVA / 100);
            $total = $neto + $iva + $exento;
        }

        $totales = ['MntNeto' => $neto];
        if ($exento !== 0) {
            $totales['MntExe'] = $exento;
        }
        // TasaIVA no existe en el esquema de boleta (EnvioBOLETA_v11.xsd): su
        // Totales sólo trae MntNeto/MntExe/IVA/MntTotal/..., sin TasaIVA. Para
        // 33/56/61 sí es parte de DTE_v10.xsd. La validación de boleta
        // individual la salta la librería (se valida recién en el sobre —
        // "las boletas se validan a través del EnvioBOLETA"), así que este
        // error sólo se ve al armar el sobre, no en cada documento suelto.
        if ($tipoDte !== 39) {
            $totales['TasaIVA'] = self::TASA_IVA;
        }
        $totales['IVA'] = $iva;
        $totales['MntTotal'] = $total;

        return $totales;
    }

    /**
     * Referencias del documento. Acepta una lista (`referencias`) o la forma
     * antigua de una sola (`referencia`).
     *
     * Notas de crédito y débito EXIGEN referencia: sin ella el SII rechaza. Las
     * boletas del set de certificación llevan además una referencia especial con
     * CodRef="SET" y RazonRef="CASO-N" (ver referenciaBoleta()); en el set de
     * facturas es TpoDocRef="SET" y RazonRef="CASO <atención>-N".
     */
    private function construirReferencias(array $payload, int $tipoDte, int $folio): array
    {
        $refs = $payload['referencias'] ?? null;
        if ($refs === null && isset($payload['referencia'])) {
            $refs = [$payload['referencia']];
        }
        $refs = is_array($refs) ? array_values($refs) : [];

        // Orden de compra del cliente: referencia tipo 801 (solo factura). Va
        // después de las demás para no desplazar la referencia "SET" del set
        // de certificación ni la del documento que corrige una nota.
        $ordenCompra = trim((string) ($payload['ordenCompra'] ?? ''));
        if ($ordenCompra !== '' && $tipoDte === 33) {
            $refs[] = [
                'tpoDocRef' => '801',
                'folioRef' => $this->truncar($ordenCompra, 18),
                'fchRef' => (string) ($payload['fechaEmision'] ?? date('Y-m-d')),
            ];
        }

        if ($refs === [] && in_array($tipoDte, [56, 61], true)) {
            throw new \InvalidArgumentException(
                'Los DTE 56 y 61 requieren referencia: { tpoDocRef, folioRef, codRef, razonRef }'
            );
        }

        $nodos = [];
        foreach ($refs as $index => $ref) {
            // La boleta tiene su propio formato de Referencia (EnvioBOLETA_v11.xsd):
            // CodRef es alfanumérico (máx. 18) y TpoDocRef/FolioRef son opcionales.
            if ($tipoDte === 39) {
                $nodos[] = $this->referenciaBoleta($ref, $index + 1);
                continue;
            }

            if (!is_array($ref) || empty($ref['tpoDocRef'])) {
                throw new \InvalidArgumentException('Cada referencia requiere tpoDocRef');
            }

            // TpoDocRef normalmente es el código numérico del DTE referenciado
            // (33, 61…), pero el SII también admite texto para casos especiales
            // como "SET" en el set de pruebas de boletas.
            $tpoDocRef = (string) $ref['tpoDocRef'];

            // Orden real del XSD (Referencia): NroLinRef, TpoDocRef, IndGlobal,
            // FolioRef, RUTOtr, FchRef, CodRef, RazonRef. No usamos IndGlobal
            // ni RUTOtr, pero FolioRef SÍ debe ir antes que FchRef — verificado
            // contra el rechazo real del validador ("FchRef... was not
            // expected, the expected was... FolioRef").
            $nodo = [
                'NroLinRef' => $index + 1,
                'TpoDocRef' => $tpoDocRef,
            ];

            // FolioRef es obligatorio en el XSD (sin minOccurs="0", a
            // diferencia de IndGlobal) — no hay forma de omitirlo aunque la
            // referencia no apunte a un folio real. La referencia "SET" del
            // set de certificación no tiene folio propio que informar, así
            // que por defecto se usa el folio del propio documento — no hay
            // instrucción del SII sobre qué poner ahí, es la convención más
            // razonable ante un campo obligatorio sin dato real que informar.
            $nodo['FolioRef'] = (string) ($ref['folioRef'] ?? $folio);

            $nodo['FchRef'] = (string) ($ref['fchRef'] ?? date('Y-m-d'));

            // CodRef sólo aplica a documentos tributarios: 1=anula, 2=corrige
            // texto, 3=corrige montos. En la referencia "SET" no corresponde.
            if (isset($ref['codRef'])) {
                $nodo['CodRef'] = (int) $ref['codRef'];
            } elseif (in_array($tipoDte, [56, 61], true) && ctype_digit($tpoDocRef)) {
                throw new \InvalidArgumentException(
                    'La referencia de un DTE 56 o 61 requiere codRef (1=anula, 2=corrige texto, 3=corrige montos)'
                );
            }
            if (in_array($tipoDte, [56, 61], true) && ctype_digit($tpoDocRef) && empty($ref['folioRef'])) {
                throw new \InvalidArgumentException(
                    'La referencia de un DTE 56 o 61 requiere folioRef del documento referenciado'
                );
            }

            // Sin razón no se emite el nodo vacío (p. ej. la referencia a una orden de compra).
            $razon = $this->truncar($ref['razonRef'] ?? '', 90);
            if ($razon !== '') {
                $nodo['RazonRef'] = $razon;
            }

            $nodos[] = $nodo;
        }

        return $nodos;
    }

    /**
     * Referencia de una boleta (39). Formato distinto al de la factura
     * (formato_boletas_elec_202306.pdf §E y EnvioBOLETA_v11.xsd):
     *  - CodRef es ALFANUMÉRICO (máx. 18): el set de boletas del SII pide
     *    literalmente <CodRef>SET</CodRef> + <RazonRef>CASO-N</RazonRef>.
     *  - TpoDocRef/FolioRef son opcionales; FolioRef es obligatorio solo si
     *    se informa TpoDocRef. No existe FchRef.
     * Con TpoDocRef="SET" (el formato del set de FACTURAS) el SII rechazó las
     * boletas del set con HED-3-211 "Dato invalido".
     */
    private function referenciaBoleta(mixed $ref, int $linea): array
    {
        if (!is_array($ref) || (empty($ref['tpoDocRef']) && empty($ref['codRef']))) {
            throw new \InvalidArgumentException('Cada referencia de boleta requiere codRef o tpoDocRef');
        }

        $nodo = ['NroLinRef' => $linea];

        if (!empty($ref['tpoDocRef'])) {
            if (empty($ref['folioRef'])) {
                throw new \InvalidArgumentException('Una referencia de boleta con tpoDocRef requiere folioRef');
            }
            $nodo['TpoDocRef'] = $this->truncar($ref['tpoDocRef'], 3);
            $nodo['FolioRef'] = $this->truncar($ref['folioRef'], 18);
        }
        if (!empty($ref['codRef'])) {
            $nodo['CodRef'] = $this->truncar($ref['codRef'], 18);
        }
        $razon = $this->truncar($ref['razonRef'] ?? '', 90);
        if ($razon !== '') {
            $nodo['RazonRef'] = $razon;
        }

        return $nodo;
    }

    /**
     * Razón de la referencia con CodRef, para el detalle de monto cero.
     *
     * Todo documento del set de certificación lleva primero una referencia
     * "SET / CASO N-x" sin CodRef (la arma el script del set de pruebas)
     * y, a partir de la línea 2, la referencia real al documento que corrige o
     * anula. Por eso no basta con tomar la primera referencia con texto: hay
     * que buscar específicamente la que trae CodRef.
     */
    private function razonPrimeraReferencia(array $payload): ?string
    {
        $refs = $payload['referencias'] ?? (isset($payload['referencia']) ? [$payload['referencia']] : []);
        $refs = (array) $refs;

        foreach ($refs as $ref) {
            if (is_array($ref) && isset($ref['codRef']) && !empty($ref['razonRef'])) {
                return (string) $ref['razonRef'];
            }
        }
        // Ninguna referencia trae CodRef (p. ej. solo la "SET"): usar la
        // primera con texto en vez de no generar detalle.
        foreach ($refs as $ref) {
            if (is_array($ref) && !empty($ref['razonRef'])) {
                return (string) $ref['razonRef'];
            }
        }
        return null;
    }

    private function truncar(mixed $valor, int $largo): string
    {
        return mb_substr(trim((string) $valor), 0, $largo);
    }
}
