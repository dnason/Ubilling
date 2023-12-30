ALTER TABLE `envydevices` ADD `port` INT NULL DEFAULT NULL AFTER `cutend`;

CREATE TABLE IF NOT EXISTS `oph_nostromo` (
  `login` VARCHAR(50) DEFAULT NULL,
  `month` tinyint(4) DEFAULT NULL,
  `year` SMALLINT(6) DEFAULT NULL,
  `U0` BIGINT(20) DEFAULT NULL,
  `D0` BIGINT(20) DEFAULT NULL,
  `cash` DOUBLE DEFAULT NULL,
  KEY `login` (`login`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 ;