<?php
require __DIR__ . '/../vendor/autoload.php';

// error_reporting(0);
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, $errno, E_ERROR, $errfile, $errline);
});

use Gws\System\Registry;

Registry::setConf(array(
    __DIR__ . '/config.json',
    __DIR__ . '/sysconfig.php'
));
Registry::setLogger(
    Registry::getConf()->get('app.basepath'). '/log/error.log',
    Registry::getConf()->get('app.log_level')
);