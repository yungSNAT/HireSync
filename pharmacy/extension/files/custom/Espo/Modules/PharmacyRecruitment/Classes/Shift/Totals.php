<?php

namespace Espo\Modules\PharmacyRecruitment\Classes\Shift;

/**
 * Works out a shift's paid hours and flat-rate totals.
 *
 * shortcut: rates are the flat hourly rates agreed for the shift. Award penalties, overtime,
 * superannuation, payroll tax and GST are outside this; payroll and accounting systems own them.
 */
final class Totals
{
    public function paidMinutes(int $workedSeconds, int $breakMinutes): int
    {
        return max(0, (int) floor($workedSeconds / 60) - $breakMinutes);
    }

    /**
     * Works in whole cents so that binary fractions cannot move a rounded total.
     */
    public function total(int $paidMinutes, float $hourlyRate): float
    {
        $rateCents = (int) round($hourlyRate * 100);

        return round($paidMinutes * $rateCents / 60) / 100;
    }

    public function margin(float $chargeTotal, float $payTotal): float
    {
        return (round($chargeTotal * 100) - round($payTotal * 100)) / 100;
    }
}
