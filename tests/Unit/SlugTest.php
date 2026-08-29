<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testLowercasesAndHyphenates(): void
    {
        $this->assertSame('pride-and-prejudice', Slug::make('Pride and Prejudice'));
        $this->assertSame('the-time-machine', Slug::make('  The   Time Machine  '));
    }

    public function testDropsPunctuation(): void
    {
        $this->assertSame('alices-adventures-in-wonderland', Slug::make("Alice's Adventures in Wonderland"));
        $this->assertSame('c-a-primer', Slug::make('C++: A Primer!'));
    }

    public function testTransliteratesAccents(): void
    {
        $this->assertSame('les-miserables', Slug::make('Les Misérables'));
    }

    public function testTruncatesWithoutLeavingATrailingHyphen(): void
    {
        $slug = Slug::make('a very long title that keeps going and going', 20);

        $this->assertLessThanOrEqual(20, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testAScriptWithNoAsciiFormGivesAnEmptySlug(): void
    {
        // The caller has to fall back to something else, which is why unique()
        // substitutes a base rather than producing an empty address.
        $this->assertSame('item', Slug::unique('हिन्दी', static fn (): bool => false));
    }

    public function testUniqueAppendsUntilItIsFree(): void
    {
        $taken = ['dune' => true, 'dune-2' => true];

        $this->assertSame(
            'dune-3',
            Slug::unique('Dune', static fn (string $candidate): bool => isset($taken[$candidate]))
        );
    }

    public function testUniqueReturnsTheBaseWhenNothingIsTaken(): void
    {
        $this->assertSame('dune', Slug::unique('Dune', static fn (): bool => false));
    }
}
