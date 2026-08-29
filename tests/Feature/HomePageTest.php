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
     * The home page is for readers. Whether the install is healthy is reported
     * to whoever can act on it, at /api/v1/health and on the admin dashboard,
     * and never as a checklist on a visitor's front page.
     */
    public function testTheHomePageCarriesNoInstallOrBuildDetail(): void
    {
        $body = $this->get('/')->body();

        $this->assertStringNotContainsString('Install status', $body);
        $this->assertStringNotContainsString('Migrations', $body);
        $this->assertStringNotContainsString('MAIL_DRIVER', $body);
        $this->assertStringNotContainsString(PHP_VERSION, $body);
        $this->assertStringNotContainsString((string) $this->kernel()->container()->get(
            \App\Core\Config::class
        )->get('app.version'), $body);

        // The health endpoint still says everything the panel used to.
        $health = json_decode($this->get('/api/v1/health')->body(), true);

        $this->assertArrayHasKey('ok', $health);
        $this->assertArrayHasKey('database', $health);
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
