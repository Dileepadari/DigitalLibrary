<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testPassesWhenEveryRuleIsSatisfied(): void
    {
        $validator = new Validator(
            ['email' => 'reader@example.com', 'password' => 'longenough', 'title' => 'Dune'],
            ['email' => 'required|email', 'password' => 'required|min:8', 'title' => 'required|max:255']
        );

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->errors());
    }

    public function testCollectsOneMessagePerBrokenRule(): void
    {
        $validator = new Validator(
            ['email' => 'not-an-email', 'password' => 'short'],
            ['email' => 'required|email', 'password' => 'required|min:8']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('Enter a valid email address.', $validator->first('email'));
        $this->assertStringContainsString('at least 8', (string) $validator->first('password'));
    }

    public function testOptionalFieldsAreSkippedWhenEmpty(): void
    {
        $validator = new Validator(['isbn' => ''], ['isbn' => 'min:10']);

        $this->assertTrue($validator->passes());
    }

    public function testRequiredFieldsAreNotSkippedWhenEmpty(): void
    {
        $validator = new Validator(['title' => ''], ['title' => 'required|min:2']);

        $this->assertSame('Title is required.', $validator->first('title'));
    }

    public function testMatchesComparesTwoFields(): void
    {
        $ok = new Validator(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password_confirmation' => 'required|matches:password']
        );
        $bad = new Validator(
            ['password' => 'secret123', 'password_confirmation' => 'different'],
            ['password_confirmation' => 'required|matches:password']
        );

        $this->assertTrue($ok->passes());
        $this->assertTrue($bad->fails());
    }

    public function testSlugRuleRejectsSpacesAndCapitals(): void
    {
        $this->assertTrue((new Validator(['slug' => 'upsc-preparation'], ['slug' => 'slug']))->passes());
        $this->assertTrue((new Validator(['slug' => 'UPSC Prep'], ['slug' => 'slug']))->fails());
    }

    public function testUnknownRuleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Validator(['a' => 'b'], ['a' => 'nonsense']);
    }

    public function testLabelsAreUsedInMessages(): void
    {
        $validator = new Validator([], ['published_year' => 'required'], ['published_year' => 'year of publication']);

        $this->assertSame('Year of publication is required.', $validator->first('published_year'));
    }
}
