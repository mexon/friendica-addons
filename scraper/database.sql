
CREATE TABLE IF NOT EXISTS `scraper_window` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `sid` char(255) NOT NULL,
       `wid` char(64) NOT NULL,
       `addr` char(64) NOT NULL,
       `uid` int(11) NOT NULL,
       `network` char(255) NOT NULL,
       `state` char(255) NOT NULL,
       `interval` int NOT NULL,
       `url` char(255) NOT NULL,
       `scraped` mediumtext NOT NULL,
       `data` mediumtext NOT NULL,
       `first-seen` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `last-seen` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       PRIMARY KEY (`id`),
       KEY `sid` (`sid`),
       KEY `wid` (`wid`),
       KEY `uid` (`uid`)
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