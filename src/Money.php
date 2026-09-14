<?php
namespace JeleDev\Shoppingcart;

/**
 * Encapsula la conversión monetaria a centavos y el reparto proporcional exacto.
 *
 * Cart y CartItem conservan entradas numéricas por compatibilidad; esta clase
 * fija la frontera de dos decimales y evita usar presentación como contabilidad.
 * Los repartos operan con enteros y se convierten a unidades monetarias solo
 * al devolver consultas. No es un motor decimal de precisión arbitraria:
 * cents() convierte la entrada a float y el rango entero depende de PHP.
 * Los importes cercanos al límite admitido requieren enteros de 64 bits.
 */
final class Money
{
    /**
     * Convierte un importe numérico a centavos con redondeo o truncamiento.
     *
     * Admite valores negativos en este auxiliar; son los consumidores los que
     * restringen precios, costos o bases. Rechaza no numéricos, no finitos y valores
     * absolutos superiores a 1.000.000.000. Multiplica por 100 como float y redondea
     * el valor escalado a seis decimales para reducir ruido de representación.
     * Después usa mitad hacia arriba o truncamiento hacia cero; devuelve un int.
     * No acepta strings con separadores de presentación que no sean numéricos.
     *
     * @param int|float|numeric-string $value Importe en unidades monetarias.
     * @param bool $truncate True para truncar; false para redondear la mitad hacia arriba.
     * @return int Importe en centavos, con su signo.
     * @throws \InvalidArgumentException Si el valor no es numérico, finito o está fuera del límite.
     */
    public static function cents($value, $truncate = false)
    {
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > 1000000000) {
            throw new \InvalidArgumentException('Invalid monetary value (maximum absolute amount: 1 billion).');
        }
        $scaled = round((float) $value * 100, 6);
        return (int) ($truncate ? ($scaled < 0 ? ceil($scaled) : floor($scaled)) : round($scaled, 0, PHP_ROUND_HALF_UP));
    }

    /**
     * Reparte un monto entero entre pesos no negativos conservando cada centavo.
     *
     * Calcula el cociente entero y resto exactos de monto por peso entre suma
     * de pesos, evitando multiplicar directamente los dos primeros factores.
     * Asigna inicialmente los cocientes; entrega los centavos restantes a los
     * mayores restos y desempata comparando las claves como strings en orden
     * lexicográfico. Conserva claves y orden del array de pesos en el resultado.
     * Así la suma de asignaciones coincide exactamente con amount.
     *
     * Con monto cero devuelve ceros para cada clave, incluso con pesos vacíos
     * o todos cero, después de validar entradas. Un monto positivo exige suma
     * de pesos positiva. Los pesos cero no reciben parte del monto.
     * La suma de pesos debe ser int y no superar PHP_INT_MAX dividido entre cuatro.
     *
     * @param int $amount Monto no negativo, ya expresado en centavos.
     * @param array<array-key, int> $weights Bases o pesos enteros no negativos por destinatario.
     * @return array<array-key, int> Asignación en centavos para cada clave.
     * @throws \InvalidArgumentException Si monto, pesos o su suma no cumplen los límites enteros.
     * @throws \DomainException Si el monto es positivo y no existe peso total positivo.
     */
    public static function allocate($amount, array $weights)
    {
        $total = array_sum($weights);
        if (!is_int($amount) || $amount < 0 || !is_int($total) || $total > intdiv(PHP_INT_MAX, 4)) {
            throw new \InvalidArgumentException('Allocation exceeds the supported integer range.');
        }
        foreach ($weights as $weight) if (!is_int($weight) || $weight < 0) throw new \InvalidArgumentException('Allocation weights must be nonnegative integer cents.');
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
