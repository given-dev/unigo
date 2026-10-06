-- ===========================================================================
--  UniGo - Integrated Smart Transport System
--  Database schema (MySQL 5.7+ / MariaDB 10.3+)
--
--  Design notes
--   * InnoDB + utf8mb4 everywhere (emoji + international names).
--   * Every foreign key column that is filtered/sorted is indexed.
--   * UNIQUE constraints double as business rules, e.g.
--       bookings (trip_id, seat_number)  -> a seat can only be sold once
--       ratings  (trip_id, passenger_id) -> one review per passenger per trip
--   * Money is DECIMAL (never FLOAT) to avoid rounding drift.
--   * Timestamps are DATETIME in server timezone, not TIMESTAMP, so values do
--     not shift when the server timezone changes.
--   * Soft status columns instead of hard deletes where audit matters.
--
--  Target: ~1,000 registered users on a single modest VPS; every list
--  endpoint uses LIMIT/OFFSET and covering indexes.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `unigo_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `unigo_db`;

-- ---------------------------------------------------------------------------
-- 1. roles  -  role catalogue (many-to-many with users)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(30)  NOT NULL,
  `slug`        VARCHAR(30)  NOT NULL,
  `description` VARCHAR(160) NOT NULL DEFAULT '',
  `home_route`  VARCHAR(80)  NOT NULL DEFAULT '/home',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. users  -  single identity table shared by every role
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                VARCHAR(150) NOT NULL,
  `password_hash`        VARCHAR(255) NOT NULL,
  `first_name`           VARCHAR(60)  NOT NULL,
  `last_name`            VARCHAR(60)  NOT NULL DEFAULT '',
  `phone`                VARCHAR(25)  NOT NULL DEFAULT '',
  `national_id`          VARCHAR(40)  NOT NULL DEFAULT '',
  `date_of_birth`        DATE         NULL DEFAULT NULL,
  `gender`               ENUM('male','female','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
  `avatar`               VARCHAR(255) NULL DEFAULT NULL,
  `status`               ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `email_verified_at`    DATETIME     NULL DEFAULT NULL,
  `last_login_at`        DATETIME     NULL DEFAULT NULL,
  `password_changed_at`  DATETIME     NULL DEFAULT NULL,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `failed_logins`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     NULL DEFAULT NULL,
  `last_seen_at`         DATETIME     NULL DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `ix_users_status_created` (`status`,`created_at`),
  KEY `ix_users_phone` (`phone`),
  KEY `ix_users_last_login` (`last_login_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. user_roles  -  a user may hold several roles (e.g. driver + passenger)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `user_id`     INT UNSIGNED NOT NULL,
  `role_id`     TINYINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `ix_user_roles_role` (`role_id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_by`   FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. passengers  -  profile extension
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `passengers`;
CREATE TABLE `passengers` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED NOT NULL,
  `address`             VARCHAR(200) NOT NULL DEFAULT '',
  `city`                VARCHAR(80)  NOT NULL DEFAULT '',
  `district`            VARCHAR(80)  NOT NULL DEFAULT '',
  `home_latitude`       DECIMAL(10,7) NULL DEFAULT NULL,
  `home_longitude`      DECIMAL(10,7) NULL DEFAULT NULL,
  `emergency_contact`   VARCHAR(80)  NOT NULL DEFAULT '',
  `emergency_phone`     VARCHAR(25)  NOT NULL DEFAULT '',
  `preferred_payment`   VARCHAR(20)  NOT NULL DEFAULT 'mobile_money',
  `loyalty_points`      INT UNSIGNED NOT NULL DEFAULT 0,
  `total_bookings`      INT UNSIGNED NOT NULL DEFAULT 0,
  `total_spent`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passengers_user` (`user_id`),
  KEY `ix_passengers_city` (`city`),
  CONSTRAINT `fk_passengers_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. operators  -  transport companies
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `operators`;
CREATE TABLE `operators` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `company_name`   VARCHAR(150) NOT NULL,
  `license_number` VARCHAR(60)  NOT NULL DEFAULT '',
  `approval_status` ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  `approved_at`    DATETIME     NULL DEFAULT NULL,
  `approved_by`    INT UNSIGNED NULL DEFAULT NULL,
  `rejection_note` VARCHAR(255) NOT NULL DEFAULT '',
  `contact_email`  VARCHAR(150) NOT NULL DEFAULT '',
  `contact_phone`  VARCHAR(25)  NOT NULL DEFAULT '',
  `address`        VARCHAR(200) NOT NULL DEFAULT '',
  `logo`           VARCHAR(255) NULL DEFAULT NULL,
  `description`    TEXT         NULL,
  `rating_avg`     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `total_revenue`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operators_user` (`user_id`),
  UNIQUE KEY `uq_operators_license` (`license_number`),
  KEY `ix_operators_status` (`approval_status`,`created_at`),
  CONSTRAINT `fk_operators_user`      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_operators_approved`  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. drivers  -  driver profiles + cached rating aggregate
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `drivers`;
CREATE TABLE `drivers` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `operator_id`       INT UNSIGNED NULL DEFAULT NULL,
  `license_number`    VARCHAR(60)  NOT NULL,
  `license_type`      VARCHAR(30)  NOT NULL DEFAULT 'heavy_bus',
  `license_expiry`    DATE         NULL DEFAULT NULL,
  `experience_years`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `rating_avg`        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `total_trips`       INT UNSIGNED NOT NULL DEFAULT 0,
  `total_distance_km` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`            ENUM('available','on_trip','off_duty','suspended') NOT NULL DEFAULT 'off_duty',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drivers_user` (`user_id`),
  UNIQUE KEY `uq_drivers_license` (`license_number`),
  KEY `ix_drivers_operator` (`operator_id`,`status`),
  KEY `ix_drivers_rating` (`rating_avg`),
  CONSTRAINT `fk_drivers_user`     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_drivers_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. operator_drivers  -  company roster (a driver may sub-contract)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `operator_drivers`;
CREATE TABLE `operator_drivers` (
  `operator_id` INT UNSIGNED NOT NULL,
  `driver_id`   INT UNSIGNED NOT NULL,
  `joined_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_primary`  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`operator_id`,`driver_id`),
  KEY `ix_operator_drivers_driver` (`driver_id`),
  CONSTRAINT `fk_op_drivers_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_op_drivers_driver`   FOREIGN KEY (`driver_id`)   REFERENCES `drivers`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. vehicles
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `vehicles`;
CREATE TABLE `vehicles` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_number` VARCHAR(20)  NOT NULL,
  `vehicle_type`        ENUM('bus','electric_bus','taxi','boda','shared_ride','truck','boat') NOT NULL DEFAULT 'bus',
  `make`                VARCHAR(60)  NOT NULL DEFAULT '',
  `model`               VARCHAR(60)  NOT NULL DEFAULT '',
  `year`                SMALLINT UNSIGNED NULL DEFAULT NULL,
  `colour`              VARCHAR(30)  NOT NULL DEFAULT '',
  `plate_colour`        VARCHAR(30)  NOT NULL DEFAULT '',
  `capacity`            SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  `operator_id`         INT UNSIGNED NULL DEFAULT NULL,
  `driver_id`           INT UNSIGNED NULL DEFAULT NULL,
  `home_route_id`       INT UNSIGNED NULL DEFAULT NULL,
  `status`              ENUM('active','inactive','on_trip','maintenance','suspended') NOT NULL DEFAULT 'inactive',
  `gps_enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `gps_device_id`       VARCHAR(60)  NOT NULL DEFAULT '',
  `insurance_expiry`    DATE         NULL DEFAULT NULL,
  `inspection_status`   ENUM('valid','due','expired','failed') NOT NULL DEFAULT 'due',
  `last_inspection`     DATE         NULL DEFAULT NULL,
  `odometer_km`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_reg` (`registration_number`),
  KEY `ix_vehicles_operator` (`operator_id`,`status`),
  KEY `ix_vehicles_driver`   (`driver_id`),
  KEY `ix_vehicles_type_status` (`vehicle_type`,`status`),
  KEY `ix_vehicles_approval` (`inspection_status`,`insurance_expiry`),
  CONSTRAINT `fk_vehicles_operator` FOREIGN KEY (`operator_id`)   REFERENCES `operators`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vehicles_driver`   FOREIGN KEY (`driver_id`)     REFERENCES `drivers`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_vehicles_route`    FOREIGN KEY (`home_route_id`) REFERENCES `routes`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. routes
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `routes`;
CREATE TABLE `routes` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_code`       VARCHAR(20) NOT NULL,
  `name`             VARCHAR(120) NOT NULL,
  `origin_name`      VARCHAR(120) NOT NULL,
  `origin_latitude`  DECIMAL(10,7) NOT NULL DEFAULT 0,
  `origin_longitude` DECIMAL(10,7) NOT NULL DEFAULT 0,
  `destination_name` VARCHAR(120) NOT NULL,
  `destination_latitude`  DECIMAL(10,7) NOT NULL DEFAULT 0,
  `destination_longitude` DECIMAL(10,7) NOT NULL DEFAULT 0,
  `distance_km`      DECIMAL(7,2) NOT NULL DEFAULT 0,
  `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `base_fare`        DECIMAL(10,2) NOT NULL DEFAULT 0,
  `operator_id`      INT UNSIGNED NULL DEFAULT NULL,
  `status`           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `colour`           VARCHAR(10) NOT NULL DEFAULT '#2563EB',
  `description`      TEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_routes_code` (`route_code`),
  KEY `ix_routes_origin` (`origin_name`),
  KEY `ix_routes_destination` (`destination_name`),
  KEY `ix_routes_status` (`status`),
  KEY `ix_routes_operator` (`operator_id`),
  FULLTEXT KEY `ft_routes_search` (`name`,`origin_name`,`destination_name`),
  CONSTRAINT `fk_routes_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 10. route_stops  -  ordered intermediate stops with a fare step
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `route_stops`;
CREATE TABLE `route_stops` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`         INT UNSIGNED NOT NULL,
  `stop_order`       SMALLINT UNSIGNED NOT NULL,
  `stop_name`        VARCHAR(120) NOT NULL,
  `latitude`         DECIMAL(10,7) NOT NULL DEFAULT 0,
  `longitude`        DECIMAL(10,7) NOT NULL DEFAULT 0,
  `minutes_from_origin` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `fare_from_origin` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `is_pickup_point`  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route_stop_order` (`route_id`,`stop_order`),
  KEY `ix_route_stops_name` (`stop_name`),
  CONSTRAINT `fk_route_stops_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 11. trips  -  a scheduled journey of one vehicle on one route
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `trips`;
CREATE TABLE `trips` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_code`        VARCHAR(24) NOT NULL,
  `route_id`         INT UNSIGNED NOT NULL,
  `vehicle_id`       INT UNSIGNED NOT NULL,
  `driver_id`        INT UNSIGNED NULL DEFAULT NULL,
  `operator_id`      INT UNSIGNED NULL DEFAULT NULL,
  `departure_time`   DATETIME NOT NULL,
  `arrival_time`     DATETIME NOT NULL,
  `boarding_opens`   DATETIME NULL DEFAULT NULL,
  `status`           ENUM('scheduled','boarding','in_transit','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `seats_total`      SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  `fare`             DECIMAL(10,2) NOT NULL DEFAULT 0,
  `currency`         CHAR(3) NOT NULL DEFAULT 'UGX',
  `actual_departure` DATETIME NULL DEFAULT NULL,
  `actual_arrival`   DATETIME NULL DEFAULT NULL,
  `delay_minutes`    SMALLINT NOT NULL DEFAULT 0,
  `notes`            VARCHAR(255) NOT NULL DEFAULT '',
  `cancelled_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by`       INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trips_code` (`trip_code`),
  KEY `ix_trips_search` (`status`,`departure_time`),
  KEY `ix_trips_route_departure` (`route_id`,`departure_time`),
  KEY `ix_trips_vehicle` (`vehicle_id`,`departure_time`),
  KEY `ix_trips_driver` (`driver_id`,`departure_time`),
  KEY `ix_trips_operator` (`operator_id`,`departure_time`),
  CONSTRAINT `fk_trips_route`    FOREIGN KEY (`route_id`)    REFERENCES `routes`(`id`)    ON DELETE RESTRICT,
  CONSTRAINT `fk_trips_vehicle`  FOREIGN KEY (`vehicle_id`)  REFERENCES `vehicles`(`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_trips_driver`   FOREIGN KEY (`driver_id`)   REFERENCES `drivers`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_trips_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trips_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 12. seats  -  physical seat inventory for a vehicle (static layout)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `seats`;
CREATE TABLE `seats` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id`  INT UNSIGNED NOT NULL,
  `seat_number` VARCHAR(6) NOT NULL,
  `seat_type`   ENUM('standard','premium','wheelchair') NOT NULL DEFAULT 'standard',
  `row_number`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_seat` (`vehicle_id`,`seat_number`),
  CONSTRAINT `fk_seats_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 13. bookings
--     uq_bookings_seat_slot is the concurrency guard: even two simultaneous
--     requests cannot sell the same numbered seat. It is built on a generated
--     column so that it only constrains *occupying* bookings:
--       - a cancelled / no_show booking leaves seat_slot NULL, which frees the
--         seat to be sold again;
--       - shared rides store 'SHARED' in seat_number, and those slots are also
--         NULL here, so a shared vehicle can be booked by many passengers.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`      VARCHAR(20) NOT NULL,
  `trip_id`        INT UNSIGNED NOT NULL,
  `passenger_id`   INT UNSIGNED NOT NULL,   -- users.id
  `vehicle_id`     INT UNSIGNED NOT NULL,
  `driver_id`      INT UNSIGNED NULL DEFAULT NULL,
  `seat_number`    VARCHAR(6) NOT NULL,
  `seat_slot`      VARCHAR(6) GENERATED ALWAYS AS (
                     CASE
                       WHEN `status` IN ('pending','confirmed','completed')
                            AND `seat_number` <> 'SHARED'
                       THEN `seat_number`
                       ELSE NULL
                     END
                   ) STORED,
  `from_stop_id`   INT UNSIGNED NULL DEFAULT NULL,
  `to_stop_id`     INT UNSIGNED NULL DEFAULT NULL,
  `pickup_point`   VARCHAR(150) NOT NULL DEFAULT '',
  `status`         ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  `fare`           DECIMAL(10,2) NOT NULL DEFAULT 0,
  `currency`       CHAR(3) NOT NULL DEFAULT 'UGX',
  `payment_status` ENUM('unpaid','pending','paid','refunded','failed') NOT NULL DEFAULT 'unpaid',
  `booking_channel` ENUM('web','mobile_web','pwa','ussd','sms','call_centre','admin') NOT NULL DEFAULT 'web',
  `is_simulated`   TINYINT(1) NOT NULL DEFAULT 0,
  `booked_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at`   DATETIME NULL DEFAULT NULL,
  `cancelled_at`   DATETIME NULL DEFAULT NULL,
  `cancel_reason`  VARCHAR(255) NOT NULL DEFAULT '',
  `boarded_at`     DATETIME NULL DEFAULT NULL,
  `notes`          VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_reference` (`reference`),
  UNIQUE KEY `uq_bookings_seat_slot` (`trip_id`,`seat_slot`),
  KEY `ix_bookings_passenger` (`passenger_id`,`status`,`booked_at`),
  KEY `ix_bookings_trip_status` (`trip_id`,`status`),
  KEY `ix_bookings_vehicle` (`vehicle_id`),
  KEY `ix_bookings_created` (`booked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_trip`      FOREIGN KEY (`trip_id`)      REFERENCES `trips`(`id`)      ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bookings_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `users`(`id`)      ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bookings_vehicle`   FOREIGN KEY (`vehicle_id`)   REFERENCES `vehicles`(`id`)   ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_bookings_driver`    FOREIGN KEY (`driver_id`)    REFERENCES `drivers`(`id`)    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bookings_from_stop` FOREIGN KEY (`from_stop_id`) REFERENCES `route_stops`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bookings_to_stop`   FOREIGN KEY (`to_stop_id`)   REFERENCES `route_stops`(`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 14. payments  -  mock gateway ledger. NO card numbers / CVV are ever
--     stored: only a masked reference and the provider transaction id.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`            VARCHAR(30) NOT NULL,
  `user_id`              INT UNSIGNED NOT NULL,
  `booking_id`           INT UNSIGNED NULL DEFAULT NULL,
  `delivery_id`          INT UNSIGNED NULL DEFAULT NULL,
  `amount`               DECIMAL(12,2) NOT NULL DEFAULT 0,
  `currency`             CHAR(3) NOT NULL DEFAULT 'UGX',
  `method`               ENUM('mobile_money','card','cash','wallet') NOT NULL DEFAULT 'mobile_money',
  `provider`             VARCHAR(40) NOT NULL DEFAULT 'mock',
  `provider_reference`   VARCHAR(80) NOT NULL DEFAULT '',
  `masked_account`       VARCHAR(40) NOT NULL DEFAULT '',
  `status`               ENUM('pending','successful','failed','refunded') NOT NULL DEFAULT 'pending',
  `failure_reason`       VARCHAR(160) NOT NULL DEFAULT '',
  `is_mock`              TINYINT(1) NOT NULL DEFAULT 1,
  `refunded_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `initiated_by`         INT UNSIGNED NULL DEFAULT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_reference` (`reference`),
  KEY `ix_payments_user` (`user_id`,`created_at`),
  KEY `ix_payments_booking` (`booking_id`),
  KEY `ix_payments_status` (`status`,`created_at`),
  KEY `ix_payments_provider_ref` (`provider_reference`),
  CONSTRAINT `fk_payments_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_payments_booking`  FOREIGN KEY (`booking_id`)  REFERENCES `bookings`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_payments_initiator` FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 15. locations  -  canonical place dictionary (origins, destinations,
--      pickup points). Normalising place names is what makes search fast.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `category`   ENUM('stop','district','city','landmark','pickup_point','terminal') NOT NULL DEFAULT 'stop',
  `address`    VARCHAR(200) NOT NULL DEFAULT '',
  `city`       VARCHAR(80)  NOT NULL DEFAULT '',
  `district`   VARCHAR(80)  NOT NULL DEFAULT '',
  `latitude`   DECIMAL(10,7) NOT NULL DEFAULT 0,
  `longitude`  DECIMAL(10,7) NOT NULL DEFAULT 0,
  `search_text` VARCHAR(200) NOT NULL DEFAULT '',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_name_cat` (`name`,`category`),
  KEY `ix_locations_search` (`search_text`),
  KEY `ix_locations_geo` (`latitude`,`longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 16. vehicle_locations  -  GPS / simulated telemetry.
--     Written by (a) a real GPS device posting to the API, or (b) the demo
--     simulator. is_simulated + source make that explicit in the UI.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `vehicle_locations`;
CREATE TABLE `vehicle_locations` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id`   INT UNSIGNED NOT NULL,
  `trip_id`      INT UNSIGNED NULL DEFAULT NULL,
  `latitude`     DECIMAL(10,7) NOT NULL,
  `longitude`    DECIMAL(10,7) NOT NULL,
  `speed`        DECIMAL(6,2) NOT NULL DEFAULT 0,   -- km/h
  `heading`      DECIMAL(6,2) NOT NULL DEFAULT 0,   -- degrees from north
  `accuracy`     DECIMAL(6,2) NOT NULL DEFAULT 10,  -- metres
  `recorded_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source`       ENUM('gps_device','driver_phone','simulator','manual') NOT NULL DEFAULT 'simulator',
  `is_simulated` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_vloc_vehicle_time` (`vehicle_id`,`recorded_at`),
  KEY `ix_vloc_trip_time` (`trip_id`,`recorded_at`),
  KEY `ix_vloc_time` (`recorded_at`),
  CONSTRAINT `fk_vloc_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vloc_trip`    FOREIGN KEY (`trip_id`)    REFERENCES `trips`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 17. deliveries  -  goods / parcel logistics
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `deliveries`;
CREATE TABLE `deliveries` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_number`     VARCHAR(20) NOT NULL,
  `customer_id`         INT UNSIGNED NOT NULL,
  `recipient_name`      VARCHAR(120) NOT NULL,
  `recipient_phone`     VARCHAR(25)  NOT NULL,
  `pickup_address`      VARCHAR(200) NOT NULL,
  `pickup_latitude`     DECIMAL(10,7) NULL DEFAULT NULL,
  `pickup_longitude`    DECIMAL(10,7) NULL DEFAULT NULL,
  `dropoff_address`     VARCHAR(200) NOT NULL,
  `dropoff_latitude`    DECIMAL(10,7) NULL DEFAULT NULL,
  `dropoff_longitude`   DECIMAL(10,7) NULL DEFAULT NULL,
  `parcel_description`  VARCHAR(255) NOT NULL,
  `weight_kg`           DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  `is_fragile`          TINYINT(1) NOT NULL DEFAULT 0,
  `declared_value`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vehicle_id`          INT UNSIGNED NULL DEFAULT NULL,
  `trip_id`             INT UNSIGNED NULL DEFAULT NULL,
  `operator_id`         INT UNSIGNED NULL DEFAULT NULL,
  `status`              ENUM('created','assigned','picked_up','in_transit','delivered','cancelled') NOT NULL DEFAULT 'created',
  `estimated_delivery`  DATETIME NULL DEFAULT NULL,
  `delivered_at`        DATETIME NULL DEFAULT NULL,
  `proof_of_delivery`   VARCHAR(255) NOT NULL DEFAULT '',
  `signature_by`        VARCHAR(120) NOT NULL DEFAULT '',
  `price`               DECIMAL(10,2) NOT NULL DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deliveries_tracking` (`tracking_number`),
  KEY `ix_deliveries_customer` (`customer_id`,`created_at`),
  KEY `ix_deliveries_status` (`status`,`created_at`),
  KEY `ix_deliveries_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_deliveries_customer` FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_vehicle`  FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_trip`     FOREIGN KEY (`trip_id`)    REFERENCES `trips`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_operator` FOREIGN KEY (`operator_id`) REFERENCES `operators`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 18. delivery_tracking  -  status history with optional coordinates
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `delivery_tracking`;
CREATE TABLE `delivery_tracking` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` INT UNSIGNED NOT NULL,
  `status`      ENUM('created','assigned','picked_up','in_transit','delivered','cancelled') NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `latitude`    DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude`   DECIMAL(10,7) NULL DEFAULT NULL,
  `location_name` VARCHAR(150) NOT NULL DEFAULT '',
  `recorded_by` INT UNSIGNED NULL DEFAULT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_delivery_tracking_delivery` (`delivery_id`,`recorded_at`),
  CONSTRAINT `fk_dtrack_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dtrack_user`     FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 19. notifications
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       VARCHAR(40) NOT NULL DEFAULT 'system',
  `title`      VARCHAR(120) NOT NULL,
  `message`    VARCHAR(255) NOT NULL DEFAULT '',
  `link`       VARCHAR(190) NULL DEFAULT NULL,
  `icon`       VARCHAR(30) NOT NULL DEFAULT 'bell',
  `severity`   ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`    DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_user_unread` (`user_id`,`is_read`,`created_at`),
  KEY `ix_notif_type` (`type`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 20. ratings  -  passenger review of a completed trip
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `ratings`;
CREATE TABLE `ratings` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_id`      INT UNSIGNED NOT NULL,
  `booking_id`   INT UNSIGNED NULL DEFAULT NULL,
  `passenger_id` INT UNSIGNED NOT NULL,
  `driver_id`    INT UNSIGNED NOT NULL,
  `vehicle_id`   INT UNSIGNED NULL DEFAULT NULL,
  `rating`       TINYINT UNSIGNED NOT NULL,
  `punctuality`  TINYINT UNSIGNED NULL DEFAULT NULL,
  `cleanliness`  TINYINT UNSIGNED NULL DEFAULT NULL,
  `comment`      VARCHAR(500) NOT NULL DEFAULT '',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ratings_trip_passenger` (`trip_id`,`passenger_id`),
  KEY `ix_ratings_driver` (`driver_id`,`created_at`),
  KEY `ix_ratings_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_ratings_trip`      FOREIGN KEY (`trip_id`)      REFERENCES `trips`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_ratings_booking`   FOREIGN KEY (`booking_id`)   REFERENCES `bookings`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_ratings_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `users`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_ratings_driver`    FOREIGN KEY (`driver_id`)    REFERENCES `drivers`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_ratings_vehicle`   FOREIGN KEY (`vehicle_id`)   REFERENCES `vehicles`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `ck_ratings_range`     CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 21. emergency_alerts  -  SOS
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `emergency_alerts`;
CREATE TABLE `emergency_alerts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`      VARCHAR(20) NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `vehicle_id`     INT UNSIGNED NULL DEFAULT NULL,
  `trip_id`        INT UNSIGNED NULL DEFAULT NULL,
  `emergency_type` ENUM('accident','medical','security','breakdown','harassment','missing_person','other') NOT NULL DEFAULT 'other',
  `description`    VARCHAR(500) NOT NULL DEFAULT '',
  `latitude`       DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude`      DECIMAL(10,7) NULL DEFAULT NULL,
  `address_text`   VARCHAR(190) NOT NULL DEFAULT '',
  `contact_phone`  VARCHAR(25) NOT NULL DEFAULT '',
  `status`         ENUM('new','investigating','responding','resolved','dismissed') NOT NULL DEFAULT 'new',
  `severity`       ENUM('low','medium','high','critical') NOT NULL DEFAULT 'high',
  `handled_by`     INT UNSIGNED NULL DEFAULT NULL,
  `handled_at`     DATETIME NULL DEFAULT NULL,
  `resolution_note` VARCHAR(500) NOT NULL DEFAULT '',
  `resolved_at`    DATETIME NULL DEFAULT NULL,
  `notify_police`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_simulated`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emergency_reference` (`reference`),
  KEY `ix_emergency_status` (`status`,`created_at`),
  KEY `ix_emergency_user` (`user_id`,`created_at`),
  KEY `ix_emergency_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_emergency_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_emergency_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emergency_trip`    FOREIGN KEY (`trip_id`)    REFERENCES `trips`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_emergency_handler` FOREIGN KEY (`handled_by`) REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 22. complaints  -  passenger / driver problem reports
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaints`;
CREATE TABLE `complaints` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`    VARCHAR(20) NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `booking_id`   INT UNSIGNED NULL DEFAULT NULL,
  `trip_id`      INT UNSIGNED NULL DEFAULT NULL,
  `vehicle_id`   INT UNSIGNED NULL DEFAULT NULL,
  `driver_id`    INT UNSIGNED NULL DEFAULT NULL,
  `category`     ENUM('late_departure','overcharging','vehicle_condition','driver_behaviour','cleanliness','lost_property','safety','other') NOT NULL DEFAULT 'other',
  `severity`     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `description`  VARCHAR(1000) NOT NULL,
  `status`       ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
  `resolution`   VARCHAR(500) NOT NULL DEFAULT '',
  `handled_by`   INT UNSIGNED NULL DEFAULT NULL,
  `resolved_at`  DATETIME NULL DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaints_reference` (`reference`),
  KEY `ix_complaints_status` (`status`,`created_at`),
  KEY `ix_complaints_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_complaints_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_complaints_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_trip`    FOREIGN KEY (`trip_id`)    REFERENCES `trips`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_driver`  FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_handler` FOREIGN KEY (`handled_by`) REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 23. activity_logs  -  audit trail
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL DEFAULT NULL,
  `action`      VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL DEFAULT '',
  `entity_id`   INT UNSIGNED NULL DEFAULT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `ip_address`  VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_logs_created` (`created_at`),
  KEY `ix_logs_user` (`user_id`,`created_at`),
  KEY `ix_logs_action` (`action`,`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 24. system_settings
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(80) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_type`  ENUM('string','number','boolean','json','text') NOT NULL DEFAULT 'string',
  `group_name`    VARCHAR(40) NOT NULL DEFAULT 'general',
  `label`         VARCHAR(120) NOT NULL DEFAULT '',
  `description`   VARCHAR(255) NOT NULL DEFAULT '',
  `is_public`     TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by`    INT UNSIGNED NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `ix_settings_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 25. login_attempts  -  rate limiting buckets
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`           VARCHAR(30) NOT NULL DEFAULT 'login',
  `identifier_hash` CHAR(64) NOT NULL,
  `ip_address`      VARCHAR(45) NOT NULL DEFAULT '',
  `was_successful`  TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_attempts_lookup` (`scope`,`identifier_hash`,`attempted_at`),
  KEY `ix_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 26. remember_tokens  -  "remember me" (opaque, hashed validator)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE `remember_tokens` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `selector`      CHAR(18) NOT NULL,
  `validator_hash` CHAR(64) NOT NULL,
  `expires_at`    DATETIME NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `ix_remember_user` (`user_id`),
  KEY `ix_remember_expiry` (`expires_at`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 27. traffic_reports  -  congestion / accident feed for the authority
--     dashboard. Populated manually by the authority in this version; the
--     PredictionService will later write congestion estimates here.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `traffic_reports`;
CREATE TABLE `traffic_reports` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`     INT UNSIGNED NULL DEFAULT NULL,
  `location_name` VARCHAR(150) NOT NULL DEFAULT '',
  `latitude`     DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude`    DECIMAL(10,7) NULL DEFAULT NULL,
  `category`     ENUM('congestion','accident','roadworks','weather','flooding','event','other') NOT NULL DEFAULT 'congestion',
  `severity`     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `delay_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `description`  VARCHAR(500) NOT NULL DEFAULT '',
  `source`       ENUM('manual','sensor','prediction','community') NOT NULL DEFAULT 'manual',
  `reported_by`  INT UNSIGNED NULL DEFAULT NULL,
  `status`       ENUM('open','monitoring','cleared') NOT NULL DEFAULT 'open',
  `resolved_at`  DATETIME NULL DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_traffic_status` (`status`,`created_at`),
  KEY `ix_traffic_route` (`route_id`),
  KEY `ix_traffic_geo` (`latitude`,`longitude`),
  CONSTRAINT `fk_traffic_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 28. ai_predictions  -  storage for future ML module output.
--     The demo heuristic service writes rows here with model='heuristic-v0'
--     so that nothing computed by simple rules is ever presented as a
--     trained machine learning model.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `ai_predictions`;
CREATE TABLE `ai_predictions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`       VARCHAR(40) NOT NULL,          -- demand | congestion | maintenance | fraud | optimisation
  `scope_type`   VARCHAR(30) NOT NULL DEFAULT 'route',
  `scope_id`     INT UNSIGNED NULL DEFAULT NULL,
  `label`        VARCHAR(120) NOT NULL DEFAULT '',
  `score`        DECIMAL(6,4) NOT NULL DEFAULT 0,
  `value_numeric` DECIMAL(12,4) NULL DEFAULT NULL,
  `value_text`   VARCHAR(255) NOT NULL DEFAULT '',
  `confidence`   DECIMAL(5,4) NOT NULL DEFAULT 0,
  `model`        VARCHAR(40) NOT NULL DEFAULT 'heuristic-v0',
  `is_demo`      TINYINT(1) NOT NULL DEFAULT 1,
  `features`     JSON NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_predictions_module` (`module`,`generated_at`),
  KEY `ix_predictions_scope` (`scope_type`,`scope_id`,`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 29. api_rate_limits  -  generic throttle for public API endpoints
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `api_rate_limits`;
CREATE TABLE `api_rate_limits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint`    VARCHAR(120) NOT NULL,
  `identity`    VARCHAR(80) NOT NULL,
  `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_endpoint_identity` (`endpoint`,`identity`),
  KEY `ix_rate_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 30. channel_messages  -  telephony channel log (USSD / SMS / call centre).
--     The channels are NOT connected in this version: every row is written
--     with provider = 'none' and is_simulated = 1 so a captured payload can
--     never be mistaken for a live USSD or SMS exchange.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `channel_messages`;
CREATE TABLE `channel_messages` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`        ENUM('ussd','sms','call_centre','voice') NOT NULL,
  `direction`      ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
  `event_type`     VARCHAR(40) NOT NULL DEFAULT '',
  `phone_masked`   VARCHAR(12) NOT NULL DEFAULT '',
  `user_id`        INT UNSIGNED NULL DEFAULT NULL,
  `payload`        JSON NULL,
  `provider`       VARCHAR(40) NOT NULL DEFAULT 'none',
  `provider_reference` VARCHAR(80) NOT NULL DEFAULT '',
  `status`         ENUM('recorded','sent','delivered','failed') NOT NULL DEFAULT 'recorded',
  `is_simulated`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_channel_created` (`created_at`),
  KEY `ix_channel_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_channel_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Initial roles (id order matters for readability only)
-- ---------------------------------------------------------------------------
INSERT INTO `roles` (`name`,`slug`,`description`,`home_route`) VALUES
('Passenger','passenger','Books seats, tracks trips, sends SOS alerts','/home'),
('Driver','driver','Operates a vehicle, runs trips, shares location','/driver/dashboard'),
('Operator','operator','Transport company: fleet, routes, trips, revenue','/operator/dashboard'),
('Administrator','admin','Full system administration','/admin/dashboard'),
('Authority','authority','Transport authority: regulation and analytics','/authority/dashboard');

-- ---------------------------------------------------------------------------
-- Default system settings
-- ---------------------------------------------------------------------------
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`setting_type`,`group_name`,`label`,`description`,`is_public`) VALUES
('site_name','UniGo','string','general','Application name','Name shown in the header and browser title',1),
('support_phone','','string','support','Support phone','Shown on the help screen',1),
('support_email','','string','support','Support email','Shown on the help screen',1),
('emergency_hotline','','string','support','Emergency hotline','Police / ambulance number used on the SOS screen',1),
('cancellation_window_minutes','30','number','booking','Cancellation window','Minutes before departure after which a booking can no longer be cancelled online',0),
('demo_mode','0','boolean','general','Simulated data mode','When ON, the UI labels simulated GPS and predictions as DEMO',1),
('booking_reference_prefix','UG','string','booking','Booking reference prefix','',0),
('delivery_reference_prefix','UNI-GO','string','delivery','Parcel tracking prefix','',0),
('max_advance_booking_days','30','number','booking','Advance booking horizon','How far ahead passengers may book',0),
('default_currency','UGX','string','general','Default currency','',1),
('enable_ussd_channel','0','boolean','channels','Enable USSD channel (placeholder)','Interface exists; no gateway is wired up in this version',0),
('enable_sms_channel','0','boolean','channels','Enable SMS channel (placeholder)','Interface exists; no gateway is wired up in this version',0);
