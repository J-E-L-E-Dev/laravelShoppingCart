<?php
namespace JeleDev\Shoppingcart;

use Illuminate\Contracts\Support\Arrayable;
use JeleDev\Shoppingcart\Contracts\Buyable;
use Illuminate\Contracts\Support\Jsonable;

use Illuminate\Support\Arr;

/**
 * Representa una línea original de producto y su impuesto de fila completa.
 *
 * El código id pertenece al consumidor; rowId identifica la línea dentro de
 * Cart y puede conservar un hash de una versión anterior. La identidad vigente
 * incluye id, opciones y alícuota, pero no nombre, cantidad ni precio.
 * Los precios y getters de esta clase no incorporan los ajustes documentales:
 * para facturar con costos o descuentos usar Cart::summary().
 * Implementa Arrayable/Jsonable y puede asociar una clase de modelo para
 * resolverla bajo demanda mediante la propiedad virtual model.
 *
 * @property-read string $subtotal Base de fila cuantizada, con precisión configurada y punto.
 * @property-read string $total Base de fila más tax, con precisión configurada y punto.
 * @property-read int|float $tax IVA de fila; Cart proporciona reconciliación HKA derivada.
 * @property-read int|float $unitTax IVA unitario independiente; no se multiplica para obtener tax.
 * @property-read int|float $priceTax Precio unitario más unitTax, derivado de configuración vigente.
 * @property-read string $taxTotal Alias formateado de tax; no multiplica por cantidad.
 * @property-read mixed $model Resultado de find(id) en el modelo asociado, o null.
 */
class CartItem implements Arrayable, Jsonable, \JsonSerializable
{
    /**
     * Identificador interno de la línea, distinto del código del producto.
     *
     * La aplicación debe utilizar el valor devuelto por Cart, sin generar ni
     * predecir manualmente el hash. Puede diferir de identity() en snapshots antiguos.
     *
     * @var string
     */
    public $rowId;

    /**
     * Código del producto proporcionado por el consumidor; puede repetirse entre líneas.
     *
     * Se conserva sin convertir a número, incluidos ceros iniciales en strings.
     * El constructor admite escalares no vacíos; el uso habitual es int|string.
     *
     * @var int|string|float|bool
     */
    public $id;

    /**
     * Cantidad original de la línea; puede ser fraccionaria o un string numérico.
     *
     * El constructor no la establece; Cart la asigna al incorporar el producto.
     *
     * @var int|float|numeric-string|null
     */
    public $qty;

    /**
     * Descripción original del producto utilizada para presentar la línea.
     *
     * @var string
     */
    public $name;

    /**
     * Precio unitario original sin IVA, convertido a float al construir la línea.
     *
     * No contiene prorrateos ni descuentos de la liquidación documental.
     *
     * @var float
     */
    public $price;

    /**
     * Campo histórico de precio con IVA retenido para lectura de snapshots antiguos.
     *
     * La consulta pública ahora deriva price + unitTax mediante __get(), sin usar
     * el impuesto de fila ni una cuantización guardada con otra configuración.
     *
     * @var int|float|null
     */
    protected $priceTax;

    /**
     * Resolutores externos al estado de cada item; nunca se serializan en sesión/snapshots.
     * @var \WeakMap<CartItem, \Closure>|null
     */
    private static $taxResolvers;

    /** Cart inyecta el contexto fiscal temporal; el item no consulta Cart ni Session. @internal */
    public function resolveTaxUsing(\Closure $resolver)
    {
        if (self::$taxResolvers === null) self::$taxResolvers = new \WeakMap();
        self::$taxResolvers[$this] = $resolver;
    }

    /** Persistencia de atributos; excluye toda resolución fiscal temporal. */
    public function __serialize(): array
    {
        $attributes = get_object_vars($this);
        return $attributes;
    }

    /** Lee también propiedades con nombres privados de snapshots legacy y v2. */
    public function __unserialize(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $parts = explode("\0", $key);
            $key = end($parts);
            if (array_key_exists($key, get_object_vars($this))) $this->$key = $value;
        }
    }

    /** Admite el campo histórico priceTax al normalizar objetos antiguos; el getter es derivado. */
    public function __set($name, $value)
    {
        if ($name === 'priceTax') { $this->priceTax = $value; return; }
        throw new \InvalidArgumentException('Unknown CartItem property: ' . $name);
    }

    /** Conserva la consulta isset del precio unitario con IVA, ahora derivado. */
    public function __isset($name)
    {
        return in_array($name, ['priceTax', 'unitTax', 'tax', 'taxTotal', 'subtotal', 'total'], true);
    }

    /** JSON directo y colecciones de Laravel comparten la misma representación fiscal. */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Clave de cart.taxes que define la tasa; no es el porcentaje de IVA.
     *
     * Puede ser null en datos antiguos antes de la normalización de Cart.
     *
     * @var int|string|null
     */
    public $aliquot;

    /**
     * Opciones del producto que participan en la identidad de la línea.
     *
     * @var CartItemOptions
     */
    public $options;

    /**
     * Nombre completo de la clase cuyo find(id) se invoca al consultar model.
     *
     * Se almacena la clase, no la instancia recibida en associate().
     *
     * @var class-string|null
     */
    private $associatedModel = null;

    /**
     * Porcentaje de IVA conservado desde cart.taxes mediante setTaxRate().
     *
     * @var int|float|numeric-string|null
     */
    private $taxRate = 0;

    /**
     * Valida atributos, construye opciones e identidad e inicializa el precio con IVA.
     *
     * Normaliza alícuota null con cart.default_aliquot y comprueba su clave en el
     * catálogo. Convierte price a float y valida su rango mediante Money.
     * No establece qty: el llamador debe asignarla con setQuantity().
     *
     * @param int|string|float|bool $id Código escalar cuya representación no sea vacía.
     * @param string $name Descripción no vacía según empty().
     * @param int|float|numeric-string $price Precio no negativo, finito y dentro del límite monetario.
     * @param int|string|null $aliquot Clave del catálogo o null para usar la predeterminada.
     * @param array<array-key, mixed> $options Opciones originales del producto.
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     */
    public function __construct($id, $name, $price, $aliquot, array $options = [])
    {
        if(!is_scalar($id) || (string) $id === '') {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }
        if(empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }
        if (!is_numeric($price) || !is_finite((float) $price) || Money::compare($price, 0) < 0) throw new \InvalidArgumentException('Invalid price.');
        Money::minorUnits($price);
        $aliquot = $aliquot === null ? config('cart.default_aliquot') : $aliquot;
        FiscalCalculator::taxRate($aliquot);

        $this->id       = $id;
        $this->name     = $name;
        $this->price    = floatval($price);
        $this->aliquot  = $aliquot;
        $this->options  = new CartItemOptions($options);
        $this->rowId = $this->generateRowId($id, $options);
        $this->setTaxRate($aliquot);
    }

    /**
     * Presenta el precio unitario original sin IVA con el formato de CartItem.
     *
     * Usa decimales y separador decimal de cart.format, sin separador de miles.
     *
     * @return string Precio formateado, sin ajustes documentales.
     */
    public function price()
    {
        return $this->numberFormat($this->price);
    }

    /**
     * Presenta el precio unitario más IVA unitario con el formato de CartItem.
     *
     * Recalcula con la configuración vigente, sin costos ni descuentos documentales.
     *
     * @return string Precio unitario con IVA formateado.
     */
    public function priceTax()
    {
        return $this->numberFormat($this->__get('priceTax'));
    }

    /**
     * Presenta cantidad por precio original de la línea, sin IVA ni ajustes.
     *
     * Money cuantiza con la precisión configurada; este método aplica los separadores.
     *
     * @return string Base original formateada; para la base fiscal final usar Cart::summary().
     */
    public function subtotal()
    {
        return $this->numberFormat($this->subtotal);
    }

    /**
     * Presenta base original cuantizada más IVA de fila, sin ajustes del documento.
     *
     * Utiliza tax de fila (reconciliado en HKA cuando Cart provee contexto).
     *
     * @return string Importe original con IVA, formateado.
     */
    public function total()
    {
        return $this->numberFormat($this->total);
    }

    /**
     * Presenta el IVA de la fila original según la configuración vigente.
     *
     * No es el impuesto de la liquidación final de Cart.
     *
     * @return string IVA de fila formateado.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public function tax()
    {
        return $this->numberFormat($this->tax);
    }

    /**
     * Presenta el mismo IVA de fila que tax(), sin ajustes documentales.
     *
     * No vuelve a multiplicar por cantidad. Puede diferir de summary() con ajustes.
     *
     * @return string IVA de la línea original formateado.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public function taxTotal()
    {
        return $this->numberFormat($this->taxTotal);
    }

    /**
     * Asigna una cantidad numérica, finita y estrictamente positiva.
     *
     * Conserva el tipo numérico recibido y no modifica rowId ni persiste sesión.
     * Para eliminar una línea por cantidad cero utilizar Cart::update().
     *
     * @param int|float|numeric-string $qty Cantidad del producto.
     * @return void
     * @throws \InvalidArgumentException Si la cantidad no es positiva, numérica o finita.
     */
    public function setQuantity($qty)
    {
        if(!is_numeric($qty) || !is_finite((float) $qty) || $qty <= 0)
            throw new \InvalidArgumentException('Please supply a valid quantity.');

        $this->qty = $qty;
    }

    /**
     * Actualiza código, descripción y precio a partir del contrato Buyable.
     *
     * Entrega el CartItemOptions actual a los métodos del contrato y delega en
     * updateFromArray(), conservando cantidad, alícuota y opciones. Puede cambiar
     * rowId; Cart::update() se ocupa de mover la clave y sus descuentos en sesión.
     *
     * @param Buyable $item Producto que proporciona los nuevos atributos.
     * @return void
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     */
    public function updateFromBuyable(Buyable $item)
    {
        $this->updateFromArray(['id' => $item->getBuyableIdentifier($this->options), 'name' => $item->getBuyableDescription($this->options), 'price' => $item->getBuyablePrice($this->options)]);
    }

    /**
     * Valida y aplica atributos parciales manteniendo los omitidos.
     *
     * Construye una línea temporal para validar datos y recalcular identidad e IVA.
     * Conserva el rowId anterior si identity() no cambia, incluso para hashes antiguos.
     * Valida que qty sea numérica y finita, pero admite cero o negativos aquí:
     * Cart::update() decide entonces eliminar la línea. No guarda sesión ni mueve
     * claves de colección; conserva la asociación de modelo existente.
     *
     * @param array{id?: int|string, name?: string, price?: int|float|numeric-string, aliquot?: int|string|null, options?: array<array-key, mixed>, qty?: int|float|numeric-string} $attributes Atributos a sustituir.
     * @return void
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     * @throws \InvalidArgumentException Si qty no es numérica o finita.
     */
    public function updateFromArray(array $attributes)
    {
        $updated = new self(Arr::get($attributes, 'id', $this->id), Arr::get($attributes, 'name', $this->name), Arr::get($attributes, 'price', $this->price), Arr::get($attributes, 'aliquot', $this->aliquot), (array) Arr::get($attributes, 'options', $this->options->all()));
        $qty = Arr::get($attributes, 'qty', $this->qty);
        if (!is_numeric($qty) || !is_finite((float) $qty)) throw new \InvalidArgumentException('Invalid quantity.');
        $rowId = $this->identity() === $updated->identity() ? $this->rowId : $updated->rowId;
        foreach (['id', 'name', 'price', 'aliquot', 'options', 'priceTax', 'taxRate'] as $field) $this->$field = $updated->$field;
        $this->qty = $qty;
        $this->rowId = $rowId;
    }

    /**
     * Conserva el nombre de clase del modelo sin cargarlo ni comprobar su existencia.
     *
     * model invocará new Clase y find(id) cuando se consulte. Cart::associate()
     * comprueba nombres de clases y persiste la colección; este método no lo hace.
     *
     * @param class-string|object $model Nombre de clase o instancia asociable.
     * @return $this
     */
    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);

        return $this;
    }

    /**
     * Configura la alícuota y carga su porcentaje de IVA desde cart.taxes.
     *
     * Pese al nombre del parámetro, recibe una clave de catálogo, no un porcentaje.
     * Null usa cart.default_aliquot. Si cambia su representación string actualiza
     * aliquot y rowId; valida la tasa y calcula priceTax antes de modificar campos.
     * No mueve claves de sesión ni descuentos: sobre líneas de Cart usar Cart::setTax().
     *
     * @param int|string|null $taxRate Clave de la alícuota.
     * @return $this
     * @throws \InvalidArgumentException Si la alícuota o tasa son inválidas o Money rechaza el impuesto.
     */
    public function setTaxRate($taxRate)
    {
        $aliquot = $taxRate === null ? config('cart.default_aliquot') : $taxRate;
        $rate = $this->getTaxRate($aliquot);
        $priceTax = $this->price + self::calculateTaxes($this->price, $rate);
        if ((string) $this->aliquot !== (string) $aliquot) {
            $this->aliquot = $aliquot;
            $this->rowId = $this->identity();
        }
        $this->taxRate = $rate;
        $this->priceTax = $priceTax;
        return $this;
    }

    /**
     * Lee el porcentaje configurado de una alícuota sin asignarlo a la línea.
     *
     * Exige una alícuota existente y tasa numérica, finita y no negativa.
     *
     * @param int|string $aliquot Clave de cart.taxes.
     * @return int|float|numeric-string Valor configurado validado.
     * @throws \InvalidArgumentException Si la configuración o la tasa no son válidas.
     */
    public function getTaxRate($aliquot)
    {
        return FiscalCalculator::taxRate($aliquot);
    }

    /**
     * Resuelve propiedades virtuales y permite leer propiedades declaradas no accesibles.
     *
     * subtotal, total y taxTotal usan precisión configurada y punto. tax es IVA de
     * fila: PNP parte de qty × price sin truncar la entrada; GENERAL cuantiza la base.
     * HKA usa la reconciliación provista por Cart; sin contexto sólo calcula provisional.
     * model instancia la clase asociada y ejecuta find(id), por lo que puede consultar
     * la base de datos. No aplica descuentos ni costos documentales.
     * priceTax y unitTax son conceptos unitarios independientes del IVA de fila.
     *
     * @param string $attribute Nombre del atributo solicitado.
     * @return mixed Valor declarado, importe, modelo o null para nombres desconocidos.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public function __get($attribute)
    {
        if ($attribute === 'priceTax') {
            return $this->price + $this->unitTax;
        }
        if ($attribute === 'unitTax') {
            return self::calculateTaxes($this->price, $this->getTaxRate($this->aliquot));
        }
        if(property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        if($attribute === 'subtotal') {
            $base = Money::minorUnits($this->qty * $this->price, config('cart.driver') === 'PNP');
            return number_format(Money::fromMinorUnits($base), Money::decimals(), '.', '');
        }

        if($attribute === 'total') {
            $base = Money::minorUnits($this->qty * $this->price, config('cart.driver') === 'PNP');
            return number_format(Money::fromMinorUnits($base + Money::minorUnits($this->tax)), Money::decimals(), '.', '');
        }

        if($attribute === 'aliquot') {
            return $this->aliquot;
        }

        if($attribute === 'tax') {
            $resolver = self::$taxResolvers[$this] ?? null;
            if ($resolver) {
                $units = $resolver($this);
                if ($units !== null) return Money::fromMinorUnits($units);
            }
            $base = $this->qty * $this->price;
            if (config('cart.driver') !== 'PNP') $base = Money::fromMinorUnits(Money::minorUnits($base));
            return self::calculateTaxes($base, $this->getTaxRate($this->aliquot));
        }

        if($attribute === 'taxTotal') {
            return number_format($this->tax, Money::decimals(), '.', '');
        }

        if($attribute === 'model' && isset($this->associatedModel)) {
            return with(new $this->associatedModel)->find($this->id);
        }

        return null;
    }

    /**
     * Calcula el IVA de una base numérica usando el driver configurado.
     *
     * Multiplica price por tax_rate / 100. GENERAL y HKA redondean la mitad hacia
     * arriba a unidades menores; PNP trunca hacia cero. Un driver desconocido usa GENERAL.
     * La tasa recibida debe ser numérica, finita y no negativa. Ya es un porcentaje:
     * este método no consulta su alícuota ni agrupa productos.
     * Devuelve un número crudo, no un importe formateado.
     * La estrategia de acumulación pertenece a Cart: GENERAL entrega la base
     * completa de una línea fiscal; PNP entrega la entrada sin truncar de esa fila;
     * HKA agrupa bases en FiscalCalculator. unitTax no sustituye el IVA de fila.
     *
     * @param int|float|numeric-string $price Base sin IVA, unitaria o agrupada por el llamador.
     * @param int|float|numeric-string $tax_rate Porcentaje de IVA.
     * @return int|float Importe de IVA cuantizado a la precisión configurada.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public static function calculateTaxes($price, $tax_rate)
    {
        FiscalCalculator::validateTaxRate($tax_rate);
        switch (config('cart.driver')) {
            case 'GENERAL':
                return self::generalDriver($price, $tax_rate);
                break;

            case 'HKA':
                return self::hkaDriver($price, $tax_rate);
                break;

            case 'PNP':
                return self::pnpDriver($price, $tax_rate);
                break;

            default:
                return self::generalDriver($price, $tax_rate);
                break;
        }
    }

    /**
     * Calcula base por porcentaje y redondea la mitad hacia arriba mediante Money::minorUnits().
     *
     * GENERAL cuantiza el importe resultante a la precisión configurada.
     *
     * @param int|float|numeric-string $price Base sin IVA.
     * @param int|float|numeric-string $tax_rate Porcentaje aplicable.
     * @return int|float IVA numérico en unidades monetarias, no unidades menores.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    protected static function generalDriver($price, $tax_rate)
    {
        return Money::fromMinorUnits(Money::minorUnits($price * ($tax_rate / 100)));
    }

    /**
     * Calcula base por porcentaje y redondea la mitad hacia arriba mediante Money::minorUnits().
     *
     * HKA comparte actualmente la cuantización de GENERAL; no consulta equipos fiscales.
     *
     * @param int|float|numeric-string $price Base sin IVA.
     * @param int|float|numeric-string $tax_rate Porcentaje aplicable.
     * @return int|float IVA numérico en unidades monetarias, no unidades menores.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    protected static function hkaDriver($price, $tax_rate)
    {
        return Money::fromMinorUnits(Money::minorUnits($price * ($tax_rate / 100)));
    }

    /**
     * Calcula base por porcentaje y trunca hacia cero mediante Money::minorUnits().
     *
     * PNP conserva la política de truncamiento tras normalizar el ruido binario.
     *
     * @param int|float|numeric-string $price Base sin IVA.
     * @param int|float|numeric-string $tax_rate Porcentaje aplicable.
     * @return int|float IVA numérico en unidades monetarias, no unidades menores.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    protected static function pnpDriver($price, $tax_rate)
    {
        return Money::fromMinorUnits(Money::minorUnits($price * ($tax_rate / 100), true));
    }

    /**
     * Construye una línea original desde los atributos del contrato y la alícuota predeterminada.
     *
     * Entrega options como array a los tres métodos de Buyable.
     * No asigna cantidad ni asocia el modelo; Cart realiza esos pasos.
     *
     * @param Buyable $item Producto que implementa el contrato.
     * @param array<array-key, mixed> $options Opciones entregadas al producto y a la nueva línea.
     * @return CartItem Línea nueva sin cantidad asignada.
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     */
    public static function fromBuyable(Buyable $item, array $options = [])
    {
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), config('cart.default_aliquot'), $options);
    }

    /**
     * Construye una línea desde id, name y price, con alícuota y opciones opcionales.
     *
     * No usa qty aunque el array la incluya; Cart la asigna por separado.
     * Si falta aliquot utiliza cart.default_aliquot y, si falta options, un array vacío.
     *
     * @param array{id: int|string, name: string, price: int|float|numeric-string, aliquot?: int|string|null, options?: array<array-key, mixed>} $attributes Atributos del producto.
     * @return CartItem Línea nueva sin cantidad asignada.
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     */
    public static function fromArray(array $attributes)
    {
        $options = Arr::get($attributes, 'options', []);

        return new self($attributes['id'], $attributes['name'], $attributes['price'], Arr::get($attributes, 'aliquot', config('cart.default_aliquot')), $options);
    }

    /**
     * Construye una línea desde atributos individuales, sin asignar cantidad.
     *
     * Delega validación, opciones, identidad y precio con IVA en el constructor.
     *
     * @param int|string $id Código de producto de la aplicación.
     * @param string $name Descripción no vacía.
     * @param int|float|numeric-string $price Precio unitario original sin IVA.
     * @param int|string|null $aliquot Clave de alícuota o null para la predeterminada.
     * @param array<array-key, mixed> $options Opciones que participan en la identidad.
     * @return CartItem Línea nueva sin cantidad asignada.
     * @throws \InvalidArgumentException Si identificador, nombre, precio, alícuota o impuesto no son válidos.
     */
    public static function fromAttributes($id, $name, $price, $aliquot, array $options = [])
    {
        return new self($id, $name, $price, $aliquot, $options);
    }

    /**
     * Calcula la identidad vigente desde código, opciones y alícuota actuales.
     *
     * No asigna rowId ni modifica el objeto. Puede diferir del rowId guardado en
     * snapshots antiguos; Cart compara esta identidad para reutilizar aquella clave.
     * No incluye nombre, cantidad, precio, modelo asociado ni ajustes documentales.
     *
     * @return string Hash MD5 vigente; no debe predecirse desde la aplicación.
     */
    public function identity()
    {
        return $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Genera el hash interno de código, opciones serializadas y alícuota actual.
     *
     * Ordena una copia del primer nivel de options con ksort(), concatena id,
     * serialize(options), ':' y aliquot como string, y calcula MD5.
     * No ordena arrays anidados ni altera las opciones originales. Variantes de
     * opciones o alícuotas pueden producir líneas distintas para el mismo código.
     *
     * @param int|string|float|bool $id Código del producto que se concatena al hash.
     * @param array<array-key, mixed> $options Opciones a ordenar superficialmente.
     * @return string Hash de identidad de línea, no identificador de negocio.
     */
    protected function generateRowId($id, array $options)
    {
        ksort($options);

        return md5($id . serialize($options) . ':' . (string) $this->aliquot);
    }

    /**
     * Exporta atributos originales y cálculos de la línea para Arrayable.
     *
     * No incluye modelo asociado, precio con IVA ni ajustes de Cart::summary().
     * tax es IVA de fila numérico y subtotal es el string original de precisión configurada.
     *
     * @return array{rowId: string, id: int|string|float|bool, name: string, qty: int|float|numeric-string|null, price: float, aliquot: int|string|null, options: array<array-key, mixed>, tax: int|float, subtotal: string}
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public function toArray()
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'aliquot'  => $this->aliquot,
            'options'  => $this->options->toArray(),
            'tax'      => $this->tax,
            'subtotal' => $this->subtotal
        ];
    }

    /**
     * Serializa toArray() mediante json_encode sin modificar los atributos.
     *
     * Conserva el comportamiento de json_encode: puede devolver false o lanzar
     * JsonException cuando el llamador utiliza JSON_THROW_ON_ERROR.
     *
     * @param int $options Opciones de codificación JSON.
     * @return string|false JSON de los atributos originales o false ante fallo sin excepción.
     * @throws \JsonException Si la codificación falla con JSON_THROW_ON_ERROR.
     * @throws \InvalidArgumentException Si el importe calculado no es finito o excede el límite de Money.
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Aplica el formato de presentación de la línea, siempre sin separador de miles.
     *
     * Usa precisión validada (2 si falta) y decimal_point (punto si es null).
     *
     * @param int|float|numeric-string|null $value Importe original o calculado a presentar.
     * @return string Valor formateado.
     */
    private function numberFormat($value)
    {
        $decimals = Money::decimals();
        $decimalPoint = is_null(config('cart.format.decimal_point')) ? '.' : config('cart.format.decimal_point');
        $thousandSeparator = '';

        return number_format($value, $decimals, $decimalPoint, $thousandSeparator);
    }
}
