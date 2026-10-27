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

/**
 * Маршруты блока 1:
 *   /install    путь для первоначальной установки (портал присылает POST с AUTH_ID и REFRESH_ID)
 *   /app        путь обработчика, главная страница в iframe
 *   /api/me     кто открыл приложение (проверка токена пользователя на сервере)
 *   /api/check  вызов REST с сервера по сохранённому токену приложения
 * Блок 2:
 *   /api/structure  отделы, руководители и цепочка руководителей текущего сотрудника
 *   /api/hr-compare руководители по новой структуре (humanresources) для сравнения с department.get
 * Блок 3:
 *   /api/storage        (администратор) создаёт недостающие хранилища entity.*, показывает их свойства и права
 *   /api/storage/probe  (POST) сервер пишет, меняет, читает и удаляет пробную запись
 */
final class App
{
    public function __construct(
        private readonly PortalStore $portals,
        private readonly OAuthClient $oauth,
        private readonly RestClient $rest,
        private readonly UserResolver $users,
        private readonly StructureService $structure,
        private readonly EntityStorage $storage,
        private readonly string $templates,
    ) {
    }

    public function handle(string $path, array $post, array $server): void
    {
        try
        {
            match (true) {
                $path === '/install' => $this->install($post),
                $path === '/' || $path === '/app' => $this->openApp($post),
                $path === '/api/me' => $this->json($this->me($this->currentUser($server))),
                $path === '/api/check' => $this->json($this->check($this->currentUser($server))),
                $path === '/api/structure' => $this->json($this->structure($this->currentUser($server))),
                $path === '/api/hr-compare' => $this->json($this->hrCompare($this->currentUser($server))),
                $path === '/api/storage' => $this->json($this->storageInfo($this->currentAdmin($server))),
                $path === '/api/storage/probe' && ($server['REQUEST_METHOD'] ?? '') === 'POST'
                    => $this->json($this->storageProbe($this->currentUser($server))),
                default => $this->notFound(),
            };
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
            $this->storage->ensure($portal);
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
            try
            {
                $tokens = $this->oauth->refresh($refreshToken);
                $portal = $this->portals->findByMemberId($tokens->memberId);
                if ($portal !== null && $tokens->userId === $portal->installedBy)
                {
                    $this->portals->save($portal->withTokens($tokens));
                }
            }
            catch (RestException)
            {
                // Страница работает и без этого: токены приложения остаются прежними
            }
        }

        $this->render('app.php', []);
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

    private function check(CurrentUser $user): array
    {
        $info = $this->rest->call($user->portal, 'app.info');

        return [
            'appInfo' => [
                'code' => $info['CODE'] ?? null,
                'version' => $info['VERSION'] ?? null,
                'status' => $info['STATUS'] ?? null,
                'installed' => $info['INSTALLED'] ?? null,
                'license' => $info['LICENSE'] ?? null,
                'paymentExpired' => $info['PAYMENT_EXPIRED'] ?? null,
                'days' => $info['DAYS'] ?? null,
            ],
            'tokenExpiresAt' => $user->portal->expiresAt,
        ];
    }

    private function structure(CurrentUser $user): array
    {
        $chart = $this->structure->orgChart($user->portal);
        $departments = [];
        foreach ($chart->all() as $department)
        {
            $departments[] = [
                'id' => $department->id,
                'name' => $department->name,
                'parentId' => $department->parentId,
                'headUserId' => $department->headUserId,
            ];
        }

        return [
            'userId' => $user->userId,
            'myDepartments' => $this->structure->departmentsOf($user->portal, $user->userId),
            'headChain' => $this->structure->headChain($user->portal, $chart, $user->userId),
            'departments' => $departments,
        ];
    }

    private function hrCompare(CurrentUser $user): array
    {
        $chart = $this->structure->orgChart($user->portal);
        $nodes = $this->structure->humanResourcesHeads($user->portal);
        foreach ($nodes as &$node)
        {
            // Предполагаем, что код доступа D<ID> или DR<ID> указывает на отдел из department.get. Это и проверяем.
            $departmentId = preg_match('/^DR?(\d+)$/', $node['accessCode'], $m) ? (int)$m[1] : null;
            $department = $departmentId !== null ? $chart->department($departmentId) : null;
            $node['departmentGetId'] = $department?->id;
            $node['departmentGetHead'] = $department?->headUserId;
            // Руководитель не назначен ни там, ни там: это тоже совпадение
            $node['headsMatch'] = $department !== null && ($department->headUserId === null
                ? $node['heads'] === []
                : in_array($department->headUserId, $node['heads'], true));
        }

        return ['nodes' => $nodes];
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
    private function storageProbe(CurrentUser $user): array
    {
        $portal = $user->portal;
        $started = microtime(true);
        $id = $this->storage->add($portal, Schema::REQUESTS, 'probe', [
            'USER_ID' => $user->userId,
            'KIND' => 'probe',
            'STATUS' => 'probe',
            'DAYS' => 1,
        ]);
        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => 'probe_updated']);
        $found = $this->storage->find($portal, Schema::REQUESTS, ['ID' => $id]);
        $values = $found[0]['PROPERTY_VALUES'] ?? [];
        $this->storage->delete($portal, Schema::REQUESTS, $id);
        $afterDelete = $this->storage->find($portal, Schema::REQUESTS, ['ID' => $id]);

        return [
            'itemId' => $id,
            'createdBy' => isset($found[0]['CREATED_BY']) ? (int)$found[0]['CREATED_BY'] : null,
            'valuesAfterUpdate' => $values,
            'updateKeepsOtherProperties' => ($values['KIND'] ?? null) === 'probe',
            'deleted' => $afterDelete === [],
            'milliseconds' => (int)round((microtime(true) - $started) * 1000),
        ];
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
