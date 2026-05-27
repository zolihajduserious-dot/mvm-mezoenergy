CREATE TABLE IF NOT EXISTS `lead_imports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(80) NOT NULL,
    `external_lead_id` VARCHAR(190) NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `work_request_id` INT UNSIGNED NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'processing',
    `duplicate_of_id` INT UNSIGNED NULL,
    `error_message` TEXT DEFAULT NULL,
    `imported_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_lead_imports_source_external` (`source`, `external_lead_id`),
    KEY `idx_lead_imports_customer` (`customer_id`),
    KEY `idx_lead_imports_work_request` (`work_request_id`),
    KEY `idx_lead_imports_status` (`status`, `created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
