<?php
declare(strict_types=1);

namespace Kb24Rest\Portal;

use PDO;

final class PdoPortalStore implements PortalStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByMemberId(string $memberId): ?Portal
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal WHERE member_id = ?');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();

        return $row ? new Portal(
            (int)$row['id'],
            $row['member_id'],
            $row['domain'],
            $row['client_endpoint'],
            $row['access_token'],
            $row['refresh_token'],
            (int)$row['expires_at'],
            (int)$row['installed_by'],
        ) : null;
    }

    public function save(Portal $portal): Portal
    {
        $this->pdo->prepare(
            'INSERT INTO portal (member_id, domain, client_endpoint, access_token, refresh_token, expires_at, installed_by, installed_at)
             VALUES (:member_id, :domain, :client_endpoint, :access_token, :refresh_token, :expires_at, :installed_by, NOW())
             ON DUPLICATE KEY UPDATE domain = VALUES(domain), client_endpoint = VALUES(client_endpoint),
                access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at)'
        )->execute([
            'member_id' => $portal->memberId,
            'domain' => $portal->domain,
            'client_endpoint' => $portal->clientEndpoint,
            'access_token' => $portal->accessToken,
            'refresh_token' => $portal->refreshToken,
            'expires_at' => $portal->expiresAt,
            'installed_by' => $portal->installedBy,
        ]);

        return $this->findByMemberId($portal->memberId);
    }
}
