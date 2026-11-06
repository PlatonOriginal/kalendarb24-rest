-- Журнал запросов сервера к REST API Битрикс24 и к oauth.bitrix.info. Хранится 3 суток, затем удаляется.
-- Параметры запроса, ответ и токены сюда не пишутся: только метод, портал, код ответа и время
CREATE TABLE rest_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at INT UNSIGNED NOT NULL,
    member_id VARCHAR(64) NOT NULL,
    method VARCHAR(128) NOT NULL,
    auth_type VARCHAR(8) NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    duration_ms INT UNSIGNED NOT NULL,
    KEY ix_rest_log_created (created_at),
    KEY ix_rest_log_member (member_id)
)
