<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\SessionManager;
use JeleDev\Shoppingcart\Cart;
use JeleDev\Shoppingcart\CartItem;
use JeleDev\Shoppingcart\Money;
use PHPUnit\Framework\TestCase;

/** Comprueba estrategias fiscales con diferencias observables de centavos. */
class FiscalDriverTest extends TestCase
{
    private $cart;

    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'cart' => require __DIR__.'/../config/cart.php',
            'session' => ['driver' => 'array'],
        ]));
        $session = new SessionManager($app);
        $session->extend('array', function () { return new ArraySessionHandler(120); });
        $this->cart = new Cart($session, new Dispatcher($app));
        // Las consultas deben usar el nombre del catálogo, no una etiqueta fija.
        config(['cart.taxes.0.name' => 'IVA_GENERAL']);
    }

    public function testGeneralAndHkaHaveIdenticalBasesButDifferentTaxes(): void
    {
        $this->cart->add('A', 'A', 1, 0.03, 0);
        $this->cart->add('B', 'B', 1, 0.03, 0);
        $results = [];
        foreach (['GENERAL' => 0, 'HKA' => 1, 'PNP' => 0] as $driver => $taxCents) {
            config(['cart.driver' => $driver]);
            $results[$driver] = $this->cart->summary();
            $this->assertLiquidation($results[$driver], [6, 0, 0, 0], [$taxCents, 0, 0, 0], 6 + $taxCents);
            self::assertSame($results[$driver]['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
        }
        self::assertSame($results['GENERAL']['bases'], $results['HKA']['bases']);
        self::assertSame($results['GENERAL']['lines'], $results['HKA']['lines']);
        self::assertNotSame($results['GENERAL']['taxes'], $results['HKA']['taxes']);
        self::assertEquals(0.00, $results['GENERAL']['taxes'][config('cart.taxes.0.name')]['value']);
        self::assertEquals(0.01, $results['HKA']['taxes'][config('cart.taxes.0.name')]['value']);
    }

    public function testGeneralUsesWholeQuantityBeforeCalculatingLineTax(): void
    {
        config(['cart.driver' => 'GENERAL']);
        $item = $this->cart->add('A', 'A', 2, 10.23, 0);
        $summary = $this->cart->summary();
        $this->assertLiquidation($summary, [2046, 0, 0, 0], [327, 0, 0, 0], 2373);
        self::assertSame(328, Money::cents(CartItem::calculateTaxes(10.23, config('cart.taxes.0.value'))) * 2);
        self::assertSame(2046, Money::cents($summary['lines'][$item->rowId]['base']));
        self::assertSame($summary['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
    }

    public static function mixedRates(): array
    {
        return [
            'GENERAL por fila' => ['GENERAL', [0, 0, 2, 2], 41],
            'HKA acumulado' => ['HKA', [1, 0, 1, 2], 41],
            'PNP acumulado truncado' => ['PNP', [0, 0, 1, 1], 39],
        ];
    }

    /** @dataProvider mixedRates */
    public function testStrategiesKeepAliquotsSeparate($driver, $taxes, $total): void
    {
        config(['cart.driver' => $driver]);
        foreach ([[0, .03], [0, .03], [2, .07], [2, .07], [3, .03], [3, .03], [1, .11]] as $key => $data) {
            $this->cart->add('P'.$key, 'P', 1, $data[1], $data[0]);
        }
        $summary = $this->cart->summary();
        $this->assertLiquidation($summary, [6, 11, 14, 6], $taxes, $total);
        self::assertSame($summary['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
    }

    public static function baseQuantization(): array
    {
        return [
            'GENERAL redondea cada base' => ['GENERAL', 4, 8, 2, 10],
            'HKA redondea antes de acumular' => ['HKA', 4, 8, 1, 9],
            'PNP trunca antes de acumular' => ['PNP', 3, 6, 0, 6],
        ];
    }

    /** @dataProvider baseQuantization */
    public function testEachBaseIsQuantizedBeforeTaxAccumulation($driver, $lineBase, $base, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, .039, 0);
        $this->cart->add('B', 'B', 1, .039, 0);
        $summary = $this->cart->summary();
        foreach ($summary['lines'] as $line) self::assertSame($lineBase, Money::cents($line['original']));
        $this->assertLiquidation($summary, [$base, 0, 0, 0], [$tax, 0, 0, 0], $total);
        self::assertSame($summary['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
    }

    public function testPnpTruncatesTaxAfterAccumulatingRatherThanPerLine(): void
    {
        config(['cart.driver' => 'PNP']);
        $this->cart->add('A', 'A', 1, .049, 0);
        $this->cart->add('B', 'B', 1, .049, 0);
        $this->cart->add('C', 'C', 1, .049, 0);
        // 0.04 + 0.04 + 0.04 = 0.12; 0.12 * 16% = 0.0192 -> 0.01.
        // Truncar IVA por fila daría 0.00; redondear el IVA acumulado daría 0.02.
        $summary = $this->cart->summary();
        $this->assertLiquidation($summary, [12, 0, 0, 0], [1, 0, 0, 0], 13);
        self::assertSame($summary['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
    }

    public static function proratedDrivers(): array
    {
        return [['GENERAL', 0, 6], ['HKA', 1, 7], ['PNP', 0, 6]];
    }

    /** @dataProvider proratedDrivers */
    public function testProratedCostsFeedFinalLineBases($driver, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, .01, 0);
        $this->cart->add('B', 'B', 1, .01, 0);
        $this->cart->addCost('freight', .04, Cart::COST_PRORATED);
        $summary = $this->cart->summary();
        foreach ($summary['lines'] as $line) {
            self::assertSame(1, Money::cents($line['original']));
            self::assertSame(2, Money::cents($line['prorated']));
            self::assertSame(3, Money::cents($line['base']));
        }
        $this->assertLiquidation($summary, [6, 0, 0, 0], [$tax, 0, 0, 0], $total);
        self::assertSame(4, Money::cents($summary['totalCost']));
        self::assertCount(2, $this->cart->content());
        self::assertSame([], $summary['costLines']);
    }

    /** @dataProvider proratedDrivers */
    public function testLineAndGeneralDiscountsPrecedeDriverTaxStrategy($driver, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $a = $this->cart->add('A', 'A', 1, .10, 0);
        $b = $this->cart->add('B', 'B', 1, .08, 0);
        $this->cart->addDiscountToItem('A', 'percentage', 20, 'Línea');
        $this->cart->addDiscount('fixed', .10, 'Documento');
        $summary = $this->cart->summary();
        self::assertSame([2, 10], array_column($summary['discounts'], 'cents'));
        self::assertSame(5, $summary['discounts'][1]['allocations'][$a->rowId]);
        self::assertSame(5, $summary['discounts'][1]['allocations'][$b->rowId]);
        self::assertSame(3, Money::cents($summary['lines'][$a->rowId]['base']));
        self::assertSame(3, Money::cents($summary['lines'][$b->rowId]['base']));
        self::assertSame(12, Money::cents($this->cart->totalDiscount()));
        self::assertSame(12, Money::cents($this->cart->observations()->firstWhere('type', 'discount_total')['amount']));
        $this->assertLiquidation($summary, [6, 0, 0, 0], [$tax, 0, 0, 0], $total);
    }

    public static function itemDrivers(): array
    {
        return [['GENERAL', 0, 9], ['HKA', 1, 10], ['PNP', 1, 10]];
    }

    /** @dataProvider itemDrivers */
    public function testEachItemCostIsAnIndependentFiscalLine($driver, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, .03, 0);
        $this->cart->addCost('installation', .03, Cart::COST_ITEM, 0);
        $this->cart->addCost('installation', .03, Cart::COST_ITEM, 0);
        $costs = $this->cart->costs()->all();
        $summary = $this->cart->summary();
        $this->assertLiquidation($summary, [9, 0, 0, 0], [$tax, 0, 0, 0], $total);
        self::assertSame($costs, $summary['costLines']);
        self::assertSame($costs, $this->cart->costs()->all());
        self::assertCount(1, $this->cart->content());
        self::assertSame(0, Money::cents($this->cart->totalTaxes($this->cart->content(), [])[config('cart.taxes.0.name')]['value']));
    }

    /** @dataProvider proratedDrivers */
    public function testTipAndLegacyLeaveFiscalBasesAndTaxesUntouched($driver, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, .03, 0);
        $this->cart->add('B', 'B', 1, .03, 0);
        $before = $this->cart->summary();
        $this->cart->addCost('tip', 5);
        $this->cart->addCost('transaction', 2);
        $after = $this->cart->summary();
        self::assertSame($before['bases'], $after['bases']);
        self::assertSame($before['taxes'], $after['taxes']);
        self::assertSame($before['lines'], $after['lines']);
        self::assertSame([], $after['costLines']);
        self::assertSame(500, Money::cents($after['tip']));
        self::assertSame(200, Money::cents($after['legacyCost']));
        $this->assertLiquidation($after, [6, 0, 0, 0], [$tax, 0, 0, 0], $total + 700);
    }

    public static function drivers(): array { return [['GENERAL'], ['HKA'], ['PNP']]; }

    /** @dataProvider itemDrivers */
    public function testCompleteAdjustedDocumentPreservesMetadataAndCountsEachAmountOnce($driver, $tax, $total): void
    {
        config(['cart.driver' => $driver]);
        $this->cart->add('A', 'A', 1, .09, 0);
        $this->cart->add('B', 'B', 1, .07, 0);
        $this->cart->addCost('freight', .02, Cart::COST_PRORATED);
        $this->cart->addDiscountToItem('A', 'percentage', 20, 'Línea');
        $this->cart->addDiscount('fixed', .10, 'Documento');
        $this->cart->addCost('installation', .03, Cart::COST_ITEM, 0);
        $this->cart->addCost('tip', 5);
        $this->cart->addCost('transaction', 2);
        $this->cart->addObservation('Conservar');
        $before = serialize($this->cart->content());
        $costs = $this->cart->costs()->all();
        $summary = $this->cart->summary();
        // 0.09 + 0.01 - 0.02 - 0.05 = 0.03; 0.07 + 0.01 - 0.05 = 0.03.
        foreach ($summary['lines'] as $line) {
            self::assertSame(1, Money::cents($line['prorated']));
            self::assertSame(3, Money::cents($line['base']));
        }
        $this->assertLiquidation($summary, [9, 0, 0, 0], [$tax, 0, 0, 0], $total + 700);
        self::assertSame(705, Money::cents($summary['totalCost']));
        self::assertSame(12, Money::cents($summary['totalDiscount']));
        self::assertSame([2, 10], array_column($summary['discounts'], 'cents'));
        self::assertCount(1, $summary['costLines']);
        self::assertCount(2, $this->cart->content());
        self::assertSame(2, $this->cart->count());
        self::assertSame($before, serialize($this->cart->content()));
        self::assertSame($costs, $this->cart->costs()->all());
        self::assertSame('Conservar', $this->cart->observations()->first()['text']);
        self::assertSame($summary, $this->cart->summary());
    }

    public function testGeneralRoundsCompleteLineBaseAndTaxHalfUp(): void
    {
        config(['cart.driver' => 'GENERAL', 'cart.taxes.0.value' => 25]);
        $this->cart->add('A', 'A', 3, 2.005, 0);
        // 3 * 2.005 = 6.015 -> 6.02; 6.02 * 25% = 1.505 -> 1.51.
        $summary = $this->cart->summary();
        $this->assertLiquidation($summary, [602, 0, 0, 0], [151, 0, 0, 0], 753);
        self::assertSame($summary['taxes'], $this->cart->totalTaxes($this->cart->content(), []));
    }

    /** @dataProvider drivers */
    public function testStandaloneTaxQueryPreservesOtherEntriesAndReturnsConfiguredCategories($driver): void
    {
        config(['cart.driver' => $driver, 'cart.format.decimal_point' => ',', 'cart.format.thousand_separator' => '.']);
        $this->cart->add('A', 'A', 2, 1000.03, 0);
        $result = $this->cart->totalTaxes($this->cart->content(), ['external' => 'unchanged', config('cart.taxes.0.name') => ['IVA' => 99, 'value' => 99]]);
        self::assertSame('unchanged', $result['external']);
        unset($result['external']);
        self::assertSame($this->cart->summary()['taxes'], $result);
        self::assertSame($driver === 'PNP' ? 32000 : 32001, Money::cents($result[config('cart.taxes.0.name')]['value']));
        $this->cart->destroy();
        self::assertSame($this->cart->summary()['taxes'], $this->cart->totalTaxes([], []));
        self::assertSame(0, Money::cents($this->cart->summary()['tax']));
    }

    /** Compara importes exactos en centavos y verifica la estructura pública. */
    private function assertLiquidation(array $summary, array $bases, array $taxes, int $total): void
    {
        self::assertSame(['lines', 'costLines', 'bases', 'taxes', 'subtotal', 'tax', 'tip', 'legacyCost', 'totalCost', 'discounts', 'totalDiscount', 'total'], array_keys($summary));
        foreach (config('cart.taxes') as $aliquot => $rate) {
            self::assertSame($bases[$aliquot], Money::cents($summary['bases'][$aliquot]));
            self::assertSame($taxes[$aliquot], Money::cents($summary['taxes'][$rate['name']]['value']));
            self::assertSame($rate['value'], $summary['taxes'][$rate['name']]['IVA']);
        }
        self::assertSame(array_sum($bases), Money::cents($summary['subtotal']));
        self::assertSame(array_sum($taxes), Money::cents($summary['tax']));
        self::assertSame($total, Money::cents($summary['total']));
        self::assertSame($summary['taxes'], $this->cart->tax());
        self::assertSame($total, Money::cents($this->cart->total()));
        self::assertSame(array_sum($bases), Money::cents($this->cart->subtotal()));
    }
}
