<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequestStatus;
use PHPUnit\Framework\TestCase;

final class RequestStatusTest extends TestCase
{
    public function testOpenAndClaimedBothCountAsOutstanding(): void
    {
        $this->assertTrue(RequestStatus::Open->isOpen());
        $this->assertTrue(RequestStatus::Claimed->isOpen());
    }

    public function testAnEndingIsAnEnding(): void
    {
        $endings = [
            RequestStatus::Fulfilled,
            RequestStatus::Unavailable,
            RequestStatus::Duplicate,
            RequestStatus::Rejected,
        ];

        foreach ($endings as $status) {
            $this->assertFalse($status->isOpen(), $status->value . ' should not be open');
        }
    }

    public function testFulfilledIsNotSomethingAPersonPicks(): void
    {
        // It comes from a book arriving, never from a dropdown.
        $this->assertNotContains(RequestStatus::Fulfilled, RequestStatus::closable());
        $this->assertContains(RequestStatus::Unavailable, RequestStatus::closable());
        $this->assertContains(RequestStatus::Duplicate, RequestStatus::closable());
        $this->assertContains(RequestStatus::Rejected, RequestStatus::closable());
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (RequestStatus::all() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
