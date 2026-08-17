<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class TurnstileConfigurationTest extends TestCase
{
    public function test_test_environment_has_turnstile_test_keys_when_no_keys_are_configured(): void
    {
        $this->assertSame(
            '1x00000000000000000000AA',
            config('services.turnstile.site_key'),
        );

        $this->assertSame(
            '1x0000000000000000000000000000000AA',
            config('services.turnstile.secret_key'),
        );
    }
}
