# TESS_Session
For Querying the USPTOs Trademark Electronic Search System (TESS)

TESS_Session contains the underlying code that [CockyBot](https://twitter.com/cockybot) uses to interact with [TESS](http://tmsearch.uspto.gov).

Specifically, it supports "Word and/or Design Mark Search (Free Form)" queries.

Basic usage:
```php
$session = new TESS_Session();
$session->logIn();
$results1 = $session->getQueryResults($yourQueryString1);
$results2 = $session->getQueryResults($yourQueryString2);
$session->logOut();
```
Results are returned as an array of QueryResult objects.
```php
$results = $session->getQueryResults($yourQueryString);
foreach($results as $result) {
  echo $result->serialNumber . "\n";
  echo $result->wordMark . "\n";
  echo $result->registrationNumber . "\n";
  echo $result->getShareableStatusLink() . "\n";
  echo $result->getShareableDocumentLink() . "\n";
}
```
Query strings should conform to TESS's "Word and/or Design Mark Search (Free Form)" query design
e.g.
```php
$yourQueryString = '`FD > 20180501 < 20180514 and (novel or book)[GS] same (("fiction" NOT NEAR "non"))[GS] and ("4")[MD] and (LIVE)[LD] and (Trademark)[TM] and ("016" or "009")[IC]'
```
