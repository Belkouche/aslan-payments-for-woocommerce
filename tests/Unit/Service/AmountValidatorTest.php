<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\AmountValidator;
use Aslan\Woo\Service\NonWholeMadException;
use PHPUnit\Framework\TestCase;

final class AmountValidatorTest extends TestCase
{
    public function test_accepts_whole_mad_centimes(): void
    {
        AmountValidator::assertWholeMadCentimes(29900);
        AmountValidator::assertWholeMadCentimes(100);
        self::assertTrue(true); // reached without exception
    }

    public function test_rejects_fractional_centimes(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::assertWholeMadCentimes(12345);
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::assertWholeMadCentimes(0);
    }

    public function test_mad_to_centimes_converts_whole_amount(): void
    {
        self::assertSame(29900, AmountValidator::madToCentimes(299.0));
    }

    public function test_mad_to_centimes_rejects_fractional_mad(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::madToCentimes(299.50);
    }
}
