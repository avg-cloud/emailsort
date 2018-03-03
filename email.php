<?php
require_once("config.inc.php");
// Logverzeichnis anlegen
if(!is_dir( LOGPATH )){
	mkdir( LOGPATH );
}
function get_dirs ($path){
	$dirs = array();
	$d = dir($path);
	while (false !== ($entry = $d->read())) {
	   if(is_dir($path.$entry) && $entry !== "." &&  $entry !== ".."){
		   $dirs[]=$entry;
	   }
	}
	$d->close();
	if(!empty($dirs)){
		sort($dirs);
	}
	return $dirs;
}
// ende functions

// Verbindung zum Server
$mbox = imap_open("{".$email_server.":993/imap/ssl/novalidate-cert}INBOX", $email_benutzer, $email_passwort) or die('Cannot connect to mail: ' . imap_last_error());

// Array mit allen Msg-IDs der Mails die '$crit' im Betreff haben, siehe config
$imap_array = imap_search($mbox, $crit );

// Gibt es neue Mails?
if (is_array($imap_array)) {
	// Bereichs-Verzeichnisse holen
	$dirs = get_dirs(EMAILPATH);
	// Logfile starten
	$lhandle = fopen(LOGPATH . "log.txt","a");
	fwrite($lhandle,strftime(TIMESTRING) . "\n");
	fclose($lhandle);

	// Jede mail für sich, Mail-Schleife
	foreach($imap_array AS $val){
		$header = '';
		$subject = '';
		
		$header = imap_headerinfo($mbox,$val);
		$subject = $header->subject;
		
		// Fallnummer herausholen (RegEx überprüfen)
		preg_match('/AKM([0-9]+);/',$subject,$matches);
		// Fallnummer aus Emailheader holen, sonst Fehlerprozedur 1
		if(isset($matches) && !empty($matches[1]) && is_numeric($matches[1])){
			// Passendes Bereichs-Verzeichnis suchen
			foreach($dirs AS $val2){
				$bereich = explode("-",$val2);
				$fall = $matches[1];
				$folder = '';
				if($bereich[0] <= $fall && $fall <= $bereich[1]){
					$folder = $val2;
					break;
				}
			}
			// Wenn passendes Bereichs-Verzeichnis gefunden wurde, passendes Fall-Verzeichnis suchen, sonst Fehlerprozedur 2
			if(!empty($folder)){
				$subdirs = get_dirs(EMAILPATH . $folder."/");
				// Wenn es Unterverzeichnisse gibt, suche passendes
				if(!empty($subdirs)){
					$subdir = ""; /// Korrekte Zeile?
					foreach($subdirs AS $val3){
						if(strstr($val3,$fall)){
							$subdir = $val3;
							break;
						}
					}
					// Wenn passendes Fall-Verzeichnis gefunden wurde, passendes Subdir suchen, sonst Fehlerprozedur 3
					if(!empty($subdir)){
						// Dateinamen bilden (wie sollen die Dateien eigentlich heißen?)
						$name = strftime("%Y-%m-%d_%H-%M-%S") . "_" . $company . $fall . "_" . $val;
						$fullname = $name.'.eml';
						// Ablagepfad bilden, 'EMAILPATH' siehe config
						$full_path = EMAILPATH . $folder . "/" . $subdir . "/";
						
						// Mail-Datei schreiben, Original-Mail in 'gespeichert' verschieben, Logeintrag
						$whandle = fopen($full_path . $fullname,'w');
						if(imap_savebody($mbox, $whandle, $val,'')){
							imap_mail_move ( $mbox , $val , SUCCESBOX );
							$lhandle = fopen(LOGPATH . "log.txt","a");
							fwrite($lhandle,"[SUCCESS] Email Nr. " . $val . " wurde in den Ordner '" . $subdir . "' gespeichert und nach 'gespeichert' verschoben\n");
							fclose($lhandle);
						}
						fclose($whandle);
					}else{
						// Fehlerprozedur 3
						imap_mail_move ( $mbox , $val , FAILBOX );
						$lhandle = fopen(LOGPATH . "log.txt","a");
						fwrite($lhandle,"[FAILED] Email Nr. " . $val . " wurde nicht gespeichert und nach 'fehler' verschoben (kein Fall-Ordner)\n");
						fclose($lhandle);
					}
				}
			}else{
				// Fehlerprozedur 2
				imap_mail_move ( $mbox , $val , FAILBOX );
				$lhandle = fopen(LOGPATH . "log.txt","a");
				fwrite($lhandle,"[FAILED] Email Nr. " . $val . " wurde nicht gespeichert und nach 'fehler' verschoben (kein Bereichs-Ordner)\n");
				fclose($lhandle);
			}			
		}else{
			// Fehlerprozedur 1
			imap_mail_move ( $mbox , $val , FAILBOX );
			$lhandle = fopen(LOGPATH . "log.txt","a");
			fwrite($lhandle,"[FAILED] Aus Email Nr. " . $val . " konnte keine Fallnummer extrahiert werden (preg_match), sie wurde nicht gespeichert und nach 'fehler' verschoben\n");
			fclose($lhandle);
		}
	} // Ende Mail-Schleife
	// Logfile abschließen
	$lhandle = fopen(LOGPATH . "log.txt","a");
	fwrite($lhandle,"\n");
	fclose($lhandle);
}
// Löscht alle durch imap_delete oder imap_mail_move markierten mails, schließt Mail-Handler
imap_close($mbox,CL_EXPUNGE);
?>
