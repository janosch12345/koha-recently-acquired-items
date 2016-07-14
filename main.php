<?php 
//error_reporting(E_ALL);
//ini_set('display_errors','On');

/*
this function filters the given ASIN (Amazon standard identifier number) from the marcxml data of the bibliorecord
subfield 024
*/
function getAsin($xml){
$pxml = simplexml_load_string($xml);
if ($pxml === False)
	{
		return False; // no xml
	}
else
	{
		if (isset($pxml->datafield))	
			foreach ($pxml->datafield as $datafield) {
				switch((string) $datafield['tag']) { // Verwende Attribute als Element-Indizes
				case '24':
					return $pxml->datafield->subfield;
					break;
				}
			}
	}
	return false;
}

include('settings.php');
include('libs/BookcoverResolver.class.php');

$cover = new BookcoverResolver();

$conn = mysqli_connect($host, $user, $passwd,$db);

$debug = false;

if (mysqli_connect_errno()){
    //echo "Keine Verbindung zu DB möglich: " . mysql_error();
    exit;
}

mysqli_set_charset($conn,'UTF-8');

$sqlBooks = "SELECT distinct(biblioitemnumber),isbn, biblio.biblionumber,author,title,copyrightdate,publishercode FROM `biblio`
join biblioitems on biblio.biblionumber = biblioitems.biblionumber
where isbn != ''
order by `datecreated` desc limit 30";

$result = mysqli_query($conn,$sqlBooks);

if (!$result) {
	//
}

$books = array();
$count = 0;
$colCount = 1;
$regex = "/([0-9xX]{10,13})/";

while ($row = mysqli_fetch_assoc($result)) {
	$book = null;
	$isbnfield = $row['isbn'];
	//$isbns = explode("|",$isbnfield);
	$cover_found = false;
	preg_match($regex, $isbnfield, $isbns);//sometimes there are several isbns in the field
	foreach ($isbns as $isbn){
		$isbn = trim($isbn);
		
		if (!$cover_found){
			$coverurl = $cover->getCover($isbn);
			if ($coverurl != 'blank.gif'){
				$cover_found = true;
				$count++;
				if ($count >= 19)
					break;
				$book['coverurl'] = $coverurl;
				$book['isbn'] = $isbn;
				$book['biblionumber'] = $row['biblionumber'];
				$book['title'] = utf8_encode($row['title']);
				$book['meta'] = utf8_encode($row['author']).' ('.utf8_encode($row['copyrightdate']).')';
				$books[] = $book;
				//continue;
			}
		}
	} 
}
//we want the columns in a shelf
$mod = count($books) % 3;
if ($mod != 0){
	for ($i = 0; $i < $mod; $i++){
		array_pop($books);
	}
}
mysqli_free_result($result);

//now the dvds
//children first
$dvds = array();
$sqlDVDs = "SELECT title,biblio.biblionumber,itemtype,marcxml,copyrightdate FROM `biblio`
join biblioitems on biblio.biblionumber = biblioitems.biblionumber
where itemtype like 'DVD KI'
 order by `datecreated` desc limit 100";//

$result = mysqli_query($conn,$sqlDVDs);

if (!$result) {
  //  
}

$dvds = array();
$count = 0;
$colCount = 1;


while ($row = mysqli_fetch_assoc($result)) {
	
	$dvd = null;	
	//if ($debug) print_r($row);
	
	$asin = getAsin(utf8_encode($row['marcxml']));
	if ($asin){
		
		$coverurl = $cover->getCover($asin);
		if ($coverurl != 'blank.gif'){
			if ($count >= 9)
				break;
			
			$count++;
			$dvd['coverurl'] = $coverurl;
			$dvd['biblionumber'] = $row['biblionumber'];
			$dvd['title'] = utf8_encode($row['title']);
			$dvd['meta'] = "";
			$dvds[] = $dvd;
			continue;
		}
		
	}
}
$mod = count($dvds) % 3;
if ($mod != 0){
	for ($i = 0; $i < $mod; $i++){
		array_pop($dvds);
	}
}
mysqli_free_result($result);

//now the dvds 
//the elderly
$sqlDVDs = "SELECT title,biblio.biblionumber,itemtype,marcxml,copyrightdate FROM `biblio`
join biblioitems on biblio.biblionumber = biblioitems.biblionumber
where itemtype like 'DVD E'
 order by `datecreated` desc limit 100";//
$dvds2 = array();
$result = mysqli_query($conn,$sqlDVDs);

if (!$result) {
  //  
}



$count = 0;
$colCount = 1;

while ($row = mysqli_fetch_assoc($result)) {
	$dvd = null;	
	//if ($debug) print_r($row);
	$asin = getAsin(utf8_encode($row['marcxml']));
	if ($asin){
		
		$coverurl = $cover->getCover($asin);
		if ($coverurl != 'blank.gif'){
			if ($count >= 9)
				break;
			$count++;
			
			$dvd['coverurl'] = $coverurl;
			$dvd['biblionumber'] = $row['biblionumber'];
			$dvd['title'] = utf8_encode($row['title']);
			$dvd['meta'] = "";
			$dvds2[] = $dvd;
			continue;
		}
		
	}
}
$mod = count($dvds2) % 3;
if ($mod != 0){
	for ($i = 0; $i < $mod; $i++){
		array_pop($dvds2);
	}
}


$collection = array();
mysqli_free_result($result);
mysqli_close($conn);
$collection['books'] = $books;
$collection['dvdsk'] = $dvds;
$collection['dvdse'] = $dvds2;

echo json_encode($collection);

?>
