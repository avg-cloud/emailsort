<?php
require_once("config.inc.php");
function chown_chgrp($file, $uid, $gid){
	chown($file, $uid); 
	chgrp($file, $gid); 
}
function getFileExtension($fileName){
   $parts=explode(".",$fileName);
   return $parts[count($parts)-1];
}
function get_attachment($imap, $m, $mailname, $path){
   $structure = imap_fetchstructure($imap, $m);

    $attachments = array();
    if(isset($structure->parts) && count($structure->parts)) {

        for($i = 0; $i < count($structure->parts); $i++) {

            $attachments[$i] = array(
                'is_attachment' => false,
                'filename' => '',
                'name' => '',
                'attachment' => ''
            );

            if($structure->parts[$i]->ifdparameters) {
                foreach($structure->parts[$i]->dparameters as $object) {
                    if(strtolower($object->attribute) == 'filename') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['filename'] = $object->value;
                    }
                }
            }

            if($structure->parts[$i]->ifparameters) {
                foreach($structure->parts[$i]->parameters as $object) {
                    if(strtolower($object->attribute) == 'name') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['name'] = $object->value;
                    }
                }
            }

            if($attachments[$i]['is_attachment']) {
                $attachments[$i]['attachment'] = imap_fetchbody($imap, $m, $i+1);
                if($structure->parts[$i]->encoding == 3) { // 3 = BASE64
                    $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                }
                elseif($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                    $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                }
            }
        }
    }

    foreach ($attachments as $key => $attachment) {
		if(!empty($attachment['name'])){
			$name = $attachment['name'];
			$contents = $attachment['attachment'];
			$filepath = $path.$mailname.$name;
			file_put_contents($filepath, $contents, LOCK_EX);
		}
    }
}
	// Pfad zum Ablage-Ordner
	
	// Email Zugangsdaten
	
	// Verbindung zum Server
	$mbox = imap_open("{".$email_server.":993/imap/ssl/novalidate-cert}INBOX", $email_benutzer, $email_passwort) or die('Cannot connect to mail: ' . imap_last_error());

	// Array mit allen Msg-IDs der Mails die '$crit' im Betreff haben, siehe config
	$imap_array = imap_search($mbox, $crit , SE_UID );

	// Gibt es Ergebnisse?
	if (!is_array($imap_array)) {
		echo "nö";
		exit;
	}
	
	// Liste der Fallbereichs-Orner holen
	$d = dir($path);
	while (false !== ($entry = $d->read())) {
	   if(is_dir($path.$entry) && $entry !== "." &&  $entry !== ".."){
		   $dirs[]=$entry;
	   }
	}
	$d->close();
	sort($dirs);
	
function existAttachment($part){
    if (isset($part->parts)){
        foreach ($part->parts as $partOfPart){
            existAttachment($partOfPart);
        }
    }
    else{
        if (isset($part->disposition)){
            if ($part->disposition == 'attachment'){
                echo '<p>' . $part->dparameters[0]->value . '</p>';
// here you can create a link to the file whose name is  $part->dparameters[0]->value to download it
                return true;
            }
        }
    }
}
	// Jede Mail für sich
	foreach($imap_array AS $val){
		// Betreffzeile holen
//		echo "<b>Mail: ".$val."</b><br>";
		$header = '';
		$subject = '';
		
		$header = imap_headerinfo($mbox,$val);
		$subject = $header->subject;
		
		// Fallnummer herausholen (RegEx überprüfen)
		preg_match('/(?:AKM)(\d+)(;)/',$subject,$matches);
		
		if(is_numeric($matches[1])){
			$fall = $matches[1];
			// Bereichsordner suchen
			$folder = '';
			$marker = "nope";
			foreach($dirs AS $val2){
				
				$bereich = explode("-",$val2);
				
				if($bereich[0] <= $fall && $fall <= $bereich[1]){
					$folder = $val2;
					$marker = "Treffer!";
				}
				if($marker == "Treffer!"){
					break;
				}else{
					$marker = "nope";
				}
			}
			// Unterordner finden
			
			// Dateinamen bilden (wie sollen die Dateien eigentlich heißen?)
			$name = $val."_".$fall."_".time();
			$fullname = $name.'.eml';
			// Wenn kein passender Folder gefunden wird?
			if(empty($folder)){
				$folder = "";
			}
			// Ablagepfad bilden, '$path' siehe config
			$full_path = $path.$folder."/";
			
			// Datei schreiben
			$whandle = fopen($full_path.$name.'.eml','w');
			imap_savebody ($mbox, $whandle, $val,'',FT_UID);
			fclose($whandle);
			
			// chgrp & chown scheint nicht zu funktionieren
			/*
			echo "Vorher<br>";
			print_r(posix_getpwuid(fileowner($full_path.$fullname)));
			print_r(posix_getgrgid(filegroup($full_path.$fullname)));
			
			chown_chgrp ($full_path.$fullname, "www-data", "www-data");
			
			echo "Nachher:<br>";
			print_r(posix_getpwuid(fileowner($full_path.$fullname)));
			print_r(posix_getgrgid(filegroup($full_path.$fullname)));
			*/
			
			// Jetzt sollte man im Erfolgsfall noch die Mail vom Server löschen
			/* imap_delete($mbox, $val); */
			// und ggf. die Anhänge extrahieren
			// $prefix = $name."_";
			// get_attachment( $mbox,$val,$prefix,$full_path );

		}
	}
	// Löscht alle durch imap_delete markierten mails
	/*
	imap_expunge($mbox);
	*/
	imap_close($mbox);
?>
