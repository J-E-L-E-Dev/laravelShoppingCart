<?php
namespace JeleDev\Shoppingcart;

/**
 * Encapsula la conversión monetaria a unidades menores y el reparto proporcional exacto.
 *
 * Cart y CartItem conservan entradas numéricas por compatibilidad; esta clase
 * fija la frontera de precisión configurada y evita usar presentación como contabilidad.
 * Los repartos operan con enteros y se convierten a unidades monetarias solo
 * al devolver consultas. No es un motor decimal de precisión arbitraria:
 * minorUnits() conserva dígitos decimales de strings; los floats siguen siendo aproximados.
 * Los importes cercanos al límite admitido requieren enteros de 64 bits.
 */
final class Money
{
    /** Máximo de decimales: escala 10000, con margen entero para reparto en PHP de 64 bits. */
    public const MAX_DECIMALS = 4;

    /**
     * Compara int, float o numeric-string: devuelve -1, 0 o 1, sin cuantizar.
     * Strings conservan todos sus dígitos; floats usan 15 cifras significativas.
     * Exponentes se operan como enteros decimales escritos, sin expandir ceros.
     * @throws \InvalidArgumentException Si un operando no es numérico o es un float no finito.
     */
    public static function compare($left, $right)
    {
        [$ls, $ld, $le] = self::comparisonParts($left);
        [$rs, $rd, $re] = self::comparisonParts($right);
        if ($ls !== $rs) return $ls <=> $rs;
        if ($ls === 0) return 0;
        $order = self::compareIntegerStrings($le, $re);
        if ($order === 0) {
            $length = max(strlen($ld), strlen($rd));
            $order = strcmp(str_pad($ld, $length, '0'), str_pad($rd, $length, '0')) <=> 0;
        }
        return $ls * $order;
    }

    /** Signo, mantisa sin ceros iniciales y posición decimal; independiente de la escala monetaria. */
    private static function comparisonParts($value)
    {
        if (!is_numeric($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Invalid numeric comparison: operands must be finite numeric values.');
        }
        $text = is_float($value)
            ? str_replace(localeconv()['decimal_point'], '.', sprintf('%.15g', $value))
            : trim((string) $value);
        preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $text, $parts);
        $digits = $parts[2].($parts[3] ?? '');
        $significant = ltrim($digits, '0');
        if ($significant === '') return [0, '', '0'];
        $offset = strlen($parts[2]) - (strlen($digits) - strlen($significant));
        return [$parts[1] === '-' ? -1 : 1, $significant,
            self::addIntegerStrings($parts[4] ?? '0', (string) $offset)];
    }

    /** Canoniza un exponente entero sin convertirlo a int ni float. */
    private static function canonicalIntegerString($value)
    {
        $digits = ltrim(ltrim($value, '+-'), '0');
        return $digits === '' ? '0' : ($value[0] === '-' ? '-' : '').$digits;
    }

    private static function compareIntegerStrings($left, $right)
    {
        $ln = $left[0] === '-';
        $rn = $right[0] === '-';
        if ($ln !== $rn) return $ln ? -1 : 1;
        $a = ltrim($left, '-');
        $b = ltrim($right, '-');
        $order = (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
        return $ln ? -$order : $order;
    }

    /** Suma con signo sobre dígitos; memoria proporcional a la longitud escrita del exponente. */
    private static function addIntegerStrings($left, $right)
    {
        $left = self::canonicalIntegerString($left);
        $right = self::canonicalIntegerString($right);
        $negative = $left[0] === '-';
        $sameSign = $negative === ($right[0] === '-');
        $a = ltrim($left, '-');
        $b = ltrim($right, '-');
        if (!$sameSign && self::compareIntegerStrings($a, $b) < 0) {
            [$a, $b] = [$b, $a];
            $negative = !$negative;
        }
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0; $i--, $j--) {
            $x = $i >= 0 ? (int) $a[$i] : 0;
            $y = $j >= 0 ? (int) $b[$j] : 0;
            $digit = $sameSign ? $x + $y + $carry : $x - $y - $carry;
            $carry = $sameSign ? intdiv($digit, 10) : ($digit < 0 ? 1 : 0);
            $result .= (string) (($digit + 10) % 10);
        }
        if ($sameSign && $carry) $result .= '1';
        return self::canonicalIntegerString(($negative ? '-' : '').strrev($result));
    }

    /** Valida la precisión contable; sólo admite enteros entre 0 y 4. */
    public static function decimals($decimals = null)
    {
        $decimals = $decimals ?? config('cart.format.decimals', 2);
        if (!is_int($decimals) || $decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new \InvalidArgumentException('cart.format.decimals must be an integer between 0 and 4.');
        }
        return $decimals;
    }

    /** Escala única para importes, impuestos y ajustes. */
    public static function scale($decimals = null)
    {
        return 10 ** self::decimals($decimals);
    }

    /**
     * Convierte dígitos decimales a enteros, sin redondear previamente el valor escalado.
     * Los numeric-string conservan su representación exacta, incluida notación científica.
     * Los floats se normalizan a 15 cifras significativas para quitar ruido IEEE-754
     * habitual (p. ej. .29); no se recuperan dígitos perdidos ni se distinguen diferencias
     * más allá de esas cifras. Para fronteras exactas se deben suministrar strings.
     * Sólo el primer dígito descartado decide HALF_UP; truncar simplemente lo descarta.
     */
    public static function minorUnits($value, $truncate = false, $decimals = null)
    {
        $decimals = self::decimals($decimals);
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 1000000000) {
            throw new \InvalidArgumentException('Invalid monetary value (maximum absolute amount: 1 billion).');
        }
        $decimal = is_float($value)
            ? str_replace(localeconv()['decimal_point'], '.', sprintf('%.15g', $value))
            : trim((string) $value);
        preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $decimal, $parts);
        $digits = $parts[2] . ($parts[3] ?? '');
        $significant = ltrim($digits, '0');
        if ($significant === '') return 0;
        $exponent = (float) ($parts[4] ?? 0);
        // Evita desbordar el exponente o reservar memoria por exponentes negativos enormes.
        if ($exponent < -strlen($decimal) - self::MAX_DECIMALS) return 0;
        $point = strlen($parts[2]) - (strlen($digits) - strlen($significant)) + (int) $exponent;
        if ($point > 10 || ($point === 10 && ($significant[0] !== '1' || trim(substr($significant, 1), '0') !== ''))) {
            throw new \InvalidArgumentException('Invalid monetary value (maximum absolute amount: 1 billion).');
        }
        $cut = $point + $decimals;
        $whole = $cut > 0 ? str_pad(substr($significant, 0, $cut), $cut, '0') : '0';
        $limit = (string) intdiv(PHP_INT_MAX, 4);
        if (strlen($whole) > strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) > 0)) {
            throw new \InvalidArgumentException('Amount exceeds the supported integer range.');
        }
        $units = (int) $whole;
        if (!$truncate && $cut >= 0 && isset($significant[$cut]) && $significant[$cut] >= '5') $units++;
        if ($units > intdiv(PHP_INT_MAX, 4)) throw new \InvalidArgumentException('Amount exceeds the supported integer range.');
        return $parts[1] === '-' ? -$units : $units;
    }

    /** Devuelve un importe numérico desde unidades menores de una precisión conocida. */
    public static function fromMinorUnits($units, $decimals = null)
    {
        if (!is_int($units)) throw new \InvalidArgumentException('Minor units must be integers.');
        return $units / self::scale($decimals);
    }

    /** Convierte escalas sin floats; reducir precisión redondea HALF_UP hacia afuera en empates. */
    public static function rescale($units, $from, $to)
    {
        $source = self::scale($from);
        $target = self::scale($to);
        if (!is_int($units) || $units === PHP_INT_MIN) throw new \InvalidArgumentException('Invalid minor units.');
        if ($target >= $source) {
            $factor = intdiv($target, $source);
            if (abs($units) > intdiv(PHP_INT_MAX, 4 * $factor)) throw new \InvalidArgumentException('Scale conversion overflow.');
            return $units * $factor;
        }
        $factor = intdiv($source, $target);
        $absolute = abs($units);
        $result = intdiv($absolute, $factor) + (($absolute % $factor) * 2 >= $factor ? 1 : 0);
        return $units < 0 ? -$result : $result;
    }

    /** Suma unidades menores rechazando desbordamientos en lugar de producir floats. */
    public static function sum(array $units)
    {
        $sum = array_sum($units);
        if (!is_int($sum)) throw new \InvalidArgumentException('Minor unit sum overflow.');
        return $sum;
    }
    /**
     * Alias histórico compatible: cents representa unidades menores de la precisión configurada.
     *
     * Admite valores negativos en este auxiliar; son los consumidores los que
     * restringen precios, costos o bases. Rechaza no numéricos, no finitos y valores
     * absolutos superiores a 1.000.000.000. Delega en la conversión decimal de
     * minorUnits(): HALF_UP o truncamiento hacia cero, con resultado entero.
     * No acepta strings con separadores de presentación que no sean numéricos.
     *
     * @param int|float|numeric-string $value Importe en unidades monetarias.
     * @param bool $truncate True para truncar; false para redondear la mitad hacia arriba.
     * @return int Importe en unidades menores, con su signo.
     * @throws \InvalidArgumentException Si el valor no es numérico, finito o está fuera del límite.
     */
    public static function cents($value, $truncate = false)
    {
        return self::minorUnits($value, $truncate);
    }

    /**
     * Reparte un monto entero entre pesos no negativos conservando cada unidad menor.
     *
     * Calcula el cociente entero y resto exactos de monto por peso entre suma
     * de pesos, evitando multiplicar directamente los dos primeros factores.
     * Asigna inicialmente los cocientes; entrega las unidades menores restantes a los
     * mayores restos y desempata comparando las claves como strings en orden
     * lexicográfico. Conserva claves y orden del array de pesos en el resultado.
     * Así la suma de asignaciones coincide exactamente con amount.
     *
     * Con monto cero devuelve ceros para cada clave, incluso con pesos vacíos
     * o todos cero, después de validar entradas. Un monto positivo exige suma
     * de pesos positiva. Los pesos cero no reciben parte del monto.
     * La suma de pesos debe ser int y no superar PHP_INT_MAX dividido entre cuatro.
     *
     * @param int $amount Monto no negativo, ya expresado en unidades menores.
     * @param array<array-key, int> $weights Bases o pesos enteros no negativos por destinatario.
     * @return array<array-key, int> Asignación en unidades menores para cada clave.
     * @throws \InvalidArgumentException Si monto, pesos o su suma no cumplen los límites enteros.
     * @throws \DomainException Si el monto es positivo y no existe peso total positivo.
     */
    public static function allocate($amount, array $weights)
    {
        $total = array_sum($weights);
        if (!is_int($amount) || $amount < 0 || !is_int($total) || $total > intdiv(PHP_INT_MAX, 4)) {
            throw new \InvalidArgumentException('Allocation exceeds the supported integer range.');
        }
        foreach ($weights as $weight) if (!is_int($weight) || $weight < 0) throw new \InvalidArgumentException('Allocation weights must be nonnegative integer minor units.');
        $result = array_fill_keys(array_keys($weights), 0);
        if (!$amount) return $result;
        if ($total <= 0) throw new \DomainException('A positive product base is required for allocation.');
        $remainders = [];
        foreach ($weights as $key => $weight) {
            // Obtiene cociente y resto exactos sin multiplicar directamente monto por peso.
            list($result[$key], $remainders[$key]) = self::multiplyDivide($amount, $weight, $total);
        }
        uksort($remainders, function ($a, $b) use ($remainders) {
            return ($remainders[$b] <=> $remainders[$a]) ?: strcmp((string) $a, (string) $b);
        });
        $remaining = $amount - array_sum($result);
        foreach ($remainders as $key => $unused) {
            if ($remaining-- <= 0) break;
            $result[$key]++;
        }
        return $result;
    }

    /**
     * Obtiene cociente y resto de amount × weight / total sin formar el producto.
     *
     * Descompone amount en bits mediante divisiones por dos. Acumula pares de
     * cociente/resto y duplica sus partes con normalización por total, evitando
     * el desbordamiento de la multiplicación directa. Lo llama allocate() tras
     * validar enteros, pesos no negativos y divisor positivo.
     *
     * @param int $amount Factor no negativo que se descompone.
     * @param int $weight Peso no negativo, menor o igual que total.
     * @param int $total Divisor positivo con el límite impuesto por allocate().
     * @return array{0: int, 1: int} Cociente entero y resto no negativo menor que total.
     */
    private static function multiplyDivide($amount, $weight, $total)
    {
        $quotient = $remainder = 0;
        $partQuotient = intdiv($weight, $total);
        $partRemainder = $weight % $total;
        while ($amount > 0) {
            if ($amount % 2) {
                $remainder += $partRemainder;
                $quotient += $partQuotient + intdiv($remainder, $total);
                $remainder %= $total;
            }
            $amount = intdiv($amount, 2);
            if (!$amount) break;
            $partRemainder *= 2;
            $partQuotient = $partQuotient * 2 + intdiv($partRemainder, $total);
            $partRemainder %= $total;
        }
        return [$quotient, $remainder];
    }
}
