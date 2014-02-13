mensaplan-parser
================

Parser for the Studentenwerk Ulm menus. You need to write a config.php file 
first, see config_example.php for directions. 

usage:
$ php getfood.php

dependencies:
 * pdftohtml
 * wget

tested on ubuntu 13.04


## Changes and Notes by taxilof
- Feature: export the Data in XML format for compatibility
- Use Weeknumbers in JSON and XML
- configurations vars for working and output dirs

## Changes and Notes by seder
- Changed the JSON output format
- JSON-File now includes information whether a cafeteria is open
- configuration vars now in seperate file (config.php, see config_example.php for reference)
- added prices for all plans that provide this information

This Parser is now used productive: http://www.uni-ulm.de/mensaplan/ 

