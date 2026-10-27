-- Версия схемы хранилищ entity.*, уже созданной в портале (хэш описания из Schema::storages())
ALTER TABLE portal ADD COLUMN storage_version VARCHAR(12) NULL
