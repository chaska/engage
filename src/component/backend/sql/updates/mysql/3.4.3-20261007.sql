/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Fork 0.7.0 (package 3.4.3): comment reactions. Additive and idempotent: it runs when updating from the original
-- 3.4.2 and from the published fork package 3.4.2.1 (both have the schema at 3.0.2-20220107) and does nothing if the
-- table already exists. It does not touch #__engage_comments.
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
