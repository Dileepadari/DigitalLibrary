<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

/**
 * The parts of accessibility a server-rendered page can be held to: a language,
 * a way past the navigation, labelled controls, alternative text, and errors
 * that are attached to the field they are about.
 *
 * The rest (contrast, focus order in a live browser) is a manual pass.
 */
final class AccessibilityTest extends DatabaseTestCase
{
    /** @return list<string> the pages any visitor can reach */
    private function publicPages(): array
    {
        $this->makeBook('Meditations', ['authors' => ['Marcus Aurelius'], 'categories' => ['/religion/']]);

        return ['/', '/books', '/books/meditations', '/categories', '/tags', '/requests', '/collections',
            '/contributors', '/report', '/login', '/register'];
    }

    public function testEveryPageDeclaresItsLanguageAndOffersASkipLink(): void
    {
        foreach ($this->publicPages() as $path) {
            $body = $this->get($path)->body();

            $this->assertStringContainsString('<html lang="en">', $body, $path . ' should declare a language.');
            $this->assertStringContainsString('class="skip-link" href="#main"', $body, $path . ' needs a skip link.');
            $this->assertStringContainsString('id="main"', $body, $path . ' needs the target for it.');
        }
    }

    public function testEveryPageHasExactlyOneFirstLevelHeading(): void
    {
        foreach ($this->publicPages() as $path) {
            $body = $this->get($path)->body();

            $this->assertSame(
                1,
                substr_count($body, '<h1'),
                $path . ' should have one h1, not ' . substr_count($body, '<h1') . '.'
            );
        }
    }

    public function testEveryImageHasAlternativeText(): void
    {
        foreach ($this->publicPages() as $path) {
            preg_match_all('/<img\b[^>]*>/i', $this->get($path)->body(), $images);

            foreach ($images[0] as $image) {
                $this->assertMatchesRegularExpression(
                    '/\balt="/',
                    $image,
                    $path . ' has an image with no alt attribute: ' . $image
                );
            }
        }
    }

    public function testEveryFormControlIsLabelled(): void
    {
        foreach (['/login', '/register', '/report'] as $path) {
            $body = $this->get($path)->body();

            preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $body, $controls);

            foreach ($controls[0] as $control) {
                if (preg_match('/type="(hidden|submit)"/i', $control) === 1) {
                    continue;
                }

                preg_match('/\bid="([^"]+)"/', $control, $id);

                $labelled = ($id !== [] && str_contains($body, 'for="' . $id[1] . '"'))
                    || str_contains($control, 'aria-label=');

                $this->assertTrue($labelled, $path . ' has an unlabelled control: ' . $control);
            }
        }
    }

    public function testARejectedFieldPointsAtItsError(): void
    {
        $this->post('/login', ['email' => 'not-an-address', 'password' => '']);

        $body = $this->get('/login')->body();

        $this->assertStringContainsString('id="email-error"', $body);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="email"[^>]*aria-invalid="true"[^>]*aria-describedby="email-error"/',
            $body,
            'The field should say it is invalid and point at the message.'
        );
    }

    public function testFlashMessagesAreAnnounced(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $body = $this->get('/')->body();

        $this->assertMatchesRegularExpression('/role="status" aria-live="polite"/', $body);
    }

    public function testTheCurrentPageIsMarkedInNavigation(): void
    {
        $this->makeBook('Polity', ['categories' => ['/academics/competitive-exams/upsc/']]);

        $body = $this->get('/categories/academics/competitive-exams/upsc')->body();

        $this->assertStringContainsString('aria-current="page"', $body);
    }

    public function testTablesHaveHeaderCells(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        foreach (['/admin/users', '/admin/audit'] as $path) {
            $body = $this->get($path)->body();

            if (!str_contains($body, '<table')) {
                continue;
            }

            $this->assertStringContainsString('<th scope="col">', $body, $path . ' has a table with no headers.');
        }
    }
}
