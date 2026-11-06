<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentConfigurationTest extends TestCase
{
    public function test_maintenance_state_is_isolated_from_the_deployed_application(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('cache', config('app.maintenance.driver'));
        $this->assertSame('array', config('app.maintenance.store'));
    }
}
