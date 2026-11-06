-- Токен приложения из событий портала: по нему сервер проверяет, что ONAPPUNINSTALL прислал Битрикс24
ALTER TABLE portal ADD COLUMN application_token VARCHAR(64) NULL
