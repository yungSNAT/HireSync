<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Identifier;

/**
 * Australian Business Number check digits, as published by the Australian Business Register.
 * Spaces are ignored. A valid result proves the number is well formed, not that it is active.
 */
final class Abn
{
    private const WEIGHTS = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

    public static function isValid(string $value): bool
    {
        $digits = str_replace(' ', '', $value);

        if (!preg_match('/^[0-9]{11}$/', $digits)) {
            return false;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $index => $weight) {
            $digit = (int) $digits[$index] - ($index === 0 ? 1 : 0);
            $sum += $digit * $weight;
        }

        return $sum % 89 === 0;
    }
}
