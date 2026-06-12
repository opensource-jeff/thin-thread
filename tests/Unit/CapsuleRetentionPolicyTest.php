<?php

namespace Tests\Unit;

use App\Support\CapsuleRetentionPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CapsuleRetentionPolicyTest extends TestCase
{
    public function test_breach_capsules_are_retained_indefinitely(): void
    {
        $ingestedAt = CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC');

        $this->assertNull(CapsuleRetentionPolicy::expiresAt(CapsuleRetentionPolicy::BREACH, $ingestedAt));
    }

    public function test_non_breach_capsules_expire_after_three_months(): void
    {
        $ingestedAt = CarbonImmutable::create(2026, 6, 11, 10, 0, 0, 'UTC');

        foreach ([
            CapsuleRetentionPolicy::STEALER,
            CapsuleRetentionPolicy::ULP_LOG,
            CapsuleRetentionPolicy::TELEGRAM,
            CapsuleRetentionPolicy::SCRAPED,
        ] as $policy) {
            $this->assertTrue(
                $ingestedAt->addMonthsNoOverflow(3)->equalTo(CapsuleRetentionPolicy::expiresAt($policy, $ingestedAt))
            );
        }
    }
}
