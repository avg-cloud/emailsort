<?php

/*
 * This file is part of the Gws package.
 *
 * (c) Viktor Grandgeorg <viktor@grandgeorg.de>
 * (c) Arne Kronberger
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gws\Mail;

use Gws\System\Registry;
use Gws\File\Manager;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/**
 * IMAP Mail Class
 *
 * Get mails from IMAP and save them to local file system
 *
 * @author Viktor Grandgeorg <viktor@grandgeorg.de>
 */
class Imap
{
    private $conf;
    private $conn;
    private $errors = false;

    public function __construct(\Noodlehaus\Config $conf)
    {
        $this->conf = $conf;
    }

    /**
     * Set open connection to IMAP server's INBOX
     *
     * @return boolean Whether the connection is set
     */
    public function connect()
    {
        if (isset($this->conn)) {
            return true;
        }
        try {
            $this->conn = imap_open(
                '{' .
                $this->conf->get('mail.srv') .
                $this->conf->get('imap.suffix') .
                '}' .
                $this->conf->get('imap.inbox'),
                $this->conf->get('mail.usr'),
                $this->conf->get('mail.pwd')
            );
            return true;
        } catch (\Exception $e) {
            Registry::getLogger()->error($e->getMessage());
            return false;
        }
    }

    /**
     * Search mails on IMAP server.
     * Basically fetch all mails in INBOX.
     *
     * @return boolean|array false or mail ids
     */
    public function searchMails()
    {
        if (!isset($this->conn)) {
            Registry::getLogger()->error('IMAP connection is not set');
            return false;
        } else {
            return imap_search($this->conn, 'ALL');
        }
    }

    /**
     * Main method to handle mails in INBOX
     *
     * 1.   Get all mails in INBOX
     * 2.   Iterate over mails
     * 3.   Find/match string for case in each subject
     * 4.   Get the path to save each mail to
     * 5.   Save each mail
     *
     * @return void
     */
    public function saveMails()
    {
        $mails = $this->searchMails();
        if (false === $mails || empty($mails)) {
            imap_close($this->conn);
            return;
        }
        $mgr = new Manager($this->conf->get('app.savepath'));
        foreach ($mails as $uid) {
            if ($this->conf->get('app.allowed_senders_only') && !$this->verifySender($uid)) {
                $this->errors = true;
                if ($this->conf->get('app.move_mail') && !$this->moveMailToFailbox($uid)) {
                    $this->logMoveMailError($this->conf->get('imap.failbox'));
                }
                continue;
            }
            if (!$case = $this->findCaseInSubject($uid)) {
                $this->errors = true;
                Registry::getLogger()->debug('Could not match subject for msg: ' . $uid);
                if ($this->conf->get('app.move_mail') && !$this->moveMailToFailbox($uid)) {
                    $this->logMoveMailError($this->conf->get('imap.failbox'));
                }
                continue;
            }
            if(!$savePath = $mgr->getFileSavePath((int) $case)) {
                $this->errors = true;
                Registry::getLogger()->debug('Could not match savePath for msg: ' . $uid);
                if ($this->conf->get('app.move_mail') && !$this->moveMailToFailbox($uid)) {
                    $this->logMoveMailError($this->conf->get('imap.failbox'));
                }
                continue;
            }
            if (!$this->saveCurrentMail($uid, $savePath)) {
                $this->errors = true;
                Registry::getLogger()->debug('Could not save mail with msg id: ' . $uid);
                if ($this->conf->get('app.move_mail') && !$this->moveMailToFailbox($uid)) {
                    $this->logMoveMailError($this->conf->get('imap.failbox'));
                }
                continue;
            }
            if ($this->conf->get('app.move_mail') && !$this->moveMailToSuccessbox($uid)) {
                $this->errors = true;
                $this->logMoveMailError($this->conf->get('imap.successbox'));
            }
        }
        if ($this->errors) {
            $this->sendAdminMail();
        }
        imap_close($this->conn, CL_EXPUNGE);
    }

    /**
     * Move a mail to the successbox on IMAP server
     *
     * @param int $uid IMAP mail UID
     * @return boolean TRUE on success or FALSE on failure
     */
    public function moveMailToSuccessbox($uid)
    {
        return imap_mail_move($this->conn, $uid, $this->conf->get('imap.successbox'));
    }

    /**
     * Move a mail to the failbox on IMAP server
     *
     * @param int $uid IMAP mail UID
     * @return boolean TRUE on success or FALSE on failure
     */
    public function moveMailToFailbox($uid)
    {
        return imap_mail_move($this->conn, $uid, $this->conf->get('imap.failbox'));
    }

    public function logMoveMailError($dir)
    {
        Registry::getLogger()->error('Could not move mail on IMAP to: ' . $dir);
    }

    /**
     * Save a given mail to local filesystem as eml
     *
     * @param int $uid IMAP mail UID
     * @param string the current path to save the file to
     * @return void
     */
    public function saveCurrentMail($uid, $savePath)
    {
        $filename = $this->getFilename($uid);
        $filePath = $savePath . DIRECTORY_SEPARATOR . $filename;
        if (is_file($filePath)) {
            Registry::getLogger()->error('Skipped writing - file already exists: ' . $filePath);
            return false;
        }
        if(!$handle = fopen($filePath, 'w')) {
            Registry::getLogger()->error('Cannot write file: ' . $filePath);
            return false;
        }
        if (!imap_savebody($this->conn, $handle, $uid)) {
            Registry::getLogger()->error('Could not save mail to: ' . $filePath);
            fclose($handle);
            return false;
        }
        fclose($handle);
        if ($this->conf->get('app.signed_mail_only') && !$this->verifySignature($filePath, $uid)){
            Registry::getLogger()->debug('Signuture not valid for: ' . $filePath);
            if (is_file($filePath)) {
                unlink($filePath);
            }
            return false;
        }
        if ($this->conf->get('app.change_file_perm') && !$this->setOwnerGroupModForFile($filePath)){
            Registry::getLogger()->error('Could not change owner|group|mod for: ' . $filePath);
        }
        return true;
    }

    /**
     * Find string in mail subject by regex
     *
     * @param int $uid IMAP mail UID
     * @return boolean|string false on error or matched string
     */
    public function findCaseInSubject($uid)
    {
        if (!$subject = $this->getSubject($uid)) {
            return false;
        }
        if (!preg_match($this->conf->get('mail.sbmatch'), $subject, $matches)) {
            return false;
        }
        if (!isset($matches[1]) || empty($matches[1])) {
            return false;
        }
        return $matches[1];
    }

    /**
     * Verify the email sender (from) address from a whitelist
     *
     * @param int $uid
     * @return boolean false if no match true if match
     */
    public function verifySender($uid)
    {
        $header = imap_headerinfo($this->conn, $uid);
        $pattern = '/.*\<([\._a-zA-Z0-9-]+@[\.a-z0-9-]+\.[a-z]{2,})\>$/i';
        if (!isset($header->fromaddress) || empty($header->fromaddress)) {
            return false;
        }
        preg_match($pattern, $header->fromaddress, $matches);
        if (!isset($matches[1]) || empty($matches[1])) {
            return false;
        }
        $sender = filter_var($matches[1], FILTER_VALIDATE_EMAIL);
        if (!$sender) {
            return false;
        }
        if (!in_array($sender, $this->conf->get('mail.allowed_senders'))) {
            Registry::getLogger()->debug('Sender not in allowed_senders list: ' . $sender);
            return false;
        }
        return true;
    }

    /**
     * Verify the SMIME Signature of a mail
     *
     * @param string $filePath
     * @param int $uid
     * @return boolean true if signature is ok false otherwhise
     */
    public function verifySignature($filePath, $uid)
    {
        $pem = $this->conf->get('app.basepath') . '/temp/' .
            time() . '-' . $uid . '.pem';
        $verify = openssl_pkcs7_verify($filePath, 0, $pem);
        if (is_file($pem)) {
            unlink($pem);
        }
        if ($verify === -1) {
            $verify = false;
        }
        return $verify;
    }


    /**
     * Get the subject of a mail
     *
     * @param int $uid IMAP mail UID
     * @return boolean|string false on error or subject text
     */
    public function getSubject($uid)
    {
        $header = imap_headerinfo($this->conn, $uid);
        if (!property_exists($header, 'subject')) {
            return false;
        }
        $subjects = imap_mime_header_decode($header->subject);
        reset($subjects);
        $subject = current($subjects);
        if ($subject->charset !== 'utf-8' && strtolower($subject->charset) !== 'default') {
            $subject->text = mb_convert_encoding($subject->text, 'UTF-8', $subject->charset);
        }
        Registry::getLogger()->debug(var_export($subject->text, true));
        return $subject->text;
    }

    /**
     * Get the filename for a mail
     *
     * Filename will be:
     * YYYY-MM-DD_HH-II-SS_UID_Subject.eml
     *
     * @param int $uid IMAP message UID
     * @return string the filename
     */
    public function getFilename($uid)
    {
        $name = date('Y-m-d_H-i-s') . '_' . (int)$uid . '_';
        if (!$subject = $this->getSubject($uid)) {
            $subject = '';
        } else {
            $german = array(
                'Ä' => 'Ae',
                'Ö' => 'Oe',
                'Ü' => 'Ue',
                'ä' => 'ae',
                'ö' => 'oe',
                'ü' => 'ue',
                'ß' => 'ss',
                '@' => '-bei-',
                '&' => '-und-',
            );
            $noalpha = 'ÁÉÍÓÚÝáéíóúýÂÊÎÔÛâêîôûÀÈÌÒÙàèìòùÄËÏÖÜäëïöüÿÃãÕõÅåÑñÇç@°ºªß&';
            $alpha = 'AEIOUYaeiouyAEIOUaeiouAEIOUaeiouAEIOUaeiouyAaOoAaNnCcaooas-';

            $subject = strtr($subject, $german);
            $subject = strtr($subject, $noalpha, $alpha);

            $subject = preg_replace(
                array(
                    $this->conf->get('mail.sbmatch'),
                    '/[^a-zA-Z0-9\-\._\s]/',
                    '/[ ]{2,}/',
                    '/[\s]/',
                    '/\./'
                ),
                array('', '-', ' ', '_', '-'),
                $subject
            );
        }
        $name .= $subject . '.eml';
        return $name;
    }

    /**
     * Change owner, group and modify permissions
     *
     * @param string $file
     * @return boolean true an success false on failure
     */
    public function setOwnerGroupModForFile($file)
    {
        if(!chown($file, $this->conf->get('app.owner'))) {
            return false;
        }
        if(!chgrp($file, $this->conf->get('app.group'))) {
            return false;
        }
        // We need an octal number, so we convert the given
        // string from oct to dec and pass that as mod
        // ... anybody confused?
        $mod = octdec(Registry::getConf()->get('app.mod'));
        if(!chmod($file, $mod)) {
            return false;
        }
        return true;
    }

    /**
     * Send mail with PHPMailer about mails
     * moved to FAILBOX
     *
     * @return void
     */
    private function sendAdminMail()
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->conf->get('mail.srv');
            $mail->SMTPAuth = true;
            $mail->Username = $this->conf->get('mail.usr');
            $mail->Password = $this->conf->get('mail.pwd');
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom($this->conf->get('mail.address'));
            $mail->addAddress($this->conf->get('admin_mail.address'));

            $mail->Subject = $this->conf->get('admin_mail.subject');
            $mail->Body = $this->conf->get('admin_mail.body');

            $mail->send();
        } catch (Exception $e) {
            Registry::getLogger()->error(
                'Message could not be sent. Mailer Error: ', $mail->ErrorInfo
            );
            return false;
        }
    }

}
