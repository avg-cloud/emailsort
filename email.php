<?php
require_once("config.inc.php");
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

// Gibt es Ergebnisse?
if (is_array($imap_array)) {
	
	$dirs = get_dirs(EMAILPATH);
	/*
	var_dump($dirs);
	var_dump($imap_array);
	die();
	*/
	// Jede mail für sich
	foreach($imap_array AS $val){
		$header = '';
		$subject = '';
		
		$header = imap_headerinfo($mbox,$val);
		$subject = $header->subject;
		
		// Fallnummer herausholen (RegEx überprüfen)
		preg_match('/AKM([0-9]+);/',$subject,$matches);
		if(isset($matches) && !empty($matches[1]) && is_numeric($matches[1])){
			foreach($dirs AS $val2){
				$bereich = explode("-",$val2);
				// if(isset($matches) && !empty($matches[1]) && is_numeric($matches[1])){
				$fall = $matches[1];
				$folder = '';
				if($bereich[0] <= $fall && $fall <= $bereich[1]){
					$folder = $val2;
					break;
				}
			}
			// hier sollte $folder gefüllt sein
			if(!empty($folder)){
				$subdirs = get_dirs(EMAILPATH . $folder."/");
				if(!empty($subdirs)){
					$subdir = "";
					foreach($subdirs AS $val3){
						if(strstr($val3,$fall)){
							$subdir = $val3;
							break;
						}
					}
					if(!empty($subdir)){
						// Dateinamen bilden (wie sollen die Dateien eigentlich heißen?)
						$name = strftime("%Y-%m-%d_%H-%M-%S") . "_AKM" . $fall . "_" . $val;
						$fullname = $name.'.eml';
						// Ablagepfad bilden, '$path' siehe config
						$full_path = EMAILPATH . $folder . "/" . $subdir . "/";
						
						// Datei schreiben
						$whandle = fopen($full_path . $fullname,'w');
						if(imap_savebody($mbox, $whandle, $val,'')){
							imap_mail_move ( $mbox , $val , 'gespeichert' );
							$lhandle = fopen(EMAILPATH . "log.txt","a");
							fwrite($lhandle,strftime(TIMESTRING) . " [SUCCESS] Email Nr. " . $val . " wurde in den Ordner '" . $subdir . "' gespeichert und nach 'gespeichert' verschoben\n");
							fclose($lhandle);
						}
						fclose($whandle);
					}else{
						imap_mail_move ( $mbox , $val , 'fehler' );
						$lhandle = fopen(EMAILPATH . "log.txt","a");
						fwrite($lhandle,strftime(TIMESTRING) . " [FAILED] Email Nr. " . $val . " wurde nicht gespeichert und nach 'fehler' verschoben (kein Fall-Ordner)\n");
						fclose($lhandle);
					}
				}
			}else{
				imap_mail_move ( $mbox , $val , 'fehler' );
				$lhandle = fopen(EMAILPATH . "log.txt","a");
				fwrite($lhandle,strftime(TIMESTRING) . " [FAILED] Email Nr. " . $val . " wurde nicht gespeichert und nach 'fehler' verschoben (kein Bereichs-Ordner)\n");
				fclose($lhandle);
			}			
		}else{
			imap_mail_move ( $mbox , $val , 'fehler' );
			$lhandle = fopen(EMAILPATH . "log.txt","a");
			fwrite($lhandle,strftime(TIMESTRING) . " [ERROR] Aus Email Nr. " . $val . " konnte keine Fallnummer extrahiert werden (preg_match), sie wurde nicht gespeichert und nach 'fehler' verschoben\n");
			fclose($lhandle);
		}
	}
	$lhandle = fopen(EMAILPATH . "log.txt","a");
	fwrite($lhandle,"\n");
	fclose($lhandle);
}
// Löscht alle durch imap_delete oder imap_mail_move markierten mails
imap_close($mbox,CL_EXPUNGE);
?>
