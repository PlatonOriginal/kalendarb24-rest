<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\AbsenceCalendar;
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
use Kb24Rest\Vacation\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Структура как на тестовом портале: директор 1 руководит компанией, в бухгалтерии руководитель 30,
 * в отделе продаж руководителя нет. ОК: 40 и 41.
 */
final class RequestServiceTest extends TestCase
{
    private const DIRECTOR = 1;
    private const ACCOUNTING_HEAD = 30;
    private const ACCOUNTANT = 31;
    private const SALES_MANAGER = 100;
    private const HR = [40, 41];

    private FakePortal $http;
    private RoleService $roles;
    private RequestService $service;
    private Notifier $notifier;
    private AbsenceCalendar $absence;
    private Portal $portal;

    protected function setUp(): void
    {
        $this->http = new FakePortal(
            [
                ['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '1'],
                ['ID' => '5', 'NAME' => 'Продажи', 'PARENT' => '1'],
                ['ID' => '14', 'NAME' => 'Бухгалтерия', 'PARENT' => '1', 'UF_HEAD' => '30'],
            ],
            [1 => [1], 30 => [14], 31 => [14], 40 => [14], 41 => [14], 100 => [5], 200 => [5]],
        );
        $this->portal = new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
        $this->rebuild();
        $this->roles->set($this->portal, RoleService::DIRECTOR, [self::DIRECTOR]);
        $this->roles->set($this->portal, RoleService::HR, self::HR);
        $this->rebuild();
    }

    /**
     * Новые сервисы на тот же портал: так каждое действие идёт как отдельный HTTP-запрос, без кэша прошлого.
     */
    private function rebuild(): void
    {
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $this->roles = new RoleService($storage);
        $this->notifier = new Notifier($rest, 'local.app.code');
        $this->absence = new AbsenceCalendar($rest, $storage);
        $this->service = new RequestService($storage, new StructureService($rest), $this->roles, new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), false, $this->notifier, $this->absence, new Settings($rest));
    }

    private function user(int $id, bool $isAdmin = false): CurrentUser
    {
        return new CurrentUser($this->portal, $id, $isAdmin);
    }

    private function approve(int $approverId, int $requestId): array
    {
        $this->rebuild();

        return $this->service->approve($this->user($approverId), $requestId);
    }

    public function testEmployeeGoesHeadThenHrThenDirector(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        self::assertSame(Status::HEAD_REVIEW, $request['status']);
        self::assertSame(self::ACCOUNTING_HEAD, $request['approverId']);
        self::assertSame(14, $request['deptId']);

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame(Status::HR_REVIEW, $request['status']);
        self::assertContains($request['approverId'], self::HR);

        $request = $this->approve($request['approverId'], $request['id']);
        self::assertSame(Status::FINAL_APPROVAL, $request['status']);
        self::assertSame(self::DIRECTOR, $request['approverId']);

        $request = $this->approve(self::DIRECTOR, $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);
        self::assertSame(0, $request['approverId']);

        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['create', 'approve', 'approve', 'approve'], array_column($card['history'], 'action'));
        self::assertSame([self::ACCOUNTANT, self::ACCOUNTING_HEAD], array_slice(array_column($card['history'], 'actorId'), 0, 2));
    }

    public function testWithoutHeadInDepartmentDirectorApprovesOnceAtFirstStep(): void
    {
        $request = $this->service->create($this->user(self::SALES_MANAGER), 'vacation', '2026-11-09', '2026-11-13');
        self::assertSame(self::DIRECTOR, $request['approverId']);

        $request = $this->approve(self::DIRECTOR, $request['id']);
        $request = $this->approve($request['approverId'], $request['id']);

        self::assertSame(Status::APPROVED, $request['status']);
    }

    public function testDirectorRequestIsCheckedOnlyByHr(): void
    {
        $request = $this->service->create($this->user(self::DIRECTOR), 'unpaid', '2026-11-09', '2026-11-10');
        self::assertSame(Status::HR_REVIEW, $request['status']);

        $request = $this->approve($request['approverId'], $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);
    }

    public function testHrRequestIsNotSentToTheSameHr(): void
    {
        $request = $this->service->create($this->user(40), 'vacation', '2026-11-09', '2026-11-10');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);

        self::assertSame(41, $request['approverId']);
    }

    public function testHrWithFewerPendingRequestsGetsTheNextOne(): void
    {
        $first = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');
        $first = $this->approve(self::ACCOUNTING_HEAD, $first['id']);
        $this->rebuild();
        $second = $this->service->create($this->user(self::ACCOUNTING_HEAD), 'vacation', '2026-12-07', '2026-12-08');

        self::assertSame(Status::HEAD_REVIEW, $second['status'], 'руководитель бухгалтерии согласует у директора как у руководителя компании');
        self::assertSame(self::DIRECTOR, $second['approverId']);
        $second = $this->approve(self::DIRECTOR, $second['id']);

        self::assertNotSame($first['approverId'], $second['approverId']);
    }

    public function testOnlyCurrentApproverDecides(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');

        $this->expectException(VacationError::class);
        $this->approve(self::DIRECTOR, $request['id']);
    }

    public function testRejectNeedsReasonFromListAndClosesRequest(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');
        $this->rebuild();
        try
        {
            $this->service->reject($this->user(self::ACCOUNTING_HEAD), $request['id'], 'Иванов в это время в отпуске');
            self::fail('Свободный текст вместо причины принят');
        }
        catch (VacationError)
        {
        }

        $request = $this->service->reject($this->user(self::ACCOUNTING_HEAD), $request['id'], 'workload');

        self::assertSame(Status::REJECTED, $request['status']);
        $history = $this->service->card($this->user(self::ACCOUNTANT), $request['id'])['history'];
        self::assertSame('workload', $history[1]['reason']);
    }

    public function testOwnerWithdrawsWhileUnderReview(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');

        $request = $this->service->withdraw($this->user(self::ACCOUNTANT), $request['id']);

        self::assertSame(Status::WITHDRAWN, $request['status']);
        self::assertSame([], $this->service->toApprove($this->user(self::ACCOUNTING_HEAD)));
    }

    public function testVacationDaysSkipHolidaysOtherKindsCountCalendarDays(): void
    {
        $vacation = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2027-01-01', '2027-01-10');
        $unpaid = $this->service->create($this->user(self::SALES_MANAGER), 'unpaid', '2027-01-01', '2027-01-10');

        self::assertSame(7, $vacation['days']);
        self::assertSame(10, $unpaid['days']);
    }

    public function testRejectsPastAndOverlappingDates(): void
    {
        $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-20');

        foreach ([['2026-10-26', '2026-10-30'], ['2026-11-15', '2026-11-25'], ['2026-11-20', '2026-11-10']] as [$from, $to])
        {
            try
            {
                $this->service->create($this->user(self::ACCOUNTANT), 'vacation', $from, $to);
                self::fail("Заявка $from — $to принята");
            }
            catch (VacationError)
            {
                self::assertTrue(true);
            }
        }
    }

    public function testCardIsHiddenFromColleagues(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');

        self::assertTrue($this->service->card($this->user(self::ACCOUNTING_HEAD), $request['id'])['can']['approve']);
        self::assertFalse($this->service->card($this->user(41), $request['id'])['can']['approve'], 'ОК видит, но не решает');
        self::assertTrue($this->service->card($this->user(self::ACCOUNTANT), $request['id'])['can']['withdraw']);

        $this->expectException(VacationError::class);
        $this->service->card($this->user(200), $request['id']);
    }

    public function testWithoutDirectorRequestIsNotCreated(): void
    {
        $this->roles->set($this->portal, RoleService::DIRECTOR, []);
        $this->rebuild();

        try
        {
            $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-10');
            self::fail('Заявка без директора принята');
        }
        catch (VacationError $e)
        {
            self::assertStringContainsString('директор', $e->getMessage());
        }
        self::assertSame([], $this->http->items[Schema::REQUESTS] ?? []);
    }

    public function testNobodyApprovesOwnRequest(): void
    {
        $this->roles->set($this->portal, RoleService::HR, [self::DIRECTOR]);
        $this->rebuild();

        try
        {
            $this->service->create($this->user(self::DIRECTOR), 'vacation', '2026-11-11', '2026-11-19');
            self::fail('Директор согласовал бы свою заявку сам');
        }
        catch (VacationError $e)
        {
            self::assertStringContainsString('отдел кадров', $e->getMessage());
        }
    }

    public function testSelfApprovalCanBeAllowedForTestingOnOneAccount(): void
    {
        $this->roles->set($this->portal, RoleService::HR, [self::DIRECTOR]);
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $service = new RequestService($storage, new StructureService($rest), new RoleService($storage), new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), true);

        $request = $service->create($this->user(self::DIRECTOR), 'vacation', '2026-11-11', '2026-11-19');

        self::assertSame(self::DIRECTOR, $request['approverId']);
    }

    /**
     * @return list<array{0: string, 1: list<int>}> событие и получатели
     */
    private function sent(): array
    {
        return array_map(static fn(array $n) => [$n['event'], $n['to']], $this->notifier->pending());
    }

    public function testApproverAndAuthorAreNotifiedOnEachStep(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        self::assertSame([['review', [self::ACCOUNTING_HEAD]]], $this->sent());

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame([['review', [$request['approverId']]], ['step', [self::ACCOUNTANT]]], $this->sent());

        $request = $this->approve($request['approverId'], $request['id']);
        $request = $this->approve(self::DIRECTOR, $request['id']);
        self::assertSame([['approved', [self::ACCOUNTANT]]], $this->sent());
    }

    public function testRejectionReachesAuthorAndThoseWhoApproved(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $this->rebuild();

        $this->service->reject($this->user($request['approverId']), $request['id'], 'workload');

        self::assertSame([['rejected', [self::ACCOUNTANT, self::ACCOUNTING_HEAD]]], $this->sent());
        self::assertSame('workload', $this->notifier->pending()[0]['reason']);
    }

    public function testWithdrawalReachesCurrentAndPastApprovers(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $this->rebuild();

        $this->service->withdraw($this->user(self::ACCOUNTANT), $request['id']);

        self::assertSame([['withdrawn', [$request['approverId'], self::ACCOUNTING_HEAD]]], $this->sent());
    }

    public function testNotificationsGoInOneBatchWithLinkToCard(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $calls = count($this->http->calls);

        $this->notifier->send();

        self::assertSame(['user.get', 'batch'], array_slice($this->http->calls, $calls));
        self::assertCount(2, $this->http->notifications);
        [$toHr, $toAuthor] = $this->http->notifications;
        self::assertSame((string)$request['approverId'], $toHr['USER_ID']);
        self::assertStringContainsString('Фамилия31 Имя31 просит согласовать', $toHr['MESSAGE']);
        self::assertStringContainsString('[URL=https://test.bitrix24.ru/marketplace/view/local.app.code/?params%5Breq%5D=' . $request['id'] . ']', $toHr['MESSAGE']);
        self::assertSame((string)self::ACCOUNTANT, $toAuthor['USER_ID']);
        self::assertStringContainsString('теперь в отделе кадров', $toAuthor['MESSAGE']);
        self::assertSame('KB24_REQ_' . $request['id'] . '_' . self::ACCOUNTANT, $toAuthor['TAG']);
        self::assertSame([], $this->notifier->pending());
    }

    public function testActorIsNotNotifiedAboutOwnActionUnlessTestingOnOneAccount(): void
    {
        $this->roles->set($this->portal, RoleService::HR, [self::DIRECTOR]);
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $notifier = new Notifier($rest, 'code', true);
        $service = new RequestService($storage, new StructureService($rest), new RoleService($storage), new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), true, $notifier);

        $service->create($this->user(self::DIRECTOR), 'vacation', '2026-11-11', '2026-11-19');
        self::assertSame([self::DIRECTOR], $notifier->pending()[0]['to']);

        $usual = new Notifier($rest, 'code');
        $usual->add($this->portal, 'rejected', ['id' => 1], [self::ACCOUNTANT, self::ACCOUNTING_HEAD], self::ACCOUNTING_HEAD);
        self::assertSame([self::ACCOUNTANT], $usual->pending()[0]['to']);
    }

    private function approveAll(int $authorId, string $from = '2026-11-09', string $to = '2026-11-22'): array
    {
        $request = $this->service->create($this->user($authorId), 'vacation', $from, $to);
        while ($request['status'] !== Status::APPROVED)
        {
            $request = $this->approve($request['approverId'], $request['id']);
            self::assertSame($request['status'] === Status::APPROVED, $this->absence->hasWork(), 'событие только после утверждения');
        }

        return $request;
    }

    public function testApprovedRequestBecomesAbsenceInAuthorsCalendar(): void
    {
        $request = $this->approveAll(self::ACCOUNTANT);

        $this->absence->run();

        self::assertCount(1, $this->http->events);
        $event = $this->http->events[0];
        self::assertSame(['user', self::ACCOUNTANT, '2026-11-09', '2026-11-22', 'Y', 'absent'], [$event['type'], $event['ownerId'], $event['from'], $event['to'], $event['skip_time'], $event['accessibility']]);
        self::assertSame('Ежегодный оплачиваемый отпуск', $event['name']);
        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(1001, $card['request']['calEventId']);
        self::assertSame('', $card['request']['calError']);
    }

    public function testCalendarRefusalIsShownInCardAndDoesNotUndoApproval(): void
    {
        $this->http->calendarError = ['error' => 'ACCESS_DENIED', 'error_description' => 'Creation of events in the specified calendar is prohibited'];
        $request = $this->approveAll(self::ACCOUNTANT);

        $this->absence->run();

        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(Status::APPROVED, $card['request']['status']);
        self::assertSame(0, $card['request']['calEventId']);
        self::assertStringContainsString('ACCESS_DENIED', $card['request']['calError']);
    }

    public function testCancellationGoesHeadThenHrAndRemovesCalendarEvent(): void
    {
        $request = $this->approveAll(self::ACCOUNTANT);
        $this->absence->run();
        $this->rebuild();

        $request = $this->service->startCancellation($this->user(self::ACCOUNTANT), $request['id'], 'Перенесли командировку');
        self::assertSame(Status::CANCELLING, $request['status']);
        self::assertSame(self::ACCOUNTING_HEAD, $request['approverId']);
        self::assertSame([['cancel_review', [self::ACCOUNTING_HEAD]]], $this->sent());

        $this->rebuild();
        $request = $this->service->approveCancellation($this->user(self::ACCOUNTING_HEAD), $request['id']);
        self::assertSame(RequestService::CANCEL_HR, $request['cancelStep']);
        self::assertContains($request['approverId'], self::HR);

        $this->rebuild();
        $hr = $request['approverId'];
        $request = $this->service->approveCancellation($this->user($hr), $request['id']);
        self::assertSame(Status::CANCELLED, $request['status']);
        self::assertSame(0, $request['approverId']);
        $sent = $this->sent();
        self::assertSame('cancelled', end($sent)[0]);
        $recipients = end($sent)[1];
        foreach ([self::ACCOUNTANT, self::ACCOUNTING_HEAD, self::DIRECTOR] as $participant)
        {
            self::assertContains($participant, $recipients);
        }
        self::assertNotContains($hr, $recipients, 'тот, кто согласовал отмену, уведомление о ней не получает');

        $this->absence->run();
        self::assertSame([1001], $this->http->deletedEvents);
        self::assertSame(0, $this->service->card($this->user(self::ACCOUNTANT), $request['id'])['request']['calEventId']);
    }

    public function testRefusedCancellationKeepsAbsence(): void
    {
        $request = $this->approveAll(self::ACCOUNTANT);
        $this->rebuild();
        $this->service->startCancellation($this->user(self::ACCOUNTANT), $request['id'], 'Передумал');
        $this->rebuild();
        try
        {
            $this->service->refuseCancellation($this->user(self::ACCOUNTING_HEAD), $request['id'], ' ');
            self::fail('Отказ без причины принят');
        }
        catch (VacationError)
        {
        }

        $request = $this->service->refuseCancellation($this->user(self::ACCOUNTING_HEAD), $request['id'], 'Некому заменить');

        self::assertSame(Status::APPROVED, $request['status']);
        self::assertSame(RequestService::CANCEL_REFUSED, $request['cancelStep']);
        $history = $this->service->card($this->user(self::ACCOUNTANT), $request['id'])['history'];
        self::assertSame(['cancel_start', 'Передумал'], [$history[4]['action'], $history[4]['reason']]);
        self::assertSame(['cancel_refuse', 'Некому заменить'], [$history[5]['action'], $history[5]['reason']]);
        self::assertSame([], $this->http->deletedEvents);
    }

    public function testCancellationOnlyByAuthorAndNotTooLate(): void
    {
        $soon = $this->approveAll(self::ACCOUNTANT, '2026-10-29', '2026-10-30');
        $later = $this->approveAll(self::SALES_MANAGER);
        $this->rebuild();

        $card = $this->service->card($this->user(self::ACCOUNTANT), $soon['id']);
        self::assertFalse($card['can']['cancel']);
        self::assertSame('Отменить можно не позже чем за 3 раб. дн. до начала', $card['cancelHint']);
        self::assertTrue($this->service->card($this->user(self::SALES_MANAGER), $later['id'])['can']['cancel']);
        self::assertFalse($this->service->card($this->user(self::DIRECTOR), $later['id'])['can']['cancel'], 'не автор');

        $this->expectException(VacationError::class);
        $this->service->startCancellation($this->user(self::DIRECTOR), $later['id'], 'Хочу');
    }

    public function testCancellationDeadlineComesFromSettings(): void
    {
        $soon = $this->approveAll(self::ACCOUNTANT, '2026-10-29', '2026-10-30');
        $this->http->options = ['max_cancel_working_days' => '1'];
        $this->rebuild();

        self::assertTrue($this->service->card($this->user(self::ACCOUNTANT), $soon['id'])['can']['cancel']);
    }
}
