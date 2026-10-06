<?php
/** Adds the vehicle_images table to an existing installation. Safe to re-run. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('Command line only.');
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;

Database::instance()->run(
    'CREATE TABLE IF NOT EXISTS `vehicle_images` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `vehicle_id`  INT UNSIGNED NOT NULL,
        `image_path`  VARCHAR(255) NOT NULL,
        `caption`     VARCHAR(120) NOT NULL DEFAULT "",
        `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ix_vehicle_images_vehicle` (`vehicle_id`,`sort_order`,`id`),
        CONSTRAINT `fk_vehicle_images_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

@mkdir(dirname(__DIR__) . '/public/uploads/vehicles', 0755, true);
echo "vehicle_images table ready.\n";
