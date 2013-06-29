<?php

  include("../www/anzeige/libs/simple_html_dom.php");

  $plans = array();
  $plansHtml = array();

  $plans = getPlans();

  $timestamp = time();
  $week = date("W", $timestamp); 

  function whitelisted($url){
    $whitelist = array ( 'Lecker und Fein', 'Gut und Günstig', 'Pizza', 
                         'Pasta', 'Schneller Teller', 'Wok und Grill',
                         'Buffet', 'Vegetarisch', 'Bio', 'Eintopfgerichte',
                         'Aktion');
    foreach ( $whitelist as $fooditem )
      if ( strpos($url, $fooditem) !== FALSE ) return true;
    return false;
  }
   
  function getStyleAttribute($attribute, $style){
    $styles = explode(";",$style);
    foreach ( $styles as $sty ){
      $tmp = explode(":",$sty);
      if ( $tmp[0] == $attribute ){
        return  $tmp[1];
      } 
    }
    return $attribute . " not found";
  }

  function getPlans(){
    $urls = array();
    $domain = "http://www.studentenwerk-ulm.de/";
    $url = $domain."/hochschulgastronomie/speiseplaene.html";
    $site = new simple_html_dom();  
    $site->load_file($url);
    $as = $site->find("a");
    foreach ( $as as $a ) {
      if ( strpos($a->href,"UL") !== false || 
           strpos($a->href,"Bistro") !== false )
        array_push($urls,$domain.$a->href."\n");
    }
    return $urls;
  }

  function parsePlan ($posy, $posx, $maxposy, $maxposx,
                      $timestamp, $url, $place, $json) {

    // some elements are further left / right than the headline element for this
    // row, the buffer exists to account for this.
    $buffer = 10;  
    
    $site = new simple_html_dom();  
    $site->load_file($url);

    $Ps = $site->find("DIV");
    $elements = array();
    
    $rows = array();
    $columns = array();
    $columnsNames = array();

    $food = array(array());

    foreach ( $Ps as $P ){
      $P->innertext=strip_tags($P->innertext);
      $P->innertext = str_replace("&#160;"," ",$P->innertext);
      $P->innertext = str_replace(
          array("1","2","3","4","5","6","7","8","9","0",",","€","&nbsp;")
          ," ",$P->innertext);
      $P->innertext = trim($P->innertext);
      if ( $P->innertext != "&#160;" && 
           $P->innertext != "<b>&#160;</b>" &&
           $P->innertext != "" ){ 
        array_push($elements,$P);
      }
    }

    // get positions of rows and columns
    foreach ( $elements as $element ){
      $top = str_replace("px","",getStyleAttribute("top",$element->style));
      $left = str_replace("px","",getStyleAttribute("left",$element->style));
      // rows
      if ( $left > $posx && $top < $posy && $top > $posy-35) {
        $tmp = $left-$buffer;
        if ( sizeof($rows) == 0 || $rows[sizeof($rows)-1]-$tmp < 0 ){
          array_push($rows, $tmp);  
        }
      }
      // columns 
      if ( $left < $posx && $top > $posy && $top < $maxposy) {    
        $tmp = $top-$buffer;
        if ( whitelisted($element->innertext) ){
          array_push($columns, $tmp);  
          array_push($columnsNames, $element->innertext);  
        }
      }
    }

    // initialise food
    for ( $i = 0; $i < sizeof($rows); $i++){
      for ( $j = 0; $j < sizeof($columns); $j++){  
         $food[$i][$j]="";
      }
    }

    // get positions of elements
    foreach ( $elements as $element ){
      $top = str_replace("px","",getStyleAttribute("top",$element->style));
      $left = str_replace("px","",getStyleAttribute("left",$element->style));
      if ( $left > $posx && $top > $posy && $left < $maxposx 
                && $top < $columns[sizeof($columns)-1] + 3*$buffer ) {
        $i = 0; $j = 0;
        for ( ; $i < sizeof($rows) ; $i++ ){
          if ( $left <= $rows[$i] ) {
            break;
          }
        }
        for ( ; $j < sizeof($columns) ; $j++ ){
          if ( $top <= $columns[$j] ) {
            break;
          }
        }
        $i--; $j--;
        if ( $i < 0 ) $i = 0;
        if ( $j < 0 ) $j = 0;
        $food[$i][$j] .= " " .$element->innertext;
      }
    }

    // JSONify
    for ( $i = 0; $i < sizeof($rows); $i++){
      for ( $j = 0; $j < sizeof($columns); $j++){  
        if ( $food[$i][$j] != "" ){
          $json[$place][date("Y-m-d", $timestamp)][$columnsNames[$j]]=
              str_replace("- ", "", trim($food[$i][$j]));
        }
      }
      $timestamp = $timestamp + 24*60*60;
    }
    return $json;
  }

  // download & parse
  $i = 0;
  foreach ($plans as $plan ) {
    exec("mkdir plans");
    exec("wget --output-document plans/plan$i.pdf ".$plan);
    exec("pdftohtml -c plans/plan$i.pdf");
    array_push($plansHtml,"plans/plan$i-1.html");
    $i++;
  }

  $json = array();

  $t = 0;
  foreach ( $plansHtml as $planHtml ) {
    $cw = substr($plans[$t], -7, 2);
    $year = date("Y",time());
    $timestamp = strtotime($year."W".$cw);
    // mensa
    if ( strpos($plans[$t], "UL") !== false ) {
      $json=parsePlan(120,60,650,1500,$timestamp,$planHtml,"Mensa",$json);
    // bistro
    } else if ( strpos($plans[$t], "Bistro") !== false ){
      $json=parsePlan(120,120,600,1500,$timestamp,$planHtml,"Bistro",$json);
    }
    $t++;
  }

  $fp = fopen('food.json', 'w');
  fwrite($fp, json_encode($json));
  fclose($fp);

  exec("rm -rf plans");
?>
