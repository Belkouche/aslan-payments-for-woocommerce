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
    public static function madToCentimes(string $mad): int
    {
        $mad = trim($mad);
        if ($mad === '' || !preg_match('/^\d+(\.\d+)?$/', $mad)) {
            throw new NonWholeMadException(
                sprintf('Amount must be a decimal string like "299.00". Got: "%s"', $mad)
            );
        }
        // Split on the decimal point. No point means zero fractional part.
        $parts = explode('.', $mad);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        // Reject any non-zero fractional digit — fractional MAD is not accepted.
        if (ltrim($fraction, '0') !== '') {
            throw new NonWholeMadException(
                sprintf('Amount must be whole MAD. Got: %s MAD', $mad)
            );
        }

        $centimes = (int) $whole * 100;
        if ($centimes <= 0) {
            throw new NonWholeMadException(
                sprintf('Amount must be positive. Got: %s MAD', $mad)
            );
        }
        return $centimes;
    }
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
