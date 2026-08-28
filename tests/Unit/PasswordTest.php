<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testHashesAreSaltedAndVerifiable(): void
    {
        $first = Password::hash('correct-horse-battery');
        $second = Password::hash('correct-horse-battery');

        $this->assertNotSame($first, $second, 'Two hashes of one password must differ.');
        $this->assertTrue(Password::verify('correct-horse-battery', $first));
        $this->assertTrue(Password::verify('correct-horse-battery', $second));
    }

    public function testWrongPasswordFails(): void
    {
        $this->assertFalse(Password::verify('nearly-right', Password::hash('correct-horse-battery')));
    }

    public function testFreshHashDoesNotNeedRehashing(): void
    {
        $this->assertFalse(Password::needsRehash(Password::hash('correct-horse-battery')));
    }

    public function testAnOlderAlgorithmIsFlaggedForRehash(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('This build has no Argon2id, so there is nothing to upgrade from.');
        }

        $this->assertTrue(Password::needsRehash(password_hash('correct-horse-battery', PASSWORD_BCRYPT)));
    }
}
