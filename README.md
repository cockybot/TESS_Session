# TESS_Session
For Querying the USPTOs Trademark Electronic Search System (TESS)

TESS_Session contains the underlying code that [CockyBot](https://twitter.com/cockybot) uses to interact with [TESS](http://tmsearch.uspto.gov).

Specifically, it supports "Word and/or Design Mark Search (Free Form)" queries.

Basic usage:
```
$session = new TESS_Session();
$session->logIn();
$results1 = $session->getQueryResults($yourQueryString1);
$results2 = $session->getQueryResults($yourQueryString2);
$session->logOut();
```
