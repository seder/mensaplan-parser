<?php
  include("./libs/simple_html_dom.php");
  include("./config.php");

  $plans = array();
  $plansHtml = array();
  $plans = getPlansURLs();

  $timestamp = time();
  $week = date("W", $timestamp); 

  $weeksRegistered = array();
  $daysRegistered = array();

  $json = array();
  $json["weeks"]=array();  

  /*
   * These functions are used for the JSON-file to ensure that every week/day we 
   * find in the pdf sources gets a sequential index beginning with 0 while 
   * meals at the same day/in the same week are correctly assigned.
   */

  /*
   * returns index of the value, false if not present
   */
  function isWeekRegistered($int){
    global $weeksRegistered;
    return array_search($int, $weeksRegistered);
  }

  function registerWeek($int){
    global $weeksRegistered;
    array_push($weeksRegistered,$int);
  }

  /*
   * returns index of the value, false if not present
   */
  function isDayRegistered($date,$week){
    global $daysRegistered;
    return array_search($date, $daysRegistered[$week]);
  }

  function registerDay($date,$week){
    global $daysRegistered;
    array_push($daysRegistered[$week],$date);
  }

  /*
   * Returns, if a menu item is whitelisted. This needs to be done as otherwise 
   * the fine print would be accepted as menu item.
   */
  function whitelisted($fooditem){
    $whitelist = array ( 'lecker und fein', 'gut und g', 'pizza', 
                         'pasta', 'schneller teller', 'wok und grill',
                         'buffet', 'vegetarisch', 'bio', 'eintopfgerichte',
                         'aktion', 'tagessuppe');
    foreach ( $whitelist as $wlElement )
      if ( strpos($fooditem, $wlElement) !== FALSE ) return true;
    return false;
  }
   
  function filterMeals($meal) {
    $meal = str_replace(array("/ Bed."," Gast", "Stud.", "  .", "  ,", "g =")," ",$meal);
    $meal = str_replace(array("MONTAG","DIENSTAG","MITTWOCH","MITT WOCH","DONNERSTAG","FREITAG", "FRE ITAG")," ",$meal);
    echo $meal;
    return trim(str_replace(" , ",", ",$meal));
  }

  /*
   * Gets $attribute of an elements tag. 
   */
  function getStyleAttribute($attribute, $tag){
    $attributes = explode(";",$tag);
    foreach ( $attributes as $attributesItem ){
      $tmp = explode(":",$attributesItem);
      if ( $tmp[0] == $attribute ){
        return  $tmp[1];
      } 
    }
    return $attribute . " not found";
  }

  /*
   * Looks for links and returns array with the addresses for all plans we use
   */
  function getPlansURLs(){
    $urls = array();
    $domain = "http://www.studentenwerk-ulm.de/";
    $url = $domain."/hochschulgastronomie/speiseplaene.html";
    $site = new simple_html_dom();  
    $site->load_file($url);
    $as = $site->find("a");
    foreach ( $as as $a ) {
      if ( strpos($a->href,"UL") !== false || 
           strpos($a->href,"Bistro") !== false || 
           strpos($a->href,"West") !== false || 
           strpos($a->href,"Prittwitzstr") !== false )
        array_push($urls,$domain.$a->href."\n");
    }
    return $urls;
  }

  /*
   * The real parsing:
   * 
   * ($posx,$posy) is the top left position of the table 
   * ($maxposx,$maxposy) is the bottom right position of the table
   * these differ from plan to plan
   * 
   * $timestamp, $week, $place: used for the JSON file
   * 
   * $json: the json conststruct, new plan gets added at the end.
   * $extraYbuffer: In Pixel: In some Plans, meals can have more rows as usual.
   */
  function parsePlan ($posy, $posx, $maxposy, $maxposx,
                      $timestamp, $week, $url, $place, $json, $extraYbuffer) {

    // some elements are further left / right than the headline element for this
    // row, the buffer exists to account for this.
    $buffer = 10;  
    
    $days = array("montag","dienstag","mittwoch","mitt woch","donnerstag","freitag", "fre itag"); //some with spaces b/c Bistro does that (wtf)
    
    $site = new simple_html_dom();  
    $site->load_file($url);

    global $elementToFind;
    $Ps = $site->find($elementToFind);

    $elements = array();
    
    $column = array();
    $columnNames = array();
    $rows = array();
    $rowsNames = array();

    $food = array(array());
    
    foreach ( $Ps as $P ){
      $P->innertext = strip_tags($P->innertext);
      $P->innertext = str_replace("&#160;"," ",$P->innertext);
      $P->innertext = str_replace(
          array("1","2","3","4","5","6","7","8","9","0"," ,","€","&nbsp;")
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
      // column detection with heading
      $tmp = $left-$buffer;
      if (in_array(strtolower(trim($element->innertext)),$days)) {
        array_push($column, $tmp);  
        array_push($columnNames,$element->innertext);
      }
      // rows 
      if ( $left < $posx && $top > $posy && $top < $maxposy) {    
        $tmp = $top-$buffer;
        if ( whitelisted(trim(strtolower($element->innertext))) ){
          array_push($rows, $tmp);  
          array_push($rowsNames, $element->innertext);  
        } else {
          echo "$place: not found: (".$element->innertext.") <br/>";
	}
      }
    }
	  //print_r($columnNames);
    // initialise food
    for ( $i = 0; $i < sizeof($column); $i++){
      for ( $j = 0; $j < sizeof($rows); $j++){  
         $food[$i][$j]="";
      }
    }

    // get positions of elements and sort them to the right position
    foreach ( $elements as $element ){
      $top = str_replace("px","",getStyleAttribute("top",$element->style));
      $left = str_replace("px","",getStyleAttribute("left",$element->style));
      if ( $left > $posx && $top > $posy && $left < $maxposx 
                && $top < $rows[sizeof($rows)-1] + 3*$buffer + $extraYbuffer ) {
        $i = 0; $j = 0;
        for ( ; $i < sizeof($column) ; $i++ ){
          if ( $left <= $column[$i] ) {
            break;
          }
        }
        for ( ; $j < sizeof($rows) ; $j++ ){
          if ( $top <= $rows[$j] ) {
            break;
          }
        }
        $i--; $j--;
        if ( $i < 0 ) $i = 0;
        if ( $j < 0 ) $j = 0;
        $food[$i][$j] .= " " .$element->innertext;
      }
    }

    //get the index for the week element 
    $weekIndex = isWeekRegistered($week);
    if (!$weekIndex){
      registerWeek($week);
      $weekIndex = isWeekRegistered($week);
      global $daysRegistered;
      $daysRegistered[$weekIndex] = array();
    }     

    $json["weeks"][$weekIndex]["weekNumber"] = (int) $week;
    // JSONify
    for ( $i = 0; $i < sizeof($column); $i++){

      //get index for the day element
      $dayIndex = isDayRegistered(date("Y-m-d", $timestamp),$weekIndex);
      if(!$dayIndex){
        registerDay(date("Y-m-d", $timestamp),$weekIndex);
        $dayIndex = isDayRegistered(date("Y-m-d", $timestamp),$weekIndex);
      }

      $json["weeks"][$weekIndex]["days"][$dayIndex]["date"]=date("Y-m-d", $timestamp);
      $k = 0;
      $theresSomethingToEatToday=FALSE;
      for ( $j = 0; $j < sizeof($rows); $j++){  
        if ( $food[$i][$j] != "" && $rowsNames[$j] != "Salatbuffet"){
          $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k] = array();
          $meal = filterMeals($food[$i][$j]);
          if ( $meal != "") {
            $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["category"]= $rowsNames[$j];
            $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["meal"]= filterMeals($food[$i][$j]);
            $theresSomethingToEatToday=TRUE;
          }
          $k++;
        }
      }
      $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["open"] = $theresSomethingToEatToday;
      $timestamp = $timestamp + 24*60*60;
    }
    return $json;
  }

  // download & parse
  $i = 0;
  foreach ($plans as $plan ) {
    exec("mkdir " . $pfad . "/plans");
    exec("wget --output-document ".$pfad."/plans/plan$i.pdf ".$plan);
    exec("pdftohtml -c ".$pfad."/plans/plan$i.pdf");
    array_push($plansHtml,"plans/plan$i-1.html");
    $i++;
  }

  $t = 0;
  foreach ( $plansHtml as $planHtml ) {
    preg_match_all('/\d+/', $plans[$t], $matches);
    $calendarWeek = array_pop($matches[0]);
    $year = date("Y",time());
    $timestamp = strtotime($year."W".$calendarWeek);
    // cut out old weeks
    if ($calendarWeek >= date("W",time())) {   
      // Mensa
      if ( strpos($plans[$t], "UL") !== false ) {
        $json=parsePlan(120,60,650,1500,$timestamp,$calendarWeek,$planHtml,"Mensa",$json, 0);
      // Bistro
      } else if ( strpos($plans[$t], "Bistro") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$calendarWeek,$planHtml,"Bistro",$json, 0);
      // Cafeteria West
      } else if ( strpos($plans[$t], "West") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$calendarWeek,$planHtml,"West",$json, 20);
      // Prittwitzstrasse
      } else if ( strpos($plans[$t], "Prittwitzstr") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$calendarWeek,$planHtml,"Prittwitzstr",$json, 20);
      }            
    }
    $t++;
  }

  //print_r($json);
  
  // Save as JSON
  $fp = fopen($outputDir.'mensaplan.json', 'w');
  fwrite($fp, json_encode($json));
  fclose($fp);
  //echo '<pre>';
  //print_r($json);
  //echo  '</pre>';

  // Save also as XML for compatibility reasons
  $xml = new SimpleXMLElement('<mensaplan/>');
  foreach ( $json['weeks'] as $weekkey => $weekvalue ) {
    //echo $weekvalue['weekNumber']."<br>";
    // add weeks
    $xmlweek = $xml->addChild("week");
    $xmlweek->addAttribute('weekOfYear', $weekvalue['weekNumber']);
    foreach ( $weekvalue['days'] as $daykey => $dayvalue ) {
      //echo $dayvalue['date']."<br>";
      if ($dayvalue['Mensa']['open']) {
        //echo "open<br>";
        $xmlday = $xmlweek->addChild("day");
        $xmlday->addAttribute('date', $dayvalue['date']);
        $xmlday->addAttribute('open', "1");
        // mark today day as today b/c htmlifier needs this..
        if ($dayvalue['date']==date("Y-m-d")) {
          $xmlday->addAttribute('today', "today");
        }
        foreach ( $dayvalue['Mensa']['meals'] as $mealkey => $mealvalue ) {
          //echo $mealvalue['category'].": ".$mealvalue['meal']."<br>";
          $xmlmeal = $xmlday->addChild("meal");
          $xmlmeal->addAttribute('type', $mealvalue['category']);
          $xmlmeal->addChild('item', $mealvalue['meal']);
        }
      }
    }
  }
  //print($xml->asXML());
  $xml->asXML($outputDir."/mensaplan.xml");

  // clean up
  exec("rm -rf ".$pfad."/plans");
  
  echo "done\n";
  
?>
