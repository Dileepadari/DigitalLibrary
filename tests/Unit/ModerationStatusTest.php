<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ModerationStatus;
use App\Support\RejectionReason;
use PHPUnit\Framework\TestCase;

final class ModerationStatusTest extends TestCase
{
    public function testTheOpenStatesAreTheOnesStillInTheQueue(): void
    {
        $this->assertTrue(ModerationStatus::Pending->isOpen());
        $this->assertTrue(ModerationStatus::UnderReview->isOpen());
        $this->assertTrue(ModerationStatus::ChangesRequested->isOpen());

        $this->assertTrue(ModerationStatus::Approved->isFinal());
        $this->assertTrue(ModerationStatus::Rejected->isFinal());
        $this->assertTrue(ModerationStatus::Withdrawn->isFinal());
    }

    public function testTheHappyPath(): void
    {
        $this->assertTrue(ModerationStatus::Pending->canMoveTo(ModerationStatus::UnderReview));
        $this->assertTrue(ModerationStatus::UnderReview->canMoveTo(ModerationStatus::Approved));
    }

    public function testChangesRequestedGoesBackIntoTheQueue(): void
    {
        $this->assertTrue(ModerationStatus::UnderReview->canMoveTo(ModerationStatus::ChangesRequested));
        $this->assertTrue(ModerationStatus::ChangesRequested->canMoveTo(ModerationStatus::Pending));
    }

    public function testADecisionIsTheEndOfTheLine(): void
    {
        foreach (ModerationStatus::all() as $status) {
            $this->assertFalse(
                ModerationStatus::Approved->canMoveTo($status),
                'Approved should not move to ' . $status->value
            );
            $this->assertFalse(ModerationStatus::Rejected->canMoveTo($status));
            $this->assertFalse(ModerationStatus::Withdrawn->canMoveTo($status));
        }
    }

    public function testYouCannotSkipStraightFromWaitingToApproved(): void
    {
        // A reviewer has to claim it first, which is the under_review step.
        $this->assertFalse(ModerationStatus::Pending->canMoveTo(ModerationStatus::Approved));
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ModerationStatus::all() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }

    public function testOnlyCopyrightRejectionsCountAsStrikes(): void
    {
        $this->assertTrue(RejectionReason::isCopyright(RejectionReason::COPYRIGHT));
        $this->assertFalse(RejectionReason::isCopyright('Poor scan quality'));
        $this->assertContains(RejectionReason::COPYRIGHT, RejectionReason::all());
    }
}
