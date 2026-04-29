CREATE TABLE IF NOT EXISTS `minicrm_import_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_name` VARCHAR(190) NOT NULL,
    `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `imported_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_minicrm_import_batches_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_work_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` INT UNSIGNED NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `card_name` VARCHAR(255) NOT NULL,
    `customer_name` VARCHAR(190) DEFAULT NULL,
    `responsible` VARCHAR(160) DEFAULT NULL,
    `minicrm_status` VARCHAR(120) DEFAULT NULL,
    `work_type` VARCHAR(160) DEFAULT NULL,
    `work_kind` VARCHAR(160) DEFAULT NULL,
    `request_type` VARCHAR(60) DEFAULT NULL,
    `date_value` VARCHAR(60) DEFAULT NULL,
    `submitted_date` VARCHAR(60) DEFAULT NULL,
    `birth_name` VARCHAR(160) DEFAULT NULL,
    `birth_place` VARCHAR(120) DEFAULT NULL,
    `birth_date` VARCHAR(60) DEFAULT NULL,
    `mother_name` VARCHAR(160) DEFAULT NULL,
    `mailing_address` VARCHAR(255) DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `city` VARCHAR(120) DEFAULT NULL,
    `site_address` VARCHAR(255) DEFAULT NULL,
    `street` VARCHAR(160) DEFAULT NULL,
    `house_number` VARCHAR(80) DEFAULT NULL,
    `floor_door` VARCHAR(80) DEFAULT NULL,
    `hrsz` VARCHAR(80) DEFAULT NULL,
    `consumption_place_id` VARCHAR(120) DEFAULT NULL,
    `meter_serial` VARCHAR(120) DEFAULT NULL,
    `controlled_meter_serial` VARCHAR(120) DEFAULT NULL,
    `wire_type` VARCHAR(120) DEFAULT NULL,
    `meter_cabinet` VARCHAR(160) DEFAULT NULL,
    `meter_location` VARCHAR(255) DEFAULT NULL,
    `pole_type` VARCHAR(120) DEFAULT NULL,
    `wire_note` TEXT DEFAULT NULL,
    `cabinet_note` TEXT DEFAULT NULL,
    `document_links_json` LONGTEXT DEFAULT NULL,
    `raw_payload` LONGTEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_work_items_source_id` (`source_id`),
    KEY `idx_minicrm_work_items_status` (`minicrm_status`),
    KEY `idx_minicrm_work_items_responsible` (`responsible`),
    KEY `idx_minicrm_work_items_batch_id` (`batch_id`),
    KEY `idx_minicrm_work_items_submitted_date` (`submitted_date`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_work_item_files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_item_id` INT UNSIGNED NOT NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `project_id` VARCHAR(40) NOT NULL,
    `label` VARCHAR(190) DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `zip_entry` TEXT DEFAULT NULL,
    `zip_entry_hash` CHAR(64) NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(120) DEFAULT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_work_item_files_zip_hash` (`zip_entry_hash`),
    KEY `idx_minicrm_work_item_files_work_item` (`work_item_id`),
    KEY `idx_minicrm_work_item_files_source_id` (`source_id`),
    KEY `idx_minicrm_work_item_files_project_id` (`project_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_connection_request_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_item_id` INT UNSIGNED NOT NULL,
    `source_id` VARCHAR(80) NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `connection_request_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_connection_links_work_item` (`work_item_id`),
    KEY `idx_minicrm_connection_links_source_id` (`source_id`),
    KEY `idx_minicrm_connection_links_customer` (`customer_id`),
    KEY `idx_minicrm_connection_links_request` (`connection_request_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minicrm_customer_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_id` VARCHAR(80) NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `project_id` VARCHAR(40) DEFAULT NULL,
    `card_name` VARCHAR(255) DEFAULT NULL,
    `responsible` VARCHAR(160) DEFAULT NULL,
    `minicrm_status` VARCHAR(120) DEFAULT NULL,
    `status_group` VARCHAR(160) DEFAULT NULL,
    `status_updated_at` VARCHAR(60) DEFAULT NULL,
    `visibility` VARCHAR(60) DEFAULT NULL,
    `created_by_name` VARCHAR(160) DEFAULT NULL,
    `created_date` VARCHAR(60) DEFAULT NULL,
    `modified_by_name` VARCHAR(160) DEFAULT NULL,
    `modified_date` VARCHAR(60) DEFAULT NULL,
    `card_url` VARCHAR(500) DEFAULT NULL,
    `minicrm_imported_at` VARCHAR(60) DEFAULT NULL,
    `person_type` VARCHAR(80) DEFAULT NULL,
    `person_name` VARCHAR(190) DEFAULT NULL,
    `person_first_name` VARCHAR(120) DEFAULT NULL,
    `person_last_name` VARCHAR(120) DEFAULT NULL,
    `person_email` VARCHAR(190) DEFAULT NULL,
    `person_phone` VARCHAR(80) DEFAULT NULL,
    `person_summary` TEXT DEFAULT NULL,
    `person_created_by_name` VARCHAR(160) DEFAULT NULL,
    `person_created_date` VARCHAR(60) DEFAULT NULL,
    `person_modified_by_name` VARCHAR(160) DEFAULT NULL,
    `person_modified_date` VARCHAR(60) DEFAULT NULL,
    `person_position` VARCHAR(160) DEFAULT NULL,
    `person_website` VARCHAR(255) DEFAULT NULL,
    `person_consent` VARCHAR(120) DEFAULT NULL,
    `raw_payload` LONGTEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_minicrm_customer_profiles_source_id` (`source_id`),
    KEY `idx_minicrm_customer_profiles_customer` (`customer_id`),
    KEY `idx_minicrm_customer_profiles_project` (`project_id`),
    KEY `idx_minicrm_customer_profiles_status` (`minicrm_status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `minicrm_customer_profiles`
    ADD COLUMN IF NOT EXISTS `person_type` VARCHAR(80) DEFAULT NULL AFTER `minicrm_imported_at`,
    ADD COLUMN IF NOT EXISTS `person_name` VARCHAR(190) DEFAULT NULL AFTER `person_type`,
    ADD COLUMN IF NOT EXISTS `person_first_name` VARCHAR(120) DEFAULT NULL AFTER `person_name`,
    ADD COLUMN IF NOT EXISTS `person_last_name` VARCHAR(120) DEFAULT NULL AFTER `person_first_name`,
    ADD COLUMN IF NOT EXISTS `person_email` VARCHAR(190) DEFAULT NULL AFTER `person_last_name`,
    ADD COLUMN IF NOT EXISTS `person_phone` VARCHAR(80) DEFAULT NULL AFTER `person_email`,
    ADD COLUMN IF NOT EXISTS `person_summary` TEXT DEFAULT NULL AFTER `person_phone`,
    ADD COLUMN IF NOT EXISTS `person_created_by_name` VARCHAR(160) DEFAULT NULL AFTER `person_summary`,
    ADD COLUMN IF NOT EXISTS `person_created_date` VARCHAR(60) DEFAULT NULL AFTER `person_created_by_name`,
    ADD COLUMN IF NOT EXISTS `person_modified_by_name` VARCHAR(160) DEFAULT NULL AFTER `person_created_date`,
    ADD COLUMN IF NOT EXISTS `person_modified_date` VARCHAR(60) DEFAULT NULL AFTER `person_modified_by_name`,
    ADD COLUMN IF NOT EXISTS `person_position` VARCHAR(160) DEFAULT NULL AFTER `person_modified_date`,
    ADD COLUMN IF NOT EXISTS `person_website` VARCHAR(255) DEFAULT NULL AFTER `person_position`,
    ADD COLUMN IF NOT EXISTS `person_consent` VARCHAR(120) DEFAULT NULL AFTER `person_website`;
