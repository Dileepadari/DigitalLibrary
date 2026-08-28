<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ErrorHandlingTest extends TestCase
{
    public function testUnknownPageRendersThe404View(): void
    {
        $response = $this->get('/no-such-page');

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('404', $response->body());
        $this->assertStringContainsString('Back to the library', $response->body());
    }

    public function testUnknownApiPathReturnsJson(): void
    {
        $response = $this->get('/api/v1/no-such-thing');
        $payload = json_decode($response->body(), true);

        $this->assertSame(404, $response->status());
        $this->assertSame(404, $payload['status']);
        $this->assertArrayHasKey('error', $payload);
    }
}
