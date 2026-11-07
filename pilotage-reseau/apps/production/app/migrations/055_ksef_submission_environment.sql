ALTER TABLE `ksef_submission`
    ADD COLUMN IF NOT EXISTS `environment` ENUM('test', 'production') NOT NULL DEFAULT 'test' AFTER `status`;
