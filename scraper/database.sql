
CREATE TABLE IF NOT EXISTS `scraper` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `guid` char(64) NOT NULL,
       `address` char(64) NOT NULL,
       `uid` int(11) NOT NULL,
       `type` char(255) NOT NULL,
       `network` char(255) NOT NULL,
       `status` char(255) NOT NULL,
       `want-status` char(255) NOT NULL,
       `activity` char(255) NOT NULL,
       `want-activity` char(255) NOT NULL,
       `command` char(255) NOT NULL,
       `interval` int NOT NULL,
       `data` mediumtext NOT NULL,
       `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `update` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       PRIMARY KEY (`id`),
       KEY(`guid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `scraper_command` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `guid` char(64) NOT NULL,
       `uid` int(11) NOT NULL,
       `scraper-guid` char(64) NOT NULL,
       `command` char(255) NOT NULL,
       `data` mediumtext NOT NULL,
       `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `valid` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `expires` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `started` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `finished` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `result` mediumtext NOT NULL,
       PRIMARY KEY (`id`),
       KEY(`guid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8