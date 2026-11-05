<?php

namespace PharmacyTests\Unit;

use Espo\Modules\PharmacyRecruitment\Classes\Identifier\Abn;
use PHPUnit\Framework\TestCase;

final class AbnTest extends TestCase
{
    public function testPublishedExample(): void
    {
        $this->assertTrue(Abn::isValid('51 824 753 556'));
        $this->assertTrue(Abn::isValid('51824753556'));
    }

    public function testWrongCheckDigits(): void
    {
        $this->assertFalse(Abn::isValid('51 824 753 557'));
    }

    public function testWrongShape(): void
    {
        $this->assertFalse(Abn::isValid('5182475355'));
        $this->assertFalse(Abn::isValid('5182475355A'));
        $this->assertFalse(Abn::isValid(''));
    }
}
