-- Портал, где установлено приложение. Персональных данных здесь нет: только адрес портала и токены.
CREATE TABLE portal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id VARCHAR(64) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    client_endpoint VARCHAR(255) NOT NULL,
    access_token VARCHAR(255) NOT NULL,
    refresh_token VARCHAR(255) NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    installed_by INT UNSIGNED NOT NULL,
    installed_at DATETIME NOT NULL,
    UNIQUE KEY ux_portal_member (member_id)
);

-- Кэш проверки токена пользователя: хеш токена → ID сотрудника, чтобы не звать user.current на каждый запрос
CREATE TABLE user_session (
    token_hash CHAR(64) PRIMARY KEY,
    portal_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_admin TINYINT(1) NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    KEY ix_user_session_expires (expires_at)
);

CREATE TABLE schema_version (
    version INT UNSIGNED NOT NULL
);
