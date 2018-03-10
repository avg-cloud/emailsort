<?php

/*
 * This file is part of the Gws package.
 *
 * (c) Viktor Grandgeorg <viktor@grandgeorg.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gws\System;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Noodlehaus\Config;


/**
 * Registry
 *
 * Allows to get instances in the global scope
 * via static method calls on this class.
 *
 * We also use this for monolog.
 * Although monolog has its own nice reigistry we
 * like to use our own.
 *
 * @author Viktor Grandgeorg <viktor@grandgeorg.de>
 */
class Registry
{
    /**
     * Monolog\Logger instance
     *
     * @var Monolog\Logger
     */
    public static $logger;

    public static $conf;

    /**
     * Simple static singleton Monolog\Logger Setter
     * Change log level (what will be logged) by setting
     * Logger::<LEVEL> e.g. Logger::WARNING
     *
     * @param string $file path to logfile
     * @return void
     */
    public static function setLogger($file, $level = '')
    {
        if (!isset(self::$logger)) {
            $level = (!empty($level)) ? $level : 'ERROR';
            $level = 'Logger::' . $level;
            self::$logger = new Logger('emailsort');
            self::$logger->pushHandler(
                new StreamHandler(realpath($file), $level)
            );
        }
    }

    /**
     * static Monolog\Logger getter
     *
     * @return Monolog\Logger
     */
    public static function getLogger()
    {
        return self::$logger;
    }

    public static function setConf($files)
    {
        if (!isset(self::$conf)) {
            self::$conf = new Config($files);
        }
    }

    public static function getConf()
    {
        return self::$conf;
    }

}
