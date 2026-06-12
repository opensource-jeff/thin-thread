<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class CapsuleRetentionPolicy
{
    public const BREACH = 'breach';

    public const STEALER = 'stealer';

    public const ULP_LOG = 'ulp_log';

    public const TELEGRAM = 'telegram';

    public const SCRAPED = 'scraped';

    /**
     * Get available retention policy options.
     *
     * @return array<string, array{label: string, retention: string}>
     */
    public static function options(): array
    {
        return [
            self::BREACH => [
                'label' => 'Breach capsule',
                'retention' => 'Retain indefinitely',
            ],
            self::STEALER => [
                'label' => 'Stealer logs',
                'retention' => 'Retain for 3 months',
            ],
            self::ULP_LOG => [
                'label' => 'ULP logs',
                'retention' => 'Retain for 3 months',
            ],
            self::TELEGRAM => [
                'label' => 'Telegram data',
                'retention' => 'Retain for 3 months',
            ],
            self::SCRAPED => [
                'label' => 'Scraped data',
                'retention' => 'Retain for 3 months',
            ],
        ];
    }

    /**
     * Get the valid policy values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }

    public static function default(): string
    {
        return self::BREACH;
    }

    public static function label(string $policy): string
    {
        return self::options()[$policy]['label'] ?? $policy;
    }

    public static function retentionDescription(string $policy): string
    {
        return self::options()[$policy]['retention'] ?? 'Unknown retention';
    }

    public static function expiresAt(string $policy, CarbonImmutable $ingestedAt): ?CarbonImmutable
    {
        return match ($policy) {
            self::BREACH => null,
            self::STEALER,
            self::ULP_LOG,
            self::TELEGRAM,
            self::SCRAPED => $ingestedAt->addMonthsNoOverflow(3),
            default => throw new \InvalidArgumentException("Unknown capsule retention policy [{$policy}]."),
        };
    }
}
