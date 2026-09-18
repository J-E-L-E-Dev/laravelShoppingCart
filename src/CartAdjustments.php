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
     * Si la clave falta devuelve precisión y tres listas vacías. Los costos conservan importe
     * cuantizado y cents; los descuentos guardan la instrucción solicitada; solamente
     * las observaciones manuales se almacenan en observations. Valida estructura y
     * reglas de dominio antes de convertir importes, sin liquidar cada operación.
     *
     * @return array{decimals: int, costs: list<array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}>, discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, fixedUnits?: int}>, observations: list<array{type: 'manual', text: string}>} Estado documental de la instancia.
     */
    protected function metadata()
    {
        return $this->normalizeMetadata($this->session->get($this->metadataKey(), [
            'decimals' => Money::decimals(), 'costs' => [], 'discounts' => [], 'observations' => [],
        ]));
    }

    /**
     * Interpreta cents con su escala almacenada; registros históricos sin escala son de 2 decimales.
     * No modifica la sesión durante consultas. Al guardar una operación se materializa la
     * precisión actual. Reducir precisión aplica HALF_UP; aumentar no inventa fracciones.
     * Valida todas las entradas antes de convertir; amount se reconstruye desde cents.
     */
    private function normalizeMetadata($metadata)
    {
        if (!is_array($metadata)) throw new \InvalidArgumentException('Invalid cart metadata: metadata must be an array.');
        foreach (['costs', 'discounts', 'observations'] as $key) {
            if (!isset($metadata[$key]) || !is_array($metadata[$key])) throw new \InvalidArgumentException('Invalid cart metadata: '.$key.' must be an array.');
        }
        $from = Money::decimals($metadata['decimals'] ?? 2);
        $to = Money::decimals();
        foreach ($metadata['costs'] as $cost) $this->validateCostMetadataEntry($cost, $from);
        foreach ($metadata['discounts'] as $discount) $this->validateDiscountMetadataEntry($discount, $from, $to);
        foreach ($metadata['observations'] as $observation) $this->validateObservationMetadataEntry($observation);
        foreach ($metadata['costs'] as &$cost) {
            $cost['cents'] = Money::rescale($cost['cents'], $from, $to);
            $cost['amount'] = Money::fromMinorUnits($cost['cents']);
        }
        unset($cost);
        foreach ($metadata['discounts'] as &$discount) {
            if ($discount['type'] === 'fixed') {
                $units = $discount['fixedUnits'] ?? Money::minorUnits($discount['value'], false, $from);
                $discount['fixedUnits'] = Money::rescale($units, $from, $to);
            }
        }
        unset($discount);
        $metadata['decimals'] = $to;
        return $metadata;
    }

    /** Valida costos persistidos según los modos que puede producir addCost(). amount es derivado. */
    private function validateCostMetadataEntry($cost, $decimals)
    {
        if (!is_array($cost) || !isset($cost['name'], $cost['cents'], $cost['mode'], $cost['description'])
            || !is_string($cost['name']) || $cost['name'] === '' || !is_int($cost['cents']) || $cost['cents'] < 0
            || !is_string($cost['description']) || $cost['description'] === ''
            || !array_key_exists('aliquot', $cost)
            || !in_array($cost['mode'], [self::COST_ITEM, self::COST_PRORATED, 'tip', 'legacy'], true)) {
            throw new \InvalidArgumentException('Invalid cart metadata: invalid cost entry.');
        }
        if (($cost['mode'] === 'tip') !== ($cost['name'] === self::COST_TIP)) {
            throw new \InvalidArgumentException('Invalid cart metadata: tip name and mode must match.');
        }
        if ($cost['mode'] === self::COST_ITEM) FiscalCalculator::taxRate($cost['aliquot']);
        elseif ($cost['aliquot'] !== null) throw new \InvalidArgumentException('Invalid cart metadata: only ITEM costs may have an aliquot.');
        Money::minorUnits(Money::fromMinorUnits($cost['cents'], $decimals), false, $decimals);
    }

    /** Conserva value solicitado y fixedUnits convertido; pueden diferir tras cambios de precisión. */
    private function validateDiscountMetadataEntry($discount, $from, $to)
    {
        if (!is_array($discount) || !isset($discount['type'], $discount['value'], $discount['concept'])
            || !in_array($discount['type'], ['percentage', 'fixed'], true)
            || !is_numeric($discount['value']) || !is_finite((float) $discount['value']) || $discount['value'] < 0
            || ($discount['type'] === 'percentage' && $discount['value'] > 100)
            || !is_string($discount['concept']) || !array_key_exists('rowId', $discount)
            || ($discount['rowId'] !== null && !is_string($discount['rowId']) && !is_int($discount['rowId']))) {
            throw new \InvalidArgumentException('Invalid cart metadata: invalid discount entry.');
        }
        if ($discount['type'] === 'fixed') {
            Money::minorUnits($discount['value'], false, $from);
            if (array_key_exists('fixedUnits', $discount)) {
                if (!is_int($discount['fixedUnits']) || $discount['fixedUnits'] < 0) {
                    throw new \InvalidArgumentException('Invalid cart metadata: fixedUnits must be a nonnegative integer.');
                }
                Money::rescale($discount['fixedUnits'], $from, $to);
            }
        }
    }

    /** Sólo las observaciones manuales se persisten; las automáticas se derivan al consultar. */
    private function validateObservationMetadataEntry($observation)
    {
        if (!is_array($observation) || ($observation['type'] ?? null) !== 'manual'
            || !isset($observation['text']) || !is_string($observation['text']) || trim($observation['text']) === '') {
            throw new \InvalidArgumentException('Invalid cart metadata: observations must contain manual, nonempty text.');
        }
    }

    /** Valida sobres v2/v3 y convierte los metadatos antes de restore/merge. */
    private function snapshotMetadata(array $snapshot)
    {
        if (!isset($snapshot['version']) || !in_array($snapshot['version'], [2, 3], true)) throw new \InvalidArgumentException('Unsupported cart snapshot version.');
        if (!array_key_exists('content', $snapshot) || (!is_array($snapshot['content']) && !$snapshot['content'] instanceof Collection)) {
            throw new \InvalidArgumentException('Invalid cart snapshot: content must be an array or Collection.');
        }
        if (!isset($snapshot['metadata']) || !is_array($snapshot['metadata'])) throw new \InvalidArgumentException('Invalid cart snapshot: metadata must be an array.');
        if ($snapshot['version'] === 3 && !isset($snapshot['decimals'])) throw new \InvalidArgumentException('Snapshot precision is required.');
        $metadata = $snapshot['metadata'];
        $metadata['decimals'] = $snapshot['version'] === 2 ? 2 : Money::decimals($snapshot['decimals']);
        return $this->normalizeMetadata($metadata);
    }

    /**
     * Reemplaza en sesión los metadatos completos de la instancia, sin validarlos.
     *
     * No modifica la colección de productos ni genera eventos.
     *
     * @param array{decimals: int, costs: list<array{name: string, amount: int|float, cents: int, mode: 'item'|'prorated'|'tip'|'legacy', aliquot: int|string|null, description: string}>, discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, fixedUnits?: int}>, observations: list<array{type: 'manual', text: string}>} $metadata Listas de operaciones y observaciones manuales.
     * @return void
     */
    protected function saveMetadata(array $metadata)
    {
        $metadata['decimals'] = Money::decimals();
        $this->session->put($this->metadataKey(), $metadata);
    }

    /**
     * Devuelve los registros de costos, conservando operaciones repetidas.
     *
     * Cada array identifica nombre, importe cuantizado, unidades menores, modo, alícuota
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
     * Suma las unidades menores registrados de todos los costos y devuelve un importe numérico.
     *
     * Incluye ITEM, PRORATED, propinas y legacy. Es una consulta informativa del
     * importe de entrada: no refleja cuánto de un prorrateo queda tras descuentos.
     * No debe sumarse de nuevo a total(), que ya incorpora estos conceptos.
     *
     * @return int|float Suma de costos antes de descuentos, sin formato ni IVA adicional.
     */
    public function totalCost()
    {
        return Money::fromMinorUnits(Money::sum(array_column($this->metadata()['costs'], 'cents')));
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
     * para todo el documento. Se limita al saldo y reparte en unidades menores por base
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
        if (!is_null($concept) && !is_scalar($concept) && !$concept instanceof \Stringable) {
            throw new \InvalidArgumentException('Discount concept must be convertible to a string.');
        }
        if (!in_array($type, ['percentage', 'fixed'], true) || !is_numeric($value) || !is_finite((float) $value) || $value < 0 || ($type === 'percentage' && $value > 100)) {
            throw new \InvalidArgumentException('Discount must be nonnegative: percentage (0–100) or fixed.');
        }
        $units = Money::minorUnits($value);
        $metadata = $this->metadata();
        $discount = ['rowId' => $rowId, 'type' => $type, 'value' => (float) $value, 'concept' => (string) $concept];
        if ($type === 'fixed') $discount['fixedUnits'] = $units;
        $metadata['discounts'][] = $discount;
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
     * entero y allocations contiene las unidades menores descontados por rowId.
     * Los montos se recalculan al consultar, incluso si las cantidades cambiaron.
     *
     * @return Collection<int, array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, fixedUnits?: int, amount: int|float, cents: int, allocations: array<string, int>}> Operaciones efectivas.
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
     * usan la precisión configurada y punto, independientemente del separador decimal.
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
                'text' => 'Se agregó propina por ' . number_format($cost['amount'], Money::decimals(), '.', ''),
            ];
        }
        $discounts = $this->discounts();
        foreach ($discounts as $discount) $observations[] = array_merge($discount, [
            'discountType' => $discount['type'], 'type' => 'discount',
            'text' => $discount['concept'] . ': ' . number_format($discount['amount'], Money::decimals(), '.', ''),
        ]);
        if ($discounts->count()) $observations[] = [
            'type' => 'discount_total', 'amount' => Money::fromMinorUnits($discounts->sum('cents')),
            'text' => 'Se aplicaron descuentos por un total de ' . number_format(Money::fromMinorUnits($discounts->sum('cents')), Money::decimals(), '.', '') . '. Conceptos: ' . $discounts->pluck('concept')->implode('; ') . '.',
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
     *    Los porcentajes redondean a unidades menores; un fijo se limita al saldo.
     *    Los generales afectan únicamente productos, no ITEM, propina ni legacy.
     * 4. Trata cada ITEM como línea fiscal adicional. GENERAL redondea el IVA
     *    de cada línea final y suma los importes por alícuota. HKA acumula bases
     *    finales por alícuota y redondea su IVA; PNP trunca IVA por fila sobre
     *    qty × price sin cuantizar + prorrateos - descuentos (mínimo cero).
     *    FiscalCalculator usa cart.taxes; ITEM ya llega en unidades menores.
     *    bases siempre muestra las sumas por alícuota para consulta, incluso
     *    cuando GENERAL calcula los impuestos por línea.
     * 5. Suma base final + IVA + propina + legacy. Costos prorrateados y descuentos
     *    ya distribuidos no se contabilizan por segunda vez.
     *
     * Money::allocate() conserva unidades menores exactos con mayor resto y desempate
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
     * - discounts: operaciones efectivas y reparto por línea en unidades menores.
     * - totalDiscount: suma monetaria efectivamente descontada.
     * - total: subtotal + tax + tip + legacyCost.
     *
     * Todos los importes monetarios retornados son números sin formato. Los campos
     * cents y allocations permanecen en unidades menores enteros, no en unidades monetarias.
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
     *     discounts: list<array{rowId: string|null, type: 'percentage'|'fixed', value: float, concept: string, fixedUnits?: int, amount: int|float, cents: int, allocations: array<string, int>}>,
     *     totalDiscount: int|float,
     *     total: int|float
     * } Liquidación numérica completa.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function summary()
    {
        $taxCatalog = FiscalCalculator::taxCatalog();
        $metadata = $this->metadata();
        foreach ($metadata['costs'] as $cost) {
            if ($cost['mode'] === self::COST_ITEM) FiscalCalculator::validateAliquot($cost['aliquot'] ?? null, $taxCatalog);
        }
        $lines = $weights = $rawBases = [];
        foreach ($this->getContent() as $rowId => $item) {
            $rawBases[$rowId] = $item->qty * $item->price;
            $base = Money::minorUnits($rawBases[$rowId], config('cart.driver') === 'PNP');
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
            $amount = min($base, $discount['type'] === 'fixed' ? $discount['fixedUnits'] : (int) round($base * $discount['value'] / 100, 0, PHP_ROUND_HALF_UP));
            $allocation = Money::allocate($amount, $eligible);
            foreach ($allocation as $key => $part) {
                $lines[$key]['discount'] += $part;
                $lines[$key]['base'] -= $part;
            }
            $applied[] = array_merge($discount, ['amount' => Money::fromMinorUnits($amount), 'cents' => $amount, 'allocations' => $allocation]);
        }
        $bases = [];
        foreach ($taxCatalog as $aliquot => $tax) {
            $bases[$aliquot] = 0;
        }
        foreach ($lines as $line) $bases[$line['aliquot']] += $line['base'];
        foreach ($costLines as $cost) $bases[$cost['aliquot']] += $cost['cents'];
        $fiscalLines = [];
        foreach ($lines as $rowId => $line) $fiscalLines[$rowId] = [
            'aliquot' => $line['aliquot'], 'base' => $line['base'],
            'rawBase' => max(0, $rawBases[$rowId] + Money::fromMinorUnits($line['prorated'] - $line['discount'])),
        ];
        foreach ($costLines as $key => $cost) $fiscalLines['cost:' . $key] = [
            'aliquot' => $cost['aliquot'], 'base' => $cost['cents'], 'rawBase' => Money::fromMinorUnits($cost['cents']),
        ];
        $taxes = FiscalCalculator::calculate($fiscalLines, FiscalCalculator::driver())['taxes'];
        $taxTotal = 0;
        foreach ($taxes as $tax) $taxTotal += Money::minorUnits($tax['value']);
        foreach ($bases as $aliquot => $base) {
            $bases[$aliquot] = Money::fromMinorUnits($base);
        }
        $subtotal = array_sum(array_column($lines, 'base')) + array_sum(array_column($costLines, 'cents'));
        foreach ($lines as &$line) foreach (['original', 'prorated', 'discount', 'base'] as $field) $line[$field] = Money::fromMinorUnits($line[$field]);
        unset($line);
        return ['lines' => $lines, 'costLines' => $costLines, 'bases' => $bases, 'taxes' => $taxes,
            'subtotal' => Money::fromMinorUnits($subtotal), 'tax' => Money::fromMinorUnits($taxTotal), 'tip' => Money::fromMinorUnits($tip), 'legacyCost' => Money::fromMinorUnits($legacy),
            'totalCost' => $this->totalCost(), 'discounts' => $applied, 'totalDiscount' => Money::fromMinorUnits(Money::sum(array_column($applied, 'cents'))),
            'total' => Money::fromMinorUnits(Money::sum([$subtotal, $taxTotal, $tip, $legacy]))];
    }
}
