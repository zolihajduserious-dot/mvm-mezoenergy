ALTER TABLE `quotes`
    ADD COLUMN IF NOT EXISTS `fee_request_issuer` VARCHAR(40) NOT NULL DEFAULT 'mezoenergy' AFTER `customer_message`;
