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
        $this->assertStringContainsString('Title, author, or a phrase from inside a book', $response->body());
        $this->assertStringContainsString('text/html', $response->headers()['Content-Type']);
    }

    /**
     * The install checklist is for whoever has to fix it: an admin, or anyone
     * at all while something is broken. A healthy public library does not put a
     * checklist on its front page.
     */
    public function testTheInstallPanelOnlyShowsWhenSomethingNeedsDoing(): void
    {
        $healthy = json_decode($this->get('/api/v1/health')->body(), true)['ok'] ?? false;
        $body = $this->get('/')->body();

        if ($healthy === true) {
            $this->assertStringNotContainsString('Install status', $body);

            return;
        }

        $this->assertStringContainsString('Install status', $body);

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
