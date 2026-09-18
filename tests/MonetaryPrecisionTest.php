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

    public static function invalidCatalogs(): array
    {
        $catalog = [0 => ['name' => 'GENERAL', 'value' => 16], 1 => ['name' => 'EXEMPT', 'value' => 0],
            2 => ['name' => 'REDUCED', 'value' => 8], 3 => ['name' => 'LUXURY', 'value' => 31]];
        $cases = ['empty' => [[]]];
        foreach ([0, 1, 2, 3] as $key) {
            $invalid = $catalog;
            unset($invalid[$key]);
            $cases['missing '.$key] = [$invalid];
        }
        foreach ([4, 99, '03'] as $key) $cases['extra '.$key] = [$catalog + [$key => ['name' => 'EXTRA', 'value' => 10]]];
        foreach (['GENERAL', 'general', ' GENERAL ', '', '   ', null, 16, []] as $index => $name) {
            $invalid = $catalog;
            $invalid[2]['name'] = $name;
            $cases['name '.$index] = [$invalid];
        }
        $invalid = $catalog;
        $invalid[0]['name'] = 'ÁLICUOTA';
        $invalid[2]['name'] = 'álicuota';
        $cases['unicode duplicate'] = [$invalid];
        foreach (['name', 'value'] as $field) {
            $invalid = $catalog;
            unset($invalid[3][$field]);
            $cases['missing field '.$field] = [$invalid];
        }
        $invalid = $catalog;
        $invalid[3] = null;
        $cases['invalid entry'] = [$invalid];
        return $cases;
    }

    /** @dataProvider invalidCatalogs */
    public function testCatalogIntegrityBeforeQueriesAndWrites($catalog): void
    {
        $item = $this->cart->add('A', 'A', 1, 10, 3);
        $this->cart->addCost('service', 1, 'item', 3);
        $before = serialize($this->session->all());
        config(['cart.taxes' => $catalog]);
        foreach (['GENERAL', 'PNP', 'HKA'] as $driver) {
            config(['cart.driver' => $driver]);
            foreach ([fn () => FiscalCalculator::taxCatalog(), fn () => $this->cart->summary(),
                fn () => $this->cart->totalTaxes(new Collection([$item]), []), fn () => $item->toArray(),
                fn () => $this->cart->add('B', 'B', 1, 1, 0), fn () => $this->cart->update($item->rowId, ['qty' => 2]),
                fn () => $this->cart->setTax($item->rowId, 0), fn () => $this->cart->addCost('invalid', 1, 'item', 3)] as $route) {
                try { $route(); self::fail('Invalid catalog accepted'); }
                catch (InvalidArgumentException $e) { self::assertStringContainsString('Invalid tax configuration:', $e->getMessage()); }
                self::assertSame($before, serialize($this->session->all()));
            }
        }
    }

    public function testFourAliquotsAcceptCanonicalStringKeysAndKeepConfiguredNames(): void
    {
        $catalog = ['3' => ['name' => ' Luxury custom ', 'value' => 150], '2' => ['name' => 'Reducido', 'value' => 8],
            '1' => ['name' => 'Exento', 'value' => 0], '0' => ['name' => 'General custom', 'value' => '16.0000']];
        config(['cart.taxes' => $catalog]);
        self::assertSame($catalog, FiscalCalculator::taxCatalog());
        foreach (['GENERAL', 'PNP', 'HKA'] as $driver) {
            $lines = [];
            foreach (['0', '1', '2', '3'] as $aliquot) {
                self::assertSame($catalog[$aliquot]['value'], FiscalCalculator::taxRate($aliquot));
                $lines['line'.$aliquot] = ['aliquot' => $aliquot, 'base' => 10000, 'rawBase' => 100];
            }
            $result = FiscalCalculator::calculate($lines, $driver);
            self::assertSame(4, count($result['lineTaxes']));
            self::assertSame(array_column($catalog, 'name'), array_keys($result['taxes']));
            foreach ($catalog as $key => $tax) self::assertSame(Money::minorUnits($tax['value']), $result['lineTaxes']['line'.$key]);
        }
        self::assertSame($catalog, config('cart.taxes'));
    }

    public static function invalidFiscalLines(): array
    {
        $cases = [];
        foreach ([9, -1, '03', '3.0', false, null, [], 'unknown'] as $key => $aliquot) {
            $cases['aliquot '.$key] = [['aliquot' => $aliquot, 'base' => 100, 'rawBase' => 1]];
        }
        foreach (['aliquot', 'base', 'rawBase'] as $field) {
            $line = ['aliquot' => 0, 'base' => 100, 'rawBase' => 1];
            unset($line[$field]);
            $cases['missing '.$field] = [$line];
        }
        $cases['not array'] = [null];
        return $cases;
    }

    /** @dataProvider invalidFiscalLines */
    public function testAllFiscalLinesAreValidatedBeforeAnyCalculation($invalid): void
    {
        foreach (['GENERAL', 'PNP', 'HKA'] as $driver) {
            try {
                // Una base inválida en la primera fila no debe impedir detectar la segunda antes de calcular.
                FiscalCalculator::calculate(['first' => ['aliquot' => 0, 'base' => null, 'rawBase' => []], 'invalid' => $invalid], $driver);
                self::fail('Invalid fiscal line accepted');
            } catch (InvalidArgumentException $e) {
                self::assertMatchesRegularExpression('/^Invalid (aliquot|fiscal line):/', $e->getMessage());
            }
        }
    }

    public function testInvalidExistingProductAndItemCostCannotProducePartialQueries(): void
    {
        $item = $this->cart->add('A', 'A', 1, 10, 3);
        $item->aliquot = 9;
        $before = serialize($this->session->all());
        foreach ([fn () => $this->cart->summary(), fn () => $this->cart->totalTaxes(new Collection([$item]), []),
            fn () => $item->tax, fn () => $this->cart->content()] as $route) {
            try { $route(); self::fail('Unknown product aliquot accepted'); }
            catch (InvalidArgumentException $e) { self::assertStringStartsWith('Invalid aliquot:', $e->getMessage()); }
            self::assertSame($before, serialize($this->session->all()));
        }
        $item->aliquot = 3;
        $this->cart->addCost('service', 1, 'item', 3);
        $metadata = $this->session->get('cart_metadata.shopping_cart');
        $metadata['costs'][0]['aliquot'] = 9;
        $this->session->put('cart_metadata.shopping_cart', $metadata);
        $before = serialize($this->session->all());
        foreach ([fn () => $this->cart->summary(), fn () => $this->cart->addCost('invalid', 1, 'item', 9),
            fn () => $this->cart->add('B', 'B', 1, 1, 9), fn () => $this->cart->update($item->rowId, ['aliquot' => 9]),
            fn () => $this->cart->setTax($item->rowId, 9)] as $route) {
            try { $route(); self::fail('Unknown aliquot accepted'); }
            catch (InvalidArgumentException $e) { self::assertStringStartsWith('Invalid aliquot:', $e->getMessage()); }
            self::assertSame($before, serialize($this->session->all()));
        }
    }

    public static function invalidSnapshotAliquots(): array
    {
        $cases = [];
        foreach (['legacy', 2, 3] as $version) foreach (['restore', 'merge'] as $operation) {
            $cases[] = [$version, $operation, 'product'];
            if ($version !== 'legacy') $cases[] = [$version, $operation, 'cost'];
        }
        return $cases;
    }

    /** @dataProvider invalidSnapshotAliquots */
    public function testInvalidSnapshotAliquotsFailBeforeMutationOrConsumption($version, $operation, $target): void
    {
        $this->cart->add('first', 'First valid', 1, 1, 0);
        $last = $this->cart->add('last', 'Last', 1, 2, 3);
        $this->cart->addCost('service', 1, 'item', 3);
        $this->cart->store('invalid-snapshot');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        if ($target === 'product') $snapshot['content']->get($last->rowId)->aliquot = 9;
        else $snapshot['metadata']['costs'][0]['aliquot'] = 9;
        if ($version === 'legacy') $snapshot = $snapshot['content'];
        else {
            $snapshot['version'] = $version;
            if ($version === 2) unset($snapshot['decimals']);
        }
        $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
        $this->cart->destroy();
        $this->cart->add('existing', 'Existing', 1, 10, 0);
        $this->cart->addCost('tip', 2);
        $before = serialize($this->session->all());
        $stored = serialize($this->db->table('shopping_cart')->get()->all());
        try { $this->cart->$operation('invalid-snapshot'); self::fail('Invalid snapshot accepted'); }
        catch (InvalidArgumentException $e) { self::assertStringStartsWith('Invalid aliquot:', $e->getMessage()); }
        self::assertSame($before, serialize($this->session->all()));
        self::assertSame($stored, serialize($this->db->table('shopping_cart')->get()->all()));
    }

    public static function invalidDestinationRestorations(): array
    {
        $cases = [];
        foreach ([2, 3] as $version) foreach (['restore', 'merge'] as $operation) {
            foreach (['product', 'cost', 'precision'] as $fault) $cases[] = [$version, $operation, $fault];
        }
        $cases[] = [3, 'merge', 'incoming-price'];
        $cases[] = [3, 'restore', 'date'];
        return $cases;
    }

    /** @dataProvider invalidDestinationRestorations */
    public function testRestorationValidationHasNoObservableMutation($version, $operation, $fault): void
    {
        $this->cart->instance('atomic');
        $this->cart->add('incoming-first', 'Valid', 1, 1, 0);
        $last = $this->cart->add('incoming-last', 'Last', 1, 2, 3);
        $this->cart->addCost('incoming-cost', 1, 'item', 3);
        $this->cart->addDiscount('fixed', .1);
        $this->cart->addObservation('Incoming observation');
        $this->cart->store('valid-snapshot');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        $snapshot['version'] = $version;
        if ($version === 2) unset($snapshot['decimals']);
        if ($fault === 'incoming-price') $snapshot['content']->get($last->rowId)->price = INF;
        $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
        if ($fault === 'date') $this->db->table('shopping_cart')->update(['updated_at' => 'invalid-date']);
        $this->cart->destroy();
        $legacy = $this->cart->add('legacy', 'Legacy', 1, 1, 0);
        $item = $this->cart->add('existing', 'Existing', 1, 10, 0);
        $this->cart->addCost('existing-cost', 2, 'item', 0);
        $this->cart->addDiscount('fixed', .2);
        $this->cart->addObservation('Existing observation');
        // Esta referencia no debe ser normalizada si falla otra validación posterior.
        $legacy->aliquot = null;
        if ($fault === 'product') $item->aliquot = 9;
        $metadata = $this->session->get('cart_metadata.atomic');
        if ($fault === 'cost') $metadata['costs'][0]['aliquot'] = 9;
        if ($fault === 'precision') $metadata['decimals'] = 5;
        $this->session->put('cart_metadata.atomic', $metadata);
        $this->cart->createdAt = new \Carbon\Carbon('2020-01-01');
        $this->cart->updatedAt = new \Carbon\Carbon('2020-02-01');
        $createdAt = $this->cart->createdAt;
        $updatedAt = $this->cart->updatedAt;
        $session = serialize($this->session->all());
        $database = serialize($this->db->table('shopping_cart')->get()->all());
        $events = [];
        foreach (['cart.restored', 'cart.merged', 'cart.adding', 'cart.added'] as $name) {
            $this->events->listen($name, function () use (&$events, $name) { $events[] = $name; });
        }
        try {
            if ($operation === 'restore') $this->cart->restore('valid-snapshot');
            else $this->cart->merge('valid-snapshot', true, 'atomic');
            self::fail('Invalid restoration accepted');
        } catch (InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        self::assertSame($session, serialize($this->session->all()));
        self::assertSame($metadata, $this->session->get('cart_metadata.atomic'));
        self::assertSame($database, serialize($this->db->table('shopping_cart')->get()->all()));
        self::assertSame('atomic', $this->cart->currentInstance());
        self::assertSame($createdAt, $this->cart->createdAt);
        self::assertSame($updatedAt, $this->cart->updatedAt);
        self::assertSame([], $events);
        self::assertNull($legacy->aliquot);
    }

    public function testValidRestoreOverlaysProductsConcatenatesMetadataAndConsumesSnapshot(): void
    {
        $saved = $this->cart->add('shared', 'Saved', 3, 1, 0);
        $this->cart->addCost('saved-cost', 1, 'item', 3);
        $this->cart->addDiscount('fixed', .1);
        $this->cart->addObservation('Saved observation');
        $this->cart->store('valid');
        $this->cart->destroy();
        $this->cart->add('shared', 'Current', 1, 1, 0);
        $this->cart->add('existing', 'Existing', 1, 2, 0);
        $this->cart->addCost('existing-cost', 2, 'item', 0);
        $this->cart->addDiscount('fixed', .2);
        $this->cart->addObservation('Existing observation');
        $events = 0;
        $this->events->listen('cart.restored', function () use (&$events) { $events++; });
        $this->cart->restore('valid');
        self::assertSame(2, $this->cart->content()->count());
        self::assertSame(3, $this->cart->getByRowId($saved->rowId)->qty);
        self::assertSame('Saved', $this->cart->getByRowId($saved->rowId)->name);
        $metadata = $this->session->get('cart_metadata.shopping_cart');
        self::assertSame(['existing-cost', 'saved-cost'], array_column($metadata['costs'], 'name'));
        self::assertCount(2, $metadata['discounts']);
        self::assertSame(['Existing observation', 'Saved observation'], array_column($metadata['observations'], 'text'));
        self::assertSame(1, $events);
        self::assertSame(0, $this->db->table('shopping_cart')->count());
        $this->assertTaxSums();
    }

    public static function corruptStoredProducts(): array
    {
        $cases = [];
        foreach (['legacy', 2, 3] as $version) foreach (['restore', 'merge'] as $operation) {
            foreach (['price' => INF, 'qty' => INF, 'entry' => new \stdClass()] as $field => $value) {
                $cases[$version.' '.$operation.' '.$field] = [$version, $operation, $field, $value];
            }
        }
        $invalid = [
            'price' => [-1, -.01, -INF, NAN, 'abc', 1000000001],
            'qty' => [0, -1, -.01, -INF, NAN, 'abc', null, true, [], 1e308],
            'entry' => [null, [], 'abc'],
            'options' => [null, 'abc', new \stdClass(), 1],
            'id' => [null, [], ''],
            'name' => ['', null, []],
        ];
        foreach (['restore', 'merge'] as $operation) foreach ($invalid as $field => $values) {
            foreach ($values as $index => $value) $cases[$operation.' '.$field.' '.$index] = [3, $operation, $field, $value];
        }
        return $cases;
    }

    /** @dataProvider corruptStoredProducts */
    public function testCorruptStoredProductsAreRejectedAtomically($version, $operation, $field, $value): void
    {
        $this->cart->instance('integrity');
        $this->cart->add('first', 'First valid', 1, 1, 0);
        $last = $this->cart->add('last', 'Last valid', 1, 10, 0);
        $this->cart->addCost('snapshot-cost', 1, 'item', 3);
        $this->cart->addDiscount('fixed', .1);
        $this->cart->addObservation('Snapshot observation');
        $this->cart->store('corrupt');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        if ($field === 'entry') $snapshot['content']->put($last->rowId, $value);
        else $snapshot['content']->get($last->rowId)->$field = $value;
        if ($version === 'legacy') $snapshot = $snapshot['content'];
        else {
            $snapshot['version'] = $version;
            if ($version === 2) unset($snapshot['decimals']);
        }
        $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
        $this->cart->destroy();
        $existing = $this->cart->add('existing', 'Existing', 2, 3, 0, ['color' => 'blue']);
        $this->cart->addCost('current-cost', 2, 'item', 0);
        $this->cart->addDiscount('fixed', .2);
        $this->cart->addObservation('Current observation');
        $existing->aliquot = null;
        $this->cart->createdAt = new \Carbon\Carbon('2020-01-01');
        $this->cart->updatedAt = new \Carbon\Carbon('2020-02-01');
        $createdAt = $this->cart->createdAt;
        $updatedAt = $this->cart->updatedAt;
        $before = serialize($this->session->all());
        $database = serialize($this->db->table('shopping_cart')->get()->all());
        $events = [];
        foreach (['cart.restored', 'cart.merged', 'cart.adding', 'cart.added'] as $event) {
            $this->events->listen($event, function () use (&$events, $event) { $events[] = $event; });
        }
        try {
            if ($operation === 'restore') $this->cart->restore('corrupt');
            else $this->cart->merge('corrupt', true, 'integrity');
            self::fail('Corrupt product accepted');
        } catch (InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
            if ($field === 'entry') self::assertSame('Invalid cart snapshot: every product must be a CartItem.', $e->getMessage());
        }
        self::assertSame($before, serialize($this->session->all()));
        self::assertSame($database, serialize($this->db->table('shopping_cart')->get()->all()));
        self::assertSame('integrity', $this->cart->currentInstance());
        self::assertSame($createdAt, $this->cart->createdAt);
        self::assertSame($updatedAt, $this->cart->updatedAt);
        self::assertSame([], $events);
        self::assertNull($existing->aliquot);
    }

    public static function compatibleStoredProducts(): array
    {
        $cases = [];
        foreach (['legacy', 2, 3] as $version) foreach (['restore', 'merge'] as $operation) {
            foreach ([false, true] as $arrayOptions) $cases[] = [$version, $operation, $arrayOptions];
        }
        return $cases;
    }

    /** @dataProvider compatibleStoredProducts */
    public function testStoredLegacyAliquotAndRowIdRemainCompatible($version, $operation, $arrayOptions): void
    {
        config(['cart.default_aliquot' => 2]);
        $item = $this->cart->add('legacy', 'Legacy', '2', 10, 2, ['color' => 'blue']);
        $this->cart->store('compatible');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        $storedItem = $snapshot['content']->get($item->rowId);
        $storedItem->aliquot = null;
        $storedItem->rowId = 'historical-row-id';
        if ($arrayOptions) $storedItem->options = $storedItem->options->all();
        $snapshot['content'] = new Collection(['historical-row-id' => $storedItem]);
        if ($version === 'legacy') $snapshot = $snapshot['content'];
        else {
            $snapshot['version'] = $version;
            if ($version === 2) unset($snapshot['decimals']);
        }
        $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
        $this->cart->destroy();
        $this->cart->$operation('compatible');
        $restored = $this->cart->getById('legacy');
        self::assertSame(2, $restored->aliquot);
        self::assertSame('historical-row-id', $restored->rowId);
        self::assertNotSame($restored->identity(), $restored->rowId);
        self::assertSame(['color' => 'blue'], $restored->options->all());
        self::assertSame('2', $restored->qty);
        self::assertSame(160, Money::minorUnits($restored->tax));
        self::assertSame(2160, Money::minorUnits($this->cart->total()));
        self::assertSame(2160, Money::minorUnits($this->cart->summary()['total']));
        self::assertSame($operation === 'restore' ? 0 : 1, $this->db->table('shopping_cart')->count());
        $this->assertTaxSums();
    }

    private function assertRejectedWithoutMutation(callable $operation): void
    {
        $session = serialize($this->session->all());
        $database = serialize($this->db->table('shopping_cart')->get()->all());
        $instance = $this->cart->currentInstance();
        $created = $this->cart->createdAt;
        $updated = $this->cart->updatedAt;
        $events = [];
        foreach (['cart.adding', 'cart.added', 'cart.updated', 'cart.merged', 'cart.restored'] as $event) {
            $this->events->listen($event, function () use (&$events, $event) { $events[] = $event; });
        }
        try { $operation(); self::fail('Invalid state accepted'); }
        catch (InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        self::assertSame($session, serialize($this->session->all()));
        self::assertSame($database, serialize($this->db->table('shopping_cart')->get()->all()));
        self::assertSame($instance, $this->cart->currentInstance());
        self::assertSame($created, $this->cart->createdAt);
        self::assertSame($updated, $this->cart->updatedAt);
        self::assertSame([], $events);
    }

    public static function invalidCurrentProducts(): array
    {
        $cases = [];
        foreach (['restore', 'merge'] as $operation) foreach (['price' => INF, 'qty' => INF,
            'options' => 'abc', 'id' => [], 'name' => ''] as $field => $value) $cases[] = [$operation, $field, $value];
        return $cases;
    }

    /** @dataProvider invalidCurrentProducts */
    public function testDestinationUsesFullProductValidation($operation, $field, $value): void
    {
        $this->cart->add('incoming', 'Incoming', 1, 1, 0);
        $this->cart->store('source');
        $this->cart->destroy();
        $item = $this->cart->add('current', 'Current', 1, 1, 0);
        $item->$field = $value;
        $this->assertRejectedWithoutMutation(fn () => $this->cart->$operation('source'));
    }

    public static function accumulatedQuantities(): array
    {
        $cases = [];
        foreach (['add', 'addCartItem', 'merge', 'update'] as $operation) {
            $cases[] = [$operation, 600000000, 500000000, 1, false];
            $cases[] = [$operation, 1e308, 1e308, 0, false];
            $cases[] = [$operation, 600000000, 400000000, 1, true];
            $cases[] = [$operation, 1.25, 2.5, 2, true];
        }
        return $cases;
    }

    /** @dataProvider accumulatedQuantities */
    public function testFinalAccumulatedLineIsValidatedBeforeMutation($operation, $first, $second, $price, $valid): void
    {
        if ($operation === 'merge') {
            $this->cart->add('before-collision', 'First incoming', 1, 0, 0);
            $this->cart->add('A', 'A', $second, $price, 0);
            $this->cart->store('source');
            $this->cart->destroy();
        }
        $item = $this->cart->add('A', 'A', $first, $price, 0);
        $this->cart->addDiscountToItem($item->rowId, 'percentage', 1, 'Keep discount');
        if ($operation === 'update') {
            $variant = $this->cart->add('A', 'Variant', $second, $price, 0, ['variant' => 1]);
            $this->cart->addDiscountToItem($variant->rowId, 'percentage', 2, 'Keep variant discount');
            $run = fn () => $this->cart->update($variant->rowId, ['options' => []]);
        } elseif ($operation === 'merge') $run = fn () => $this->cart->merge('source');
        elseif ($operation === 'addCartItem') {
            $incoming = new CartItem('A', 'A', $price, 0);
            $incoming->setQuantity($second);
            $run = fn () => $this->cart->addCartItem($incoming);
        } else $run = fn () => $this->cart->add('A', 'A', $second, $price, 0);
        if (!$valid) $this->assertRejectedWithoutMutation($run);
        else {
            $run();
            self::assertEquals($first + $second, $this->cart->getById('A')->qty);
            self::assertGreaterThanOrEqual(0, $this->cart->summary()['tax']);
            $this->assertTaxSums();
        }
    }

    public static function malformedEnvelopes(): array
    {
        $cases = [];
        foreach (['restore', 'merge'] as $operation) foreach (['content', 'metadata', 'version', 'decimals',
            'metadata.costs', 'metadata.discounts', 'metadata.observations'] as $path) {
            $cases[] = [$operation, $path, null, true];
            $cases[] = [$operation, $path, 'abc', false];
        }
        foreach (['restore', 'merge'] as $operation) {
            foreach (['metadata.costs', 'metadata.discounts'] as $path) {
                $cases[] = [$operation, $path, ['abc'], false];
                $cases[] = [$operation, $path, [[]], false];
            }
            $cases[] = [$operation, 'version', 99, false];
            $cases[] = [$operation, 'decimals', 5, false];
        }
        return $cases;
    }

    /** @dataProvider malformedEnvelopes */
    public function testMalformedEnvelopeFailsWithoutPhpErrorsOrMutation($operation, $path, $value, $remove): void
    {
        $this->cart->add('source', 'Source', 1, 1, 0);
        $this->cart->store('source');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        if ($remove) \Illuminate\Support\Arr::forget($snapshot, $path);
        else \Illuminate\Support\Arr::set($snapshot, $path, $value);
        $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
        $this->cart->destroy();
        $this->cart->add('destination', 'Destination', 1, 1, 0);
        $this->assertRejectedWithoutMutation(fn () => $this->cart->$operation('source'));
    }

    public function testCorruptCurrentMetadataFailsBeforeRestoration(): void
    {
        $this->cart->add('A', 'A', 1, 1, 0);
        $this->cart->store('source');
        foreach (['abc', ['costs' => [], 'discounts' => 'abc', 'observations' => []]] as $metadata) {
            $this->session->put('cart_metadata.shopping_cart', $metadata);
            foreach (['restore', 'merge'] as $operation) $this->assertRejectedWithoutMutation(fn () => $this->cart->$operation('source'));
        }
    }

    public static function invalidSemanticMetadata(): array
    {
        $cases = [];
        $cost = ['name' => 'installation', 'mode' => 'item', 'cents' => 100, 'aliquot' => 0, 'description' => 'Installation'];
        foreach (['cents' => [-100, -1, '-1', 1.5, null, INF], 'mode' => ['unknown', '', 'tax'],
            'aliquot' => [null, 9], 'description' => [[], new \stdClass(), null, ''], 'name' => ['', null]] as $field => $values) {
            foreach ($values as $key => $value) $cases['cost '.$field.' '.$key] = ['costs', array_replace($cost, [$field => $value])];
        }
        foreach ([['mode' => 'prorated'], ['mode' => 'legacy'], ['mode' => 'tip', 'name' => 'tip'],
            ['mode' => 'tip', 'aliquot' => null], ['mode' => 'legacy', 'name' => 'tip', 'aliquot' => null],
            ['name' => 'tip'], ['name' => 'tip', 'mode' => 'prorated', 'aliquot' => null]] as $key => $override) {
            $cases['cost mode relation '.$key] = ['costs', array_replace($cost, $override)];
        }
        $discount = ['rowId' => null, 'type' => 'percentage', 'value' => 5, 'concept' => 'Discount'];
        foreach (['type' => ['unknown', '', null], 'value' => [-10, -1, -.01, 100.01, 150, INF, -INF, NAN, 'abc'],
            'concept' => [[], new \stdClass(), null], 'rowId' => [[], new \stdClass(), true]] as $field => $values) {
            foreach ($values as $key => $value) $cases['discount '.$field.' '.$key] = ['discounts', array_replace($discount, [$field => $value])];
        }
        foreach ([-1, 1000000001] as $key => $value) $cases['fixed value '.$key] = ['discounts', array_replace($discount, ['type' => 'fixed', 'value' => $value])];
        foreach ([-1, '100', 1.5, null, PHP_INT_MAX] as $key => $units) {
            $cases['fixed units '.$key] = ['discounts', array_replace($discount, ['type' => 'fixed', 'fixedUnits' => $units])];
        }
        foreach (['abc', [], ['text' => 'Text'], ['type' => 'manual'], ['type' => 'tip', 'text' => 'Tip'],
            ['type' => 'discount', 'text' => 'Discount'], ['type' => 'manual', 'text' => []],
            ['type' => 'manual', 'text' => ''], ['type' => 'manual', 'text' => '   ']] as $key => $observation) {
            $cases['observation '.$key] = ['observations', $observation];
        }
        return $cases;
    }

    /** @dataProvider invalidSemanticMetadata */
    public function testSemanticMetadataRejectsSessionAndSnapshotsAtomically($section, $entry): void
    {
        $this->cart->add('source', 'Source', 2, 10, 0);
        $this->cart->addCost('installation', 1, 'item', 0);
        $this->cart->addDiscount('fixed', .5, 'Saved');
        $this->cart->addObservation('Saved note');
        $this->cart->store('source');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        $this->cart->destroy();
        $this->cart->add('current', 'Current', 1, 20, 0);
        $this->cart->addObservation('Current note');
        $this->cart->createdAt = new \Carbon\Carbon('2020-01-01');
        $this->cart->updatedAt = new \Carbon\Carbon('2020-02-01');
        $valid = $this->session->get('cart_metadata.shopping_cart');
        $corrupt = $valid;
        $corrupt[$section] = [$entry];
        $this->session->put('cart_metadata.shopping_cart', $corrupt);
        $this->assertRejectedWithoutMutation(fn () => $this->cart->summary());
        $this->session->put('cart_metadata.shopping_cart', $valid);
        foreach ([2, 3] as $version) {
            $snapshot['version'] = $version;
            $snapshot['metadata'][$section] = [$entry];
            if ($version === 2) unset($snapshot['decimals']);
            else $snapshot['decimals'] = 2;
            $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
            foreach (['restore', 'merge'] as $operation) $this->assertRejectedWithoutMutation(fn () => $this->cart->$operation('source'));
        }
    }

    public function testPublicMetadataRemainsValidAcrossVersionsAndPrecisionChanges(): void
    {
        foreach ([2, 3] as $version) foreach (['restore', 'merge'] as $operation) {
            $this->cart->destroy();
            config(['cart.format.decimals' => 2]);
            $this->cart->add('A', 'A', 1, 100, 0);
            $this->cart->addCost('installation', 1, 'item', 0);
            $this->cart->addCost('freight', 1, 'prorated');
            $this->cart->addCost('tip', 1);
            $this->cart->addCost('legacy', 1);
            foreach ([0, 5, 100, '10.5'] as $value) $this->cart->addDiscount('percentage', $value, 'Percentage');
            $this->cart->addDiscount('fixed', 0, 'Zero');
            $this->cart->addDiscountToItem('A', 'fixed', .125, 'Requested value');
            $this->cart->addObservation(' Manual note ');
            $id = 'valid-'.$version.'-'.$operation;
            $this->cart->store($id);
            $snapshot = unserialize($this->db->table('shopping_cart')->where('identifier', $id)->value('content'));
            $snapshot['version'] = $version;
            if ($version === 2) { unset($snapshot['decimals']); unset($snapshot['metadata']['discounts'][5]['fixedUnits']); }
            $snapshot['metadata']['costs'][0]['amount'] = new \stdClass();
            $this->db->table('shopping_cart')->where('identifier', $id)->update(['content' => serialize($snapshot)]);
            $this->cart->destroy();
            config(['cart.format.decimals' => 3]);
            $this->cart->$operation($id);
            $metadata = $this->session->get('cart_metadata.shopping_cart');
            self::assertSame(['item', 'prorated', 'tip', 'legacy'], array_column($metadata['costs'], 'mode'));
            self::assertEquals(1, $metadata['costs'][0]['amount']);
            self::assertSame(.125, $metadata['discounts'][5]['value']);
            self::assertSame(130, $metadata['discounts'][5]['fixedUnits']);
            self::assertSame(125, Money::minorUnits($metadata['discounts'][5]['value']));
            self::assertSame([['type' => 'manual', 'text' => ' Manual note ']], $metadata['observations']);
            self::assertGreaterThanOrEqual(0, $this->cart->summary()['tax']);
            $this->cart->addObservation('Retain converted units');
            self::assertSame(130, $this->session->get('cart_metadata.shopping_cart')['discounts'][5]['fixedUnits']);
        }
    }

    public function testPublicDescriptionAndConceptRejectIncompatibleTypesBeforeWriting(): void
    {
        $this->cart->add('A', 'A', 1, 10, 0);
        foreach ([[], new \stdClass(), 1, false] as $description) {
            $this->assertRejectedWithoutMutation(fn () => $this->cart->addCost('cost', 1, null, null, $description));
        }
        foreach ([[], new \stdClass()] as $concept) {
            $this->assertRejectedWithoutMutation(fn () => $this->cart->addDiscount('fixed', 1, $concept));
            $this->assertRejectedWithoutMutation(fn () => $this->cart->addDiscountToItem('A', 'fixed', 1, $concept));
        }
        foreach ([null, '', 'Valid'] as $description) $this->cart->addCost('cost', 1, null, null, $description);
        self::assertSame(['cost', 'cost', 'Valid'], array_column($this->cart->costs()->all(), 'description'));
    }

    public static function historicalFixedUnits(): array
    {
        $cases = [];
        foreach (range(0, 4) as $origin) foreach (range(0, 4) as $stored) {
            foreach ([.125, .005, 1.2345, 10, .004, 0, 1] as $value) {
                $cases[$origin.' to '.$stored.' value '.$value] = [$origin, $stored, $value];
            }
        }
        return $cases;
    }

    /** @dataProvider historicalFixedUnits */
    public function testAllHistoricalFixedQuantizationsAreAccepted($origin, $stored, $value): void
    {
        config(['cart.format.decimals' => $stored]);
        $this->cart->add('A', 'A', 1, 100, 0);
        $units = Money::rescale(Money::minorUnits($value, false, $origin), $origin, $stored);
        $metadata = ['decimals' => $stored, 'costs' => [], 'observations' => [], 'discounts' => [
            ['rowId' => null, 'type' => 'fixed', 'value' => $value, 'fixedUnits' => $units, 'concept' => 'Historical'],
        ]];
        $this->session->put('cart_metadata.shopping_cart', $metadata);
        $before = serialize($this->session->all());
        self::assertSame($units, $this->cart->summary()['discounts'][0]['cents']);
        self::assertSame($before, serialize($this->session->all()));
    }

    public static function inconsistentFixedUnits(): array
    {
        // Para value=5 ninguna precisión 0..4 puede cuantizar el importe a cero.
        return [[.01, 100000, 2], [.125, 999, 3], [5, 0, 2]];
    }

    /** @dataProvider inconsistentFixedUnits */
    public function testInventedFixedUnitsAreRejectedAtomically($value, $units, $stored): void
    {
        config(['cart.format.decimals' => $stored]);
        $this->cart->add('source', 'Source', 1, 100, 0);
        $this->cart->addDiscount('fixed', $value, 'Requested');
        $this->cart->store('source');
        $snapshot = unserialize($this->db->table('shopping_cart')->value('content'));
        $snapshot['metadata']['discounts'][0]['fixedUnits'] = $units;
        $this->cart->destroy();
        $this->cart->add('current', 'Current', 1, 10, 0);
        $this->cart->addObservation('Current note');
        $this->cart->createdAt = new \Carbon\Carbon('2020-01-01');
        $this->cart->updatedAt = new \Carbon\Carbon('2020-02-01');
        $current = $this->session->get('cart_metadata.shopping_cart');
        $this->session->put('cart_metadata.shopping_cart', $snapshot['metadata']);
        $this->assertRejectedWithoutMutation(fn () => $this->cart->summary());
        $this->session->put('cart_metadata.shopping_cart', $current);
        foreach ($stored === 2 ? [2, 3] : [3] as $version) {
            $snapshot['version'] = $version;
            $this->db->table('shopping_cart')->update(['content' => serialize($snapshot)]);
            foreach (['restore', 'merge'] as $operation) {
                $this->assertRejectedWithoutMutation(fn () => $this->cart->$operation('source'));
            }
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
