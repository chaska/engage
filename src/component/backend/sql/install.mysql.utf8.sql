/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

CREATE TABLE `#__engage_comments` (
    `id`          BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
    `parent_id`   BIGINT(20) unsigned NULL,
    `asset_id`    int(10) unsigned NOT NULL,
    `body`        longtext     NOT NULL,
    `name`        varchar(255) DEFAULT NULL,
    `email`       varchar(255) DEFAULT NULL,
    `ip`          varchar(64)  DEFAULT NULL,
    `user_agent`  varchar(255) NOT NULL,
    `enabled`     tinyint(3) NOT NULL DEFAULT '0',
    `created`     datetime     DEFAULT NULL,
    `created_by`  int(11) DEFAULT NULL,
    `modified`    datetime     DEFAULT NULL,
    `modified_by` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY           `#__engage_comments_asset` (`asset_id`),
    KEY           `#__engage_comments_created` (`created` DESC)
) ENGINE InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci COMMENT='Content comments';

CREATE TABLE `#__engage_unsubscribe` (
    `asset_id` bigint(20) NOT NULL,
    `email`    varchar(255) NOT NULL,
    PRIMARY KEY (`asset_id`, `email`(100))
) ENGINE InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci COMMENT='Unsubscribed emails';

-- Fork 0.7.0: reactions (like, dislike, favorite). Additive; idempotent (also created by sql/updates/mysql/3.4.3-20261007.sql)
CREATE TABLE IF NOT EXISTS `#__engage_reactions` (
    `id`         BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
    `comment_id` BIGINT(20) unsigned NOT NULL,
    `user_id`    INT(11) unsigned NOT NULL,
    `type`       TINYINT(3) unsigned NOT NULL COMMENT '1 = like, 2 = dislike, 3 = favorite',
    `created`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `#__engage_reactions_unique` (`comment_id`, `user_id`, `type`),
    KEY `#__engage_reactions_comment` (`comment_id`),
    KEY `#__engage_reactions_user` (`user_id`)
) ENGINE InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci COMMENT='Comment reactions (likes, dislikes, favorites)';

DROP TABLE IF EXISTS `#__engage_emailtemplates`;

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
