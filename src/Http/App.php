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

/**
 * Маршруты блока 1:
 *   /install    путь для первоначальной установки (портал присылает POST с AUTH_ID и REFRESH_ID)
 *   /app        путь обработчика, главная страница в iframe
 *   /api/me     кто открыл приложение (проверка токена пользователя на сервере)
 *   /api/check  вызов REST с сервера по сохранённому токену приложения
 */
final class App
{
    public function __construct(
        private readonly PortalStore $portals,
        private readonly OAuthClient $oauth,
        private readonly RestClient $rest,
        private readonly UserResolver $users,
        private readonly string $templates,
    ) {
    }

    public function handle(string $path, array $post, array $server): void
    {
        try
        {
            match (true) {
                $path === '/install' => $this->install($post),
                $path === '/' || $path === '/app' => $this->render('app.php', []),
                $path === '/api/me' => $this->json($this->me($this->currentUser($server))),
                $path === '/api/check' => $this->json($this->check($this->currentUser($server))),
                default => $this->notFound(),
            };
        }
        catch (RestException $e)
        {
            $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], 400);
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
        $this->portals->save(Portal::fromInstall($tokens, $existing?->id));

        $this->render('install.php', ['error' => null]);
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
