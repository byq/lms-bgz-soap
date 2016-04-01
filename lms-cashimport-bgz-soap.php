<?php

/*
 * skrypt importu raportów płatności masowych z banku BGZ transferbgz.pl do LMS
 *
 *  (C) Copyright P.H.U. AVERTIS - Jan Michlik 
 *  na podstawie  pliku lms-cashimport-bph.php (Webvisor Sp. z o.o.) i innych
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * poprawki 12-2014: Grzegorz Cichowski (gcichowski@gmail.com)
*/
/* UWAGA!!!!!!!!!!!!!!
 * Dla prawidłowego działania skryptu trzeba dodać kilka pól do tabeli sourcefiles: 
 * file - pobrany plik z banku (nieprzetworzony) 
 * fileid - identyfikator pliku (bgz)
 * state - status pliku
 * Komenda do wykoniania w mysql-u:
 * ALTER TABLE `sourcefiles` ADD COLUMN `file` BLOB NULL  AFTER `idate` , ADD COLUMN `fileid` INT(11) NULL  AFTER `file` , ADD COLUMN `state` INT NULL  AFTER `fileid` ;
 *
 * skrypt działa poprawnie tylko wtedy, gdy w banku są do pobrania minimum 2 pliki IDEN
*/
echo date("Y-m-d H:i:s")." lms-cashimport-bgz.php START \n";

// ustaw format pliku IDEN (elixir/mt940)
// w przypadku mt940 dziala wersja 1 proponowana przez BGZ (z NOTREF)
//$foramt_pliku = 'elixir';
$format_pliku = 'mt940';
//echo 'wybrany format pliku to: '.$format_pliku."\n";

// Wpisz tutaj login, hasło i identyfikator do systemu bankowego     
// przeniesione do bazy LMS jako: bgz_username, bgz_password, bgz_firm
//$pLogin = "";
//$pPassword = "";
//$pIden = "";

$soap_url = 'https://www.pf.transferbgz.pl/bgz.blc.loader/WebService?wsdl';
$client = new SoapClient("https://www.pf.transferbgz.pl/bgz.blc.loader/WebService?wsdl",
array(
'trace' => 1,
'soap_version' => SOAP_1_1,
'style' => SOAP_DOCUMENT,
'encoding' => SOAP_LITERAL,
'location' => 'https://www.pf.transferbgz.pl/bgz.blc.loader/WebService'
));

// REPLACE THIS WITH PATH TO YOU CONFIG FILE

$CONFIG_FILE = (is_readable('lms.ini')) ? 'lms.ini' : '/etc/lms-nett/lms.ini';

// PLEASE DO NOT MODIFY ANYTHING BELOW THIS LINE UNLESS YOU KNOW
// *EXACTLY* WHAT ARE YOU DOING!!!
// *******************************************************************

// Parse configuration file
$CONFIG = (array) parse_ini_file($CONFIG_FILE, true);

// Check for configuration vars and set default values
$CONFIG['directories']['sys_dir'] = (! $CONFIG['directories']['sys_dir'] ? getcwd() : $CONFIG['directories']['sys_dir']);
$CONFIG['directories']['backup_dir'] = (! $CONFIG['directories']['backup_dir'] ? $CONFIG['directories']['sys_dir'].'/backups' : $CONFIG['directories']['backup_dir']);
$CONFIG['directories']['lib_dir'] = (! $CONFIG['directories']['lib_dir'] ? $CONFIG['directories']['sys_dir'].'/lib' : $CONFIG['directories']['lib_dir']);
$CONFIG['directories']['modules_dir'] = (! $CONFIG['directories']['modules_dir'] ? $CONFIG['directories']['sys_dir'].'/modules' : $CONFIG['directories']['modules_dir']);

define('SYS_DIR', $CONFIG['directories']['sys_dir']);
define('LIB_DIR', $CONFIG['directories']['lib_dir']);
define('BACKUP_DIR', $CONFIG['directories']['backup_dir']);
define('MODULES_DIR', $CONFIG['directories']['modules_dir']);

// Load autloader
require_once(LIB_DIR.'/autoloader.php');

// Load config defaults

require_once(LIB_DIR.'/config.php');

// Init database 

$DB = null;

try {

    $DB = LMSDB::getInstance();

} catch (Exception $ex) {
    
    trigger_error($ex->getMessage(), E_USER_WARNING);
    
    // can't working without database
    die("Fatal error: cannot connect to database!\n");
    
}

$pLogin = ConfigHelper::getConfig('finances.bgz_username');
$pPassword = ConfigHelper::getConfig('finances.bgz_password');
$pIden = ConfigHelper::getConfig('finances.bgz_firm');

if (empty($pLogin) || empty($pPassword) || empty($pIden))
        die("Fatal error: BGZ credentials are not set!\n");


//funkcje
//bug - modyfikacje błednego wsdl-a z BGZ-tu - zamiast http musi byc https!!
class My_SoapClient extends SoapClient {
//source http://www.victorstanciu.ro/php-soapclient-port-bug-workaround/ +modification
    public function __doRequest($request, $location, $action, $version) {
 			$location='https'.substr($location,4);
        $return = parent::__doRequest($request, $location, $action, $version);
        return $return;
    }
}

function mt940Parser($file){
       //podzielenie na wplaty
       $wplaty = preg_split('/-}/',$file,0,PREG_SPLIT_NO_EMPTY);
       //usuwanie pustych pozycji w tablicy (do dopracowania dzielenie powyzej - narazie tak)
       sort($wplaty);
       array_shift($wplaty);
       $wplaty_parser=array();
       $i=0;
                               //dla kazdej wplaty ...
       foreach($wplaty as $wplata) {
                               //podzielenie na linie
               $tab = preg_split('/\n/',$wplata);
                               //dla kazdej linii ...
               foreach($tab as $line) {
                       $dwukropek=stripos($line,':');
                       if ( ($dwukropek==0)&&($dwukropek!==false) ){
                               $pole=preg_split('/:/',$line);
				if (isset($pole[4])){
					$wplaty_parser[$i][$pole[1]]=trim($pole[2]).':'.trim($pole[3]).':'.trim($pole[4]);
				}elseif(isset($pole[3])){
					$wplaty_parser[$i][$pole[1]]=trim($pole[2]).':'.trim($pole[3]);
				}else{
					$wplaty_parser[$i][$pole[1]]=trim($pole[2]);
				}
                               switch ($pole[1]) {
                               case '61':
                                       $wplaty_parser[$i]['value']=str_replace(",", ".", trim(substr($pole[2],11,strpos($pole[2],'NOTREF')-11)));
                                       $wplaty_parser[$i]['date']=trim('20'.substr($pole[2],0,2).'-'.substr($pole[2],2,2).'-'.substr($pole[2],4,2));
                               break;
                                       case '25':
                                               $wplaty_parser[$i]['customerid']=(int)trim(substr($pole[2],14,26));
                                               $wplaty_parser[$i]['customer']=trim($pole[2]);
                                       break;
                               }
                       }
               }
               $wplaty_parser[$i]['discription']=trim($wplaty_parser[$i][86].' Transaction no.:'.$wplaty_parser[$i]['28C']);
               $wplaty_parser[$i]['hash']=md5(trim($wplaty_parser[$i]['date'].$wplaty_parser[$i]['value'].$wplaty_parser[$i]['customer'].$wplaty_parser[$i]['28C']));
               $i++; //nastepna wplata
       }
       return $wplaty_parser;
}

function explodeX($row,$sep,$ign) {
    $cFieldPos=0;
    $openCite=false;
    $row=str_replace("\r","",$row);
    $row=str_replace("\n",$sep,$row);
    for($i=0;$i<strlen($row);$i++) {
       if (substr($row,$i,1)==$ign) $openCite=!$openCite;

       if (substr($row,$i,1)==$sep && !$openCite) {
           $rows[]=substr($row,$cFieldPos,$i-$cFieldPos);
           $cFieldPos=$i+1;
       }
    }
    return $rows;
}

function ElixirParser($if,$sourceid='',$sourcefileid=''){
//$if plik z wplatami
//$sourceid - id zrodla importu z tabeli cashsources
//$sourcefileid - id pliku z tabeli sourcefiles
for($i=0;$i<count($if);$i++) {
    $fields=explodeX($if,",","");
    if ($fields[0]=="110") {

       $data=mktime(0,0,0,substr($fields[1],4,2),substr($fields[1],6,2),substr($fields[1],0,4));	// data przelewu
       $kto=$fields[7];											// nadawca
       $kwota=number_format($fields[2]/100,2,".","");							// kwota w groszach
       $opis=$fields[11];										// tytul przelewu
       $tid=$i;												// kolejny przelew z danego pliku
       $id=substr($fields[6],15,12);									// id klienta u isp

       $hash=md5($data.$kwota.$kto.$opis.$id.$tid);
       $kto=addslashes(iconv("ISO-8859-2","UTF-8",$kto));
       $opis=addslashes(iconv("ISO-8859-2","UTF-8",$opis));
       $rs=mysql_query("Select id from cashimport where Hash='".$hash."'");
       if (mysql_num_rows($rs)==0) {
           mysql_query("Insert into cashimport (Date,Value,Customer,Description,CustomerId,Hash,sourceid, sourcefileid) values ('$data','$kwota','$kto','$opis','$id','$hash','$sourceid','$sourcefileid')");
	echo 'Dodaje wpłate '.$kto.', hash:'.$hash .' do bazy.'."\n";
       } else echo 'Wpłata '.$kto.', hash:'.$hash .' jest już w bazie.'."\n"; 
    }
}
	return $i;
}

function insertCashImport($wplaty_parser,$sourceid='',$sourcefileid=''){
//$wplaty_parser - tablica asocjacyjna z wplatami
//$sourceid - id zrodla importu z tabeli cashsources
//$sourcefileid - id pliku z tabeli sourcefiles
	global $DB;
	$i=0;
	foreach($wplaty_parser as $wplata){
		if($select_wplata = $DB->GetAll("SELECT * FROM cashimport WHERE Hash='$wplata[hash]'")){
			echo 'Wpłata '.$wplata[customer].', hash:'.$wplata[hash] .' jest już w bazie.'."\n"; 
		}else{ //dodac do bazy
			echo 'Dodaje wpłate '.$wplata['customer'].', hash:'.$wplata['hash'] .' do bazy.'."\n";
			$query = "Insert into cashimport (Date,Value,Customer,Description,CustomerId,Hash,sourceid, sourcefileid) 
				values (UNIX_TIMESTAMP('$wplata[date]'),'$wplata[value]','$wplata[customer]','$wplata[discription]',
				'$wplata[customerid]','$wplata[hash]','$sourceid','$sourcefileid')";
			echo $query;
			$DB->Execute($query);
			$i++;
		}
	}
	return $i;
}

		
// dzialania:

//probranie cashsourcesid
if($cfg = $DB->GetAll('SELECT * FROM cashsources WHERE name = "IDEN BGŻ"'))
	$sourceid=$cfg[0]['id'];

//utworzenie polaczenia z bankiem
   $soap_client = new My_SoapClient($soap_url,
                         array(   
                          	'trace' 			=> true,
                          	'exceptions' 	=> true,
									'cache_wsdl' 	=> WSDL_CACHE_NONE 
                            ));

// pobranie listy plikow z banku
//$bgzDocuments = $soap_client->getDocuments(array('in0'=>$pLogin,'in1'=>$pPassword,'in2'=>$pIden));
$bgzDocuments = $client->getDocuments(array('in0'=>$pLogin,'in1'=>$pPassword,'in2'=>$pIden));

//dla kazdego pliku  
//gdy sa minimum 2 pliki:
foreach($bgzDocuments->out->Document as $row){
//gdy jest jeden plik:
//foreach($bgzDocuments->out as $row){
		//sprawdzenie czy plik jest zapisany w bazie
		$query= "SELECT * FROM sourcefiles WHERE fileid = $row->id and name ='$row->name' and idate =UNIX_TIMESTAMP('". substr($row->fileDate,0,10)."')";
		//echo $query; 
		if($sql_plik = $DB->GetAll($query)){
		echo "Plik ".$row->name." jest już w bazie.\n"; 
		
		}else{
			echo "Dodaje plik ".$row->name." do bazy.\n"; 
			//pobranie pliku		
//			$bgzDocument = $soap_client->getDocument(array('in0'=>$row->id,'in1'=>$pLogin,'in2'=>$pPassword,'in3'=>$pIden));
			$bgzDocument = $client->getDocument(array('in0'=>$row->id,'in1'=>$pLogin,'in2'=>$pPassword,'in3'=>$pIden));
			if ($format_pliku  == 'elixir') {				// if elixir
			    $plik=iconv("WINDOWS-1250","UTF-8",$bgzDocument->out);
			}else{								// else mt940
			    $plik=iconv("ISO-8859-2","UTF-8",$bgzDocument->out);
			}
			//dodanie pliku do bazy danych 
			$query= "Insert INTO sourcefiles (name,idate,file,fileid,state)
		 	values('$row->name',UNIX_TIMESTAMP('". substr($row->fileDate,0,10)."'),'".addslashes($plik)."',$row->id,$row->state ) ";
			$DB->Execute($query);
			$sourcefileid=$DB->GetLastInsertID();			
			//uruchmienie przetwarzania plikow na wpłaty ******* 
			if ($format_pliku  == 'elixir') {					// if elixir
			    $insert=ElixirParser($plik, $sourceid, $sourcefileid);
			}else{								// else mt940
				$plik = preg_replace ('/[\"\']/', '', $plik);
				$wplaty_parser=mt940Parser($plik);
				//zapis wpłat do tabeli cashimport
				$insert=insertCashImport($wplaty_parser, $sourceid, $sourcefileid);
}
// end if
			echo 'Ilość zapisanych wpłat: '.$insert."\n";			
		
		}
}//koniec dla kazdego pliku

echo date("Y-m-d H:i:s")." lms-cashimport-bgz.php END \n";
?>
