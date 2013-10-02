<?php  
  // path for wget
  $pfad = "./";//"/users/student1/s_sfuchs/public_html/mensaplan-parser/";

  // save XML and JSON to this directory
  $outputDir = "./";//"/soft/www/root/mensaplan/data/";

  /*
   * There are different implementations of pdftohtml, for some you need "DIV" 
   * here, for some "P".
   */ 
  $elementToFind = "P";//"DIV";
