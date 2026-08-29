<?php

declare(strict_types=1);

namespace App\Support;

enum AuthResult
{
    case Success;
    case InvalidCredentials;
    case Throttled;
    case Banned;

    public function message(): string
    {
        return match ($this) {
            self::Success            => 'Signed in.',
            self::InvalidCredentials => 'That email and password do not match.',
            self::Throttled          => 'Too many attempts. Wait a few minutes and try again.',
            self::Banned             => 'This account has been suspended. Contact the administrators.',
        };
    }
}
