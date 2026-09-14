<?php
namespace JeleDev\Shoppingcart;

use Illuminate\Support\Collection;

/**
 * Añade a Cart los metadatos documentales y la liquidación de sus ajustes.
 *
 * Utiliza session e instance del Cart que lo incorpora. Conserva costos,
 * descuentos y observaciones manuales aparte de Collection<CartItem>.
 * Las observaciones automáticas y los descuentos efectivos se recalculan desde
 * los registros estructurados, no se extraen de textos ni se guardan como productos.
 * summary() integra este estado con CartItem::calculateTaxes() y Money.
 *
 * @see Cart
 * @see Money
 */
trait CartAdjustments
{
    /**
     * Construye la clave de metadatos sustituyendo el prefijo cart. de instance.
     *
     * @return string Clave cart_metadata.<instancia> de la sesión actual.
     */
    protected function metadataKey()
    {
        return 'cart_metadata.' . substr($this->instance, 5);
    }

    /**
     * Lee los registros documentales de la sesión, sin calcular importes efectivos.
     *
     * Si la clave falta devuelve tres listas vacías. Los costos conservan importe
     * cuantizado y cents; los descuentos guardan la instrucción solicitada; solamente
     * las observaciones manuales se almacenan en observations. No valida la estructura
     * de datos que ya estuvieran guardados.
     *
     * @return array{costs: list<array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}>, discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string}>, observations: list<array{type: 'manual', text: string}>} Estado documental de la instancia.
     */
    protected function metadata()
    {
        return $this->session->get($this->metadataKey(), ['costs' => [], 'discounts' => [], 'observations' => []]);
    }

    /**
     * Reemplaza en sesión los metadatos completos de la instancia, sin validarlos.
     *
     * No modifica la colección de productos ni genera eventos.
     *
     * @param array{costs: list<array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}>, discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string}>, observations: list<array{type: 'manual', text: string}>} $metadata Listas de operaciones y observaciones manuales.
     * @return void
     */
    protected function saveMetadata(array $metadata)
    {
        $this->session->put($this->metadataKey(), $metadata);
    }

    /**
     * Devuelve los registros de costos, conservando operaciones repetidas.
     *
     * Cada array identifica nombre, importe cuantizado, centavos, modo, alícuota
     * y descripción. Incluye ITEM, PRORATED, tip y legacy, separados de content().
     *
     * @return Collection<int, array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}> Operaciones en orden de registro.
     */
    public function costs()
    {
        return new Collection($this->metadata()['costs']);
    }

    /**
     * Filtra las operaciones de costo por nombre y reindexa la colección.
     *
     * Usa la comparación de Collection::where(); no agrega importes ni liquida bases.
     * Puede devolver varios registros con el mismo nombre o una colección vacía.
     *
     * @param string $name Nombre del costo que se desea consultar.
     * @return Collection<int, array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}> Registros coincidentes.
     */
    public function costDetails($name)
    {
        return $this->costs()->where('name', $name)->values();
    }

    /**
     * Suma los centavos registrados de todos los costos y devuelve un importe numérico.
     *
     * Incluye ITEM, PRORATED, propinas y legacy. Es una consulta informativa del
     * importe de entrada: no refleja cuánto de un prorrateo queda tras descuentos.
     * No debe sumarse de nuevo a total(), que ya incorpora estos conceptos.
     *
     * @return int|float Suma de costos antes de descuentos, sin formato ni IVA adicional.
     */
    public function totalCost()
    {
        return array_sum(array_column($this->metadata()['costs'], 'cents')) / 100;
    }

    /**
     * Agrega una observación manual a los metadatos de la instancia.
     *
     * Rechaza texto vacío o compuesto solo por espacios; guarda el texto original
     * sin recortarlo. No crea CartItem ni afecta los cálculos monetarios.
     *
     * @param string $text Texto de la observación.
     * @return $this
     * @throws \InvalidArgumentException Si no se proporciona texto no vacío.
     */
    public function addObservation($text)
    {
        if (!is_string($text) || trim($text) === '') throw new \InvalidArgumentException('Observation text is required.');
        $metadata = $this->metadata();
        $metadata['observations'][] = ['type' => 'manual', 'text' => $text];
        $this->saveMetadata($metadata);
        return $this;
    }

    /**
     * Registra un descuento general que summary() aplicará a los saldos de productos.
     *
     * Se aplica después de todos los descuentos de línea y en orden de registro
     * respecto a otros generales. Excluye costos ITEM, propina y legacy.
     * percentage calcula una fracción de la base restante; fixed es un importe
     * para todo el documento. Se limita al saldo y reparte en centavos por base
     * restante, antes de calcular IVA. Sin productos el importe efectivo es cero.
     * Registrar el descuento no ejecuta todavía la liquidación.
     *
     * @param 'percentage'|'fixed' $type Modalidad del descuento.
     * @param int|float|numeric-string $value Porcentaje entre 0 y 100 o monto fijo no negativo.
     * @param string $concept Concepto que se incluirá en las observaciones.
     * @return $this
     * @throws \InvalidArgumentException Si el tipo o valor no son válidos o exceden el límite de Money.
     */
    public function addDiscount($type, $value, $concept = '')
    {
        return $this->registerDiscount(null, $type, $value, $concept);
    }

    /**
     * Resuelve una línea y registra un descuento comercial sobre su base previa al IVA.
     *
     * Acepta rowId o código inequívoco. Se aplica después de costos PRORATED y antes
     * de los descuentos generales; varios descuentos de línea siguen su orden de
     * registro. Un fixed afecta la línea completa, no cada unidad, y se limita al saldo.
     * El registro conserva rowId, modalidad, valor solicitado y concepto; summary()
     * calcula después el importe efectivo y su observación.
     *
     * @param int|string $identifier RowId o código del producto.
     * @param 'percentage'|'fixed' $type Modalidad del descuento.
     * @param int|float|numeric-string $value Porcentaje entre 0 y 100 o monto fijo no negativo.
     * @param string $concept Concepto de la operación.
     * @return $this
     * @throws \JeleDev\Shoppingcart\Exceptions\InvalidRowIDException Si no existe la línea.
     * @throws \JeleDev\Shoppingcart\Exceptions\AmbiguousItemException Si el código identifica varias líneas.
     * @throws \InvalidArgumentException Si el tipo, valor o importe no son válidos.
     */
    public function addDiscountToItem($identifier, $type, $value, $concept = '')
    {
        return $this->registerDiscount($this->get($identifier)->rowId, $type, $value, $concept);
    }

    /**
     * Valida y agrega una instrucción estructurada de descuento a la sesión.
     *
     * Valida modalidad, valor finito no negativo, porcentaje máximo y límite de Money.
     * Conserva value como float sin guardar aún el monto efectivo; concept se
     * convierte a string. No resuelve ni comprueba el rowId recibido.
     *
     * @param string|null $rowId Clave de línea, o null para descuento general.
     * @param 'percentage'|'fixed' $type Modalidad solicitada.
     * @param int|float|numeric-string $value Porcentaje o monto monetario original.
     * @param string $concept Descripción de la operación.
     * @return $this
     * @throws \InvalidArgumentException Si el tipo o valor no cumplen los límites.
     */
    private function registerDiscount($rowId, $type, $value, $concept)
    {
        if (!in_array($type, ['percentage', 'fixed'], true) || !is_numeric($value) || !is_finite((float) $value) || $value < 0 || ($type === 'percentage' && $value > 100)) {
            throw new \InvalidArgumentException('Discount must be nonnegative: percentage (0–100) or fixed.');
        }
        Money::cents($value);
        $metadata = $this->metadata();
        $metadata['discounts'][] = ['rowId' => $rowId, 'type' => $type, 'value' => (float) $value, 'concept' => (string) $concept];
        $this->saveMetadata($metadata);
        return $this;
    }

    /**
     * Traslada o elimina los descuentos vinculados exactamente a un rowId.
     *
     * Cart::update() lo utiliza al cambiar identidad; Cart::remove() al eliminar
     * una línea. Conserva el orden relativo, reindexa registros y guarda metadatos.
     *
     * @param string $oldRowId Clave interna de origen, no código de producto.
     * @param string|null $newRowId Clave de destino; null elimina los descuentos coincidentes.
     * @return void
     */
    protected function moveDiscounts($oldRowId, $newRowId = null)
    {
        $metadata = $this->metadata();
        foreach ($metadata['discounts'] as $key => &$discount) {
            if ($discount['rowId'] === $oldRowId) {
                if ($newRowId === null) unset($metadata['discounts'][$key]);
                else $discount['rowId'] = $newRowId;
            }
        }
        unset($discount);
        $metadata['discounts'] = array_values($metadata['discounts']);
        $this->saveMetadata($metadata);
    }

    /**
     * Devuelve descuentos solicitados junto con los importes efectivamente liquidados.
     *
     * Ordena primero los descuentos de línea y después los generales, respetando
     * el registro dentro de cada grupo. amount es monetario, cents su equivalente
     * entero y allocations contiene los centavos descontados por rowId.
     * Los montos se recalculan al consultar, incluso si las cantidades cambiaron.
     *
     * @return Collection<int, array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, amount: int|float, cents: int, allocations: array<string, int>}> Operaciones efectivas.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function discounts()
    {
        return new Collection($this->summary()['discounts']);
    }

    /**
     * Devuelve la suma numérica de los descuentos efectivos calculados por summary().
     *
     * Suma cents, no strings ni los valores solicitados. Los montos fijos superiores
     * al saldo solo contribuyen por el importe aplicado. No debe restarse otra vez
     * de total(), que ya utiliza las bases descontadas.
     *
     * @return int|float Importe monetario total descontado antes del IVA.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function totalDiscount()
    {
        return $this->summary()['totalDiscount'];
    }

    /**
     * Reúne observaciones manuales y automáticas para presentar el documento.
     *
     * Primero devuelve las manuales; después una por operación tip, luego una por
     * descuento efectivo y, si hay descuentos, un consolidado discount_total.
     * Las automáticas se derivan en cada consulta, sin modificar sesión. Sus textos
     * usan dos decimales y punto, independientemente de cart.format.
     * No son la fuente contable: importes y allocations provienen de costos y
     * descuentos estructurados. La consulta ejecuta la liquidación mediante discounts().
     *
     * @return Collection<int, array{
     *     type: 'manual'|'tip'|'discount'|'discount_total',
     *     text: string,
     *     description?: string,
     *     amount?: int|float,
     *     discountType?: 'percentage'|'fixed',
     *     rowId?: string|null,
     *     value?: float,
     *     concept?: string,
     *     cents?: int,
     *     allocations?: array<string, int>
     * }> Registros heterogéneos identificados por type.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function observations()
    {
        $observations = $this->metadata()['observations'];
        foreach ($this->costs() as $cost) {
            if ($cost['name'] === self::COST_TIP) $observations[] = [
                'type' => 'tip', 'description' => $cost['description'], 'amount' => $cost['amount'],
                'text' => 'Se agregó propina por ' . number_format($cost['amount'], 2, '.', ''),
            ];
        }
        $discounts = $this->discounts();
        foreach ($discounts as $discount) $observations[] = array_merge($discount, [
            'discountType' => $discount['type'], 'type' => 'discount',
            'text' => $discount['concept'] . ': ' . number_format($discount['amount'], 2, '.', ''),
        ]);
        if ($discounts->count()) $observations[] = [
            'type' => 'discount_total', 'amount' => $discounts->sum('cents') / 100,
            'text' => 'Se aplicaron descuentos por un total de ' . number_format($discounts->sum('cents') / 100, 2, '.', '') . '. Conceptos: ' . $discounts->pluck('concept')->implode('; ') . '.',
        ];
        return new Collection($observations);
    }

    /**
     * Calcula la liquidación final de la instancia sin modificar los precios originales.
     *
     * Es la consulta para construir una factura con ajustes: content() conserva
     * CartItem originales, mientras lines expone bases finales por rowId y costLines
     * los conceptos ITEM independientes. Propinas y observaciones no son productos.
     * Recalcula en cada llamada; no persiste importes efectivos ni mantiene caché.
     * getContent() puede normalizar campos de productos de snapshots antiguos.
     *
     * Secuencia de cálculo:
     * 1. Cuantiza cantidad por precio por línea; HKA/GENERAL redondean la mitad
     *    hacia arriba y PNP trunca hacia cero.
     * 2. Distribuye cada PRORATED según las mismas bases originales de productos,
     *    sin alícuota propia; cada parte hereda la del producto receptor.
     * 3. Aplica descuentos de línea y después generales, en orden de registro
     *    dentro de cada grupo, siempre sobre el saldo restante antes del IVA.
     *    Los porcentajes redondean a centavos; un fijo se limita al saldo.
     *    Los generales afectan únicamente productos, no ITEM, propina ni legacy.
     * 4. Agrega las bases ITEM y agrupa por alícuota. Usa cart.taxes y
     *    CartItem::calculateTaxes(): HKA/GENERAL redondean y PNP trunca el IVA.
     * 5. Suma base final + IVA + propina + legacy. Costos prorrateados y descuentos
     *    ya distribuidos no se contabilizan por segunda vez.
     *
     * Money::allocate() conserva centavos exactos con mayor resto y desempate
     * lexicográfico por rowId. Un prorrateo positivo exige base distribuible;
     * los descuentos con saldo cero tienen importe efectivo cero.
     *
     * Claves del resultado:
     * - lines: por rowId; original es base inicial, prorated costo distribuido,
     *   discount suma descontada y base saldo fiscal final del producto.
     * - costLines: registros ITEM, separados de los productos de content().
     * - bases: bases finales de productos e ITEM por clave de alícuota.
     * - taxes: IVA por nombre configurado; IVA es tasa y value importe.
     * - subtotal: suma de bases finales de productos e ITEM.
     * - tax: suma monetaria de todos los importes de IVA.
     * - tip / legacyCost: recargos sin IVA, excluidos de subtotal.
     * - totalCost: suma de costos registrados, incluida propina, antes de descuentos.
     * - discounts: operaciones efectivas y reparto por línea en centavos.
     * - totalDiscount: suma monetaria efectivamente descontada.
     * - total: subtotal + tax + tip + legacyCost.
     *
     * Todos los importes monetarios retornados son números sin formato. Los campos
     * cents y allocations permanecen en centavos enteros, no en unidades monetarias.
     * No sumar totalCost ni restar totalDiscount nuevamente al total.
     *
     * @return array{
     *     lines: array<string, array{
     *         rowId: string,
     *         id: int|string|float|bool,
     *         aliquot: int|string,
     *         original: int|float,
     *         prorated: int|float,
     *         discount: int|float,
     *         base: int|float
     *     }>,
     *     costLines: list<array{name: string, amount: int|float, cents: int, mode: 'item', aliquot: int|string, description: string}>,
     *     bases: array<int|string, int|float>,
     *     taxes: array<string, array{IVA: int|float, value: int|float}>,
     *     subtotal: int|float,
     *     tax: int|float,
     *     tip: int|float,
     *     legacyCost: int|float,
     *     totalCost: int|float,
     *     discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, amount: int|float, cents: int, allocations: array<string, int>}>,
     *     totalDiscount: int|float,
     *     total: int|float
     * } Liquidación numérica completa.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function summary()
    {
        $metadata = $this->metadata();
        $lines = $weights = [];
        foreach ($this->getContent() as $rowId => $item) {
            $base = Money::cents($item->qty * $item->price, config('cart.driver') === 'PNP');
            if ($base < 0) throw new \DomainException('Product bases must be nonnegative.');
            $weights[$rowId] = $base;
            $lines[$rowId] = ['rowId' => $rowId, 'id' => $item->id, 'aliquot' => $item->aliquot, 'original' => $base, 'prorated' => 0, 'discount' => 0, 'base' => $base];
        }
        $tip = $legacy = 0;
        $costLines = [];
        foreach ($metadata['costs'] as $cost) {
            if ($cost['mode'] === self::COST_PRORATED) {
                foreach (Money::allocate($cost['cents'], $weights) as $rowId => $amount) {
                    $lines[$rowId]['prorated'] += $amount;
                    $lines[$rowId]['base'] += $amount;
                }
            } elseif ($cost['mode'] === self::COST_ITEM) {
                $costLines[] = $cost;
            } elseif ($cost['name'] === self::COST_TIP) $tip += $cost['cents'];
            else $legacy += $cost['cents'];
        }
        $applied = [];
        // Primero descuentos de línea y después generales; cada grupo conserva el orden de registro.
        foreach ([true, false] as $linePhase) foreach ($metadata['discounts'] as $discount) {
            if (($discount['rowId'] !== null) !== $linePhase) continue;
            $eligible = [];
            foreach ($lines as $key => $line) if (!$linePhase || $discount['rowId'] === $key) $eligible[$key] = $line['base'];
            $base = array_sum($eligible);
            $amount = min($base, $discount['type'] === 'fixed' ? Money::cents($discount['value']) : (int) round($base * $discount['value'] / 100, 0, PHP_ROUND_HALF_UP));
            $allocation = Money::allocate($amount, $eligible);
            foreach ($allocation as $key => $part) {
                $lines[$key]['discount'] += $part;
                $lines[$key]['base'] -= $part;
            }
            $applied[] = array_merge($discount, ['amount' => $amount / 100, 'cents' => $amount, 'allocations' => $allocation]);
        }
        $bases = $taxes = [];
        foreach (config('cart.taxes') as $aliquot => $tax) {
            $bases[$aliquot] = 0;
            $taxes[$tax['name']] = ['IVA' => $tax['value'], 'value' => 0];
        }
        foreach ($lines as $line) $bases[$line['aliquot']] += $line['base'];
        foreach ($costLines as $cost) $bases[$cost['aliquot']] += $cost['cents'];
        $taxTotal = 0;
        foreach ($bases as $aliquot => $base) {
            $tax = config('cart.taxes.' . $aliquot);
            $value = Money::cents(CartItem::calculateTaxes($base / 100, $tax['value']));
            $taxes[$tax['name']]['value'] = $value / 100;
            $taxTotal += $value;
            $bases[$aliquot] = $base / 100;
        }
        $subtotal = array_sum(array_column($lines, 'base')) + array_sum(array_column($costLines, 'cents'));
        foreach ($lines as &$line) foreach (['original', 'prorated', 'discount', 'base'] as $field) $line[$field] /= 100;
        unset($line);
        return ['lines' => $lines, 'costLines' => $costLines, 'bases' => $bases, 'taxes' => $taxes,
            'subtotal' => $subtotal / 100, 'tax' => $taxTotal / 100, 'tip' => $tip / 100, 'legacyCost' => $legacy / 100,
            'totalCost' => $this->totalCost(), 'discounts' => $applied, 'totalDiscount' => array_sum(array_column($applied, 'cents')) / 100,
            'total' => ($subtotal + $taxTotal + $tip + $legacy) / 100];
    }
}
