<?php

// TESS_Session
// Searches the USPTO's Trademark Electronic Search System (TESS)
// http://tmsearch.uspto.gov/
// v0.1
// copyright 2018 cockybot - https://twitter.com/cockybot

// Requires "TagFilter" library from Barebones CMS available under MIT license
// https://github.com/cubiclesoft/ultimate-web-scraper/blob/master/support/tag_filter.php
// http://barebonescms.com/documentation/license_and_restrictions/

require_once "./tag_filter.php";
define("DEBUG", "1");             // set to "0" to disable extra logging
define("HTML_DUMP_LENGTH", "80"); // num characters of html responses to dump in debug

// QueryResult – corresponds to a single trademark application result returned from search
class QueryResult {
	public $serialNumber;
	public $registrationNumber;
	public $wordMark;
	
	// input: $rowData - array with: [$serialNumber, $registrationNumber, $wordMark]
	public function __construct($rowData) {		
		$this->serialNumber = $rowData[0];
		$this->registrationNumber = trim($rowData[1]);
		$this->wordMark = $rowData[2];
	}
	
	// returns: true if the data is as expected, false otherwise
	public function isValid() {
		$validSerial = preg_match('/^\d+$/', $this->serialNumber) == 1;
		$validWord = trim($this->wordMark) != "";
		$validReg = (preg_match('/^\d+$/', $this->registrationNumber) == 1 || trim($this->registrationNumber) == "");
		if($validSerial && $validWord && $validReg)
			return true;
		return false;
	}
	
	// returns a url used to view the current status of the application
	// e.g. http://tsdr.uspto.gov/#caseNumber=87604348&caseType=SERIAL_NO&searchType=statusSearch
	public function getShareableStatusLink() {
		return "http://tsdr.uspto.gov/#caseNumber=" . $this->serialNumber . "&caseType=SERIAL_NO&searchType=statusSearch";
	}
	
	// returns a url used to view all documents filed with respect to the application
	// e.g. http://tsdr.uspto.gov/documentviewer?caseId=sn87604348
	public function getShareableDocumentLink() {
		return "http://tsdr.uspto.gov/documentviewer?caseId=sn" . $this->serialNumber;
	}	
}

// TESS_Session Session - session with http://tmsearch.uspto.gov
// NOTE: TESS may ban for excessive use, so try to keep requests to a minimum
// NOTE: On Monday through Saturday, TESS will not be available for one hour from 4:00 to 5:00AM (EST) for database update.
class TESS_Session {
	private $url;
	private $state;		// must be passed as arg in GET requests - token + requestIndex + documentIndex
	private $token;		// token identifying the session; has form: ^(\d{4}:(?:[a-z0-9]{6}|[a-z0-9]{5}))$
	private $requestIndex;	// starts at 1, increments with each new query (navigating multi-page results of a single query doesn't change it)
	private $documentIndex; // use 1 unless navigating multipage results or selecting a result to view as detailed record
	private $curlHandle;	// handle for cURL Session
	const START_URL = "http://tmsearch.uspto.gov/bin/gate.exe?f=login&p_lang=english&p_d=trmk";
	const MAX_RESULTS_PER_PAGE = 500;	// this is the most TESS allows (&p_L=500)
	const MAX_PAGES_PER_QUERY = 5;		// could set this higher, if desired, but limit of 2500 results/query should be more than enough
	const TESS_ERROR_NO_RESULTS = 1;
	const TESS_ERROR_INVALID_QUERY = 2;
	const TESS_ERROR_UNKNOWN = 0;
	
	public function __construct() {
		$this->url = self::START_URL;
	}
	
	// NOTE: you should log out manually as soon as you're done making queries
	// but, just in case, try to force good behavior
	function __destruct() {
		if ($this->url != self::START_URL) {
			error_log("TESS_Session: Please manually log out before exit next time.");
			self::logOut();
		}
	}
	
	// the USPTO trademark site uses a resource-intensive server-side session, requests explicit logout when done
	public function logOut() {
		$ch = $this->curlHandle;
		curl_setopt($ch, CURLOPT_URL, "http://tmsearch.uspto.gov/bin/gate.exe?f=logout&a_logout=Logout&state=" . $this->state);
		$pageHTML = curl_exec($ch);
		if(DEBUG == 1) var_dump(trim(substr($pageHTML, 0, HTML_DUMP_LENGTH)));
		curl_close($ch);
		$this->url = self::START_URL;
	}
	
	private function incrementRequestState() {
		$this->requestIndex = $this->requestIndex + 1;
		self::recalculateState();
	}
	
	// updates the state string
	private function recalculateState() {
		$this->state = $this->token . "." . $this->requestIndex . "." . $this->documentIndex;
	}
	
	// Sets up cURL options and opens a TESS session, initializing state appropriately
	public function logIn() {
		if($this->url != self::START_URL) {
			return; //already logged in
		}
		$ch = curl_init($this->url);
		curl_setopt($ch, CURLOPT_HEADER, TRUE); // can get token from first response header
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE); // return response as string
		// we'll have to follow some 302 redirects, but want to handle them 
		// manually initially to capture token from location field in header
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE); 
		// TESS doesn't seem to discriminate based on user agent, we'll identify as a bot 
		curl_setopt($ch, CURLOPT_USERAGENT, "TESS_Session_Bot v1.0");
		// TMSearchsession cookie is required for access
		curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
		curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
		$headers = curl_exec($ch);
		// get the location header and parse it for state token
		if (preg_match('/^Location: (.+)$/im', $headers, $matches)) {
			$location = trim($matches[1]);
			$this->url = $location;
			if(DEBUG == 1) var_dump($location);
			// get the state argument, and break it into components
			if(preg_match('/.*&state=(.*)\.(\d+)\.(\d+)$/', $location, $matches)) {
				$this->token = trim($matches[1]);
				$this->requestIndex = intval($matches[2]);
				$this->documentIndex = intval($matches[3]);
				self::recalculateState();
				if(DEBUG == 1) var_dump($this->state);
			} else {
				error_log("Couldn't find valid session token in response header");
				exit(2);
			}
		} else {
			error_log("TESS didn't redirect");
			exit(1);
		}
		curl_setopt($ch, CURLOPT_URL, $this->url);
		curl_setopt($ch, CURLOPT_HEADER, FALSE); 	// shouldn't need headers anymore
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE); // back to auto redirecting (useful for single page results)
		$pageHTML = curl_exec($ch); 			// follow the redirect to main TESS page
		$this->curlHandle = $ch;
		sleep(5);
	}
	
	// performs a TESS "Word and/or Design Mark Search (Free Form)" query 
	// inputs: 
	// $query - a query string conforming to TESS's "Word and/or Design Mark Search (Free Form)"
	// return: array of QueryResult objects, one for each item found, empty if none found
	public function getQueryResults($query) {
		if(DEBUG == 1) echo "******\nStarting query: " . $query . "\n";
		if($this->url === self::START_URL) {
			self::logIn();
		}
		$this->documentIndex = 1;
		self::recalculateState();
		$ch = $this->curlHandle;
		// set up the url for query request
		$base = "http://tmsearch.uspto.gov/bin/gate.exe";
		// use free form search with plurals on and returning the maximum 500 results/page
		$base_q_string = "?f=toc&p_search=search&p_s_All=&BackReference=&p_L=500&p_plural=yes&a_search=Submit+Query&p_s_ALL=";
		$this->url = $base . $base_q_string . urlencode($query) . "&state=" . $this->state;
		curl_setopt($ch, CURLOPT_URL, $this->url);
		if(DEBUG == 1) var_dump($this->url);
		$pageHTML = curl_exec($ch);
		self::incrementRequestState();
		if(DEBUG == 1) var_dump(trim(substr($pageHTML, 0, HTML_DUMP_LENGTH)));
		$results = self::parseResults($pageHTML);
		$num_page_results = count($results);
		$pageCount = 1;
		// if needed, continue on to next pages of results
		while($num_page_results === self::MAX_RESULTS_PER_PAGE && $pageCount < self::MAX_PAGES_PER_QUERY) {
			$pageCount++;
			$this->documentIndex += self::MAX_RESULTS_PER_PAGE;
			self::recalculateState();
			if(DEBUG == 1) echo "Page: " . $pageCount . "\n";
			// set up url for next page of results
			$this->url = $base . "?f=toc&state=" . $this->state;
			if(DEBUG == 1) var_dump($this->url);
			curl_setopt($ch, CURLOPT_URL, $this->url);
			sleep(5);
			$pageHTML = curl_exec($ch);
			if(DEBUG == 1) var_dump(trim(substr($pageHTML, 0, HTML_DUMP_LENGTH)));
			$page_results = self::parseResults($pageHTML);
			$num_page_results = count($page_results);
			$results = array_merge($results, $page_results);
		}
		return $results;
	}
	
	// inputs: 
	// $pageHTML: string with result page's HTML
	// return: array of QueryResult objects, one for each item found, empty if none found
	function parseResults($pageHTML) {
		// parse with TagFilter: https://github.com/cubiclesoft/ultimate-web-scraper/blob/master/docs/tag_filter.md
		$results = [];
		$htmloptions = TagFilter::GetHTMLOptions();
		$html = TagFilter::Explode($pageHTML, $htmloptions);
		// Retrieve a pointer object to the root node
		$root = $html->Get();
		// check if there was an error
		$titles =  $root->Find("title");
		foreach ($titles as $title) {
			$title = trim($title->GetPlainText());
			if($title == "TESS -- Error") {
				$errorCode = self::parseError($root);
				if($errorCode === self::TESS_ERROR_NO_RESULTS) {
					return $results; // nothing here, so might as well return now
				}
				return $results; // something's wrong, but will try to continue with next request
			} else if($title == "503 Service Unavailable") {
				error_log("May have been flagged for overuse, exiting");
				sleep(5);
				self::logout();  // in case it was just temporary, still attempt a logout
				exit(503);
			}
		}
		// typical page is a table with multiple items
		// Find the main data table
		$tables = $root->Find("table[border='2']");
		
		foreach ($tables as $table) {
			$rows = $table->Find("tr");
			foreach ($rows as $row) {
				// order of cells expected: 
				// 1. index of result in query result (starts at 1)
				// 2. serial number
				// 3. registraion number (will be blank if not yet granted)
				// 4. word mark
				// 5. check status link (TSDR)
				// 6. Live/Dead status
				$cells = $row->Find("td");
				$rowData = [];
				foreach($cells as $cell) {
					$rowData[] = $cell->GetPlainText();
				}
				if(count($rowData) == 6) {
					$result = new QueryResult(array_slice($rowData,1,3));
					if($result->isValid())
						$results[] = $result;
				}
			}
		}
		if($results) {
			return $results;
		} else {
			if(DEBUG == 1) echo "No results table found, checking if single page...\n";
			$singlePageResult = self::parseSingleResultsPage($root);
			if($singlePageResult) {
				$results[] = $singlePageResult;
			}
			return $results;
		}
	}
	
	// if there is only one result for a given search query, the site uses a 302 redirect
	// to a different page with a detailed record of a sinlge application, rather than 
	// the usual table of multiple applications
	// inputs:
	// $root - a TagFilterNode for the document root
	// returns: single QueryResult object on successful parse, null on fail
	function parseSingleResultsPage($root) {
		$tables = $root->Find("table[border='0']");
		$serialNumber = "";
		$registrationNumber = "";
		$wordMark = "";
		foreach ($tables as $table) {
			$rows = $table->Find("tr");
			foreach ($rows as $row) {
				$cells = $row->Find("td");
				$rowData = [];
				foreach($cells as $cell) {
					$rowData[] = trim($cell->GetPlainText());
				}
				for ($i=0; $i<count($rowData); $i++) {
					if ($rowData[$i] == "Serial Number" && $i+1<count($rowData)) {
						$serialNumber = $rowData[i+1];
					}
					if ($rowData[$i] == "Word Mark" && $i+1<count($rowData)) {
						$wordMark = $rowData[i+1];
					}
					if ($rowData[$i] == "Registration Number" && $i+1<count($rowData)) {
						$registrationNumber = $rowData[i+1];
					}
				}
			}
		}
		$result = new QueryResult([$serialNumber, $registrationNumber, $wordMark]);
		if($result->isValid()) {
			return $result;
		} 
		return;
	}
	
	// Tries to indentify the error based on page content
	// inputs:
	// $root - a TagFilterNode for the document root
	// returns: int value corresponding to type of error
	private function parseError($root) {
		$headings = $root->Find("body > h1:first-of-type");
		if($headings->count() > 0) {
			$heading = $headings->current();
			$text = strtok(trim($heading->GetPlainText()), "\n"); //get first line of heading
			if($text == "No TESS records were found to match the criteria of your query.") {
				return self::TESS_ERROR_NO_RESULTS;
			} else if(preg_match('/Invalid/', $text) == 1) {
				// should probably terminate execution so query can be fixed in calling code
				throw new Exception('TESS query had invalid construction: ' . $text);
				return self::TESS_ERROR_INVALID_QUERY;
			} else {
				error_log('Continuing after unknown TESS error page: ' . $text);
				return self::TESS_ERROR_UNKNOWN;
			}
		}
		error_log('Continuing after unknown TESS error page');
		return self::TESS_ERROR_UNKNOWN;
	}
}

?>
