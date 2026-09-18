<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use JeleDev\Shoppingcart\Cart;
use JeleDev\Shoppingcart\CartItem;
use JeleDev\Shoppingcart\Money;
use JeleDev\Shoppingcart\FiscalCalculator;
use PHPUnit\Framework\TestCase;

class MonetaryPrecisionTest extends TestCase
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

    public static function precisions(): array
    {
        return [[0, 1, 1], [2, 123, 123], [3, 1235, 1234], [4, 12345, 12349]];
    }

    /** @dataProvider precisions */
    public function testMoneyPrecisionAndPresentation($decimals, $rounded, $truncated): void
    {
        config(['cart.format.decimals' => $decimals, 'cart.driver' => 'GENERAL']);
        self::assertSame($rounded, Money::minorUnits('1.2345'));
        self::assertSame($truncated, Money::minorUnits('1.2349', true));
        self::assertSame(-$truncated, Money::minorUnits('-1.2349', true));
        self::assertSame(-$rounded, Money::minorUnits('-1.2345'));
        self::assertSame($rounded, Money::cents('1.2345'));
        $item = $this->cart->add('A', 'A', 1, 1.2345, 1);
        $expected = number_format($rounded / Money::scale(), $decimals, '.', '');
        self::assertSame($expected, $item->subtotal);
        self::assertSame($expected, $item->subtotal());
        self::assertSame($expected, $this->cart->total());
        self::assertSame($rounded, Money::minorUnits($this->cart->summary()['subtotal']));
    }

    public static function invalidPrecisions(): array
    {
        return [[-1], [5], [1000], [2.5], ['3'], [true], [null]];
    }

    /** @dataProvider invalidPrecisions */
    public function testInvalidPrecisionRejectedBeforeMutation($decimals): void
    {
        config(['cart.format.decimals' => $decimals]);
        try { $this->cart->addCost('tip', 1); self::fail('Invalid precision accepted'); }
        catch (InvalidArgumentException $e) { self::assertFalse($this->session->has('cart_metadata.shopping_cart')); }
    }

    public function testMoneyBoundsAndIntegerRescaling(): void
    {
        self::assertSame(1230, Money::rescale(123, 2, 3));
        self::assertSame(124, Money::rescale(1235, 3, 2));
        self::assertSame(-124, Money::rescale(-1235, 3, 2));
        self::assertSame(['a' => 334, 'b' => 333, 'c' => 333], Money::allocate(1000, ['a' => 1, 'b' => 1, 'c' => 1]));
        config(['cart.format.decimals' => 4]);
        self::assertSame(10000000000000, Money::minorUnits(1000000000));
        $this->expectException(InvalidArgumentException::class);
        Money::rescale(PHP_INT_MAX, 0, 4);
    }

    public function testGeneralLineTaxAndUnitPriceRemainDistinct(): void
    {
        config(['cart.driver' => 'GENERAL']);
        $item = $this->cart->add('A', 'A', 2, 10.23, 0);
        self::assertSame('20.46', $item->subtotal);
        self::assertSame(327, Money::minorUnits($item->tax));
        self::assertSame(164, Money::minorUnits($item->unitTax));
        self::assertSame(1187, Money::minorUnits($item->priceTax));
        self::assertSame('3.27', $item->taxTotal);
        self::assertSame('3.27', $item->taxTotal());
        self::assertSame('23.73', $item->total);
        self::assertSame('23.73', $item->total());
        self::assertSame(327, Money::minorUnits($item->toArray()['tax']));
        self::assertSame(327, Money::minorUnits(json_decode($item->toJson(), true)['tax']));
        self::assertSame(327, Money::minorUnits(json_decode(json_encode($item), true)['tax']));
        $this->assertTaxSums();
    }

    public function testHkaRequiredExampleAndAllLookupSerializations(): void
    {
        $a = $this->cart->add(26411, 'A', 3, .34, 0);
        $b = $this->cart->add(21766, 'B', 1, .89, 0);
        $luxury = $this->cart->add('luxury', 'Luxury', 1, 16.68, 3);
        self::assertSame(517, Money::minorUnits($luxury->tax));
        self::assertSame(517, Money::minorUnits($this->cart->tax()['LUXURY']['value']));
        self::assertSame(17, Money::minorUnits($a->tax)); // La referencia anterior a add(B) se actualiza derivadamente.
        self::assertSame(14, Money::minorUnits($b->tax));
        self::assertSame('1.02', $a->subtotal);
        self::assertSame(.34, $a->price);
        self::assertSame('1.19', $a->total);
        foreach ([$this->cart->get(26411), $this->cart->getById(26411), $this->cart->getByRowId($a->rowId), $this->cart->content()->get($a->rowId)] as $item) {
            self::assertSame(17, Money::minorUnits($item->toArray()['tax']));
            self::assertSame(17, Money::minorUnits(json_decode($item->toJson(), true)['tax']));
        }
        self::assertSame(31, Money::minorUnits($this->cart->tax()['GENERAL']['value']));
        $this->assertTaxSums();
    }

    public function testPnpTaxesRawWholeRowAndNeverGroupedBase(): void
    {
        config(['cart.driver' => 'PNP']);
        $a = $this->cart->add('A', 'A', 1, .04, 0);
        $b = $this->cart->add('B', 'B', 1, .04, 0);
        self::assertSame(0, Money::minorUnits($a->tax));
        self::assertSame(0, Money::minorUnits($b->tax));
        self::assertSame(0, Money::minorUnits($this->cart->tax()['GENERAL']['value']));
        $this->assertTaxSums();
        $this->cart->destroy();
        // 0.069 * 16% -> 0.01; truncar primero la base a .06 daría .00.
        $item = $this->cart->add('raw', 'Raw', 3, .023, 0);
        self::assertSame('0.06', $item->subtotal);
        self::assertSame(1, Money::minorUnits($item->tax));
        self::assertSame(1, Money::minorUnits($this->cart->summary()['tax']));
        $this->assertTaxSums();
    }

    public static function hkaResidues(): array
    {
        $cases = [];
        foreach ([2, 3] as $decimals) {
            $scale = 10 ** $decimals;
            $cases['positivo multiunidad '.$decimals] = [$decimals, 3 / $scale, [10, ...array_fill(0, 19, 0)]];
            $cases['negativo un receptor '.$decimals] = [$decimals, 10 / $scale, [0, 2, 2, 2, 2]];
            $cases['negativo unitario '.$decimals] = [$decimals, 4 / $scale, [0, 1]];
            $cases['negativo varios receptores '.$decimals] = [$decimals, 4 / $scale, [0, 0, 0, 0, 1, 1, 1, 1, 1, 1]];
        }
        return $cases;
    }

    /** @dataProvider hkaResidues */
    public function testHkaSignedResidueAndLexicalTieBreak($decimals, $price, $distribution): void
    {
        config(['cart.driver' => 'HKA', 'cart.format.decimals' => $decimals]);
        $count = count($distribution);
        $expected = null;
        foreach ([range(1, $count), array_reverse(range(1, $count))] as $order) {
            $this->cart->destroy();
            foreach ($order as $id) $this->cart->add('P'.$id, 'P', 1, $price, 0);
            $map = $this->cart->content()->map(function ($item) { return Money::minorUnits($item->tax); })->all();
            ksort($map, SORT_STRING);
            self::assertSame($distribution, array_values($map));
            self::assertSame(array_sum($distribution), array_sum($map));
            if ($expected !== null) self::assertSame($expected, $map);
            $expected = $map;
            $this->assertTaxSums();
        }
    }

    public function testHkaBaseTieBreakAndIndependentAliquots(): void
    {
        foreach ([0, 1, 2, 3] as $aliquot) {
            // Productos de las cuatro categorías; no se pueden mezclar residuos.
            $this->cart->add('A'.$aliquot, 'A', 1, .03, $aliquot);
            $this->cart->add('B'.$aliquot, 'B', 1, .08, $aliquot);
            $this->cart->add('C'.$aliquot, 'C', 1, .09, $aliquot);
        }
        $this->assertTaxSums();
        foreach ($this->cart->content()->where('aliquot', 1) as $item) self::assertSame(0, Money::minorUnits($item->tax));
        $this->cart->destroy();
        // .07 y .08: ambos provisionales .01; agrupado .02, sin residuo.
        // Agregar .03 hace agrupado .03; B gana por base .08 frente a .07.
        $this->cart->add('A', 'A', 1, .07, 0);
        $b = $this->cart->add('B', 'B', 1, .08, 0);
        $this->cart->add('C', 'C', 1, .03, 0);
        self::assertSame(2, Money::minorUnits($b->tax));
        $this->assertTaxSums();
    }

    public function testHkaMixedResiduesAndNegativePriorityAtTwoAndThreeDecimals(): void
    {
        foreach ([2, 3] as $decimals) {
            config(['cart.driver' => 'HKA', 'cart.format.decimals' => $decimals]);
            $scale = Money::scale();
            $expected = null;
            $rows = [
                ['largest-tax', 10, 0], ['largest-tied-base', 9, 0],
                ['reduced-a', 3, 2], ['reduced-b', 4, 2],
                ['luxury', 1668, 3], ['exempt', 999, 1],
            ];
            for ($i = 0; $i < 8; $i++) $rows[] = ['small-'.$i, 4, 0];
            foreach ([$rows, array_reverse($rows)] as $order) {
                $this->cart->destroy();
                foreach ($order as [$id, $base, $aliquot]) $this->cart->add($id, $id, 1, $base / $scale, $aliquot);
                // GENERAL: provisional 2 + 1 + 8 = 11; fiscal 8; agota los dos primeros.
                self::assertSame(0, Money::minorUnits($this->cart->getById('largest-tax')->tax));
                self::assertSame(0, Money::minorUnits($this->cart->getById('largest-tied-base')->tax));
                for ($i = 0; $i < 8; $i++) self::assertSame(1, Money::minorUnits($this->cart->getById('small-'.$i)->tax));
                self::assertSame(0, Money::minorUnits($this->cart->getById('reduced-a')->tax));
                self::assertSame(1, Money::minorUnits($this->cart->getById('reduced-b')->tax));
                self::assertSame(517, Money::minorUnits($this->cart->getById('luxury')->tax));
                self::assertSame(0, Money::minorUnits($this->cart->getById('exempt')->tax));
                $this->assertTaxSums();
                $map = $this->cart->content()->map(function ($item) { return Money::minorUnits($item->tax); })->all();
                ksort($map, SORT_STRING);
                if ($expected !== null) self::assertSame($expected, $map);
                $expected = $map;
            }
        }
    }

    public static function drivers(): array { return [['GENERAL'], ['HKA'], ['PNP']]; }

    /** @dataProvider drivers */
    public function testThreeDecimalStrategiesAndAllAdjustments($driver): void
    {
        config(['cart.driver' => $driver, 'cart.format.decimals' => 3]);
        $this->cart->add('A', 'A', 1, .009, 0);
        $this->cart->add('B', 'B', 1, .007, 0);
        $original = $this->cart->content()->toArray();
        $this->assertTaxSums();
        $this->cart->addCost('freight', .002, 'prorated');
        $this->cart->addDiscountToItem('A', 'percentage', 20, 'Línea');
        $this->cart->addDiscount('fixed', .010, 'Documento');
        $this->cart->addCost('installation', .003, 'item', 0);
        $this->cart->addCost('tip', .005);
        $this->cart->addCost('legacy', .002);
        $summary = $this->cart->summary();
        self::assertSame(9, Money::minorUnits($summary['subtotal']));
        self::assertSame($driver === 'HKA' ? 1 : 0, Money::minorUnits($summary['tax']));
        self::assertSame($driver === 'HKA' ? 17 : 16, Money::minorUnits($summary['total']));
        self::assertSame(12, Money::minorUnits($this->cart->totalDiscount()));
        self::assertSame(12, Money::minorUnits($this->cart->totalCost()));
        self::assertSame([2, 10], array_column($summary['discounts'], 'cents'));
        self::assertSame(10, array_sum($summary['discounts'][1]['allocations']));
        self::assertSame('0.003', $this->cart->getCost('installation'));
        self::assertSame('Se agregó propina por 0.005', $this->cart->observations()->firstWhere('type', 'tip')['text']);
        self::assertSame($original, $this->cart->content()->toArray());
        $this->assertTaxSums();
    }

    public function testThreeDecimalDriversHaveThreeDifferentTotals(): void
    {
        // Provisionales HALF_UP: .001 + .001 + .001 = .003; agrupado .015*.16=.0024 -> .002.
        foreach (['A', 'B', 'C'] as $id) $this->cart->add($id, $id, 1, .005, 0);
        config(['cart.format.decimals' => 3]);
        foreach (['GENERAL' => 3, 'HKA' => 2, 'PNP' => 0] as $driver => $tax) {
            config(['cart.driver' => $driver]);
            self::assertSame($tax, Money::minorUnits($this->cart->summary()['tax']));
            $this->assertTaxSums();
        }
    }

    public function testEveryTaxCategoryReconcilesItsOwnResidue(): void
    {
        foreach ([0 => .03, 1 => .03, 2 => .07, 3 => .02] as $aliquot => $price) {
            $a = $this->cart->add('A'.$aliquot, 'A', 1, $price, $aliquot);
            $b = $this->cart->add('B'.$aliquot, 'B', 1, $price, $aliquot);
            $keys = [$a->rowId, $b->rowId]; sort($keys, SORT_STRING);
            $taxes = array_map(function ($key) { return Money::minorUnits($this->cart->getByRowId($key)->tax); }, $keys);
            self::assertSame($aliquot === 0 ? [1, 0] : ($aliquot === 1 ? [0, 0] : [0, 1]), $taxes);
        }
        $this->assertTaxSums();
    }

    public function testUnitPriceTaxTracksConfigurationWithoutUsingLineTax(): void
    {
        $item = $this->cart->add('A', 'A', 3, .34, 0);
        self::assertTrue(isset($item->priceTax));
        config(['cart.format.decimals' => 3]);
        self::assertSame(394, Money::minorUnits($item->priceTax));
        self::assertSame('0.394', $item->priceTax());
        config(['cart.taxes.0.value' => 20]);
        self::assertSame('0.408', $item->priceTax());
        self::assertSame(204, Money::minorUnits($item->tax));
    }

    public function testDerivedTaxesDoNotChangeSessionAndReactToMutations(): void
    {
        $a = $this->cart->add('A', 'A', 3, .34, 0, ['color' => 'red']);
        $this->cart->add('B', 'B', 1, .89, 0);
        $before = serialize($this->session->all());
        $identity = $a->identity();
        $this->assertTaxSums();
        $this->cart->content()->toJson();
        $this->cart->get('A')->toArray();
        self::assertSame($before, serialize($this->session->all()));
        self::assertSame($identity, $a->identity());
        self::assertSame(17, Money::minorUnits($a->tax));
        config(['cart.driver' => 'GENERAL']);
        self::assertSame(16, Money::minorUnits($a->tax));
        config(['cart.format.decimals' => 3]);
        self::assertSame(163, Money::minorUnits($a->tax));
        config(['cart.taxes.0.value' => 20]);
        self::assertSame(204, Money::minorUnits($a->tax));
        config(['cart.driver' => 'HKA', 'cart.format.decimals' => 2, 'cart.taxes.0.value' => 16]);
        $this->cart->remove('B');
        self::assertSame(16, Money::minorUnits($a->tax));
        $this->cart->update('A', 6);
        self::assertSame(33, Money::minorUnits($this->cart->get('A')->tax));
        $this->cart->setTax('A', 1);
        self::assertSame(0, Money::minorUnits($this->cart->get('A')->tax));
        $this->assertTaxSums();
        $payload = unserialize(serialize($this->session->all()));
        $this->session->flush(); $this->session->put($payload);
        $cart = new Cart($this->session, $this->events);
        self::assertSame(0, Money::minorUnits($cart->get('A')->tax));
    }

    public function testActiveMetadataPrecisionChangePreservesAmounts(): void
    {
        $this->cart->add('A', 'A', 1, 10, 1);
        $this->cart->addCost('tip', 1.23);
        $this->cart->addDiscount('fixed', 1.23);
        config(['cart.format.decimals' => 3]);
        self::assertSame(1230, $this->cart->costs()->first()['cents']);
        self::assertSame(1230, Money::minorUnits($this->cart->totalDiscount()));
        $this->cart->addCost('tip', .005);
        self::assertSame(3, $this->session->get('cart_metadata.shopping_cart')['decimals']);
        self::assertSame(1235, Money::minorUnits($this->cart->totalCost()));
        self::assertSame('10.005', $this->cart->total());
        config(['cart.format.decimals' => 2]);
        // HALF_UP por operación: 1.23 + .005 -> 1.23 + .01.
        self::assertSame(124, Money::minorUnits($this->cart->totalCost()));
        self::assertSame('10.01', $this->cart->total());
    }

    public static function snapshotPrecisions(): array
    {
        return [[2, 2], [2, 3], [3, 3], [3, 2]];
    }

    /** @dataProvider snapshotPrecisions */
    public function testV3StoreRestoreAndMergeAtDifferentPrecisions($source, $target): void
    {
        config(['cart.format.decimals' => $source]);
        $this->cart->add('A', 'A', 3, .34, 0);
        $this->cart->add('B', 'B', 1, .89, 0);
        $this->cart->addCost('tip', 1.235);
        $this->cart->addDiscount('fixed', .125);
        self::assertSame(.125, $this->cart->discounts()->first()['value']);
        $this->assertTaxSums();
        $this->cart->store('saved');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        self::assertSame(3, $snapshot['version']);
        self::assertSame($source, $snapshot['decimals']);
        self::assertStringNotContainsString('taxResolver', serialize($snapshot));
        $this->cart->store('merge-source');
        $this->cart->destroy();
        config(['cart.format.decimals' => $target]);
        $this->cart->restore('saved');
        $expectedCost = Money::rescale(Money::minorUnits(1.235, false, $source), $source, $target);
        self::assertSame($expectedCost, $this->cart->costs()->first()['cents']);
        $expectedDiscount = Money::rescale(Money::minorUnits(.125, false, $source), $source, $target);
        self::assertSame($expectedDiscount, Money::minorUnits($this->cart->totalDiscount()));
        $this->assertTaxSums();
        $summary = $this->cart->summary();
        $this->cart->destroy();
        self::assertTrue($this->cart->merge('merge-source', false));
        self::assertSame($summary, $this->cart->summary());
        self::assertSame(1, $this->db->table('shopping_cart')->count());
        $this->assertTaxSums();
    }

    public static function oldSnapshots(): array
    {
        return [['legacy', 'restore'], ['legacy', 'merge'], ['v2', 'restore'], ['v2', 'merge']];
    }

    public function testLegacySessionMetadataAndRequestedFixedDiscountSurviveConversion(): void
    {
        $this->cart->add('A', 'A', 1, 10, 1);
        $this->session->put('cart_metadata.shopping_cart', [
            'costs' => [['name' => 'tip', 'amount' => 1.23, 'cents' => 123, 'mode' => 'tip', 'aliquot' => null, 'description' => 'Tip']],
            'discounts' => [['rowId' => null, 'type' => 'fixed', 'value' => .125, 'concept' => 'Original']],
            'observations' => [],
        ]);
        $before = serialize($this->session->all());
        config(['cart.format.decimals' => 3]);
        self::assertSame(1230, $this->cart->costs()->first()['cents']);
        self::assertSame(130, Money::minorUnits($this->cart->totalDiscount()));
        self::assertSame(.125, $this->cart->discounts()->first()['value']);
        self::assertSame($before, serialize($this->session->all()));
        $this->cart->addObservation('Migration on write');
        self::assertSame(3, $this->session->get('cart_metadata.shopping_cart')['decimals']);
        self::assertSame(130, $this->cart->discounts()->first()['fixedUnits']);
    }

    public function testUnknownSnapshotVersionDoesNotMutateOrConsumeState(): void
    {
        $this->cart->add('A', 'A', 1, 10);
        $before = serialize($this->session->all());
        $this->db->table('shopping_cart')->insert(['identifier' => 'future', 'instance' => 'shopping_cart',
            'content' => serialize(['version' => 99, 'content' => new Collection(), 'metadata' => []]),
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01']);
        foreach (['restore', 'merge'] as $operation) {
            try { $this->cart->$operation('future'); self::fail('Unknown version accepted'); }
            catch (InvalidArgumentException $e) {
                self::assertSame($before, serialize($this->session->all()));
                self::assertSame(1, $this->db->table('shopping_cart')->count());
            }
        }
    }

    /** @dataProvider oldSnapshots */
    public function testOldSnapshotsHaveHistoricalTwoDecimalScale($version, $operation): void
    {
        $item = $this->cart->add('A', 'A', 1, 10, 1);
        // Formato O histórico, incluidas propiedades privadas con nombre mangled.
        $attributes = ['rowId' => $item->rowId, 'id' => 'A', 'name' => 'A', 'qty' => 1, 'price' => 10.0,
            'priceTax' => null, 'aliquot' => 1, 'options' => $item->options,
            "\0JeleDev\Shoppingcart\CartItem\0taxRate" => 0,
            "\0JeleDev\Shoppingcart\CartItem\0associatedModel" => null];
        $body = '';
        foreach ($attributes as $key => $value) $body .= serialize($key) . serialize($value);
        $class = CartItem::class;
        $oldItem = unserialize('O:'.strlen($class).':"'.$class.'":'.count($attributes).':{'.$body.'}');
        $content = new Collection([$item->rowId => $oldItem]);
        $payload = $version === 'legacy' ? $content : ['version' => 2, 'content' => $content, 'metadata' => [
            'costs' => [['name' => 'tip', 'amount' => 1.23, 'cents' => 123, 'mode' => 'tip', 'aliquot' => null, 'description' => 'Tip']],
            'discounts' => [['rowId' => null, 'type' => 'fixed', 'value' => .12, 'concept' => 'Saved']],
            'observations' => [],
        ]];
        $this->db->table('shopping_cart')->insert(['identifier' => 'old', 'instance' => 'shopping_cart',
            'content' => serialize($payload), 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01']);
        $this->cart->destroy();
        config(['cart.format.decimals' => 3]);
        $this->cart->$operation('old');
        self::assertSame(10000, Money::minorUnits($this->cart->get('A')->subtotal));
        self::assertSame($version === 'v2' ? 1230 : 0, Money::minorUnits($this->cart->totalCost()));
        self::assertSame($version === 'v2' ? 120 : 0, Money::minorUnits($this->cart->totalDiscount()));
        self::assertSame($version === 'v2' ? '11.110' : '10.000', $this->cart->total());
        $this->assertTaxSums();
    }

    /** Comprueba cada alícuota en JSON, toArray, tax y totalTaxes de productos originales. */
    public static function invalidTaxRates(): array
    {
        return [[-1], [-.01], ['-16'], [NAN], [INF], [-INF], ['abc'], [null], [true], [[]], ['-1e-9999']];
    }

    /** @dataProvider invalidTaxRates */
    public function testInvalidTaxRatesRejectEveryFiscalRouteWithoutMutation($rate): void
    {
        $item = $this->cart->add('A', 'A', 2, 10.23, 0, ['color' => 'blue']);
        $this->cart->addDiscountToItem('A', 'percentage', 10, 'Discount');
        $this->cart->addCost('freight', 1, 'prorated');
        $this->cart->store('tax-validation');
        $beforeItem = serialize($item);
        $beforeSession = serialize($this->session->all());
        $beforeSnapshot = serialize($this->db->table('shopping_cart')->get()->all());
        config(['cart.taxes.0.value' => $rate]);
        foreach (['GENERAL', 'PNP', 'HKA'] as $driver) {
            config(['cart.driver' => $driver]);
            $routes = [
                fn () => CartItem::calculateTaxes(10, $rate),
                fn () => FiscalCalculator::calculate(['a' => ['base' => 1000, 'rawBase' => 10, 'aliquot' => 0]], $driver),
                fn () => $item->setTaxRate(2),
                fn () => $item->getTaxRate(0),
                fn () => $item->tax,
                fn () => $item->unitTax,
                fn () => $item->toArray(),
                fn () => $this->cart->summary(),
                fn () => $this->cart->totalTaxes($this->cart->content(), []),
                fn () => $this->cart->addCost('invalid', 1, 'item', 0),
                fn () => $item->updateFromArray(['qty' => 5, 'price' => 20, 'aliquot' => 2]),
            ];
            foreach ($routes as $route) {
                try {
                    $route();
                    self::fail('Invalid tax rate accepted by '.$driver);
                } catch (InvalidArgumentException $e) {
                    self::assertSame('Invalid tax rate: tax rates must be finite, numeric and nonnegative.', $e->getMessage());
                }
                self::assertSame($beforeItem, serialize($item));
                self::assertSame($beforeSession, serialize($this->session->all()));
                self::assertSame($beforeSnapshot, serialize($this->db->table('shopping_cart')->get()->all()));
            }
        }
    }

    public static function validTaxRates(): array
    {
        return [[0], [8], [16], [31], [150], ['16.0000']];
    }

    /** @dataProvider validTaxRates */
    public function testValidTaxRatesAcrossAllDrivers($rate): void
    {
        config(['cart.taxes.0.value' => $rate]);
        foreach (['GENERAL', 'PNP', 'HKA'] as $driver) {
            config(['cart.driver' => $driver]);
            $this->cart->destroy();
            $item = $this->cart->add('A', 'A', 1, 100, 0);
            self::assertSame($rate, $item->getTaxRate(0));
            self::assertSame(Money::minorUnits($rate), Money::minorUnits($item->tax));
            self::assertSame(Money::minorUnits($rate), Money::minorUnits(CartItem::calculateTaxes(100, $rate)));
            self::assertSame(Money::minorUnits($rate), Money::minorUnits($this->cart->summary()['tax']));
            $this->assertTaxSums();
        }
    }

    public static function malformedTaxCatalogs(): array
    {
        return [[null], ['abc'], [16], [[null]], [[['name' => 'GENERAL']]],
            [[['value' => 16]]], [[['name' => [], 'value' => 16]]], [[['name' => '', 'value' => 16]]]];
    }

    /** @dataProvider malformedTaxCatalogs */
    public function testMalformedTaxCatalogIsRejectedWithoutWarningsOrMutation($catalog): void
    {
        $item = $this->cart->add('A', 'A', 1, 10, 0);
        $before = serialize($this->session->all());
        config(['cart.taxes' => $catalog]);
        foreach ([fn () => FiscalCalculator::calculate([], 'HKA'), fn () => $this->cart->summary(),
            fn () => $item->setTaxRate(2), fn () => new CartItem('B', 'B', 1, 0),
            fn () => $this->cart->addCost('invalid', 1, 'item', 0)] as $route) {
            try { $route(); self::fail('Malformed tax catalog accepted'); }
            catch (InvalidArgumentException $e) { self::assertStringContainsString('Invalid tax configuration:', $e->getMessage()); }
            self::assertSame($before, serialize($this->session->all()));
        }
    }

    private function assertTaxSums(): void
    {
        $content = $this->cart->content();
        $taxes = $this->cart->totalTaxes($content, []);
        $json = json_decode(json_encode($content, JSON_THROW_ON_ERROR), true);
        foreach (config('cart.taxes') as $aliquot => $rate) {
            $units = 0;
            foreach ($content as $key => $item) {
                if ($item->aliquot != $aliquot) continue;
                $value = Money::minorUnits($item->tax);
                self::assertGreaterThanOrEqual(0, $value);
                self::assertSame($value, Money::minorUnits($item->toArray()['tax']));
                self::assertSame($value, Money::minorUnits(json_decode($item->toJson(), true)['tax']));
                self::assertSame($value, Money::minorUnits($json[$key]['tax']));
                $units += $value;
            }
            self::assertSame(Money::minorUnits($taxes[$rate['name']]['value']), $units);
        }
    }
}
