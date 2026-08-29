<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $session = new Session(new Config(BASE_PATH . '/config'));
        $session->start();
        $this->csrf = new Csrf($session);
    }

    public function testTokenIsStableWithinASession(): void
    {
        $this->assertSame($this->csrf->token(), $this->csrf->token());
        $this->assertSame(64, strlen($this->csrf->token()));
    }

    public function testVerifiesTheIssuedToken(): void
    {
        $this->assertTrue($this->csrf->verify($this->csrf->token()));
    }

    public function testRejectsAnythingElse(): void
    {
        $this->csrf->token();

        $this->assertFalse($this->csrf->verify('wrong'));
        $this->assertFalse($this->csrf->verify(''));
        $this->assertFalse($this->csrf->verify(null));
    }

    public function testFieldRendersAHiddenInput(): void
    {
        $this->assertStringContainsString('name="_token"', $this->csrf->field());
        $this->assertStringContainsString($this->csrf->token(), $this->csrf->field());
    }
}
