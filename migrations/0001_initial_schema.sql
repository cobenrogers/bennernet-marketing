CREATE TABLE IF NOT EXISTS `marketing_schema_migrations` (
  `version` VARCHAR(255) NOT NULL PRIMARY KEY,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `port_modules` (`slug`, `name`, `icon`, `description`, `is_active`, `sort_order`)
VALUES ('marketing', 'Marketing', 'megaphone', 'Drafts queue, published archive, engagement timeline for Glyc and IBD Movement', 1, 30)
ON DUPLICATE KEY UPDATE `is_active` = 1, `icon` = 'megaphone';
