<?php
require __DIR__ . '/app/bootstrap.php';

use Gws\Mail\Imap;
use Gws\System\Registry;

$imap = new Imap(Registry::getConf());
if ($imap->connect()) {
    $imap->saveMails();
}

?>