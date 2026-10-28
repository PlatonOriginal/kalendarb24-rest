<?php
declare(strict_types=1);

namespace Kb24Rest\Portal;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use PDO;

/**
 * Определяет сотрудника по токену из BX24.getAuth(). Токен проверяется вызовом user.current
 * на адрес портала из БД, поэтому подделать member_id или домен в запросе не получится.
 */
final class UserResolver
{
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly PortalStore $portals,
        private readonly RestClient $rest,
        private readonly ?PDO $cache = null,
    ) {
    }

    public function resolve(string $memberId, string $userToken): CurrentUser
    {
        $portal = $this->portals->findByMemberId($memberId);
        if ($portal === null)
        {
            throw new RestException('portal_not_installed');
        }
        if ($userToken === '')
        {
            throw new RestException('no_auth');
        }

        $hash = hash('sha256', $memberId . '|' . $userToken);
        $cached = $this->fromCache($hash, $portal);
        if ($cached !== null)
        {
            return $cached;
        }

        // Оба вопроса одним вызовом: каждый запрос к порталу стоит около секунды
        $batch = $this->rest->callAsUser($portal, $userToken, 'batch', ['halt' => 1, 'cmd' => ['me' => 'user.current', 'admin' => 'user.admin']]);
        $userId = (int)($batch['result']['me']['ID'] ?? 0);
        if ($userId <= 0)
        {
            throw new RestException('no_user');
        }
        $isAdmin = (bool)($batch['result']['admin'] ?? false);

        $user = new CurrentUser($portal, $userId, $isAdmin);
        $this->toCache($hash, $user);

        return $user;
    }

    private function fromCache(string $hash, Portal $portal): ?CurrentUser
    {
        if ($this->cache === null)
        {
            return null;
        }
        $stmt = $this->cache->prepare('SELECT user_id, is_admin FROM user_session WHERE token_hash = ? AND portal_id = ? AND expires_at > ?');
        $stmt->execute([$hash, $portal->id, time()]);
        $row = $stmt->fetch();

        return $row ? new CurrentUser($portal, (int)$row['user_id'], (bool)$row['is_admin']) : null;
    }

    private function toCache(string $hash, CurrentUser $user): void
    {
        if ($this->cache === null)
        {
            return;
        }
        $this->cache->prepare('DELETE FROM user_session WHERE expires_at <= ?')->execute([time()]);
        $this->cache->prepare('REPLACE INTO user_session (token_hash, portal_id, user_id, is_admin, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$hash, $user->portal->id, $user->userId, (int)$user->isAdmin, time() + self::CACHE_TTL]);
    }
}
