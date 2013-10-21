<?php
  include("./libs/simple_html_dom.php");
  include("./config.php");

  $plans = array();
  $plansHtml = array();

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
                         'aktion', 'tagessuppe','suppen');
    foreach ( $whitelist as $wlElement )
      if ( strpos($fooditem, $wlElement) !== FALSE ) return true;
    return false;
  }
   
  function filterMeals($meal) {
    $meal = str_replace(array("/ Bed."," Gast", "Stud.", "  .", "  ,", "g =")," ",$meal);
    $meal = str_replace(array("MONTAG","DIENSTAG","MITTWOCH","MITT WOCH","DONNERSTAG","FREITAG", "FRE ITAG")," ",$meal);
    $meal = str_replace(array("Montag","Dienstag","Mittwoch","Mitt woch","Donnerstag","Freitag", "Fre itag")," ",$meal);
    $meal = str_replace(array("1","2","3","4","5","6","7","8","9","0"," ,","€","&nbsp;")," ",$meal);
    // multiple spaces to one
    $meal = preg_replace( '/\s+/', ' ', $meal );
    return trim(str_replace(" , ",", ",$meal));
  }

  function filterHTML($str){
      $str = strip_tags($str);
      $str = str_replace("&#160;"," ",$str);         
      $str = trim($str);
      return $str;
  }

  function filterPrice($str){
      $str = str_replace("/"," ",$str);  
      $str = preg_replace( '/\s+/', ' ', $str );       
      $str = trim($str);
      return $str;
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
   * Returns the position of an element in the table or false if it's outside 
   * the table.
   */
  function getPositionOfElement($element, $posx, $posy, $maxposx, 
                                $buffer, $rows, $columns, $extraYbuffer){
    $top = str_replace("px","",getStyleAttribute("top",$element->style));
    $left = str_replace("px","",getStyleAttribute("left",$element->style));

    // if the element is within these borders, it's in the table
    if ( $left > $posx && $top > $posy && $left < $maxposx 
              && $top < $rows[sizeof($rows)-1] + 3*$buffer + $extraYbuffer ) {
      
      $position = array();
      $position['x'] = 0;
      $position['y'] = 0;

      // get the table position of the element
      for ( ; $position['x'] < sizeof($columns) ; $position['x']++ ){
        if ( $left <= $columns[$position['x']] ) {
          break;
        }
      }
      for ( ; $position['y'] < sizeof($rows) ; $position['y']++ ){
        if ( $top <= $rows[$position['y']] ) {
          break;
        }
      }
      $position['x']--; $position['y']--;
      if ( $position['x'] < 0 ) $position['x'] = 0;
      if ( $position['y'] < 0 ) $position['y'] = 0;
      
      return $position;
    } else {
      return false;
    }
  }

  /*
   * Build the json array we need. If something is changed here, the XML output
   * has to be changed to.
   */
  function jsonify($json, $rowsNames ,$food, $mealPrice, 
                   $columns, $rows,$place,
                   $week, $timestamp){
   //get the index for the week element 
    $weekIndex = isWeekRegistered($week);
    if (!$weekIndex){
      registerWeek($week);
      $weekIndex = isWeekRegistered($week);
      global $daysRegistered;
      $daysRegistered[$weekIndex] = array();
    }     

    $json["weeks"][$weekIndex]["weekNumber"] = (int) $week;
    
    for ( $i = 0; $i < $columns; $i++){

      //get index for the day element
      $dayIndex = isDayRegistered(date("Y-m-d", $timestamp),$weekIndex);
      if(!$dayIndex){
        registerDay(date("Y-m-d", $timestamp),$weekIndex);
        $dayIndex = isDayRegistered(date("Y-m-d", $timestamp),$weekIndex);
      }

      $json["weeks"][$weekIndex]["days"][$dayIndex]["date"]=date("Y-m-d", $timestamp);
      $k = 0;
      $theresSomethingToEatToday=FALSE;
      for ( $j = 0; $j < $rows; $j++){  
        if ( $food[$i][$j] != "" && $rowsNames[$j] != "Salatbuffet"){
          $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k] = array();
          if ( filterMeals($food[$i][$j]) != "") {
            $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["category"]= $rowsNames[$j];
            $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["meal"]= filterMeals($food[$i][$j]);
            if ( $mealPrice[$i][$j] != "" ){
              $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["price"]= filterPrice($mealPrice[$i][$j]);
            }
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

  /*
   * The real parsing:
   * 
   * ($posx,$posy) is the top left position of the table in pixels
   * ($maxposx,$maxposy) is the bottom right position of the table in pixels
   * these differ from plan to plan
   * 
   * $timestamp, $week, $place: used for the JSON file
   * 
   * $json: the json construct, new plan gets added at the end.
   * $extraYbuffer: In Pixel: In some plans, meals can have more rows than usual.
   */
  function parsePlan ($posy, $posx, $maxposy, $maxposx,
                      $timestamp, $week, $url, $place, $json, $extraYbuffer) {

    /* some elements are further left / right than the headline element for this
     * column or higher / lower than the headline element for this row. The 
     * buffer exists to account for this.
     */
    $buffer = 10;  

    //some with spaces b/c Bistro does that (wtf)
    $days = array("montag","dienstag","mittwoch","mitt woch",
                  "donnerstag","freitag", "fre itag"); 

    $elements = array();
    
    $columns = array();
    $rows = array();
    $rowsNames = array();
    $rowPrice = array();

    $food = array(array());
    $mealPrice = array(array());
    $bold = array(array());
    
    // load html file and build dom
    $site = new simple_html_dom();  
    $site->load_file($url);

    /* get the elements that contain the needed data and ignore the ones that 
     * are empty
     */
    global $elementToFind;
    $Ps = $site->find($elementToFind);
    
    foreach ( $Ps as $P ){
      if ( filterHTML($P->innertext) != "" ){   
        array_push($elements,$P);
      }
    }

    // get positions of rows and columns – building table
    foreach ( $elements as $element ){
      $top = str_replace("px","",getStyleAttribute("top",$element->style));
      $left = str_replace("px","",getStyleAttribute("left",$element->style));
      $text = filterHTML($element->innertext);
      // column detection by day
      $tmp = $left-$buffer;
      if (in_array(strtolower(trim($text)),$days)) {
        array_push($columns, $tmp);  
      }
      // row detection by whitelisted meal categories 
      if ( $left < $posx && $top > $posy && $top < $maxposy) {    
        $tmp = $top-$buffer;
        if ( whitelisted(trim(strtolower($text))) ){
          array_push($rows, $tmp);  
          array_push($rowsNames, $text);  
        } else {
          if ( strpos($text,"€") !==false){
            // there's a price in the title of the row. 
            $rowPrice[sizeof($rowsNames)-1]=$text;
          } else {
            echo "$place: not found: (".$text.") <br/>\n";
          }
        }
      }
    }

    // initialise arrays
    for ( $i = 0; $i < sizeof($columns); $i++){
      for ( $j = 0; $j < sizeof($rows); $j++){  
         $food[$i][$j]="";
         $bold[$i][$j]=false;
         $mealPrice[$i][$j]="";
      }
    }

    // get positions of elements and sort them to the right position
    foreach ( $elements as $element ){

      $position = getPositionOfElement($element, $posx, $posy, $maxposx, 
                                $buffer, $rows, $columns, $extraYbuffer);

      if ( $position === false ) {
        continue;
      } else {
        $x = $position['x'];
        $y = $position['y'];
      }

      /*
       * insert " – " if text changes from bold to normal.
       * by that, the ingredients list and the name of a pizza are seperated
       * (Bistro)
       */
      if ( strpos( strtolower($element->innertext), "<b>") !== false) {
        $boldElement = true;
      } else {
        $boldElement = false;
      }
      /* trim(filterMeals($food[$i][$j])) needed b/c the day that gets 
       * filtered out later is bold
       */
      if ($bold[$x][$y] && !$boldElement && trim(filterMeals($food[$x][$y])) != ""){
        $food[$x][$y] .= " – "; 
      }
      $bold[$x][$y] = $boldElement;

      /*
       * Prices are either below the meal name or below the meal category
       * if it's below the category, it's already saved as $rowPrice. 
       * Otherwise, we find it here. Prices are identified on the basis of the
       * existence of a €. Luckily, prices are always in an extra line. Let's
       * hope it stays that way.
       */
      if ( isset($rowPrice[$y]) ){ 
        $mealPrice[$x][$y]=$rowPrice[$y];
      }
      if ( strpos(filterHTML($element->innertext),"€") !== false ){
        $mealPrice[$x][$y].=" ".filterHTML($element->innertext);
      } else {// if it's not a price, append it to the meal
        $food[$x][$y] .= " " . filterHTML($element->innertext);
      }      
    }

    return jsonify($json, $rowsNames ,$food, $mealPrice, 
                   sizeof($columns),sizeof($rows),$place,
                   $week, $timestamp);
  }

  // get the URLs of the plans we want to parse
  $plans = getPlansURLs();

  // download plans and reformat them to html
  $i = 0;
  foreach ($plans as $plan ) {
    //exec("mkdir " . $pfad . "/plans");
    //exec("wget --output-document ".$pfad."/plans/plan$i.pdf ".$plan);
    //exec("pdftohtml -c ".$pfad."/plans/plan$i.pdf");
    array_push($plansHtml,"plans/plan$i-1.html");
    $i++;
  }

  // parse plans
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
        $json=parsePlan(120,120,620,1500,$timestamp,$calendarWeek,$planHtml,"Bistro",$json, 0);
      // Cafeteria West
      } else if ( strpos($plans[$t], "West") !== false ){
        $json=parsePlan(120,120,800,1500,$timestamp,$calendarWeek,$planHtml,"West",$json, 75);
      // Prittwitzstrasse
      } else if ( strpos($plans[$t], "Prittwitzstr") !== false ){
        $json=parsePlan(120,120,800,1500,$timestamp,$calendarWeek,$planHtml,"Prittwitzstr",$json, 70);
      }            
    }
    $t++;
  }
  
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
  //exec("rm -rf ".$pfad."/plans");
  
  echo "done\n";
  
?>
