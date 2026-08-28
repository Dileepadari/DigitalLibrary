<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message = ''): self
    {
        return new self(405, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            401     => 'You need to sign in to do that.',
            403     => 'You are not allowed to do that.',
            404     => 'That page does not exist.',
            405     => 'That method is not allowed here.',
            419     => 'Your session expired. Please try again.',
            429     => 'Too many requests. Slow down.',
            default => 'Something went wrong.',
        };
    }
}
