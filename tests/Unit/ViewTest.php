<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use Tests\TestCase;

final class ViewTest extends TestCase
{
    /**
     * The configured view, from the real container: the layout reads the shared
     * auth and csrf helpers, so a hand-built View would not render it.
     */
    private function view(): View
    {
        return $this->kernel()->container()->get(View::class);
    }

    public function testRendersATemplateInsideItsLayout(): void
    {
        $html = $this->view()->render('errors/404', ['message' => 'That page does not exist.']);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('That page does not exist.', $html);
        $this->assertStringContainsString('site-header', $html, 'The layout should have rendered.');
    }

    public function testSectionsFeedTheLayoutSlots(): void
    {
        $html = $this->view()->render('errors/404', ['message' => 'gone']);

        $this->assertStringContainsString('<title>Not found</title>', $html);
    }

    public function testEscapesOutput(): void
    {
        $html = $this->view()->render('errors/404', ['message' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPageDataIsNotShadowedByTheViewsOwnLocals(): void
    {
        // A page variable called $file used to be swallowed by the local the
        // renderer used for the template path, and the template rendered with a
        // filename where its data should be.
        $html = $this->view()->render('errors/404', ['message' => 'x', 'file' => 'DATA-NOT-PATH']);

        $this->assertStringNotContainsString('app/Views', $html);
        $this->assertStringNotContainsString('.php', $html);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->view()->render('pages/does-not-exist');
    }
}
