<?php

namespace PharmacyTests\Unit;

use Espo\Modules\PharmacyRecruitment\Classes\Compliance\CredentialRecord as Credential;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\Evaluator;
use Espo\Modules\PharmacyRecruitment\Classes\Compliance\Result;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private const TYPE = 'Ahpra general registration';
    private const OPEN_TYPE = 'Right to work';

    /** @param Credential[] $credentials */
    private function check(array $credentials, string $onDate = '2026-11-10'): Result
    {
        return (new Evaluator())->evaluate(
            [self::TYPE, self::OPEN_TYPE],
            array_merge($credentials, [new Credential(self::OPEN_TYPE, 'Verified', null)]),
            $onDate,
            [self::OPEN_TYPE],
        );
    }

    public function testNoCredentialIsMissing(): void
    {
        $result = $this->check([]);

        $this->assertFalse($result->isClear());
        $this->assertSame([self::TYPE], $result->missing);
    }

    public function testPendingCredentialIsNotEnough(): void
    {
        $result = $this->check([new Credential(self::TYPE, 'Pending', '2027-11-30')]);

        $this->assertFalse($result->isClear());
        $this->assertSame([self::TYPE], $result->unverified);
    }

    public function testRejectedCredentialIsNotEnough(): void
    {
        $this->assertSame(
            [self::TYPE],
            $this->check([new Credential(self::TYPE, 'Rejected', '2027-11-30')])->unverified
        );
    }

    public function testCurrentThroughTheExpiryDate(): void
    {
        $credential = new Credential(self::TYPE, 'Verified', '2026-11-30');

        $this->assertTrue($this->check([$credential], '2026-11-30')->isClear());
        $this->assertSame([self::TYPE], $this->check([$credential], '2026-12-01')->expired);
    }

    public function testRenewalSupersedesALapsedCredential(): void
    {
        $result = $this->check([
            new Credential(self::TYPE, 'Verified', '2025-11-30'),
            new Credential(self::TYPE, 'Verified', '2027-11-30'),
        ]);

        $this->assertTrue($result->isClear());
        $this->assertSame('2027-11-30', $result->nextExpiry);
    }

    public function testPendingRenewalDoesNotRescueALapsedCredential(): void
    {
        $result = $this->check([
            new Credential(self::TYPE, 'Verified', '2025-11-30'),
            new Credential(self::TYPE, 'Pending', '2027-11-30'),
        ]);

        $this->assertSame([self::TYPE], $result->expired);
    }

    public function testExpiringTypeWithBlankExpiryIsNotVerified(): void
    {
        $this->assertSame(
            [self::TYPE],
            $this->check([new Credential(self::TYPE, 'Verified', null)])->unverified
        );
    }

    public function testOpenEndedTypeHonoursARecordedExpiry(): void
    {
        $result = (new Evaluator())->evaluate(
            [self::OPEN_TYPE],
            [new Credential(self::OPEN_TYPE, 'Verified', '2026-01-01')],
            '2026-11-10',
            [self::OPEN_TYPE],
        );

        $this->assertSame([self::OPEN_TYPE], $result->expired);
    }

    public function testNextExpiryIsTheEarliestAcrossTypes(): void
    {
        $result = (new Evaluator())->evaluate(
            ['A', 'B'],
            [new Credential('A', 'Verified', '2027-03-01'), new Credential('B', 'Verified', '2026-12-01')],
            '2026-11-10',
            [],
        );

        $this->assertSame('2026-12-01', $result->nextExpiry);
    }
}
