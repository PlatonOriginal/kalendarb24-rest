<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Domain\ApprovalRoute;
use Kb24Rest\Domain\Status;
use PHPUnit\Framework\TestCase;

final class ApprovalRouteTest extends TestCase
{
    public function testEmployeeGoesHeadThenHrThenDirector(): void
    {
        $route = new ApprovalRoute(false, false);

        self::assertSame(Status::HEAD_REVIEW, $route->getFirstStatus());
        self::assertSame(Status::HR_REVIEW, $route->getNextStatus(Status::HEAD_REVIEW));
        self::assertSame(Status::FINAL_APPROVAL, $route->getNextStatus(Status::HR_REVIEW));
        self::assertSame(Status::APPROVED, $route->getNextStatus(Status::FINAL_APPROVAL));
    }

    public function testDirectorRequestIsCheckedOnlyByHr(): void
    {
        $route = new ApprovalRoute(true, false);

        self::assertSame(Status::HR_REVIEW, $route->getFirstStatus());
        self::assertSame(Status::APPROVED, $route->getNextStatus(Status::HR_REVIEW));
    }

    public function testDirectorDoesNotApproveTwice(): void
    {
        $route = new ApprovalRoute(false, true);

        self::assertSame(Status::HEAD_REVIEW, $route->getFirstStatus());
        self::assertSame(Status::APPROVED, $route->getNextStatus(Status::HR_REVIEW));
    }

    public function testClosedRequestCannotBeApproved(): void
    {
        $this->expectException(\LogicException::class);
        (new ApprovalRoute(false, false))->getNextStatus(Status::REJECTED);
    }

    public function testRejectAndWithdrawOnlyBeforeApproval(): void
    {
        foreach ([Status::HEAD_REVIEW, Status::HR_REVIEW, Status::FINAL_APPROVAL] as $status)
        {
            self::assertTrue(ApprovalRoute::canReject($status));
            self::assertTrue(ApprovalRoute::canWithdraw($status));
        }
        foreach ([Status::APPROVED, Status::REJECTED, Status::WITHDRAWN, Status::CANCELLED] as $status)
        {
            self::assertFalse(ApprovalRoute::canReject($status));
            self::assertFalse(ApprovalRoute::canWithdraw($status));
        }
    }

    public function testWithdrawNotifiesOnlyAfterHeadApproval(): void
    {
        self::assertFalse(ApprovalRoute::withdrawNotifiesParticipants(Status::HEAD_REVIEW));
        self::assertTrue(ApprovalRoute::withdrawNotifiesParticipants(Status::HR_REVIEW));
    }
}
