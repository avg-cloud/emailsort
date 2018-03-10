<?php

/*
 * This file is part of the Gws package.
 *
 * (c) Viktor Grandgeorg <viktor@grandgeorg.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gws\File;

use Gws\System\Registry;

/**
 * File Manager Class
 *
 * Get pathes and directories for files
 *
 * @author Viktor Grandgeorg <viktor@grandgeorg.de>
 */
class Manager
{
    private $pwd;

    /**
     * @param string $pwd the base path to search dirs in
     */
    public function __construct(string $pwd)
    {
        $this->pwd = $pwd;
    }

    /**
     * Get parent directory for mails
     *
     * @param int $no mail subject case no
     * @return boolean|string False on failure or parent directory name
     */
    public function getParentDir($no)
    {
        if ($handle = opendir($this->pwd)) {
            while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && preg_match('/^[0-9]+\-[0-9]+$/', $entry)) {
                    $range = explode("-", $entry);
                    if ((int) $range[0] <= (int) $no && (int) $no <= (int) $range[1]) {
                        closedir($handle);
                        return $entry;
                    }
                }
            }
            closedir($handle);
        }
        return false;
    }

    /**
     * Get directory for mails
     *
     * @param string $parentDir directory of parent dir
     * @param string|int $no directory name to search for
     * @return boolean|string False on failure or directory name
     */
    public function getDir($parentDir, $no)
    {
        $pwd = rtrim($this->pwd, '\\/') . DIRECTORY_SEPARATOR;
        if ($handle = opendir($pwd . $parentDir)) {
            while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && preg_match('/^' . $no . '.*$/', $entry)) {
                    closedir($handle);
                    return $entry;
                }
            }
            closedir($handle);
        }
        return false;
    }

    /**
     * Get the path to save a file to
     *
     * @param string|int $no directory name to search for
     * @return boolean|string False on failure or string with directory path
     */
    public function getFileSavePath($no)
    {
        $pwd = rtrim($this->pwd, '\\/') . DIRECTORY_SEPARATOR;
        $parentDir = $this->getParentDir($no);
        if (!$parentDir) {
            Registry::getLogger()->error('Could not find parent directory for file: ' . $no);
            return false;
        }
        $dir = $this->getDir($parentDir, $no);
        if (!$dir) {
            Registry::getLogger()->error('Could not find directory to save to for this file: ' . $no);
            return false;
        }
        if (!is_writable($pwd . $parentDir . DIRECTORY_SEPARATOR . $dir)) {
            Registry::getLogger()->error('Directory is not writeable: ' .
                $pwd . $parentDir . DIRECTORY_SEPARATOR . $dir);
            return false;
        }
        return $pwd . $parentDir . DIRECTORY_SEPARATOR . $dir;
    }
}
