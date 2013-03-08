CREATE TABLE IF NOT EXISTS `mailstream_item` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `uid` int(11) NOT NULL,
       `contact-id` int(11) NOT NULL,
       `uri` char(255) NOT NULL,
       `message-id` char(255) NOT NULL,
       `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       `completed` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
       PRIMARY KEY (`id`),
       KEY `message-id` (`message-id`),
       KEY `created` (`created`),
       KEY `completed` (`completed`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
