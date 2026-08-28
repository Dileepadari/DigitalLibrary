<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HomePageTest extends TestCase
{
    public function testHomePageRenders(): void
    {
        $response = $this->get('/');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Install status', $response->body());
        $this->assertStringContainsString('text/html', $response->headers()['Content-Type']);
    }

    public function testHomePageReportsEveryStatusCheck(): void
    {
        $body = $this->get('/')->body();

        foreach (['PHP', 'Database', 'Migrations', 'Storage'] as $check) {
            $this->assertStringContainsString($check, $body);
        }
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $headers = $this->get('/')->headers();

        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString("script-src 'self'; style-src", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("object-src 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function testTheLayoutHasNoInlineScript(): void
    {
        $body = $this->get('/')->body();

        // The CSP forbids inline script, so a stray <script> block would break
        // the page in the browser without failing anything server side.
        $this->assertSame(
            0,
            preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>/i', $body),
            'Inline <script> blocks are not allowed by the CSP.'
        );
    }
}
