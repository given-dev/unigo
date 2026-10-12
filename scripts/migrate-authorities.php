<?php
/** Adds authority profiles without replacing existing data. Safe to re-run. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('Command line only.');
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;

Database::instance()->run(<<<'SQL'
CREATE TABLE IF NOT EXISTS `authorities` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `title`         VARCHAR(120) NOT NULL DEFAULT '',
  `region`        VARCHAR(80)  NOT NULL DEFAULT '',
  `district`      VARCHAR(80)  NOT NULL DEFAULT '',
  `contact_phone` VARCHAR(25)  NOT NULL DEFAULT '',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_authorities_user` (`user_id`),
  KEY `ix_authorities_district` (`district`),
  CONSTRAINT `fk_authorities_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
echo "authorities table ready.\n";
