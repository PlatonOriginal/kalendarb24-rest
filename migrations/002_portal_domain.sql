-- До исправления в domain попадал адрес сервера авторизации. Берём хост из client_endpoint (https://<портал>/rest/)
UPDATE portal
SET domain = SUBSTRING_INDEX(SUBSTRING_INDEX(client_endpoint, '/', 3), '/', -1)
WHERE domain = 'oauth.bitrix.info';
