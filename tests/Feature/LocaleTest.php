<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Translator;
use Tests\TestCase;

final class LocaleTest extends TestCase
{
    private function translator(): Translator
    {
        return $this->kernel()->container()->get(Translator::class);
    }

    public function testTheDefaultIsEnglish(): void
    {
        $this->assertSame('en', $this->translator()->locale());
        $this->assertSame('Browse', $this->translator()->get('Browse'));
    }

    public function testAKeyWithNoTranslationRendersAsItself(): void
    {
        // The key is the English string, so an untranslated template still says
        // something sensible rather than "nav.browse".
        $this->assertSame('Not in any language file', $this->translator()->get('Not in any language file'));
    }

    public function testPlaceholdersAreFilled(): void
    {
        $this->assertSame(
            'Open source. Built with PHP 8.3.6.',
            $this->translator()->get('Open source. Built with PHP :version.', ['version' => '8.3.6'])
        );
    }

    public function testHindiIsAvailableAndTranslatesTheChrome(): void
    {
        $translator = $this->translator();
        $translator->setLocale('hi');

        $this->assertSame('hi', $translator->locale());
        $this->assertSame('खोजें', $translator->get('Browse'));
    }

    public function testAnUnknownLocaleIsIgnored(): void
    {
        $translator = $this->translator();
        $translator->setLocale('xx');

        $this->assertSame('en', $translator->locale(), 'An unknown locale should leave the current one alone.');
        $this->assertFalse($translator->available('../etc/passwd'));
    }

    public function testTheSwitcherChangesThePageAndIsRemembered(): void
    {
        $english = $this->get('/');
        $this->assertStringContainsString('<html lang="en"', $english->body());
        $this->assertStringContainsString('>Browse</a>', $english->body());

        $hindi = $this->get('/?lang=hi');
        $this->assertStringContainsString('<html lang="hi"', $hindi->body());
        $this->assertStringContainsString('खोजें', $hindi->body());

        // The choice sticks for the next page without the query string.
        $next = $this->get('/books');
        $this->assertStringContainsString('<html lang="hi"', $next->body());
    }

    public function testTheSwitcherKeepsTheFiltersYouAreLookingAt(): void
    {
        $body = $this->get('/books?q=plato&type=book')->body();

        // The link back to Hindi carries the search with it, so changing
        // language does not throw the results away.
        $this->assertMatchesRegularExpression(
            '~href="/books\?[^"]*q=plato[^"]*lang=hi~',
            $body
        );
    }

    public function testEveryLocaleFileCarriesTheSameKeys(): void
    {
        $english = require BASE_PATH . '/resources/lang/en.php';

        foreach (glob(BASE_PATH . '/resources/lang/*.php') ?: [] as $file) {
            $lines = require $file;
            $name = basename($file);

            $this->assertSame(
                [],
                array_diff(array_keys($english), array_keys($lines)),
                $name . ' is missing keys that en.php has'
            );
            $this->assertSame(
                [],
                array_diff(array_keys($lines), array_keys($english)),
                $name . ' has keys en.php does not'
            );
        }
    }

    public function testTheFooterOffersTheLanguagesThatExist(): void
    {
        $body = $this->get('/')->body();

        $this->assertStringContainsString('?lang=en', $body);
        $this->assertStringContainsString('?lang=hi', $body);
        $this->assertStringNotContainsString('?lang=fr', $body);
    }
}
