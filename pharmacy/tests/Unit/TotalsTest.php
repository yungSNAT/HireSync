<?php

namespace PharmacyTests\Unit;

use Espo\Modules\PharmacyRecruitment\Classes\Shift\Totals;
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    public function testBreakComesOffPaidTime(): void
    {
        $this->assertSame(450, (new Totals())->paidMinutes(8 * 3600, 30));
    }

    public function testPaidTimeCannotGoNegative(): void
    {
        $this->assertSame(0, (new Totals())->paidMinutes(600, 30));
    }

    public function testTotalRoundsToTheCent(): void
    {
        $this->assertSame(466.88, (new Totals())->total(450, 62.25));
        $this->assertSame(0.01, (new Totals())->total(1, 0.55));
    }

    public function testFractionalRateKeepsCents(): void
    {
        $this->assertSame(1.15, (new Totals())->total(60, 1.15));
    }

    public function testMarginDoesNotPickUpFloatNoise(): void
    {
        $this->assertSame(0.3, (new Totals())->margin(0.4, 0.1));
    }
}
