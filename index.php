<?php
require_once "db.php";
require_once "req.php";
require_once "SpotParser.php";
require_once "SpotCategories.php";
require_once "SpotNntp.php";

function initialize() {
	require_once "settings.php";
	$settings = $GLOBALS['settings'];

	# we define some preferences, later these could be
	# user specific or stored in a cookie or something
	$prefs['perpage'] = 1000;
		
	# helper functions for passed variables
	$req = new Req();
	$req->initialize();

	# gather the current page
	$GLOBALS['site']['page'] = $req->getDef('page', 'index');
	if (array_search($GLOBALS['site']['page'], array('index', 'catsjson', 'getnzb', 'getspot')) === false) {
		$GLOBALS['site']['page'] = 'index';
	} # if
	
	# and put them in an encompassing site object
	$GLOBALS['site']['req'] = $req;
	$GLOBALS['site']['settings'] = $settings;
	$GLOBALS['site']['prefs'] = $prefs;
} # initialize()

function openDb() {
	extract($GLOBALS['site'], EXTR_REFS);

	# fireup the database
	$db = new db($settings['db']);

	$GLOBALS['site']['db'] = $db;
	
	return $db;
} # openDb]

function sabnzbdurl($spot) {
	extract($GLOBALS['site'], EXTR_REFS);

	# fix de category
	$spot['category'] = (int) $spot['category'];
	
	# find een geschikte category
	$category = $settings['sabnzbd']['categories'][$spot['category']]['default'];
	
	# voeg de subcategorieen samen en splits ze dan op een pipe
	if (isset($spot['subcata'])) {
		$subcatAr = explode("|", $spot['subcata'] . $spot['subcatb'] . $spot['subcatc'] . $spot['subcatd']);
	} else {
		$subcatAr = array();
		foreach($spot['sub'] as $subcat) {
			$subcatAr[] = substr($subcat, 2, 1) . ((int) substr($subcat, 3));
		} # foreach
	} # else
	
	foreach($subcatAr as $cat) {
		if (isset($settings['sabnzbd']['categories'][$spot['category']][$cat])) {
			$category = $settings['sabnzbd']['categories'][$spot['category']][$cat];
		} # if
	} # foreach
	
	# en creeer die sabnzbd url
	$tmp = $settings['sabnzbd']['url'];
	$tmp = str_replace('$SABNZBDHOST', $settings['sabnzbd']['host'], $tmp);
	$tmp = str_replace('$NZBURL', urlencode($settings['sabnzbd']['spotweburl'] . '?page=getnzb&messageid='. $spot['messageid']), $tmp);
	$tmp = str_replace('$SPOTTITLE', urlencode($spot['title']), $tmp);
	$tmp = str_replace('$SANZBDCAT', $category, $tmp);
	$tmp = str_replace('$APIKEY', $settings['sabnzbd']['apikey'], $tmp);

	return $tmp;
} # sabnzbdurl

function makesearchurl($spot) {
	extract($GLOBALS['site'], EXTR_REFS);

	if (!isset($spot['filename'])) {
		$tmp = str_replace('$SPOTFNAME', $spot['title'], $settings['search_url']);
	} else {
		$tmp = str_replace('$SPOTFNAME', $spot['filename'], $settings['search_url']);
	} # else 

	return $tmp;
} # makesearchurl

function loadSpots($start, $sqlFilter) {
	extract($GLOBALS['site'], EXTR_REFS);
	
	$spotList = $db->getSpots($start, $prefs['perpage'], $sqlFilter);

	$spotCnt = count($spotList);
	
	for ($i = 0; $i < $spotCnt; $i++) {
		if (isset($settings['sabnzbd'])) {
			$spotList[$i]['sabnzbdurl'] = sabnzbdurl($spotList[$i]);
		} # if

		$spotList[$i]['searchurl'] = makesearchurl($spotList[$i]);
	} # foreach
	
	return $spotList;
} # loadSpots()

function filterToQuery($search) {
	extract($GLOBALS['site'], EXTR_REFS);
	$filterList = array();
	$dyn2search = array();

	# dont filter anything
	if (empty($search)) {
		return '';
	} # if
	
	# convert the dynatree list to a list 
	if (!empty($search['tree'])) {
		# explode the dynaList
		$dynaList = explode(',', $search['tree']);

		# fix de tree variable zodat we dezelfde parameters ondersteunen als de JS
		$newTreeQuery = '';
		for($i = 0; $i < count($dynaList); $i++) {
			if (strlen($dynaList[$i]) == 6) {
				$hCat = (int) substr($dynaList[$i], 3, 1);
				$subCat = substr($dynaList[$i], 5);
				
				# creeer een string die alle subcategories bevat
				$tmpStr = '';
				foreach(SpotCategories::$_categories[$hCat][$subCat] as $x => $y) {
					$tmpStr .= ",cat" . $hCat . "_" . $subCat . $x;
				} # foreach
				
				$newTreeQuery .= $tmpStr;
			} elseif (substr($dynaList[$i], 0, 1) == '!') {
				# als het een NOT is, haal hem dan uit de lijst
				$newTreeQuery = str_replace(substr($dynaList[$i], 1) . ",", "", $newTreeQuery);
			} else {
				$newTreeQuery .= "," . $dynaList[$i];
			} # else
		} # foreach
		
		# explode the dynaList
		$search['tree'] = $newTreeQuery;
		$dynaList = explode(',', $search['tree']);

		# en fix the list
		foreach($dynaList as $val) {
			if (substr($val, 0, 3) == 'cat') {
				# 0e element is hoofdcategory
				# 1e element is category
				$val = explode('_', (substr($val, 3) . '_'));
				
				$catVal = $val[0];
				$subCatIdx = substr($val[1], 0, 1);
				$subCatVal = substr($val[1], 1);

				if (count($val) >= 3) {
					$dyn2search['cat'][$catVal][$subCatIdx][] = $subCatVal;
				} # if
			} # if
		} # foreach
	} # if

	# Add a list of possible head categories
	if (is_array($dyn2search['cat'])) {
		$filterList = array();

		foreach($dyn2search['cat'] as $catid => $cat) {
			$catid = (int) $catid;
			$tmpStr = "((category = " . $catid . ")";
			
			# Now start adding the sub categories
			if ((is_array($cat)) && (!empty($cat))) {
				#
				# uiteraard is een LIKE query voor category search niet super schaalbaar
				# maar omdat deze webapp sowieso niet bedoeld is voor grootschalig gebruik
				# moet het meer dan genoeg zijn
				#
				$subcatItems = array();
				foreach($cat as $subcat => $subcatItem) {
					$subcatValues = array();
					
					foreach($subcatItem as $subcatValue) {
						$subcatValues[] = "(subcat" . $subcat . " LIKE '%" . $subcat . $subcatValue . "|%') ";
					} # foreach
					
					# voeg de subfilter values (bv. alle formaten films) samen met een OR
					$subcatItems[] = " (" . join(" OR ", $subcatValues) . ") ";
				} # foreach subcat

				# voeg de category samen met de diverse subcategory filters met een OR, bv. genre: actie, type: divx.
				$tmpStr .= " AND (" . join(" AND ", $subcatItems) . ") ";
			} # if
			
			# close the opening parenthesis from this category filter
			$tmpStr .= ")";
			$filterList[] = $tmpStr;
		} # foreach
	} # if

	# Add a list of possible head categories
	$textSearch = '';
	if ((!empty($search['text'])) && ((isset($search['type'])))) {
		$field = 'title';
	
		if ($search['type'] == 'Tag') {
			$field = 'tag';
		} else if ($search['type'] == 'Poster') {
			$field = 'poster';
		} # else
		
		$textSearch .= ' (' . $field . " LIKE '%" . $db->safe($search['text']) . "%')";
	} # if

	if (!empty($filterList)) {
		# echo  '(' . (join(' OR ', $filterList) . ') ' . (empty($textSearch) ? "" : " AND " . $textSearch));
		return '(' . (join(' OR ', $filterList) . ') ' . (empty($textSearch) ? "" : " AND " . $textSearch));
	} else {
		return $textSearch;
	} # if
} # filterToQuery

function template($tpl, $params = array()) {
	extract($GLOBALS['site'], EXTR_REFS);
	extract($params, EXTR_REFS);
	
	require_once($settings['tpl_path'] . $tpl . '.inc.php');
} # template

function categoriesToJson() {
	echo "[";
	
	$hcatList = array();
	foreach(SpotCategories::$_head_categories as $hcat_key => $hcat_val) {
		$hcatTmp = '{"title": "' . $hcat_val . '", "isFolder": true, "key": "cat' . $hcat_key . '",	"children": [' ;
				
		$subcatDesc = array();
		foreach(SpotCategories::$_subcat_descriptions[$hcat_key] as $sclist_key => $sclist_desc) {
			$subcatTmp = '{"title": "' . $sclist_desc . '", "isFolder": true, "hideCheckbox": true, "key": "cat' . $hcat_key . '_' . $sclist_key . '", "unselectable": false, "children": [';
			# echo ".." . $sclist_desc . " <br>";

			$catList = array();
			foreach(SpotCategories::$_categories[$hcat_key][$sclist_key] as $key => $val) {
				if ((strlen($val) != 0) && (strlen($key) != 0)) {
					$catList[] = '{"title": "' . $val . '", "icon": false, "key":"'. 'cat' . $hcat_key . '_' . $sclist_key.$key .'"}';
				} # if
			} # foreach
			$subcatTmp .= join(",", $catList);
			
			$subcatDesc[] = $subcatTmp . "]}";
		} # foreach

		$hcatList[] = $hcatTmp . join(",", $subcatDesc) . "]}";
	} # foreach	
	
	echo join(",", $hcatList);
	echo "]";
} # categoriesToJson

#- main() -#
initialize();
extract($site, EXTR_REFS);

switch($site['page']) {
	case 'index' : {

		openDb();
		$filter = filterToQuery($req->getDef('search', $settings['index_filter']));
		$spots = loadSpots(0, $filter);

		#- display stuff -#
		template('header');
		template('filters', array('search' => $req->getDef('search', array()),
								  'filters' => $settings['filters']));
		template('spots', array('spots' => $spots));
		template('footer');
		break;
	} # case index
	
	case 'catsjson' : {
		categoriesToJson();
		break;
	} # catsjson

	case 'getspot' : {
		$db = openDb();
		$spot = $db->getSpot(Req::getDef('messageid', ''));
		
		$spot = $spot[0];
		
		$spotnntp = new SpotNntp($settings['nntp_hdr']['host'],
								 $settings['nntp_hdr']['enc'],
								 $settings['nntp_hdr']['port'],
								 $settings['nntp_hdr']['user'],
								 $settings['nntp_hdr']['pass']);
		if ($spotnntp->connect()) {
			$header = $spotnntp->getHeader('<' . $spot['messageid'] . '>');

			$xml = '';
			if ($header !== false) {
				foreach($header as $str) {
					if (substr($str, 0, 7) == 'X-XML: ') {
						$xml .= substr($str, 7);
					} # if
				} # foreach
			} # if

			$spotParser = new SpotParser();
			$xmlar = $spotParser->parseFull($xml);
			$xmlar['messageid'] = Req::getDef('messageid', '');
			$xmlar['sabnzbdurl'] = sabnzbdurl($xmlar);
			$xmlar['searchurl'] = makesearchurl($xmlar);

			# Vraag een lijst op met alle comments messageid's
			$commentList = $db->getCommentRef($xmlar['messageid']);
			$comments = array();
			
			foreach($commentList as $comment) {
				$tmpAr = array('body' => $spotnntp->getBody('<' . $comment['messageid'] . '>'));
				
				# extract de velden we die we willen hebben
				$header = $spotnntp->getHeader('<' . $comment['messageid'] . '>');
				foreach($header as $hdr) {
					if (substr($hdr, 0, strlen('From: ')) == 'From: ') {
						$tmpAr['from'] = trim(substr($hdr, strlen('From: '), strpos($hdr, '<') - 1 - strlen('From: ')));
					} # if
					
					if (substr($hdr, 0, strlen('Date: ')) == 'Date: ') {
						$tmpAr['date'] = substr($hdr, strlen('Date: '));
					} # if
				} # foreach
				
				$comments[] = $tmpAr; 
			} # foreach
			
			#- display stuff -#
			template('header');
			template('spotinfo', array('spot' => $xmlar, 'comments' => $comments));
			template('footer');
			
			break;
		} else {
			die("Unable to connect to NNTP server");
		} # else
	} # getspot
	
	case 'getnzb' : {
		$db = openDb();
		$spot = $db->getSpot(Req::getDef('messageid', ''));
		$spot = $spot[0];
		
		$hdr_spotnntp = new SpotNntp($settings['nntp_hdr']['host'],
									$settings['nntp_hdr']['enc'],
									$settings['nntp_hdr']['port'],
									$settings['nntp_hdr']['user'],
									$settings['nntp_hdr']['pass']);
		if ($settings['nntp_hdr']['host'] == $settings['nntp_nzb']['host']) {
			$connected = ($hdr_spotnntp->connect());
			$nzb_spotnntp = $hdr_spotnntp;
		} else {
			$nzb_spotnntp = new SpotNntp($settings['nntp_nzb']['host'],
										$settings['nntp_nzb']['enc'],
										$settings['nntp_nzb']['port'],
										$settings['nntp_nzb']['user'],
										$settings['nntp_nzb']['pass']);
			$connected = (($hdr_spotnntp->connect()) && ($nzb_spotnntp->connect()));
		} # else
		
		if ($connected) {
			$header = $hdr_spotnntp->getHeader('<' . $spot['messageid'] . '>');

			$xml = '';
			if ($header !== false) {
				foreach($header as $str) {
					if (substr($str, 0, 7) == 'X-XML: ') {
						$xml .= substr($str, 7);
					} # if
				} # foreach
			} # if
			
			$spotParser = new SpotParser();
			$xmlar = $spotParser->parseFull($xml);
			
			/* Connect to the NZB group */
			/* Get the NZB file */
			$nzb = false;
			if (is_array($xmlar['segment'])) {
				foreach($xmlar['segment'] as $seg) {
					$tmp = $nzb_spotnntp->getBody("<" . $seg . ">");
					
					if ($tmp !== false) {
						$nzb .= implode("", $tmp);
					} else {
						break;
					} #else
				} # foreach
			} else {
				$tmp = $nzb_spotnntp->getBody("<" . $xmlar['segment'] . ">");
				if ($tmp !== false) {
					$nzb .= implode("", $tmp);
				} # if
			} # if
			
			if ($nzb !== false) {
				Header("Content-Type: application/x-nzb");
				Header("Content-Disposition: attachment; filename=\"" . $xmlar['title'] . ".nzb\"");
				echo gzinflate($spotParser->unspecialZipStr($nzb));
			} else {
				echo "Unable to get NZB file: " . $nzb_spotnntp->getError();
			} # else
		} # if
		
		break;
	} # getnzb 
}
