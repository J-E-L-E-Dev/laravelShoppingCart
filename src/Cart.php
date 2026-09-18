<?php
namespace JeleDev\Shoppingcart;

use Carbon\Carbon;
use JeleDev\Shoppingcart\Contracts\InstanceIdentifier;
use JeleDev\Shoppingcart\Exceptions\AmbiguousItemException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Events\Dispatcher;
use JeleDev\Shoppingcart\Contracts\Buyable;
use JeleDev\Shoppingcart\Exceptions\UnknownModelException;
use JeleDev\Shoppingcart\Exceptions\InvalidRowIDException;
use JeleDev\Shoppingcart\Exceptions\CartAlreadyStoredException;

/**
 * Gestiona productos y operaciones documentales de una instancia del carrito.
 *
 * Conserva Collection<CartItem> en cart.<instancia> y utiliza CartAdjustments
 * para costos, descuentos, observaciones y liquidación en una clave de sesión
 * separada. Los métodos de consulta monetaria respetan cart.driver y cart.taxes.
 * El código del producto pertenece a la aplicación; rowId identifica una línea.
 * Para facturar ajustes se debe consultar summary(), no sumar los CartItem.
 *
 * @property-read string $subtotal Base final formateada, incluidos costos ITEM.
 * @property-read array<string, array{IVA: int|float, value: int|float}> $tax IVA agrupado de la liquidación.
 * @property-read string $total Importe final formateado.
 */
class Cart
{
    use CartAdjustments;

    /**
     * Fecha de creación del último snapshot restaurado; store() la reutiliza si existe.
     *
     * No se reinicia al cambiar o destruir una instancia.
     *
     * @var Carbon|null
     */
    public $createdAt;
    /**
     * Fecha de modificación del último snapshot restaurado, conservada en este objeto.
     *
     * @var Carbon|null
     */
    public $updatedAt;
    /**
     * Nombre convencional del costo de instalación, con modo y alícuota independientes del nombre.
     *
     * @var string
     */
    const COST_INSTALLATION = 'installation';
    /**
     * Nombre convencional del costo de embalaje.
     *
     * @var string
     */
    const COST_PACKAGING = 'packaging';
    /**
     * Nombre convencional del costo de seguro.
     *
     * @var string
     */
    const COST_INSURANCE = 'insurance';
    /**
     * Identifica propinas: sin modo ni alícuota de entrada, sin IVA y con observación automática.
     *
     * @var string
     */
    const COST_TIP = 'tip';
    /**
     * Modo de concepto facturable independiente; incorpora base con alícuota sin crear CartItem.
     *
     * @var string
     */
    const COST_ITEM = 'item';
    /**
     * Modo que reparte el costo entre bases originales y hereda las alícuotas de los productos.
     *
     * @var string
     */
    const COST_PRORATED = 'prorated';
    /**
     * Nombre utilizado cuando instance() recibe un valor evaluado como falso.
     *
     * @var string
     */
    const DEFAULT_INSTANCE = 'shopping_cart';

    /**
     * Nombre convencional del costo de flete; el modo determina cómo se liquida.
     *
     * @var string
     */
    const COST_FREIGHT = 'freight';
    /**
     * Nombre de costo de transacción conservado para llamadas legadas sin modo.
     *
     * @var string
     */
    const COST_TRANSACTION = 'transaction';

    /**
     * Administrador de sesión que conserva productos y metadatos por instancia.
     *
     * @var SessionManager
     */
    private $session;

    /**
     * Dispatcher que publica los eventos de incorporación, actualización y persistencia.
     *
     * @var Dispatcher
     */
    private $events;

    /**
     * Clave de sesión de productos, con el prefijo cart. y el nombre seleccionado.
     *
     * @var string
     */
    private $instance;


    /**
     * Recibe los servicios de Laravel y selecciona la instancia predeterminada.
     *
     * @param SessionManager $session Almacenamiento de sesión compartido entre requests.
     * @param Dispatcher $events Publicador de eventos del carrito.
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;


        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Selecciona sobre este mismo objeto la instancia de sesión de las operaciones siguientes.
     *
     * Un valor evaluado como falso usa DEFAULT_INSTANCE; no copia ni borra datos.
     *
     * @param string|null $instance Nombre de la instancia sin el prefijo cart.
     * @return $this
     */
    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        $this->instance = sprintf('%s.%s', 'cart', $instance);

        return $this;
    }

    /**
     * Devuelve el nombre quitando todas las apariciones de cart. de la clave interna.
     *
     * @return string Nombre utilizado por store(), restore() y las consultas de snapshots.
     */
    public function currentInstance()
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Construye una línea o acumula su cantidad sobre una identidad compatible.
     *
     * La identidad combina código, opciones ordenadas en su primer nivel y alícuota.
     * Si ya existe, conserva su rowId (incluidos hashes antiguos), suma cantidades
     * y reemplaza el objeto por el nuevo, con los demás atributos recibidos.
     * Nombre, precio y cantidad no forman parte de la identidad.
     * Despacha cart.added y guarda la colección en la sesión actual.
     *
     * Admite atributos individuales, un array con id/name/qty/price y
     * aliquot/options opcionales, o add($buyable, $cantidad, $opciones).
     * En la variante Buyable, el segundo argumento es cantidad (1 si es falso),
     * el tercero son opciones y se usa cart.default_aliquot; los atributos se
     * obtienen del contrato y el modelo queda asociado.
     *
     * @param int|string|array{id: int|string, name: string, qty: int|float|numeric-string, price: int|float|numeric-string, aliquot?: int|string|null, options?: array<array-key, mixed>}|Buyable $id Código, atributos o producto del consumidor.
     * @param string|int|float|numeric-string|null $name Nombre, o cantidad en la variante Buyable.
     * @param int|float|numeric-string|array<array-key, mixed>|null $qty Cantidad positiva, u opciones para Buyable.
     * @param int|float|numeric-string|null $price Precio unitario sin IVA.
     * @param int|string|null $aliquot Clave de cart.taxes; null utiliza la predeterminada.
     * @param array<array-key, mixed> $options Opciones que participan en la identidad.
     * @return CartItem Objeto incorporado con la cantidad acumulada.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function add($id, $name = null, $qty = null, $price = null, $aliquot = null, array $options = [])
    {
        if (is_array($id)) {
            $aliquot = isset($id['aliquot']) ? $id['aliquot'] : config('cart.default_aliquot');
            $options = isset($id['options']) ? $id['options'] : [];
            return $this->add($id['id'], $id['name'], $id['qty'], $id['price'], $aliquot, $options);
        }
        
        $cartItem = $this->createCartItem($id, $name, $qty, $price, $aliquot, $options);

        $content = $this->getContent();
        foreach ($content as $existing) {
            if ($existing->identity() === $cartItem->identity()) { $cartItem->rowId = $existing->rowId; break; }
        }

        if ($content->has($cartItem->rowId)) {
            $cartItem->qty += $content->get($cartItem->rowId)->qty;
        }

        $content->put($cartItem->rowId, $cartItem);

        $this->fiscalContent($content);
        $this->events->dispatch('cart.added', $cartItem);

        $this->session->put($this->instance, $content);

        return $cartItem;
    }

    /**
     * Registra una operación de costo en los metadatos de la instancia.
     *
     * ITEM exige alícuota explícita y aporta base facturable e IVA según catálogo.
     * PRORATED rechaza alícuota propia: summary() lo distribuye entre productos
     * sin crear una línea independiente. El nombre tip exige modo y alícuota
     * omitidos, aumenta el total sin IVA y origina una observación automática.
     * Los demás nombres sin modo mantienen el recargo legacy: solo aumentan total(),
     * sin subtotal ni IVA. No se aceptan los modos tip/legacy de forma explícita.
     *
     * Cada llamada agrega un registro, incluso si el nombre ya existe; guarda
     * amount cuantizado y cents enteros. No liquida ni exige productos al registrar.
     * amount permite argumentos nombrados en PHP 8 sin renombrar el parámetro price.
     *
     * @param string $name Nombre convencional o personalizado; tip tiene reglas propias.
     * @param int|float|numeric-string|null $price Importe no negativo; alternativa a amount.
     * @param 'item'|'prorated'|null $mode Tratamiento del costo; null conserva la compatibilidad.
     * @param int|string|null $aliquot Clave del catálogo, obligatoria únicamente para ITEM.
     * @param string|null $description Descripción; si es falsa se utiliza name.
     * @param int|float|numeric-string|null $amount Alias de price; no se pueden suministrar ambos.
     * @return void
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function addCost($name, $price = null, $mode = null, $aliquot = null, $description = null, $amount = null)
    {
        if ($amount !== null && $price !== null) throw new \InvalidArgumentException('Supply amount or price, not both.');
        $cents = Money::minorUnits($amount !== null ? $amount : $price);
        if (($amount !== null ? $amount : $price) < 0 || !is_string($name) || $name === '') throw new \InvalidArgumentException('A cost requires a name and nonnegative amount.');
        if ($name === self::COST_TIP) {
            if ($mode !== null || $aliquot !== null) throw new \InvalidArgumentException('Tips cannot have mode or aliquot.');
            $mode = 'tip';
        } elseif ($mode === null) {
            if ($aliquot !== null) throw new \InvalidArgumentException('Specify item mode for an aliquot.');
            $mode = 'legacy';
        } elseif (!in_array($mode, [self::COST_ITEM, self::COST_PRORATED], true)) {
            throw new \InvalidArgumentException('Unknown cost mode.');
        }
        if ($mode === self::COST_ITEM) {
            FiscalCalculator::taxRate($aliquot);
        } elseif ($aliquot !== null) throw new \InvalidArgumentException('Only ITEM costs have an aliquot.');
        $metadata = $this->metadata();
        $metadata['costs'][] = ['name' => $name, 'amount' => Money::fromMinorUnits($cents), 'cents' => $cents, 'mode' => $mode, 'aliquot' => $aliquot, 'description' => $description ?: $name];
        $this->saveMetadata($metadata);
    }

    /**
     * Suma los costos con ese nombre y devuelve el importe formateado por compatibilidad.
     *
     * Devuelve cero formateado si no hay coincidencias. Usa costDetails() o costs()
     * para recuperar modo, alícuota y otros datos; este string es solo presentación.
     *
     * @param string $name Nombre de las operaciones a sumar.
     * @return string Importe según cart.format.
     */
    public function getCost($name)
    {
        return $this->numberFormat(Money::fromMinorUnits($this->costDetails($name)->sum('cents')));
    }

    /**
     * Actualiza una copia de la línea resuelta y persiste el resultado.
     *
     * Acepta cantidad, atributos parciales o Buyable. Una cantidad menor o igual
     * a cero elimina la línea original y sus descuentos mediante remove().
     * Si cambia la identidad, mueve la clave y sus descuentos; cuando coincide
     * con otra línea suma las cantidades y aplica ambos conjuntos de descuentos
     * a la línea resultante. Conserva el rowId antiguo si la identidad no cambia.
     * Una actualización normal guarda sesión y despacha cart.updated.
     *
     * @param int|string $rowId RowId o código inequívoco del producto.
     * @param int|float|numeric-string|array<string, mixed>|Buyable $qty Cantidad o fuente de atributos.
     * @return CartItem|null Línea resultante, o null cuando se elimina.
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function update($rowId, $qty)
    {
        $original = $this->rawItem($rowId);
        $rowId = $original->rowId;
        $cartItem = clone $original;
        if ($qty instanceof Buyable) $cartItem->updateFromBuyable($qty);
        elseif (is_array($qty)) $cartItem->updateFromArray($qty);
        else {
            if (!is_numeric($qty) || !is_finite((float) $qty)) throw new \InvalidArgumentException('Invalid quantity.');
            $cartItem->qty = $qty;
        }
        if ($cartItem->qty <= 0) { $this->remove($rowId); return; }
        $content = $this->getContent();
        foreach ($content as $existing) {
            if ($existing->rowId !== $rowId && $existing->identity() === $cartItem->identity()) {
                $cartItem->rowId = $existing->rowId;
                break;
            }
        }
        $content->pull($rowId);
        if ($rowId !== $cartItem->rowId) {
            if ($content->has($cartItem->rowId)) $cartItem->setQuantity($content->get($cartItem->rowId)->qty + $cartItem->qty);
            $this->moveDiscounts($rowId, $cartItem->rowId);
        }
        $content->put($cartItem->rowId, $cartItem);
        $this->fiscalContent($content);
        $this->session->put($this->instance, $content);
        $this->events->dispatch('cart.updated', $cartItem);
        return $cartItem;
    }

    /**
     * Elimina de la sesión una línea resuelta y los descuentos asociados a su rowId.
     *
     * Conserva costos, observaciones manuales y descuentos generales; los resultados
     * automáticos se recalculan. Despacha cart.removed con el objeto eliminado.
     *
     * @param int|string $rowId RowId o código inequívoco.
     * @return void
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     */
    public function remove($rowId)
    {
        $cartItem = $this->rawItem($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);
        $this->moveDiscounts($cartItem->rowId);

        $this->events->dispatch('cart.removed', $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Resuelve primero la clave exacta rowId y después el código original del producto.
     *
     * La coincidencia por código compara strings: '000123' no equivale a '123'.
     * Varias líneas pueden compartir código por opciones o alícuotas diferentes;
     * no se selecciona ninguna arbitrariamente. El objeto devuelto pertenece a
     * la colección de sesión; la consulta no representa una liquidación ajustada.
     *
     * @param int|string $rowId Identificador interno o código de negocio.
     * @return CartItem Línea original encontrada.
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     */
    public function get($rowId)
    {
        $content = $this->getContent();
        if ($content->has($rowId)) return $this->fiscalContent()->get($rowId);
        return $this->getById($rowId);
    }

    /**
     * Obtiene exclusivamente una línea por la clave interna, sin buscar por código.
     *
     * @param string $rowId Hash conservado por el carrito; no debe generarse manualmente.
     * @return CartItem Línea de la colección original.
     * @throws InvalidRowIDException Si la clave no existe.
     */
    public function getByRowId($rowId)
    {
        if (!$this->getContent()->has($rowId)) throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        return $this->fiscalContent()->get($rowId);
    }

    /**
     * Busca una única línea por código, aunque el argumento coincida con otro rowId.
     *
     * Compara las representaciones string; entero 123 y string '123' coinciden,
     * pero los ceros iniciales de un código string se conservan.
     *
     * @param int|string $id Código suministrado por la aplicación consumidora.
     * @return CartItem Única coincidencia; no devuelve una colección.
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     */
    public function getById($id)
    {
        $matches = $this->getContent()->filter(function ($item) use ($id) { return (string) $item->id === (string) $id; });
        if ($matches->count() > 1) throw new AmbiguousItemException("Product code {$id} matches multiple lines; supply rowId.");
        if ($matches->isEmpty()) throw new InvalidRowIDException("The cart does not contain product code {$id}.");
        return $this->fiscalContent()->get($matches->first()->rowId);
    }

    /**
     * Borra productos y todos los metadatos de la instancia actual en sesión.
     *
     * Elimina costos, descuentos y observaciones manuales; las automáticas dejan
     * de generarse. No elimina snapshots de base de datos ni reinicia fechas del objeto.
     *
     * @return void
     */
    public function destroy()
    {
        $this->session->remove($this->instance);
        $this->session->forget($this->metadataKey());
    }

    /**
     * Devuelve los productos originales de la instancia, sin incorporar ajustes.
     *
     * Conserva precios y cantidades de CartItem; costos ITEM, prorrateos, descuentos,
     * propinas y observaciones permanecen separados. Para bases finales de factura
     * consultar summary(). getContent() normaliza algunos campos de snapshots antiguos.
     * La colección contiene los objetos de sesión, no copias defensivas.
     *
     * @return Collection<string, CartItem> Colección vacía si la instancia no tiene productos.
     */
    public function content()
    {
        return $this->fiscalContent();
    }

    /**
     * Suma cantidades de productos, no el número de líneas ni los costos ITEM.
     *
     * @return int|float Cantidad total, que puede ser fraccionaria.
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('qty');
    }

    /**
     * Presenta el importe final que debe pagar el cliente, calculado por summary().
     *
     * Incluye bases con prorrateos y descuentos, bases ITEM e IVA según el driver, más
     * propina y recargos legacy. No se deben sumar totalCost() ni restar
     * totalDiscount() otra vez. cart.format.decimals controla precisión y presentación.
     *
     * @return string Total final formateado.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function total()
    {
        return $this->numberFormat($this->summary()['total']);
    }

    /**
     * Adaptador protegido legado que consulta el total de la instancia actual.
     *
     * Ignora content y delega a total(); el flujo público actual no lo invoca.
     *
     * @param Collection<string, CartItem>|array<array-key, CartItem> $content Argumento conservado, no utilizado.
     * @return string Total formateado de la instancia.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    protected function generalTotal($content)
    {
        return $this->total();
    }

    /**
     * Adaptador protegido legado de HKA que delega en total(), sin usar content.
     *
     * No participa en el flujo actual de la liquidación pública.
     *
     * @param Collection<string, CartItem>|array<array-key, CartItem> $content Argumento conservado, no utilizado.
     * @return string Total formateado de la instancia.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    protected function hkaTotal($content)
    {
        return $this->total();
    }

    /**
     * Devuelve el IVA de las bases finales de productos y costos ITEM.
     *
     * Incluye prorrateos y descuentos antes del impuesto; excluye propina y legacy.
     * GENERAL y PNP suman IVA por línea (HALF_UP y truncamiento respectivamente).
     * HKA calcula IVA sobre bases acumuladas. Usa nombres y tasas de cart.taxes.
     * value es el importe numérico del IVA; IVA es la tasa porcentual del catálogo.
     *
     * @return array<string, array{IVA: int|float, value: int|float}> Impuestos indexados por nombre configurado.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function tax()
    {
        return $this->summary()['taxes'];
    }

    /**
     * Calcula impuestos únicamente para los CartItem recibidos, sin metadatos.
     *
     * Selecciona el adaptador por cart.driver; el caso desconocido usa hkaDriver().
     * GENERAL calcula IVA sobre cada fila completa y suma los importes por alícuota.
     * HKA acumula bases redondeadas; PNP trunca el IVA de cada base original sin cuantizar.
     * Conserva entradas ajenas de taxes y reemplaza las del catálogo.
     * Para IVA con ajustes usar tax().
     *
     * @param Collection<array-key, CartItem>|array<array-key, CartItem> $input Productos sin liquidación documental.
     * @param array<string, mixed> $taxes Resultado inicial a completar o reemplazar por nombre configurado.
     * @return array<string, mixed> Entradas del catálogo con IVA/value y otras entradas originales.
     * @throws \InvalidArgumentException Si Money rechaza una base o importe de IVA.
     */
    public function totalTaxes($input, array $taxes)
    {
        switch (config('cart.driver')) {
            case 'GENERAL':
                return $this->generalDriver($input, $taxes);
                break;
            
            case 'HKA':
                return $this->hkaDriver($input, $taxes);
                break;

            case 'PNP':
                return $this->pnpDriver($input, $taxes);
                break;
            
            default:
                return $this->hkaDriver($input, $taxes);
                break;
        }
    }

    /**
     * Calcula IVA por fila completa y suma los importes por alícuota para GENERAL.
     *
     * Redondea cantidad por precio antes de calcular el IVA de cada fila;
     * no multiplica IVA unitario ni calcula IVA sobre una base agrupada.
     *
     * @param Collection<array-key, CartItem>|array<array-key, CartItem> $input Productos originales.
     * @param array<string, mixed> $taxes Entradas iniciales del resultado.
     * @return array<string, mixed> Impuestos por nombre y entradas ajenas conservadas.
     * @throws \InvalidArgumentException Si Money rechaza un importe.
     */
    protected function generalDriver($input, array $taxes)
    {
        return $this->taxesForItems($input, $taxes, 'GENERAL');
    }

    /**
     * Acumula bases redondeadas por alícuota y calcula su IVA una sola vez para HKA.
     *
     * Usa el redondeo de Money y CartItem correspondiente a cart.driver.
     *
     * @param Collection<array-key, CartItem>|array<array-key, CartItem> $input Productos originales.
     * @param array<string, mixed> $taxes Entradas iniciales del resultado.
     * @return array<string, mixed> Impuestos por nombre y entradas ajenas conservadas.
     * @throws \InvalidArgumentException Si Money rechaza un importe.
     */
    protected function hkaDriver($input, array $taxes)
    {
        return $this->taxesForItems($input, $taxes, 'HKA');
    }

    /**
     * Trunca el IVA por fila desde qty × price y suma por alícuota para PNP.
     *
     * La configuración PNP activa el truncamiento en Money y CartItem.
     *
     * @param Collection<array-key, CartItem>|array<array-key, CartItem> $input Productos originales.
     * @param array<string, mixed> $taxes Entradas iniciales del resultado.
     * @return array<string, mixed> Impuestos por nombre y entradas ajenas conservadas.
     * @throws \InvalidArgumentException Si Money rechaza un importe.
     */
    protected function pnpDriver($input, array $taxes)
    {
        return $this->taxesForItems($input, $taxes, 'PNP');
    }

    /** Calcula impuestos originales usando una estrategia explícita. */
    private function taxesForItems($input, array $taxes, $driver)
    {
        return FiscalCalculator::calculate(FiscalCalculator::originalLines($input, $driver), $driver, $taxes)['taxes'];
    }

    /** Vincula resolución derivada sin guardar resolutores ni impuestos en los objetos de sesión. */
    private function fiscalContent($content = null)
    {
        $content = $content ?? $this->getContent();
        $reference = \WeakReference::create($content);
        // Referencia débil: el resolutor no prolonga la vida de la colección ni consulta Cart/Session.
        $resolve = static function ($item) use ($reference) {
            $items = $reference->get();
            if ($items === null || $items->get($item->rowId) !== $item) return null;
            $driver = FiscalCalculator::driver();
            $result = FiscalCalculator::calculate(FiscalCalculator::originalLines($items, $driver), $driver);
            return $result['lineTaxes'][$item->rowId] ?? null;
        };
        foreach ($content as $item) $item->resolveTaxUsing($resolve);
        return $content;
    }

    /** Acceso interno sin decoración; mantiene prioridad rowId y resolución inequívoca por código. */
    private function rawItem($identifier)
    {
        $content = $this->getContent();
        if ($content->has($identifier)) return $content->get($identifier);
        $matches = $content->filter(function ($item) use ($identifier) { return (string) $item->id === (string) $identifier; });
        if ($matches->count() > 1) throw new AmbiguousItemException("Product code {$identifier} matches multiple lines; supply rowId.");
        if ($matches->isEmpty()) throw new InvalidRowIDException("The cart does not contain product code {$identifier}.");
        return $matches->first();
    }

    /**
     * Cuantiza cantidad por precio a la precisión configurada redondeando la mitad hacia arriba.
     *
     * Auxiliar protegido conservado; el cálculo compartido actual usa Money directamente.
     *
     * @param int|float|numeric-string $qty Cantidad de producto.
     * @param int|float|numeric-string $value Precio unitario sin IVA.
     * @return int|float Base numérica cuantizada.
     * @throws \InvalidArgumentException Si el producto no es finito o excede el límite monetario.
     */
    protected function hka($qty, $value)
    {
        return Money::fromMinorUnits(Money::minorUnits($qty * $value));
    }

    /**
     * Cuantiza cantidad por precio a la precisión configurada truncando hacia cero.
     *
     * Auxiliar protegido conservado; el cálculo compartido actual usa Money directamente.
     *
     * @param int|float|numeric-string $qty Cantidad de producto.
     * @param int|float|numeric-string $value Precio unitario sin IVA.
     * @return int|float Base numérica cuantizada.
     * @throws \InvalidArgumentException Si el producto no es finito o excede el límite monetario.
     */
    protected function pnp($qty, $value)
    {
        return Money::fromMinorUnits(Money::minorUnits($qty * $value, true));
    }
    /**
     * Presenta la base final antes de IVA, propina y recargos legacy.
     *
     * Incluye las bases de productos tras prorrateos, descuentos de línea y
     * generales, más los costos ITEM. No es la suma de precios originales de content().
     *
     * @return string Base final formateada según cart.format.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function subtotal()
    {
        return $this->numberFormat($this->summary()['subtotal']);
    }

    /**
     * Filtra productos originales mediante una Closure, conservando claves rowId.
     *
     * No consulta costos ni bases ajustadas y no modifica la colección de sesión.
     *
     * @param Closure(CartItem, string): bool $search Predicado que recibe línea y clave.
     * @return Collection<string, CartItem> Líneas para las que el predicado sea verdadero.
     */
    public function search(Closure $search)
    {
        $content = $this->fiscalContent();

        return $content->filter($search);
    }

    /**
     * Asocia una clase de modelo a la línea resuelta y guarda la colección.
     *
     * Si se recibe un nombre de clase comprueba su existencia antes de buscar
     * la línea. La carga del modelo se difiere a CartItem::__get('model').
     *
     * @param int|string $rowId RowId o código inequívoco.
     * @param class-string|object $model Clase o instancia cuyo nombre se conservará.
     * @return void
     * @throws UnknownModelException Si el nombre de clase no existe.
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     */
    public function associate($rowId, $model)
    {
        if(is_string($model) && ! class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->rawItem($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Cambia la alícuota de una línea mediante update(), no un porcentaje arbitrario.
     *
     * Puede cambiar rowId, fusionar líneas compatibles y trasladar descuentos.
     * Despacha los eventos propios de update(); utiliza exclusivamente cart.taxes.
     *
     * @param int|string $rowId RowId o código inequívoco.
     * @param int|string|null $taxRate Clave de alícuota; null utiliza cart.default_aliquot.
     * @return void
     * @throws InvalidRowIDException Si no existe la línea solicitada.
     * @throws AmbiguousItemException Si el código corresponde a varias líneas; se debe indicar rowId.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function setTax($rowId, $taxRate)
    {
        $this->update($rowId, ['aliquot' => $taxRate]);
    }

    /**
     * Guarda productos y metadatos de la instancia como snapshot en base de datos.
     *
     * Serializa version=3, decimals, content y metadata (con su precisión) en la
     * columna content de la tabla configurada. No liquida importes ni vacía sesión.
     * Reutiliza createdAt del objeto si existe; updated_at usa la fecha actual.
     * Despacha cart.stored después de insertar.
     *
     * @param int|string|InstanceIdentifier $identifier Identificador de almacenamiento, ajeno al código de producto.
     * @return void
     * @throws CartAlreadyStoredException Si ya existe el par identificador/instancia.
     * @throws \Illuminate\Database\QueryException Si falla la consulta o inserción.
     */
    public function store($identifier)
    {
        $content = $this->getContent();

        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();

        if ($this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            throw new CartAlreadyStoredException("A cart with identifier {$identifier} was already stored.");
        }

        $this->getConnection()->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance'   => $instance,
            'content'    => serialize(['version' => 3, 'decimals' => Money::decimals(), 'content' => $content, 'metadata' => $this->metadata()]),
            'created_at' => $this->createdAt ?: Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->events->dispatch('cart.stored');

    }

    /**
     * Consulta si la tabla contiene un snapshot del par identificador/instancia.
     *
     * @param string $instance Nombre almacenado, sin prefijo cart.
     * @param int|string $identifier Identificador previamente resuelto.
     * @return bool
     * @throws \Illuminate\Database\QueryException Si falla la consulta.
     */
    private function storedCartInstanceWithIdentifierExists($instance, $identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance'=> $instance])->exists();
    }


    /**
     * Superpone un snapshot de la instancia actual y consume su registro.
     *
     * Si no existe, no realiza cambios. Acepta la Collection antigua o un sobre con
     * version 2 (precisión histórica 2) o 3 (precisión explícita) y metadata.
     * Convierte unidades menores a la precisión actual antes de combinar registros.
     * Agrega metadatos guardados después de los existentes y sobrescribe productos
     * por rowId, sin sumar cantidades ni reconciliar identidades diferentes.
     * Guarda sesión, despacha cart.restored, recupera fechas y elimina el snapshot.
     * Para sustituir todo el carrito debe llamarse destroy() antes de restaurar.
     *
     * @param int|string|InstanceIdentifier $identifier Identificador del snapshot de la instancia seleccionada.
     * @return void
     * @throws \Illuminate\Database\QueryException Si falla la lectura o eliminación.
     * @throws \InvalidArgumentException Si la normalización de productos existentes rechaza datos.
     */
    public function restore($identifier)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();

        if (!$this->storedCartInstanceWithIdentifierExists($currentInstance, $identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance' => $currentInstance])->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        if (is_array($storedContent) && isset($storedContent['version'])) {
            $incomingMetadata = $this->snapshotMetadata($storedContent);
            $metadata = $this->metadata();
            foreach (['costs', 'discounts', 'observations'] as $key) $metadata[$key] = array_merge($metadata[$key], $incomingMetadata[$key]);
            $this->saveMetadata($metadata);
            $storedContent = $storedContent['content'];
        }

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.restored');

        $this->instance($currentInstance);

        $this->createdAt = Carbon::parse(data_get($stored, 'created_at'));
        $this->updatedAt = Carbon::parse(data_get($stored, 'updated_at'));

        $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $currentInstance])->delete();

    }

    /**
     * Incorpora un snapshot a la instancia seleccionada sin consumirlo.
     *
     * Lee la instancia de origen indicada, agrega productos mediante addCartItem()
     * y acumula cantidades compatibles. Concatena costos, descuentos y observaciones
     * del origen después de los actuales; remapea los descuentos de línea al rowId
     * que sobreviva. Acepta Collection antigua y sobres con version/metadata.
     * Despacha cart.merged; repetir la llamada vuelve a incorporar datos.
     *
     * @param int|string|InstanceIdentifier $identifier Identificador del snapshot de origen.
     * @param bool $dispatchAdd Si se publican cart.adding/cart.added por producto.
     * @param string $instance Instancia de origen en base de datos, no la de destino.
     * @return bool False si no existe el snapshot; true tras incorporarlo.
     * @throws \Illuminate\Database\QueryException Si falla la lectura.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function merge($identifier, $dispatchAdd = true, $instance = self::DEFAULT_INSTANCE)
    {
        if ($identifier instanceof InstanceIdentifier) $identifier = $identifier->getInstanceIdentifier();
        if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            return false;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance'=> $instance])->first();

        $storedContent = unserialize($stored->content);
        $incomingMetadata = ['costs' => [], 'discounts' => [], 'observations' => []];
        if (is_array($storedContent) && isset($storedContent['version'])) {
            $incomingMetadata = $this->snapshotMetadata($storedContent);
            $storedContent = $storedContent['content'];
        }
        $identities = [];
        foreach ($storedContent as $cartItem) {
            $oldRowId = $cartItem->rowId;
            $identities[$oldRowId] = $this->addCartItem($cartItem, $dispatchAdd)->rowId;
        }
        foreach ($incomingMetadata['discounts'] as &$discount) {
            if ($discount['rowId'] !== null) $discount['rowId'] = $identities[$discount['rowId']] ?? $discount['rowId'];
        }
        unset($discount);
        $metadata = $this->metadata();
        foreach (['costs', 'discounts', 'observations'] as $key) $metadata[$key] = array_merge($metadata[$key], $incomingMetadata[$key]);
        $this->saveMetadata($metadata);
        $this->events->dispatch('cart.merged');

        return true;
    }

    /**
     * Incorpora una copia de un CartItem, conservando identidades compatibles.
     *
     * Normaliza alícuota nula y recalcula priceTax. Si existe la misma identidad,
     * conserva su rowId y suma cantidad; reemplaza los demás atributos por los de
     * la copia. Guarda sesión y, si se solicita, despacha cart.adding y cart.added.
     * No valida de nuevo la cantidad ni incorpora metadatos del documento origen.
     *
     * @param CartItem $item Producto de origen, que no se modifica directamente.
     * @param bool $dispatchEvent Activa los dos eventos de incorporación.
     * @return CartItem Copia guardada con su cantidad acumulada.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    public function addCartItem($item, $dispatchEvent = true)
    {
        $item = clone $item;
        if ($item->aliquot === null) $item->aliquot = config('cart.default_aliquot');
        $item->setTaxRate($item->aliquot);
        $content = $this->getContent();
        foreach ($content as $existing) {
            if ($existing->identity() === $item->identity()) { $item->rowId = $existing->rowId; break; }
        }

        if ($content->has($item->rowId)) {
            $item->qty += $content->get($item->rowId)->qty;
        }

        $content->put($item->rowId, $item);
        $this->fiscalContent($content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.adding', $item);
        }

        $this->session->put($this->instance, $content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.added', $item);
        }

        return $item;
    }

    /**
     * Permite consultar total, tax y subtotal como propiedades de solo lectura.
     *
     * Cada acceso ejecuta el método correspondiente; no almacena un resultado en caché.
     *
     * @param string $attribute Nombre de propiedad virtual.
     * @return string|array<string, array{IVA: int|float, value: int|float}>|null Resultado o null para nombres desconocidos.
     * @throws \DomainException Si hay bases negativas o un prorrateo positivo sin base distribuible.
     * @throws \InvalidArgumentException Si un importe o reparto excede los límites de Money.
     */
    public function __get($attribute)
    {
        if($attribute === 'total') {
            return $this->total();
        }

        if($attribute === 'tax') {
            return $this->tax();
        }

        if($attribute === 'subtotal') {
            return $this->subtotal();
        }

        return null;
    }

    /**
     * Recupera la colección de sesión y normaliza campos de snapshots antiguos.
     *
     * Si no existe devuelve una colección vacía. Sustituye alícuota nula por la
     * predeterminada. priceTax se deriva al leerlo, incluso en snapshots antiguos.
     * La normalización actúa sobre los objetos existentes y conserva su rowId.
     *
     * @return Collection<string, CartItem> Productos originales de la instancia.
     * @throws \InvalidArgumentException Si la alícuota o el impuesto no son válidos.
     */
    protected function getContent()
    {
        $content = $this->session->has($this->instance)
            ? $this->session->get($this->instance)
            : new Collection;

        foreach ($content as $item) {
            // Normaliza la alícuota omitida en snapshots antiguos sin sustituir su rowId.
            if ($item->aliquot === null) $item->aliquot = config('cart.default_aliquot');
        }

        return $content;
    }

    /**
     * Adapta las tres fuentes de atributos al constructor de CartItem.
     *
     * En Buyable, name indica cantidad (1 si es falso) y qty contiene opciones
     * (array vacío si es falso); utiliza la alícuota predeterminada y asocia el objeto.
     * La rama de array admite atributos completos, aunque add() normalmente los
     * descompone antes de llamar a este método. Al final recalcula la tasa del item.
     *
     * @param int|string|array<string, mixed>|Buyable $id Código, atributos o contrato.
     * @param string|int|float|numeric-string|null $name Nombre o cantidad para Buyable.
     * @param int|float|numeric-string|array<array-key, mixed>|null $qty Cantidad u opciones de Buyable.
     * @param int|float|numeric-string|null $price Precio unitario original.
     * @param int|string|null $aliquot Clave de alícuota para atributos individuales.
     * @param array<array-key, mixed> $options Opciones de atributos individuales.
     * @return CartItem Producto con cantidad y tasa, todavía no agregado a sesión.
     * @throws \InvalidArgumentException Si los datos o importes no son válidos para la operación.
     */
    private function createCartItem($id, $name, $qty, $price, $aliquot, array $options)
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $aliquot, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setTaxRate($cartItem->aliquot);

        return $cartItem;
    }

    /**
     * Comprueba si existe el identificador en cualquier instancia almacenada.
     *
     * Este auxiliar privado no tiene llamadas en el flujo actual del paquete.
     *
     * @param int|string $identifier Identificador del snapshot.
     * @return bool
     * @throws \Illuminate\Database\QueryException Si falla la consulta.
     */
    private function storedCartWithIdentifierExists($identifier)
    {
        return $this->getConnection()->table($this->getTableName())->where('identifier', $identifier)->exists();
    }

    /**
     * Resuelve el DatabaseManager de Laravel y solicita la conexión configurada.
     *
     * @return Connection Conexión usada para los snapshots del carrito.
     * @throws \InvalidArgumentException Si Laravel no reconoce la conexión solicitada.
     */
    private function getConnection()
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Obtiene cart.database.table, con shopping_cart como alternativa si es falso.
     *
     * @return string Nombre de la tabla de snapshots.
     */
    private function getTableName()
    {
        return config('cart.database.table') ?: 'shopping_cart';
    }

    /**
     * Obtiene cart.database.connection o database.default cuando el primero es null.
     *
     * @return string|null Nombre entregado al administrador de conexiones de Laravel.
     */
    private function getConnectionName()
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    /**
     * Formatea un importe únicamente para presentación mediante cart.format.
     *
     * Aplica decimales, separador decimal y separador de miles configurados, con
     * valores alternativos 2, punto y cadena vacía cuando son null.
     *
     * @param int|float|numeric-string $value Importe numérico a presentar.
     * @return string Importe formateado; no debe reutilizarse como entrada contable.
     */
    private function numberFormat($value)
    {
        $decimals = Money::decimals();
        $decimalPoint = is_null(config('cart.format.decimal_point')) ? '.' : config('cart.format.decimal_point');
        $thousandSeparator = is_null(config('cart.format.thousand_separator')) ? '' : config('cart.format.thousand_separator');;

        return number_format($value, $decimals, $decimalPoint, $thousandSeparator);
    }
}
