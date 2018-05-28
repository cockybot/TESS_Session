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
define("TESS_SESSION_DEBUG", "0");             // set to "0" to disable extra logging
define("TESS_HTML_DUMP_LENGTH", "80"); // num characters of html responses to dump in debug

class TESSException extends Exception {	
	// define error codes
	const ERROR_UNKNOWN = 0;
	const ERROR_NO_RESULTS = 1;
	const ERROR_INVALID_QUERY = 2;
	const ERROR_SESSION_EXPIRED = 3;
	const ERROR_UNAVAILABLE = 4;
	
	// Redefine the exception so message and code are required
	public function __construct($message, $code, Exception $previous = null) {
        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);
    }
}

// QueryResult – corresponds to a single trademark application result returned from search
class QueryResult {
	public $serialNumber;  		// as string
	public $registrationNumber; // as string
	public $wordMark;			// as string
	
	// input: $rowData - array with: [$serialNumber, $registrationNumber, $wordMark]
	public function __construct($rowData) {		
		$this->serialNumber = $rowData[0];
		$this->registrationNumber = trim($rowData[1]);
		$this->wordMark = utf8_encode($rowData[2]); // TESS uses ISO-8859-1, switch to UTF-8
	}
	
	// returns: true if the data is as expected, false otherwise
	public function isValid() {
		$validSerial = preg_match('/^\d{8}$/', $this->serialNumber) == 1;
		$validWord = trim($this->wordMark) != "";
		$validReg = (preg_match('/^\d{5,7}$/', $this->registrationNumber) == 1 || trim($this->registrationNumber) == "");
		if($validSerial && $validWord && $validReg)
			return true;
		return false;
	}
	
	// returns a url used to view the current status of the application
	// e.g. https://tsdr.uspto.gov/#caseNumber=87604348&caseType=SERIAL_NO&searchType=statusSearch
	public function getShareableStatusLink() {
		return "https://tsdr.uspto.gov/#caseNumber=" . $this->serialNumber . "&caseType=SERIAL_NO&searchType=statusSearch";
	}
	
	// returns a url used to view all documents filed with respect to the application
	// e.g. https://tsdr.uspto.gov/documentviewer?caseId=sn87604348
	public function getShareableDocumentLink() {
		return "https://tsdr.uspto.gov/documentviewer?caseId=sn" . $this->serialNumber;
	}
	
	// returns a url for an image of the mark (more useful for stylized v. standard character)
	// e.g. http://tmsearch.uspto.gov/ImageAgent/ImageAgentProxy?getImage=87604348 - small jpg
	// e.g. https://tsdr.uspto.gov/img/87604968/large - larger png
	public function getShareableImageLink() {
		//return "http://tmsearch.uspto.gov/ImageAgent/ImageAgentProxy?getImage=" . $this->serialNumber;
		return "https://tsdr.uspto.gov/img/".$this->serialNumber."/large";
	}
	
	// Saves the image of the mark as a file to the specified path
	// inputs: $imgPath - path of file to save image to
	// return: number of bytes written to file, or 0 on failure
	public function saveImageAsFile($imgPath) {
		return file_put_contents($imgPath, file_get_contents($this->getShareableImageLink()));
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
	const WAIT_BETWEEN_REQUESTS = 5;	// seconds to wait between page requests

	public function __construct() {
		$this->url = self::START_URL;
	}
	
	// NOTE: you should log out manually as soon as you're done making queries
	// but, just in case, try to force good behavior
	function __destruct() {
		if ($this->url != self::START_URL) {
			error_log("TESS_Session: Please manually log out before exit next time.");
			$this->logOut();
		}
	}
	
	// the USPTO trademark site uses a resource-intensive server-side session, requests explicit logout when done
	public function logOut() {
		$ch = $this->curlHandle;
		$this->url = "http://tmsearch.uspto.gov/bin/gate.exe?f=logout&a_logout=Logout&state=" . $this->state;
		curl_setopt($ch, CURLOPT_URL, $this->url);
		$pageHTML = curl_exec($ch);
		if(TESS_SESSION_DEBUG == 1) var_dump($this->url);
		if(TESS_SESSION_DEBUG == 1) var_dump(trim(substr($pageHTML, 0, TESS_HTML_DUMP_LENGTH)));
		curl_close($ch);
		$this->url = self::START_URL;
	}
	
	private function incrementRequestState() {
		$this->requestIndex = $this->requestIndex + 1;
		$this->recalculateState();
	}
	
	private function decrementRequestState() {
		$this->requestIndex = $this->requestIndex - 1;
		$this->recalculateState();
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
			if(TESS_SESSION_DEBUG == 1) var_dump($location);
			// get the state argument, and break it into components
			if(preg_match('/.*&state=(.*)\.(\d+)\.(\d+)$/', $location, $matches)) {
				$this->token = trim($matches[1]);
				$this->requestIndex = intval($matches[2]);
				$this->documentIndex = intval($matches[3]);
				$this->recalculateState();
				if(TESS_SESSION_DEBUG == 1) var_dump($this->state);
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
		sleep(self::WAIT_BETWEEN_REQUESTS);
	}
	
	// performs a TESS "Word and/or Design Mark Search (Free Form)" query 
	// inputs: 
	// $query - a query string conforming to TESS's "Word and/or Design Mark Search (Free Form)"
	// return: array of QueryResult objects, one for each item found, empty if none found
	public function getQueryResults($query) {
		echo "******\nStarting query: " . $query . "\n";
		if($this->url === self::START_URL) {
			$this->logIn();
		}
		$this->documentIndex = 1;
		$this->recalculateState();
		if(TESS_SESSION_DEBUG == 1) var_dump($this->state);
		$ch = $this->curlHandle;
		// set up the url for query request
		$base = "http://tmsearch.uspto.gov/bin/gate.exe";
		// use free form search with plurals on and returning the maximum 500 results/page
		$base_q_string = "?f=toc&p_search=search&p_s_All=&BackReference=&p_L=500&p_plural=yes&a_search=Submit+Query&p_s_ALL=";
		$this->url = $base . $base_q_string . urlencode($query) . "&state=" . $this->state;
		curl_setopt($ch, CURLOPT_URL, $this->url);
		$pageHTML = curl_exec($ch);
		$results = $this->parseResultsPage($pageHTML);
		$this->incrementRequestState();
		$num_page_results = count($results);
		$pageCount = 1;
		// if needed, continue on to next pages of results
		while($num_page_results === self::MAX_RESULTS_PER_PAGE && $pageCount < self::MAX_PAGES_PER_QUERY) {
			$pageCount++;
			$this->documentIndex += self::MAX_RESULTS_PER_PAGE;
			$this->recalculateState();
			if(TESS_SESSION_DEBUG == 1) echo "Page: " . $pageCount . "\n";
			// set up url for next page of results
			$this->url = $base . "?f=toc&state=" . $this->state;
			if(TESS_SESSION_DEBUG == 1) var_dump($this->state);
			curl_setopt($ch, CURLOPT_URL, $this->url);
			sleep(self::WAIT_BETWEEN_REQUESTS);
			$pageHTML = curl_exec($ch);
			$page_results = $this->parseResultsPage($pageHTML);
			$num_page_results = count($page_results);
			$results = array_merge($results, $page_results);
		}
		echo "Found ".count($results)." record".(count($results)==1?"":"s")."\n";
		return $results;
	}
	
	// inputs: 
	// $pageHTML: string with result page's HTML
	// return: array of QueryResult objects, one for each item found, empty if none found
	function parseResultsPage($pageHTML) {
		if(TESS_SESSION_DEBUG == 1) var_dump(trim(substr($pageHTML, 0, TESS_HTML_DUMP_LENGTH)));
		// parse with TagFilter: https://github.com/cubiclesoft/ultimate-web-scraper/blob/master/docs/tag_filter.md
		$results = [];
		$htmloptions = TagFilter::GetHTMLOptions();
		$html = TagFilter::Explode($pageHTML, $htmloptions);
		// Retrieve a pointer object to the root node
		$root = $html->Get();
		// check if there was an error
		try {
			self::checkPageForErrors($root);
		} catch (TESSException $e) {
			if($e->getCode() === TESSException::ERROR_NO_RESULTS) {
				return $results;  // no results, so save time and return now
			} elseif($e->getCode() === TESSException::ERROR_UNKNOWN) {
				error_log('Trying to continue after ' . $e->getMessage());
				return $results;
			} else {
				// error in query; log out and kill off so it can be fixed in calling code
				sleep(self::WAIT_BETWEEN_REQUESTS);
				$this->logout();
				throw $e;
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
			if(TESS_SESSION_DEBUG == 1) echo "No results table, checking if single page...\n";
			$singlePageResult = self::parseSingleRecordPage($root);
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
	private static function parseSingleRecordPage($root) {
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
						$serialNumber = $rowData[$i+1];
					}
					if ($rowData[$i] == "Word Mark" && $i+1<count($rowData)) {
						$wordMark = $rowData[$i+1];
					}
					if ($rowData[$i] == "Registration Number" && $i+1<count($rowData)) {
						$registrationNumber = $rowData[$i+1];
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
	private static function checkPageForErrors($root) {
		$titles =  $root->Find("title");
		foreach ($titles as $title) {
			$title = trim($title->GetPlainText());
			if($title == "TESS -- Error") {
				self::throwPageErrorExceptions($root);
			} else if($title == "503 Service Unavailable") {
				throw new TESSException('TESS service unavailable (503)', TESSException::ERROR_UNAVAILABLE);
			}
		}
	}
	
	// Tries to identify the error based on page content and throw appropriate exceptions
	// inputs:
	// $root - a TagFilterNode for the document root
	// returns: null
	private static function throwPageErrorExceptions($root) {
		// error messages should appear in an h1 heading, so try to get that
		$headings = $root->Find("body > h1:first-of-type");
		if($headings->count() > 0) {
			$heading = $headings->current();
			$text = strtok(trim($heading->GetPlainText()), "\n"); //get first line of heading
			if($text == "No TESS records were found to match the criteria of your query.") {
				throw new TESSException('TESS query had no results: ' . $text, TESSException::ERROR_NO_RESULTS);
			} elseif(
					preg_match('/Invalid/', $text) == 1 || 
					$text == "!Closing Quotes Required" || 
					preg_match('/!vparm .+ not in database/', $text) == 1
				)
			{
				throw new TESSException('TESS query had invalid construction: ' . $text, TESSException::ERROR_INVALID_QUERY);
			} elseif(preg_match('/This search session has expired./', $text) == 1) {
				throw new TESSException('TESS session expired', TESSException::ERROR_SESSION_EXPIRED);
			} else {
				throw new TESSException('TESS query urecognized error: ' . $text, TESSException::ERROR_UNKNOWN);
			}
		}
		throw new TESSException('TESS query urecognized error', TESSException::ERROR_UNKNOWN);
	}
}

?>
