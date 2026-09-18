<?php
namespace JeleDev\Shoppingcart;

/** Estrategias fiscales sobre filas originales o finales; sin sesión ni mutaciones. @internal */
final class FiscalCalculator
{
    /** Conserva HKA como alternativa histórica para un driver desconocido en Cart. */
    public static function driver()
    {
        $driver = config('cart.driver');
        return in_array($driver, ['GENERAL', 'PNP', 'HKA'], true) ? $driver : 'HKA';
    }

    /**
     * Cada fila tiene base (unidades menores), rawBase (importe sin cuantizar) y aliquot.
     * Devuelve impuestos por catálogo y un mapa de impuestos por clave de fila en enteros.
     * GENERAL suma HALF_UP por fila; PNP suma IVA truncado de rawBase por fila.
     * HKA calcula IVA agrupado y reconcilia provisionales por mayor IVA, mayor base,
     * y clave lexicográfica menor. El residuo completo, positivo o negativo, queda
     * en una sola fila de la misma alícuota. No se modifica ninguna base.
     */
    public static function calculate(array $lines, $driver, array $taxes = [])
    {
        Money::decimals();
        $lineTaxes = [];
        foreach (config('cart.taxes') as $aliquot => $tax) {
            $group = array_filter($lines, function ($line) use ($aliquot) {
                return (string) $line['aliquot'] === (string) $aliquot;
            });
            $provisional = [];
            foreach ($group as $key => $line) {
                $base = $driver === 'PNP' ? $line['rawBase'] : Money::fromMinorUnits($line['base']);
                $provisional[$key] = Money::minorUnits($base * $tax['value'] / 100, $driver === 'PNP');
            }
            $value = Money::sum($provisional);
            if ($driver === 'HKA' && $group) {
                $base = Money::sum(array_column($group, 'base'));
                $value = Money::minorUnits(Money::fromMinorUnits($base) * $tax['value'] / 100);
                $difference = $value - Money::sum($provisional);
                if ($difference !== 0) {
                    $keys = array_keys($group);
                    usort($keys, function ($a, $b) use ($provisional, $group) {
                        return ($provisional[$b] <=> $provisional[$a])
                            ?: ($group[$b]['base'] <=> $group[$a]['base'])
                            ?: strcmp((string) $a, (string) $b);
                    });
                    $provisional[$keys[0]] += $difference;
                }
            }
            foreach ($provisional as $key => $units) $lineTaxes[$key] = $units;
            $taxes[$tax['name']] = ['IVA' => $tax['value'], 'value' => Money::fromMinorUnits($value)];
        }
        return ['taxes' => $taxes, 'lineTaxes' => $lineTaxes];
    }

    /** Construye filas originales sin consultar tax ni incorporar ajustes documentales. */
    public static function originalLines($items, $driver)
    {
        $lines = [];
        foreach ($items as $item) {
            $rawBase = $item->qty * $item->price;
            $lines[$item->rowId] = [
                'aliquot' => $item->aliquot,
                'base' => Money::minorUnits($rawBase, $driver === 'PNP'),
                'rawBase' => $rawBase,
            ];
        }
        return $lines;
    }
}
