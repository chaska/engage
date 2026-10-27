/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Fork 0.9.14 (package 3.4.18): reports of comments by registered users. Additive and idempotent: it only creates a table if it does not exist.
CREATE TABLE IF NOT EXISTS `#__engage_reports` (
    `id`          BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
    `comment_id`  BIGINT(20) unsigned NOT NULL,
    `user_id`     INT(11) unsigned NOT NULL,
    `reason`      TINYINT(3) unsigned NOT NULL COMMENT '1 = spam, 2 = offensive, 3 = personal data, 4 = other',
    `note`        VARCHAR(200) NOT NULL DEFAULT '',
    `created`  DATETIME NOT NULL,
    `status`      TINYINT(3) unsigned NOT NULL DEFAULT 0 COMMENT '0 = open, 1 = dismissed, 2 = handled (the comment was moderated)',
    `handled_by`  INT(11) unsigned NULL DEFAULT NULL,
    `handled_on`  DATETIME NULL DEFAULT NULL,
    `notified_on` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `#__engage_reports_unique` (`comment_id`, `user_id`),
    KEY `#__engage_reports_user` (`user_id`, `created`),
    KEY `#__engage_reports_status` (`status`, `created`)
) ENGINE InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci COMMENT='Reports of comments made by registered users';
