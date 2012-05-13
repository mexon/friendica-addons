CREATE TABLE IF NOT EXISTS `retriever_rule` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `uid` int(11) NOT NULL,
       `contact-id` int(11) NOT NULL,
       `data` mediumtext NOT NULL,
       PRIMARY KEY (`id`),
       KEY `uid` (`uid`),
       KEY `contact-id` (`contact-id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `retriever_item` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `item-uri` char(255) CHARACTER SET ascii NOT NULL,
       `item-uid` int(10) unsigned NOT NULL DEFAULT '0',
       `contact-id` int(10) unsigned NOT NULL DEFAULT '0',
       `resource` int(11) NOT NULL,
       `parent` int(11) NOT NULL,
       KEY `resource` (`resource`),
       KEY `all` (`item-uri`, `item-uid`, `contact-id`, `resource`),
       PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `retriever_resource` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `retriever` int(11) NOT NULL,
       `type` char(255) NOT NULL,
       `binary` int(1) NOT NULL DEFAULT 0,
       `url` char(255) NOT NULL,
       `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `completed` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `last-try` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `num-tries` int(11) NOT NULL DEFAULT 0,
       `data` mediumtext NOT NULL,
       PRIMARY KEY (`id`),
       KEY (`retriever`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8
