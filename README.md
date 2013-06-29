mensaplan-parser
================

parser for the uni ulm mensa- and bistroplan

usage:
$ php getfood.php

dependencies:
 * pdftohtml
 * wget

There are different implementations of pdftohtml, depending on which one you use
you might have to change lines 59 and 60 to this:

//$Ps = $site->find("DIV");

$Ps = $site->find("P");

tested on ubuntu 13.04
