<?php
namespace JeleDev\Shoppingcart;

/** Estrategias fiscales sobre filas originales o finales; sin sesión ni mutaciones. @internal */
final class FiscalCalculator
{
    /**
     * Una tasa debe ser numérica, finita y no negativa; se admiten tasas mayores de 100.
     * Comprueba también el signo decimal de strings: un negativo diminuto puede
     * convertirse en -0.0 por subdesbordamiento al compararlo como float.
     */
    public static function validateTaxRate($rate)
    {
        if (!is_numeric($rate) || !is_finite((float) $rate) || $rate < 0
            || (is_string($rate) && str_starts_with(trim($rate), '-') && preg_match('/[1-9]/', preg_split('/[eE]/', $rate)[0]))) {
            throw new \InvalidArgumentException('Invalid tax rate: tax rates must be finite, numeric and nonnegative.');
        }
        return $rate;
    }

    /** Valida la estructura mínima del catálogo antes de cualquier cálculo fiscal. */
    public static function taxCatalog()
    {
        $catalog = config('cart.taxes');
        if (!is_array($catalog)) throw new \InvalidArgumentException('Invalid tax configuration: cart.taxes must be an array.');
        foreach ($catalog as $tax) {
            if (!is_array($tax) || !isset($tax['name']) || !is_string($tax['name']) || $tax['name'] === '' || !array_key_exists('value', $tax)) {
                throw new \InvalidArgumentException('Invalid tax configuration: each entry requires name and value.');
            }
            self::validateTaxRate($tax['value']);
        }
        return $catalog;
    }

    /** Obtiene una tasa validada sin modificar la línea ni el catálogo. */
    public static function taxRate($aliquot)
    {
        $catalog = self::taxCatalog();
        if ((!is_int($aliquot) && !is_string($aliquot)) || !array_key_exists($aliquot, $catalog)) {
            throw new \InvalidArgumentException('Invalid aliquot.');
        }
        return $catalog[$aliquot]['value'];
    }

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
     * y clave lexicográfica menor. El residuo positivo queda en la primera fila;
     * el negativo se resta en ese orden sin bajar de cero, dentro de la alícuota.
     * No se modifica ninguna base.
     */
    public static function calculate(array $lines, $driver, array $taxes = [])
    {
        Money::decimals();
        $lineTaxes = [];
        foreach (self::taxCatalog() as $aliquot => $tax) {
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
                    if ($difference > 0) {
                        $provisional[$keys[0]] += $difference;
                    } else {
                        $remaining = abs($difference);
                        foreach ($keys as $key) {
                            $removable = min($provisional[$key], $remaining);
                            $provisional[$key] -= $removable;
                            $remaining -= $removable;
                            if ($remaining === 0) break;
                        }
                        if ($remaining !== 0) {
                            throw new \LogicException('HKA tax reconciliation cannot exhaust the negative difference.');
                        }
                    }
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
