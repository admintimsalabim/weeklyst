-- ============================================================
-- Weeklyst — Database schema (leeg, zonder content)
-- Importeer dit bestand via phpMyAdmin of de MySQL CLI
-- Versie: 1.8
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Gezinnen ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `families` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(100)  NOT NULL DEFAULT 'Ons gezin',
  `invite_code`         VARCHAR(6)    NOT NULL,
  `owner_id`            INT UNSIGNED  NULL DEFAULT NULL,
  `push_pending`        TINYINT(1)    NOT NULL DEFAULT 0,
  `push_pending_at`     DATETIME      NULL DEFAULT NULL,
  `push_sender_id`      INT UNSIGNED  NULL DEFAULT NULL,
  `delete_requested`    TINYINT(1)    NOT NULL DEFAULT 0,
  `delete_requested_at` DATETIME      NULL DEFAULT NULL,
  `delete_requested_by` VARCHAR(255)  NULL DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gebruikers ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100)  NOT NULL,
  `email`          VARCHAR(255)  NULL DEFAULT NULL,
  `password_hash`  VARCHAR(255)  NOT NULL,
  `family_id`      INT UNSIGNED  NULL DEFAULT NULL,
  `role`           ENUM('owner','member') NOT NULL DEFAULT 'member',
  `email_verified` TINYINT(1)    NOT NULL DEFAULT 0,
  `auth_method`    ENUM('password','magic') NOT NULL DEFAULT 'password',
  `verify_code`    VARCHAR(6)    NULL DEFAULT NULL,
  `verify_expires` DATETIME      NULL DEFAULT NULL,
  `reset_code`     VARCHAR(255)  NULL DEFAULT NULL,
  `reset_expires`  DATETIME      NULL DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`),
  UNIQUE KEY `name_family_unique` (`name`, `family_id`),
  KEY `idx_family` (`family_id`),
  CONSTRAINT `fk_users_family`
    FOREIGN KEY (`family_id`) REFERENCES `families` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FK owner_id in families
ALTER TABLE `families`
  ADD CONSTRAINT `fk_families_owner`
    FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ── Lijsten ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lists` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `family_id`        INT UNSIGNED NOT NULL,
  `data`             LONGTEXT     NOT NULL,
  `updated_by_name`  VARCHAR(100) NULL DEFAULT NULL,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `family_id` (`family_id`),
  CONSTRAINT `fk_lists_family`
    FOREIGN KEY (`family_id`) REFERENCES `families` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Sessies ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sessions` (
  `token`      VARCHAR(64)  NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── App instellingen ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`        VARCHAR(50)  NOT NULL,
  `value`      VARCHAR(255) NOT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `app_settings` (`key`, `value`) VALUES ('app_version', '1.0')
  ON DUPLICATE KEY UPDATE `value` = `value`;

-- ── Klantenkaarten ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `loyalty_cards` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `family_id`  INT UNSIGNED NOT NULL,
  `shop_name`  VARCHAR(100) NOT NULL,
  `code`       VARCHAR(255) NOT NULL,
  `code_type`  ENUM('ean13','code128','qr') NOT NULL DEFAULT 'ean13',
  `color`      VARCHAR(7)   NOT NULL DEFAULT '#2b6cb0',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_family` (`family_id`),
  CONSTRAINT `fk_cards_family`
    FOREIGN KEY (`family_id`) REFERENCES `families` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Push subscriptions ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `family_id`  INT UNSIGNED NOT NULL,
  `endpoint`   TEXT         NOT NULL,
  `p256dh`     TEXT         NOT NULL,
  `auth`       TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_family` (`family_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
