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
	$logcontent = array();
	// Jede mail für sich, Mail-Schleife
	foreach($imap_array AS $val){
		$header = '';
		$subject = '';
		
		$header = imap_headerinfo($mbox,$val);
		if (property_exists($header, 'subject')) {
			$presubject = imap_mime_header_decode($header->subject);
			$subject = $presubject[0]->text;
			$berteff = substr($subject,0,32);
			// testing
			print $subject . "\n";
			// Fallnummer herausholen (RegEx überprüfen)
			preg_match('/AKM([0-9]+);/',$subject,$matches);
		} // Und wenn nicht?
		
		// Fallnummer wurde gefunden, sonst Fehlerprozedur 1
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
					$subdir = "";
					foreach($subdirs AS $val3){
						if(strstr($val3,$fall)){
							$subdir = $val3;
							break;
						}
					}
					// Wenn passendes Fall-Verzeichnis gefunden wurde, Mail speichern, sonst Fehlerprozedur 3
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
							$logcontent['SUCCESS'][] = "Email Nr. " . $val . " wurde in den Ordner '" . $subdir . "' gespeichert und nach 'gespeichert' verschoben\n";
						}
						fclose($whandle);
					}else{
						// Fehlerprozedur 3
						imap_mail_move ( $mbox , $val , FAILBOX );
						$logcontent['FAILED']['Fall'][] = "Email Nr. " . $val . " (\"" . $berteff . "...\") wurde nicht gespeichert und nach 'fehler' verschoben\n";
					}
				}
			}else{
				// Fehlerprozedur 2
				imap_mail_move ( $mbox , $val , FAILBOX );
				$logcontent['FAILED']['Bereich'][] = "Email Nr. " . $val . " (\"" . $berteff . "...\") wurde nicht gespeichert und nach 'fehler' verschoben\n";
			}			
		}else{
			// Fehlerprozedur 1
			imap_mail_move ( $mbox , $val , FAILBOX );
			$logcontent['FAILED']['preg_match'][] = "Aus Email Nr. " . $val . " (\"" . $berteff . "...\") konnte keine Fallnummer extrahiert werden, sie wurde nicht gespeichert und nach 'fehler' verschoben\n";
		}
	} // Ende Mail-Schleife
	
	// Logfile schreiben
	$lhandle = fopen(LOGPATH . "log.txt","a");
	fwrite($lhandle,strftime(TIMESTRING) . "\n");
	if(!empty($logcontent)){
		if(is_array($logcontent['SUCCESS'])){
			fwrite($lhandle,"[SUCCESS] " . count($logcontent['SUCCESS']) . " Einträge\n");
			foreach($logcontent['SUCCESS'] AS $value){
				fwrite($lhandle,$value);
			}
		fwrite($lhandle,"\n");
		}
		if(is_array($logcontent['FAILED']['preg_match'])){
			fwrite($lhandle,"[FAILED/Preg_Match] (keine Fall-Nr.) " . count($logcontent['FAILED']['preg_match']) . " Einträge\n");
			foreach($logcontent['FAILED']['preg_match'] AS $value){
				fwrite($lhandle,$value);
			}
		fwrite($lhandle,"\n");
		}
		if(is_array($logcontent['FAILED']['Bereich'])){
			fwrite($lhandle,"[FAILED/Bereich] (kein Bereichs-Ordner) " . count($logcontent['FAILED']['Bereich']) . " Einträge\n");
			foreach($logcontent['FAILED']['Bereich'] AS $value){
				fwrite($lhandle,$value);
			}
		fwrite($lhandle,"\n");
		}
		if(is_array($logcontent['FAILED']['Fall'])){
			fwrite($lhandle,"[FAILED/Fall] (kein Fall-Ordner) " . count($logcontent['FAILED']['Fall']) . " Einträge\n");
			foreach($logcontent['FAILED']['Fall'] AS $value){
				fwrite($lhandle,$value);
			}
		fwrite($lhandle,"\n");
		}
	}else{
		fwrite($lhandle,"Nichts gemacht.\n");
		fwrite($lhandle,"\n");
	}
	fclose($lhandle);
}
// Löscht alle durch imap_delete oder imap_mail_move markierten mails, schließt Mail-Handler
imap_close($mbox,CL_EXPUNGE);
?>
