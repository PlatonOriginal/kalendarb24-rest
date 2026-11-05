<?php
declare(strict_types=1);

namespace Kb24Rest\Http;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Portal\PortalStore;
use Kb24Rest\Portal\UserResolver;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\AbsenceCalendar;
use Kb24Rest\Vacation\BalanceService;
use Kb24Rest\Vacation\CalendarService;
use Kb24Rest\Vacation\DashboardService;
use Kb24Rest\Vacation\Kinds;
use Kb24Rest\Vacation\LimitService;
use Kb24Rest\Vacation\RejectReasons;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\RouteService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;

/**
 * Маршруты блока 1:
 *   /install    путь для первоначальной установки (портал присылает POST с AUTH_ID и REFRESH_ID)
 *   /app        путь обработчика, главная страница в iframe
 *   /api/me     кто открыл приложение (проверка токена пользователя на сервере)
 * Блок 3:
 *   /api/storage        (администратор) создаёт недостающие хранилища entity.*, показывает их свойства и права
 * Блок 3, заявки:
 *   /api/vacation              кто я, роли, виды отсутствий, причины отказа
 *   /api/requests/mine         мои заявки
 *   /api/requests/approve      заявки, которые ждут моего решения
 *   /api/requests              (POST kind, dateFrom, dateTo) подать заявку
 *   /api/requests/<id>         карточка заявки с историей и доступными действиями
 *   /api/requests/<id>/approve | reject (reason) | withdraw   (POST) решения по заявке
 *   /api/settings/options      (POST max_cancel_working_days — срок отмены по умолчанию, annual_days, min_submit_exempt_unpaid; право изменять «Настройки») настройки в app.option
 *   /api/requests/<id>/cancel (reason) | cancel-approve | cancel-refuse (reason)   (POST) отмена утверждённой заявки
 * Блок 4:
 *   уведомления о заявках уходят после ответа браузеру; ссылка из уведомления открывает /app
 *   через встройку REST_APP_URI с PLACEMENT_OPTIONS {"req": "<ID>"}
 * Блок 6:
 *   /api/calendar          (POST department, from, to) заявки отдела за период и дни, когда лимит исчерпан
 *   /api/settings/limits   (POST limits: JSON [{department, maxConcurrent, maxOverlap, withSub, withHead}], право изменять «Настройки»)
 * Блок 7:
 *   /api/balances          остатки и даты приёма (доступ к вкладке «Остатки»); сотрудников и ФИО браузер берёт сам
 *   /api/balances/save     (POST userId, days, asOf, comment, hireDate) остаток и дата приёма одного сотрудника (право изменять «Остатки»)
 *   /api/balances/import   (POST rows: JSON [{line, userId, hireDate, days, asOf, comment}]) загрузка из CSV (право изменять «Остатки»)
 *   /api/requests/mine     отдаёт ещё и мой остаток для подсказки в форме заявки
 * Блок 8, маршруты (доступ к вкладке «Маршруты», изменять — с правом изменять):
 *   /api/routes            маршруты с шагами, роли для шагов, отделы
 *   /api/routes/save       (POST route: JSON {id, name, active, exclusive, kinds, departmentIds, steps}) сохранить маршрут
 *   /api/routes/delete     (POST id) удалить маршрут
 *   /api/requests/<id>/acknowledge  (POST task: 1, deadline: Y-m-d) ознакомился с результатом, по желанию поставить себе задачу
 *   /api/requests/approve  отдаёт ещё и acks: заявки, с результатом которых нужно ознакомиться
 * Доступ (вкладка «Доступ»):
 *   /api/access            роли, вкладки и уровни, отделы
 *   /api/access/save       (POST role: JSON {id, name, userIds, departmentIds, tabs, scope}) сохранить роль
 *   /api/access/delete     (POST id) удалить роль
 *   /api/dashboard         (POST year) дашборд за год: доступ к вкладке «Дашборд», отделы по видимости роли
 */
final class App
{
    /** @var list<\Closure> работа после ответа браузеру */
    private array $tasks = [];

    public function __construct(
        private readonly PortalStore $portals,
        private readonly OAuthClient $oauth,
        private readonly RestClient $rest,
        private readonly UserResolver $users,
        private readonly StructureService $structure,
        private readonly EntityStorage $storage,
        private readonly RoleService $roles,
        private readonly RequestService $requests,
        private readonly string $templates,
        private readonly string $appUrl = '',
        /** @var list<AfterResponse> */
        private readonly array $afterResponse = [],
        private readonly ?Settings $settings = null,
        private readonly ?CalendarService $calendar = null,
        private readonly ?LimitService $limits = null,
        private readonly ?BalanceService $balances = null,
        private readonly ?RouteService $routes = null,
        private readonly ?DashboardService $dashboard = null,
        private readonly ?AbsenceCalendar $absence = null,
    ) {
    }

    public function handle(string $path, array $post, array $server): void
    {
        ob_start();
        try
        {
            $this->route($path, $post, $server);
        }
        finally
        {
            $this->finishResponse();
        }
        foreach ($this->tasks as $task)
        {
            $task();
        }
        foreach ($this->afterResponse as $work)
        {
            if ($work->hasWork())
            {
                $work->run();
            }
        }
    }

    /**
     * Отдать ответ браузеру до отложенной работы (уведомления, обновление токенов),
     * чтобы страница и согласование не ждали лишние вызовы REST.
     */
    private function finishResponse(): void
    {
        $pending = array_filter($this->afterResponse, static fn(AfterResponse $work) => $work->hasWork());
        if ($this->tasks === [] && $pending === [])
        {
            ob_end_flush();
            return;
        }
        if (!headers_sent())
        {
            header('Content-Length: ' . ob_get_length());
            header('Connection: close');
        }
        ignore_user_abort(true);
        ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request'))
        {
            fastcgi_finish_request();
        }
        elseif (function_exists('litespeed_finish_request'))
        {
            // LiteSpeed (LSAPI) на виртуальных хостингах
            litespeed_finish_request();
        }
    }

    private function route(string $path, array $post, array $server): void
    {
        try
        {
            match (true) {
                $path === '/install' => $this->install($post),
                $path === '/' || $path === '/app' => $this->openApp($post),
                $path === '/api/me' => $this->json($this->me($this->currentUser($server))),
                $path === '/api/storage' => $this->json($this->storageInfo($this->currentAdmin($server))),
                $path === '/api/dashboard' && $this->dashboard !== null
                    => $this->json($this->dashboard->build($this->currentUser($server), (int)($post['year'] ?? 0))),
                $path === '/api/vacation' => $this->json($this->vacation($this->currentUser($server))),
                $path === '/api/requests/mine' => $this->json($this->mine($this->currentUser($server))),
                $path === '/api/balances' && $this->balances !== null => $this->json($this->balances->list($this->currentUser($server))),
                $path === '/api/balances/save' && $this->isPost($server) && $this->balances !== null => $this->json($this->balances->save(
                    $this->currentUser($server),
                    (int)($post['userId'] ?? 0),
                    (string)($post['days'] ?? ''),
                    (string)($post['asOf'] ?? ''),
                    (string)($post['comment'] ?? ''),
                    isset($post['hireDate']) ? (string)$post['hireDate'] : null,
                )),
                $path === '/api/balances/import' && $this->isPost($server) && $this->balances !== null => $this->json($this->balances->import(
                    $this->currentUser($server),
                    array_values(array_filter((array)json_decode((string)($post['rows'] ?? ''), true), 'is_array')),
                )),
                $path === '/api/requests/approve' => $this->json($this->toApprove($this->currentUser($server))),
                $path === '/api/requests' && $this->isPost($server) => $this->json($this->requests->create(
                    $this->currentUser($server),
                    (string)($post['kind'] ?? ''),
                    (string)($post['dateFrom'] ?? ''),
                    (string)($post['dateTo'] ?? ''),
                    (int)($post['userId'] ?? 0),
                )),
                (bool)preg_match('~^/api/requests/(\d+)$~', $path, $m) => $this->json($this->requests->card($this->currentUser($server), (int)$m[1])),
                (bool)preg_match('~^/api/requests/(\d+)/(approve|reject|withdraw|cancel|cancel-approve|cancel-refuse|calendar-retry|acknowledge)$~', $path, $m) && $this->isPost($server)
                    => $this->json($this->decide($this->currentUser($server), (int)$m[1], $m[2], $post)),
                $path === '/api/settings/options' && $this->isPost($server) => $this->json($this->saveOptions($this->settingsEditor($server), $post)),
                $path === '/api/settings/limits' && $this->isPost($server) => $this->json($this->saveLimits($this->settingsEditor($server), $post)),
                $path === '/api/access' => $this->json($this->roles->list($this->currentUser($server))),
                $path === '/api/access/save' && $this->isPost($server) => $this->json($this->roles->save(
                    $this->currentUser($server),
                    (array)json_decode((string)($post['role'] ?? ''), true),
                )),
                $path === '/api/access/delete' && $this->isPost($server) => $this->json($this->deleteRole($this->currentUser($server), (int)($post['id'] ?? 0))),
                $path === '/api/calendar' && $this->isPost($server) && $this->calendar !== null => $this->json($this->calendar->get(
                    $this->currentUser($server),
                    (int)($post['department'] ?? 0),
                    (string)($post['from'] ?? ''),
                    (string)($post['to'] ?? ''),
                )),
                $path === '/api/routes' && $this->routes !== null => $this->json($this->routes->list($this->currentUser($server))),
                $path === '/api/routes/save' && $this->isPost($server) && $this->routes !== null => $this->json($this->routes->save(
                    $this->currentUser($server),
                    (array)json_decode((string)($post['route'] ?? ''), true),
                )),
                $path === '/api/routes/delete' && $this->isPost($server) && $this->routes !== null
                    => $this->json($this->routes->delete($this->currentUser($server), (int)($post['id'] ?? 0))),
                default => $this->notFound(),
            };
        }
        catch (VacationError $e)
        {
            $this->json(['error' => 'vacation', 'message' => $e->getMessage()], 422);
        }
        catch (RestException $e)
        {
            $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], 400);
        }
        catch (AccessDenied $e)
        {
            $this->json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }
    }

    private function install(array $post): void
    {
        $refreshToken = (string)($post['REFRESH_ID'] ?? '');
        if ($refreshToken === '')
        {
            $this->render('install.php', ['error' => 'Портал не передал REFRESH_ID. Откройте установку из Битрикс24.']);
            return;
        }

        // member_id и домен берём из ответа oauth.bitrix.info, а не из POST
        $tokens = $this->oauth->refresh($refreshToken);
        $existing = $this->portals->findByMemberId($tokens->memberId);
        $portal = Portal::fromInstall($tokens, $existing?->id);
        $this->portals->save($portal);

        try
        {
            // При установке проверяем всегда: после удаления приложения портал мог стереть хранилища
            $this->ensureStorage($portal, true);
        }
        catch (RestException $e)
        {
            // Без хранилищ приложению работать не с чем, поэтому установку не завершаем
            $this->render('install.php', ['error' => 'Не удалось создать хранилища данных: ' . $e->errorCode . ' ' . $e->getMessage()
                . '. Проверьте, что у приложения есть право «Хранилище данных (entity)».']);
            return;
        }

        $this->render('install.php', ['error' => null]);
    }

    /**
     * Портал присылает в обработчик свежие AUTH_ID и REFRESH_ID того, кто открыл приложение.
     * Если открыл тот, кто устанавливал, обновляем сохранённые токены: так сервер получает права,
     * добавленные приложению после установки (токен, обновлённый через refresh_token, сохраняет старые права).
     */
    private function openApp(array $post): void
    {
        $refreshToken = (string)($post['REFRESH_ID'] ?? '');
        if ($refreshToken !== '')
        {
            // После ответа: страница не ждёт OAuth-сервер, который отвечает только затем, чтобы узнать, кто открыл
            $this->tasks[] = fn() => $this->renewInstallerTokens($refreshToken);
        }

        $this->render('app.php', []);
    }

    private function renewInstallerTokens(string $refreshToken): void
    {
        try
        {
            $tokens = $this->oauth->refresh($refreshToken);
            $portal = $this->portals->findByMemberId($tokens->memberId);
            if ($portal !== null && $tokens->userId === $portal->installedBy)
            {
                $portal = $portal->withTokens($tokens);
                $this->portals->save($portal);
                // Новые хранилища и свойства из следующих версий появляются без переустановки
                $this->ensureStorage($portal);
            }
        }
        catch (RestException)
        {
            // Страница работает и без этого: токены приложения остаются прежними
        }
    }

    private function me(CurrentUser $user): array
    {
        return [
            'userId' => $user->userId,
            'isAdmin' => $user->isAdmin,
            'domain' => $user->portal->domain,
            'installedBy' => $user->portal->installedBy,
        ];
    }

    private function mine(CurrentUser $user): array
    {
        return [
            'items' => $this->requests->mine($user),
            'balance' => $this->balances?->get($user->portal, $user->userId),
        ];
    }

    private function vacation(CurrentUser $user): array
    {
        $access = $this->roles->permissions($user);
        $settings = $this->roles->can($user, 'settings');

        return [
            'userId' => $user->userId,
            'isAdmin' => $user->isAdmin,
            // Уровень доступа к вкладкам (none, view, edit) и чьи заявки видны
            'access' => $access['tabs'],
            'scope' => $access['scope'],
            'kinds' => array_map(static fn(array $kind) => $kind['name'], Kinds::all()),
            'reasons' => RejectReasons::all(),
            // Только тем, кому открыты «Настройки»: остальным лишний вызов REST при каждом открытии ни к чему
            'settings' => $settings ? ($this->settings?->all($user->portal) ?? Settings::DEFAULTS) : null,
            'limits' => $settings && $this->limits !== null ? $this->limitsByDepartment($user) : null,
            // Для лимитов на вкладке «Настройки» и фильтра на «Остатках»: все отделы, независимо от того, какие видны в календаре
            'departments' => $settings || ($this->balances?->canView($user) ?? false) ? $this->departmentTree($user) : null,
            'calendar' => $this->calendar?->departments($user),
            'balances' => $this->balances !== null && $this->balances->canView($user),
        ];
    }

    /**
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function departmentTree(CurrentUser $user): array
    {
        $departments = [];
        foreach ($this->structure->orgChart($user->portal)->tree() as ['department' => $department, 'depth' => $depth])
        {
            $departments[] = ['id' => $department->id, 'name' => $department->name, 'depth' => $depth];
        }

        return $departments;
    }

    private function deleteRole(CurrentUser $user, int $roleId): array
    {
        return $this->roles->delete($user, $roleId, $this->routes?->usingRole($user->portal, $roleId) ?? []);
    }

    /**
     * @return array<string, array<string, mixed>> по ID отдела; ключи строкой, чтобы JSON был объектом даже без лимитов
     */
    private function limitsByDepartment(CurrentUser $user): array
    {
        $limits = [];
        foreach ($this->limits->all($user->portal) as $departmentId => $limit)
        {
            unset($limit['id']);
            $limits['d' . $departmentId] = $limit;
        }

        return $limits;
    }

    private function saveLimits(CurrentUser $user, array $post): array
    {
        if ($this->limits === null)
        {
            throw new VacationError('Лимиты недоступны');
        }
        $rows = json_decode((string)($post['limits'] ?? ''), true);
        if (!is_array($rows))
        {
            throw new VacationError('Не переданы лимиты');
        }
        $number = static function (mixed $value): ?int
        {
            $value = trim((string)$value);
            if ($value === '')
            {
                return null;
            }
            if (!preg_match('/^\d+$/', $value))
            {
                throw new VacationError('В лимитах укажите целое число или оставьте поле пустым');
            }

            return (int)$value;
        };
        foreach ($rows as $row)
        {
            $this->limits->save(
                $user->portal,
                (int)($row['department'] ?? 0),
                $number($row['maxConcurrent'] ?? ''),
                $number($row['maxOverlap'] ?? ''),
                !empty($row['withSub']),
                !empty($row['withHead']),
            );
        }

        return $this->limitsByDepartment($user);
    }

    private function saveOptions(CurrentUser $user, array $post): array
    {
        if ($this->settings === null)
        {
            throw new VacationError('Настройки недоступны');
        }
        if (isset($post['max_cancel_working_days']))
        {
            $value = trim((string)$post['max_cancel_working_days']);
            if (!preg_match('/^\d+$/', $value))
            {
                throw new VacationError('Укажите число рабочих дней от 0 до 365');
            }
            $this->settings->setMaxCancelWorkingDays($user->portal, (int)$value);
        }
        if (isset($post['annual_days']))
        {
            $value = trim((string)$post['annual_days']);
            if (!preg_match('/^\d+$/', $value))
            {
                throw new VacationError('Ежегодный отпуск: от 28 до 100 календарных дней');
            }
            $this->settings->setAnnualDays($user->portal, (int)$value);
        }
        if (isset($post['min_submit_exempt_unpaid']))
        {
            $this->settings->setMinSubmitExemptUnpaid($user->portal, $post['min_submit_exempt_unpaid'] === '1');
        }

        return $this->settings->all($user->portal);
    }

    private function toApprove(CurrentUser $user): array
    {
        return ['items' => $this->requests->toApprove($user), 'acks' => $this->requests->toAcknowledge($user)];
    }

    private function decide(CurrentUser $user, int $id, string $action, array $post): array
    {
        return match ($action)
        {
            'approve' => $this->requests->approve($user, $id),
            'reject' => $this->requests->reject($user, $id, (string)($post['reason'] ?? '')),
            'withdraw' => $this->requests->withdraw($user, $id),
            'cancel' => $this->requests->startCancellation($user, $id, (string)($post['reason'] ?? '')),
            'cancel-approve' => $this->requests->approveCancellation($user, $id),
            'cancel-refuse' => $this->requests->refuseCancellation($user, $id, (string)($post['reason'] ?? '')),
            'calendar-retry' => $this->requests->retryCalendar($user, $id),
            'acknowledge' => $this->requests->acknowledge($user, $id, ($post['task'] ?? '') === '1', (string)($post['deadline'] ?? '')),
        };
    }

    private function ensureStorage(Portal $portal, bool $force = false): void
    {
        if ($force || $this->portals->storageVersion($portal->memberId) !== self::setupVersion($this->appUrl))
        {
            $this->storage->ensure($portal);
            $this->absence?->ensureTypes($portal);
            $this->bindAppUri($portal);
            $this->portals->setStorageVersion($portal->memberId, self::setupVersion($this->appUrl));
        }
    }

    /**
     * Версия того, что сервер настраивает в портале: хранилища и обработчик ссылок REST_APP_URI.
     * Адрес туннеля входит в версию: после его смены обработчик перерегистрируется при следующем открытии.
     */
    public static function setupVersion(string $appUrl): string
    {
        return substr(sha1(Schema::version() . '|' . $appUrl . '|REST_APP_URI'), 0, 12);
    }

    /**
     * Обработчик ссылок вида /marketplace/view/<код>/?params[req]=ID из уведомлений.
     * У приложения он может быть только один, поэтому старый адрес сначала снимаем.
     * https://apidocs.bitrix24.com/api-reference/widgets/universal/app-url.html
     */
    private function bindAppUri(Portal $portal): void
    {
        if ($this->appUrl === '')
        {
            return;
        }
        $handler = $this->appUrl . '/app';
        try
        {
            foreach ((array)$this->rest->call($portal, 'placement.get') as $bound)
            {
                if (($bound['placement'] ?? '') !== 'REST_APP_URI')
                {
                    continue;
                }
                if (($bound['handler'] ?? '') === $handler)
                {
                    return;
                }
                $this->rest->call($portal, 'placement.unbind', ['PLACEMENT' => 'REST_APP_URI', 'HANDLER' => $bound['handler']]);
            }
            $this->rest->call($portal, 'placement.bind', ['PLACEMENT' => 'REST_APP_URI', 'HANDLER' => $handler, 'TITLE' => 'Отпуска']);
        }
        catch (RestException $e)
        {
            // Без обработчика приложение работает, только ссылки из уведомлений не откроют карточку
            error_log('kb24 placement: ' . $e->errorCode . ' ' . $e->getMessage());
        }
    }

    private function isPost(array $server): bool
    {
        return ($server['REQUEST_METHOD'] ?? '') === 'POST';
    }

    private function storageInfo(CurrentUser $user): array
    {
        $ensure = $this->storage->ensure($user->portal);
        $storages = [];
        foreach (Schema::storages() as $entity => $storage)
        {
            $storages[] = [
                'entity' => $entity,
                'properties' => array_keys($storage['properties']),
                'rights' => $this->storage->rights($user->portal, $entity),
            ];
        }

        return $ensure + ['installedBy' => $user->portal->installedBy, 'storages' => $storages];
    }

    /**
     * Пробная запись: проверяем запись, частичное обновление, чтение и удаление на живом портале.
     * Заодно выясняем, заменяет ли entity.item.update все свойства или только переданные (в документации не сказано).
     */
    private function settingsEditor(array $server): CurrentUser
    {
        $user = $this->currentUser($server);
        if (!$this->roles->can($user, 'settings', RoleService::EDIT))
        {
            throw new AccessDenied('Настройки меняют администратор портала и роли с правом изменять «Настройки»');
        }

        return $user;
    }

    private function currentAdmin(array $server): CurrentUser
    {
        $user = $this->currentUser($server);
        if (!$user->isAdmin)
        {
            throw new AccessDenied('Доступно только администратору портала');
        }

        return $user;
    }

    private function currentUser(array $server): CurrentUser
    {
        // Свой заголовок вместо Authorization: Apache с mod_php по умолчанию не передаёт Authorization в PHP
        return $this->users->resolve((string)($server['HTTP_X_B24_MEMBER'] ?? ''), (string)($server['HTTP_X_B24_AUTH'] ?? ''));
    }

    private function render(string $template, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        extract($vars);
        require $this->templates . '/' . $template;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function notFound(): void
    {
        $this->json(['error' => 'not_found'], 404);
    }
}
