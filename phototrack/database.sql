CREATE TABLE IF NOT EXISTS `phototrack_photo_use` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `resource-id` char(64) NOT NULL,
       `table` char(64) NOT NULL,
       `field` char(64) NOT NULL,
       `row-id` int(11) NOT NULL,
       `checked` timestamp NOT NULL DEFAULT now(),
       PRIMARY KEY (`id`),
       INDEX `resource-id` (`resource-id`),
       INDEX `row` (`table`,`field`,`row-id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE IF NOT EXISTS `phototrack_row_check` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `table` char(64) NOT NULL,
       `row-id` int(11) NOT NULL,
       `checked` timestamp NOT NULL DEFAULT now(),
       PRIMARY KEY (`id`),
       INDEX `row` (`table`,`row-id`),
       INDEX `checked` (`checked`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

SELECT TRUE
