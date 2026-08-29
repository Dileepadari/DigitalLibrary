<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function testHealthReturnsAStatusReport(): void
    {
        $response = $this->get('/health');
        $payload = json_decode($response->body(), true);

        $this->assertContains($response->status(), [200, 503]);
        $this->assertStringContainsString('application/json', $response->headers()['Content-Type']);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('ok', $payload);
        $this->assertArrayHasKey('version', $payload);
        $this->assertArrayHasKey('php', $payload);
        $this->assertArrayHasKey('database', $payload);
        $this->assertArrayHasKey('storage', $payload);
    }

    public function testStatusCodeFollowsTheReport(): void
    {
        $response = $this->get('/health');
        $payload = json_decode($response->body(), true);

        $this->assertSame($payload['ok'] === true ? 200 : 503, $response->status());
    }

    public function testTheVersionedAliasReturnsTheSameShape(): void
    {
        $short = json_decode($this->get('/health')->body(), true);
        $versioned = json_decode($this->get('/api/v1/health')->body(), true);

        $this->assertSame(array_keys($short), array_keys($versioned));
    }

    public function testRequiredExtensionsArePresent(): void
    {
        $payload = json_decode($this->get('/health')->body(), true);

        $this->assertSame([], $payload['php']['missing_extensions']);
        $this->assertTrue($payload['php']['ok']);
    }
}
