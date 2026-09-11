<?php

namespace App\Services\Books;

/**
 * Roman numerals, because front matter is folioed in them.
 *
 * Kept deliberately small and strict. A single letter such as "C" is a valid
 * numeral, and this corpus contains stray capitals that Tesseract read out of
 * a headpiece, so a lone numeral is never trusted on its own -- see
 * PageLabelIndex, which requires a run before it believes a series.
 */
class RomanNumeral
{
    private const VALUES = [
        'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
        'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
        'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1,
    ];

    public static function looksLikeOne(string $value): bool
    {
        return preg_match('/^[ivxlcdm]{1,7}$/i', trim($value)) === 1;
    }

    public static function toInteger(string $value): ?int
    {
        $value = strtoupper(trim($value));

        if ($value === '' || ! self::looksLikeOne($value)) {
            return null;
        }

        $total = 0;
        $index = 0;
        $length = strlen($value);

        while ($index < $length) {
            $pair = substr($value, $index, 2);

            if (isset(self::VALUES[$pair]) && strlen($pair) === 2) {
                $total += self::VALUES[$pair];
                $index += 2;

                continue;
            }

            $single = $value[$index];

            if (! isset(self::VALUES[$single])) {
                return null;
            }

            $total += self::VALUES[$single];
            $index++;
        }

        // Round-tripping rejects malformed numerals such as "IIII" or "XCX",
        // which a purely additive reading would otherwise accept.
        return self::fromInteger($total) === $value ? $total : null;
    }

    public static function fromInteger(int $number): string
    {
        if ($number < 1) {
            return '';
        }

        $result = '';

        foreach (self::VALUES as $numeral => $value) {
            while ($number >= $value) {
                $result .= $numeral;
                $number -= $value;
            }
        }

        return $result;
    }
}
