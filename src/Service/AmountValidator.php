<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are not HTML output.
final class AmountValidator
{
    public static function assertWholeMadCentimes(int $centimes): void
    {
        if ($centimes <= 0 || $centimes % 100 !== 0) {
            throw new NonWholeMadException(
                sprintf('Amount must be a whole number of MAD (multiple of 100 centimes). Got: %d', $centimes)
            );
        }
    }

    /**
     * Convert a MAD amount (e.g. 299.00) into centimes (29900).
     * Fractional MAD is rejected: the NAPS PSP does not accept it.
     */
    public static function madToCentimes(float $mad): int
    {
        $centimes = (int) round($mad * 100);
        if (abs($mad * 100 - $centimes) > 0.0001) {
            throw new NonWholeMadException(sprintf('Amount must be whole MAD. Got: %.4f MAD', $mad));
        }
        self::assertWholeMadCentimes($centimes);
        return $centimes;
    }
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
