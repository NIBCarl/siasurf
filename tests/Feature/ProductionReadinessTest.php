<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }

    public function test_force_https_config_forces_https_urls(): void
    {
        config(['app.force_https' => true]);
        URL::forceScheme('https');

        $this->assertStringStartsWith('https://', URL::to('/up'));
    }

    public function test_forwarded_https_headers_are_trusted(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'siasurf.example.com',
        ])->get('/up');

        $response->assertOk();
    }
}
