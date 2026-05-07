-- MiniCRM es portalos adatlap archivum mezok.
-- Biztonsagosan ujrafuttathato.

SET @minicrm_archived_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'minicrm_work_items'
      AND COLUMN_NAME = 'archived_at'
);

SET @sql := IF(
    @minicrm_archived_at_exists = 0,
    'ALTER TABLE `minicrm_work_items` ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `updated_at`',
    'SELECT ''minicrm_work_items.archived_at already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @minicrm_archive_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'minicrm_work_items'
      AND INDEX_NAME = 'idx_minicrm_work_items_archived'
);

SET @sql := IF(
    @minicrm_archive_index_exists = 0,
    'ALTER TABLE `minicrm_work_items` ADD KEY `idx_minicrm_work_items_archived` (`archived_at`)',
    'SELECT ''idx_minicrm_work_items_archived already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @minicrm_archived_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'minicrm_work_items'
      AND COLUMN_NAME = 'archived_by_user_id'
);

SET @sql := IF(
    @minicrm_archived_by_exists = 0,
    'ALTER TABLE `minicrm_work_items` ADD COLUMN `archived_by_user_id` INT UNSIGNED NULL AFTER `archived_at`',
    'SELECT ''minicrm_work_items.archived_by_user_id already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @request_archived_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'connection_requests'
      AND COLUMN_NAME = 'archived_at'
);

SET @sql := IF(
    @request_archived_at_exists = 0,
    'ALTER TABLE `connection_requests` ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `updated_at`',
    'SELECT ''connection_requests.archived_at already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @request_archive_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'connection_requests'
      AND INDEX_NAME = 'idx_connection_requests_archived'
);

SET @sql := IF(
    @request_archive_index_exists = 0,
    'ALTER TABLE `connection_requests` ADD KEY `idx_connection_requests_archived` (`archived_at`)',
    'SELECT ''idx_connection_requests_archived already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @request_archived_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'connection_requests'
      AND COLUMN_NAME = 'archived_by_user_id'
);

SET @sql := IF(
    @request_archived_by_exists = 0,
    'ALTER TABLE `connection_requests` ADD COLUMN `archived_by_user_id` INT UNSIGNED NULL AFTER `archived_at`',
    'SELECT ''connection_requests.archived_by_user_id already exists'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
