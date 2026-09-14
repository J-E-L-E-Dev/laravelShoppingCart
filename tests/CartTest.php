<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Collection;
use JeleDev\Shoppingcart\Cart;
use JeleDev\Shoppingcart\CartItem;
use JeleDev\Shoppingcart\Money;
use JeleDev\Shoppingcart\Contracts\Buyable;
use JeleDev\Shoppingcart\Contracts\InstanceIdentifier;
use JeleDev\Shoppingcart\Exceptions\AmbiguousItemException;
use JeleDev\Shoppingcart\Exceptions\InvalidRowIDException;
use PHPUnit\Framework\TestCase;

class CartTest extends TestCase
{
    private $cart;
    private $session;
    private $events;
    private $db;

    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository(['cart' => require __DIR__.'/../config/cart.php', 'session' => ['driver' => 'array']]));
        $this->events = new Dispatcher($app);
        $this->session = new SessionManager($app);
        $this->session->extend('array', function () { return new ArraySessionHandler(120); });
        $this->cart = new Cart($this->session, $this->events);
        $capsule = new Capsule($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db = $capsule->getDatabaseManager();
        $app->instance(DatabaseManager::class, $this->db);
        config(['cart.database.connection' => 'default']);
        $this->db->connection()->getSchemaBuilder()->create('shopping_cart', function ($table) {
            $table->string('identifier'); $table->string('instance'); $table->text('content'); $table->timestamps();
            $table->primary(['identifier', 'instance']);
        });
    }

    public function testIdentityAndSafeResolution(): void
    {
        $a = $this->cart->add('000123', 'A', 2, 10, 0, ['size' => 'M', 'color' => 'red']);
        $b = $this->cart->add('000123', 'A', 3, 10, 0, ['color' => 'red', 'size' => 'M']);
        self::assertSame($a->rowId, $b->rowId);
        self::assertSame(5, $b->qty);
        self::assertCount(1, $this->cart->content());
        self::assertSame($b, $this->cart->get('000123'));
        self::assertSame($b, $this->cart->getByRowId($b->rowId));
        $c = $this->cart->add('000123', 'A', 1, 10, 0, ['size' => 'L']);
        $d = $this->cart->add('000123', 'A', 1, 10, 1, ['color' => 'red', 'size' => 'M']);
        self::assertCount(3, array_unique([$b->rowId, $c->rowId, $d->rowId]));
        foreach (['get', 'update', 'remove', 'addDiscountToItem', 'setTax'] as $method) {
            try {
                if ($method === 'update') $this->cart->update('000123', 8);
                elseif ($method === 'addDiscountToItem') $this->cart->addDiscountToItem('000123', 'fixed', 1);
                elseif ($method === 'setTax') $this->cart->setTax('000123', 2);
                else $this->cart->$method('000123');
                self::fail('Expected ambiguity');
            } catch (AmbiguousItemException $e) { self::assertStringContainsString('rowId', $e->getMessage()); }
        }
        self::assertSame(7, $this->cart->count());
        self::assertCount(0, $this->cart->discounts());
        $this->expectException(InvalidRowIDException::class);
        $this->cart->get('123');
    }

    public function testExactRowIdTakesPriorityOverProductCode(): void
    {
        $a = $this->cart->add('A', 'A', 1, 10);
        $b = $this->cart->add($a->rowId, 'B', 1, 20);
        self::assertSame($a, $this->cart->get($a->rowId));
        self::assertSame($b, $this->cart->getById($a->rowId));
    }

    public function testUpdateRekeysMergesAndMovesDiscounts(): void
    {
        $a = $this->cart->add('A', 'A', 1, 100, 0);
        $this->cart->addDiscountToItem('A', 'fixed', 5);
        $b = $this->cart->update('A', ['aliquot' => 1]);
        self::assertNotSame($a->rowId, $b->rowId);
        self::assertCount(1, $this->cart->content());
        self::assertEquals(95, $this->cart->summary()['bases'][1]);
        self::assertEquals(0, $this->cart->summary()['tax']);
        $this->cart->add('A', 'A', 2, 100, 0);
        $this->cart->update($b->rowId, ['aliquot' => 0]);
        self::assertCount(1, $this->cart->content());
        self::assertSame(3, $this->cart->count());
        $this->cart->update('A', ['qty' => 0, 'options' => ['new' => true]]);
        self::assertCount(0, $this->cart->content());
        self::assertCount(0, $this->cart->discounts());
    }

    public static function itemRates(): array
    {
        return [[0, 1.60, '11.60'], [1, 0, '10.00'], [2, .80, '10.80'], [3, 3.10, '13.10']];
    }

    /** @dataProvider itemRates */
    public function testItemCostRates($aliquot, $tax, $total): void
    {
        $this->cart->addCost(name: 'installation', amount: 10, mode: 'item', aliquot: $aliquot);
        self::assertSame('10.00', $this->cart->subtotal());
        self::assertEquals($tax, $this->cart->summary()['tax']);
        self::assertSame($total, $this->cart->total());
        self::assertCount(0, $this->cart->content());
        self::assertSame(0, $this->cart->count());
        self::assertEquals(10, $this->cart->totalCost());
    }

    public static function proratedCases(): array
    {
        return [[[0], [100], [15], 18.4], [[0, 0], [100, 50], [10, 5], 26.4], [[0, 1], [100, 50], [10, 5], 17.6], [[0, 2], [100, 50], [10, 5], 22.0]];
    }

    /** @dataProvider proratedCases */
    public function testProratedCosts($rates, $prices, $parts, $tax): void
    {
        $ids = [];
        foreach ($prices as $key => $price) $ids[] = $this->cart->add('P'.$key, 'P', 1, $price, $rates[$key])->rowId;
        $this->cart->addCost('freight', 15, 'prorated');
        $summary = $this->cart->summary();
        foreach ($ids as $key => $id) self::assertEquals($parts[$key], $summary['lines'][$id]['prorated']);
        self::assertEquals($tax, $summary['tax']);
        self::assertEquals(array_sum($prices) + 15, $summary['subtotal']);
        self::assertEquals(array_sum($prices) + 15 + $tax, $summary['total']);
        self::assertCount(count($prices), $this->cart->content());
        self::assertSame($summary, $this->cart->summary());
    }

    public function testRemaindersAreExactAndIndependentOfInsertionOrder(): void
    {
        self::assertSame(['a' => 334, 'b' => 333, 'c' => 333], Money::allocate(1000, ['a' => 100, 'b' => 100, 'c' => 100]));
        $reverse = Money::allocate(1000, ['c' => 100, 'b' => 100, 'a' => 100]);
        self::assertSame(334, $reverse['a']);
        foreach (['A', 'B', 'C'] as $id) $this->cart->add($id, $id, 1, 10, 1);
        $this->cart->addCost('freight', 10, 'prorated');
        self::assertSame('40.00', $this->cart->total());
        $this->cart->addDiscount('fixed', 10, 'Rebate');
        self::assertSame(1000, array_sum($this->cart->discounts()->first()['allocations']));
        self::assertSame('30.00', $this->cart->total());
    }

    public function testReferenceScenario(): void
    {
        $a = $this->cart->add('A', 'A', 1, 100, 0);
        $b = $this->cart->add('B', 'B', 1, 50, 1);
        self::assertSame('150.00', $this->cart->subtotal());
        $this->cart->addCost('freight', 15, 'prorated');
        $this->cart->addCost('installation', 20, 'item', 0);
        $this->cart->addDiscountToItem('A', 'percentage', 5, 'Línea A');
        $this->cart->addDiscount('percentage', 10, 'Pronto pago');
        $this->cart->addCost('tip', 5);
        $s = $this->cart->summary();
        self::assertEquals(['rowId' => $a->rowId, 'id' => 'A', 'aliquot' => 0, 'original' => 100, 'prorated' => 10, 'discount' => 15.95, 'base' => 94.05], $s['lines'][$a->rowId]);
        self::assertEquals(49.5, $s['lines'][$b->rowId]['base']);
        self::assertEquals([0 => 114.05, 1 => 49.5, 2 => 0, 3 => 0], $s['bases']);
        self::assertEquals(20, $s['costLines'][0]['amount']);
        self::assertEquals([5.5, 15.95], array_column($s['discounts'], 'amount'));
        self::assertEquals(21.45, $this->cart->totalDiscount());
        self::assertEquals(40, $this->cart->totalCost());
        self::assertSame('163.55', $this->cart->subtotal());
        self::assertEquals(18.25, $this->cart->tax()['GENERAL']['value']);
        self::assertEquals(5, $s['tip']);
        self::assertSame('186.80', $this->cart->total());
        self::assertSame('Se agregó propina por 5.00', $this->cart->observations()->firstWhere('type', 'tip')['text']);
        self::assertEquals(21.45, $this->cart->observations()->firstWhere('type', 'discount_total')['amount']);
        self::assertCount(2, $this->cart->content());
    }

    public static function discountCases(): array
    {
        return [['percentage', 10, 135, 14.4], ['fixed', 15, 135, 14.4], ['fixed', 500, 0, 0]];
    }

    /** @dataProvider discountCases */
    public function testDocumentDiscountMixedRates($type, $value, $base, $tax): void
    {
        $this->cart->add('A', 'A', 1, 100, 0);
        $this->cart->add('B', 'B', 1, 50, 1);
        $this->cart->addDiscount($type, $value, 'Discount');
        self::assertEquals($base, $this->cart->summary()['subtotal']);
        self::assertEquals($tax, $this->cart->summary()['tax']);
    }

    public function testLineAndGeneralDiscountsUsePhaseThenRegistrationOrder(): void
    {
        $a = $this->cart->add('A', 'A', 1, 100, 0);
        $this->cart->addDiscount('percentage', 10, 'General');
        $this->cart->addDiscountToItem($a->rowId, 'percentage', 5, 'Line');
        $this->cart->addDiscountToItem('A', 'fixed', 5, 'Line fixed');
        $this->cart->addDiscount('fixed', 1, 'General fixed');
        self::assertSame('80.00', $this->cart->subtotal());
        self::assertEquals(12.8, $this->cart->summary()['tax']);
        self::assertEquals([5, 5, 9, 1], $this->cart->discounts()->pluck('amount')->all());
    }

    public function testSessionRoundTripIsolationAndDestroy(): void
    {
        $this->cart->instance('shopping')->add('A', 'A', 1, 100);
        $this->cart->addCost('tip', 5);
        $this->cart->addDiscount('fixed', 10, 'Promotion');
        $this->cart->addObservation('Manual note');
        $payload = unserialize(serialize($this->session->all()));
        $this->session->flush(); $this->session->put($payload);
        $cart = new Cart($this->session, $this->events);
        $cart->instance('shopping');
        self::assertSame('109.40', $cart->total());
        self::assertCount(4, $cart->observations());
        $cart->instance('quotation');
        self::assertSame('0.00', $cart->total());
        self::assertCount(0, $cart->observations());
        $cart->addObservation('Quote');
        $cart->instance('shopping')->destroy();
        self::assertFalse($this->session->has('cart.shopping'));
        self::assertFalse($this->session->has('cart_metadata.shopping'));
        self::assertCount(0, $cart->costs());
        self::assertCount(0, $cart->discounts());
        self::assertCount(0, $cart->observations());
        self::assertCount(1, $cart->instance('quotation')->observations());
    }

    public function testDatabaseRoundTripAndLegacyRestore(): void
    {
        $identifier = new class implements InstanceIdentifier { public function getInstanceIdentifier($options = null) { return 'user'; } };
        $a = $this->cart->add('A', 'A', 1, 100);
        $this->cart->addCost('tip', 5);
        $this->cart->addDiscount('fixed', 10);
        $this->cart->addObservation('Stored');
        $before = $this->cart->summary();
        $this->cart->store($identifier);
        $this->cart->destroy();
        $this->cart->restore($identifier);
        self::assertSame($before, $this->cart->summary());
        self::assertCount(4, $this->cart->observations());
        self::assertSame(0, $this->db->table('shopping_cart')->count());
        $this->cart->destroy();
        $a->rowId = md5('A'.serialize([]));
        $this->db->table('shopping_cart')->insert(['identifier' => 'legacy', 'instance' => 'shopping_cart', 'content' => serialize(new Collection([$a->rowId => $a])), 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01']);
        $this->cart->restore('legacy');
        self::assertSame($a->rowId, $this->cart->get('A')->rowId);
        self::assertSame($a->rowId, $this->cart->add('A', 'A', 2, 100)->rowId);
        self::assertCount(1, $this->cart->content());
        self::assertSame(3, $this->cart->count());
        self::assertSame($a->rowId, $this->cart->update('A', ['name' => 'Renamed'])->rowId);
    }

    public function testLegacyCostAndFormattingNeverAffectArithmetic(): void
    {
        $this->cart->add('A', 'A', 1, 1000);
        $this->cart->addCost('transaction', 5);
        $this->cart->addCost('transaction', 10);
        config(['cart.format.decimal_point' => ',', 'cart.format.thousand_separator' => '.']);
        self::assertSame('1.175,00', $this->cart->total());
        self::assertSame('15,00', $this->cart->getCost('transaction'));
        self::assertEquals(1175, $this->cart->summary()['total']);
        self::assertSame('0,00', $this->cart->getCost('missing'));
    }

    public function testBuyableAndArrayAndTaxUpdate(): void
    {
        $buyable = new class implements Buyable {
            public function getBuyableIdentifier($options = null) { return 'A'; }
            public function getBuyableDescription($options = null) { return 'A'; }
            public function getBuyablePrice($options = null) { return 100; }
        };
        $item = $this->cart->add($buyable, 2, ['size' => 'M']);
        self::assertSame('M', $item->options->size);
        self::assertEquals(116, $item->priceTax);
        self::assertSame('232.00', $this->cart->total());
        $this->cart->setTax('A', 1);
        self::assertSame('200.00', $this->cart->total());
        $this->cart->add(['id' => 'B', 'name' => 'B', 'qty' => 1, 'price' => 10]);
        self::assertSame('211.60', $this->cart->total());
    }

    public static function driverCases(): array { return [['HKA', '55.87', '64.81'], ['GENERAL', '55.87', '64.81'], ['PNP', '55.86', '64.79']]; }

    /** @dataProvider driverCases */
    public function testDriverRounding($driver, $subtotal, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, 55.865, 0);
        self::assertSame($subtotal, $this->cart->subtotal());
        self::assertSame($total, $this->cart->total());
        self::assertSame(Money::cents($this->cart->summary()['subtotal']) + Money::cents($this->cart->summary()['tax']), Money::cents($this->cart->summary()['total']));
    }

    public function testEmptyProrationFailsClearly(): void
    {
        $this->cart->addCost('freight', 10, 'prorated');
        $this->expectException(DomainException::class);
        $this->cart->total();
    }

    public function testMergeKeepsMetadataAndMapsLegacyIdentities(): void
    {
        $this->cart->instance('source')->add('A', 'A', 1, 100);
        $this->cart->addDiscountToItem('A', 'fixed', 5, 'Source');
        $this->cart->addCost('tip', 3);
        $this->cart->addObservation('Source note');
        $this->cart->store('source');
        $this->cart->instance('target');
        $item = $this->cart->add('A', 'A', 1, 100);
        $oldId = $item->rowId;
        $item->rowId = md5('A'.serialize([]));
        $this->cart->content()->forget($oldId)->put($item->rowId, $item);
        $this->cart->addDiscountToItem('A', 'fixed', 1, 'Target');
        self::assertTrue($this->cart->merge('source', false, 'source'));
        self::assertCount(1, $this->cart->content());
        self::assertSame(2, $this->cart->count());
        self::assertEquals(6, $this->cart->totalDiscount());
        self::assertSame([$item->rowId, $item->rowId], $this->cart->discounts()->pluck('rowId')->all());
        self::assertSame('228.04', $this->cart->total());
        self::assertSame('target', $this->cart->currentInstance());
        self::assertCount(5, $this->cart->observations());
    }

    public function testTaxCatalogAndStandaloneTaxQuery(): void
    {
        config(['cart.taxes.0.value' => 12.5, 'cart.taxes.0.name' => 'STANDARD', 'cart.driver' => 'GENERAL', 'cart.format.decimal_point' => ',', 'cart.format.thousand_separator' => '.']);
        $this->cart->add('A', 'A', 1, 10000, 0);
        $this->cart->add('B', 'B', 1, 50, 1);
        $this->cart->addCost('installation', 20, 'item', 0);
        self::assertEquals(1252.5, $this->cart->tax()['STANDARD']['value']);
        self::assertEquals(0, $this->cart->tax()['EXEMPT']['value']);
        self::assertEquals(1250, $this->cart->totalTaxes($this->cart->content(), [])['STANDARD']['value']);
        self::assertSame('11.322,50', $this->cart->total());
    }

    public function testTipAloneAndAfterProductsNeverGeneratesTax(): void
    {
        $this->cart->addCost('tip', 5);
        self::assertSame('5.00', $this->cart->total());
        self::assertSame('0.00', $this->cart->subtotal());
        self::assertEquals(0, $this->cart->summary()['tax']);
        self::assertCount(0, $this->cart->content());
        $this->cart->add('A', 'A', 1, 100, 0);
        self::assertSame('121.00', $this->cart->total());
        self::assertEquals(16, $this->cart->summary()['tax']);
        $this->cart->addCost('tip', 2);
        self::assertCount(2, $this->cart->observations());
        self::assertSame('123.00', $this->cart->total());
    }

    public function testRepeatedAddsRetainLineDiscountAndFractionalQuantities(): void
    {
        $item = $this->cart->add('A', 'A', .5, 100, 0);
        $this->cart->addDiscountToItem('A', 'percentage', 10);
        $again = $this->cart->add('A', 'A', 1.5, 100, 0);
        self::assertSame($item->rowId, $again->rowId);
        self::assertEquals(2, $again->qty);
        self::assertSame('180.00', $this->cart->subtotal());
        self::assertEquals(28.8, $this->cart->summary()['tax']);
        self::assertCount(1, $this->cart->discounts());
    }

    public function testLargeIntegerAllocationAndZeroWeights(): void
    {
        $parts = Money::allocate(100000000000, ['a' => 100000000000, 'b' => 100000000000, 'c' => 100000000000, 'z' => 0]);
        self::assertSame(['a' => 33333333334, 'b' => 33333333333, 'c' => 33333333333, 'z' => 0], $parts);
        self::assertSame(100000000000, array_sum($parts));
        self::assertSame(29, Money::cents(.29, true));
        self::assertSame(5587, Money::cents(55.865));
        self::assertSame(5586, Money::cents(55.865, true));
    }

    public static function invalidCosts(): array
    {
        return [['tip', 5, 'item', 0], ['tip', 5, 'prorated', null], ['freight', -1, null, null], ['installation', 10, 'item', null], ['installation', 10, 'item', 99], ['freight', INF, null, null], ['freight', 10, 'bad', null]];
    }

    /** @dataProvider invalidCosts */
    public function testInvalidCostsDoNotMutate($name, $amount, $mode, $aliquot): void
    {
        try { $this->cart->addCost($name, $amount, $mode, $aliquot); self::fail('Expected invalid cost'); }
        catch (InvalidArgumentException $e) { self::assertCount(0, $this->cart->costs()); }
    }

    public static function invalidDiscounts(): array { return [['percentage', 101], ['percentage', -1], ['fixed', NAN], ['bad', 1]]; }

    /** @dataProvider invalidDiscounts */
    public function testInvalidDiscountsDoNotMutate($type, $value): void
    {
        try { $this->cart->addDiscount($type, $value); self::fail('Expected invalid discount'); }
        catch (InvalidArgumentException $e) { self::assertCount(0, $this->cart->discounts()); }
    }

    public function testRestoreKeepsExistingMetadataAndOtherInstances(): void
    {
        $this->cart->add('A', 'A', 1, 100);
        $this->cart->addCost('tip', 5);
        $this->cart->store('saved');
        $this->cart->destroy();
        $this->cart->instance('other')->addObservation('Other');
        $this->cart->instance()->addObservation('Existing');
        $this->cart->restore('saved');
        self::assertSame('121.00', $this->cart->total());
        self::assertCount(2, $this->cart->observations());
        self::assertCount(1, $this->cart->instance('other')->observations());
    }

    public function testProratedCostCannotHaveAliquot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cart->addCost('freight', 10, 'prorated', 0);
    }

    public function testLegacyNullAliquotIsNormalizedWithoutChangingRowId(): void
    {
        $item = $this->cart->add('A', 'A', 1, 100);
        $this->cart->content()->forget($item->rowId);
        $item->rowId = md5('A'.serialize([]));
        $item->aliquot = null;
        $item->priceTax = null;
        $this->cart->content()->put($item->rowId, $item);
        self::assertSame('116.00', $this->cart->total());
        self::assertSame($item->rowId, $this->cart->add('A', 'A', 1, 100)->rowId);
        self::assertCount(1, $this->cart->content());
        self::assertSame('232.00', $this->cart->total());
    }

    public function testSessionHandlerPersistsAcrossNewManagers(): void
    {
        $handler = new ArraySessionHandler(120);
        $first = new SessionManager(app());
        $first->extend('array', function () use ($handler) { return $handler; });
        $first->start();
        $cart = new Cart($first, $this->events);
        $cart->add('A', 'A', 1, 100);
        $cart->addCost('tip', 5);
        $cart->addDiscount('fixed', 10);
        $cart->addObservation('Persistent');
        $first->save();
        $second = new SessionManager(app());
        $second->extend('array', function () use ($handler) { return $handler; });
        $second->setId($first->getId());
        $second->start();
        $restored = new Cart($second, $this->events);
        self::assertSame('109.40', $restored->total());
        self::assertCount(4, $restored->observations());
        self::assertCount(1, $restored->discounts());
    }

    public function testMissingAndDuplicateStoredCarts(): void
    {
        self::assertFalse($this->cart->merge('missing'));
        $this->cart->restore('missing');
        self::assertSame('0.00', $this->cart->total());
        $this->cart->store('one');
        $this->expectException(\JeleDev\Shoppingcart\Exceptions\CartAlreadyStoredException::class);
        $this->cart->store('one');
    }

    public function testInvalidUpdateLeavesOriginalItemIntact(): void
    {
        $item = $this->cart->add('A', 'A', 1, 100);
        try { $this->cart->update('A', ['price' => -1]); self::fail('Invalid update expected'); }
        catch (InvalidArgumentException $e) {
            self::assertSame($item, $this->cart->get('A'));
            self::assertSame('116.00', $this->cart->total());
        }
    }
}
