<?php
  include("./libs/simple_html_dom.php");
  include("./config.php");

  $plans = array();
  $plansXML = array();

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
                         'buffet', 'vegetarisch', 'bio', 'eintopf',
                         'aktion', 'suppe', 'essen', 'woche', 'expedition','ranjid',
                          'gourmet', 'prima klima');
    foreach ( $whitelist as $wlElement )
      if ( strpos($fooditem, $wlElement) !== FALSE ) return true;
    return false;
  }
   
  function filterMeals($meal) {
    $meal = str_replace(array("/ Bed."," Gast", "Stud.", "  .", "  ,", "g =")," ",$meal);
    $meal = str_replace(array("MONTAG","DIENSTAG","MITTWOCH","MITTWOC H","MITT WOCH","DONNERSTAG","FREITAG", "FRE ITAG")," ",$meal);
    $meal = str_replace(array("Montag","Dienstag","Mittwoch","Mittwoc h","Mitt woch","Donnerstag","Freitag", "Fre itag")," ",$meal);
    $meal = str_replace(array("1","2","3","4","5","6","7","8","9","0"," ,","€","&nbsp;")," ",$meal);
    $meal = str_replace(array("KJ","Kcal","/ / / / /")," ",$meal);
    // multiple spaces to one
    $meal = preg_replace( '/\s+/', ' ', $meal );
    $meal = str_replace(array("KJ","Kcal","/ / / / /")," ",$meal);
    $meal = str_replace(array("( )","()"),"",$meal);
    return trim(str_replace(" , ",", ",$meal));
  }

  function filterHTML($str){
      $str = str_replace(array("&#160;","&nbsp;")," ",$str);         
      $str = trim($str);
      return $str;
  }

  function filterPrice($str){
      $str = str_replace("/"," ",$str);  
      $str = preg_replace( '/\s+/', ' ', $str );       
      $str = trim($str);
      return $str;
  }

  function getTextFromNode($xml){
      $tmp = $xml->xpath(".//text()");
      return (string) $tmp[0];
  }

  /*
   * Gets $attribute of an elements tag. 
   */
  function getStyleAttribute($attribute, $tag){
    $tmp = $tag->xpath("./@".$attribute);
    return (string) $tmp[0];
  }

  /*
   * Looks for links and returns array with the addresses for all plans we use
   */
  function getPlansURLs(){
    $urls = array();
    $domain = "https://www.studierendenwerk-ulm.de/";
    $url = $domain."/essen-trinken/speiseplaene/";
    $site = new simple_html_dom();  
    $site->load_file($url);
    $as = $site->find("a");
    foreach ( $as as $a ) {
      if ((strpos($a->href,"mensa-uni") !== false || 
           strpos($a->href,"uni-bistro") !== false || 
           strpos($a->href,"cafeteria-uni-west") !== false || 
           strpos($a->href,"cafeteria-b-mensavital") !== false || 
           strpos($a->href,"rittwitzstr") !== false ||
           strpos($a->href,"UL") !== false ||
           strpos($a->href,"Bistro") !== false || 
           strpos($a->href,"CB") !== false || 
           strpos($a->href,"West") !== false //||
           //strpos($a->href,"HL") !== false ||
           //strpos($a->href,"OE") !== false 
           ) && (strpos($a->href,".pdf") !== false || strpos($a->href,".PDF") !== false)
           )
        array_push($urls,$a->href."\n");
    }
    return $urls;
  }

  /*
   * Returns the position of an element in the table or false if it's outside 
   * the table.
   */
  function getPositionOfElement($element, $posx, $posy, $maxposx, $maxposy,
                                $rows, $columns){
    $top = getStyleAttribute("top",$element);
    $left = getStyleAttribute("left",$element);

    // if the element is within these borders, it's in the table
    if ( $left > $posx && $top > $posy && $left < $maxposx && $top < $maxposy ) {
      
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
   * has to be changed, too.
   */
  function jsonify($json, $rowsNames, $food, $mealPrice, $columns, $rows,
                   $place, $week){

    $year = date("Y",time());
    $timestamp = strtotime($year."W".str_pad($week, 2, '0', STR_PAD_LEFT));

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
        if ( filterMeals($food[$i][$j]) != "" && $rowsNames[$j] != "Salatbuffet"){
          $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k] = array();
          $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["category"]= $rowsNames[$j];
          $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["meal"]= filterMeals($food[$i][$j]);
          if ( $mealPrice[$i][$j] != "" ){
            $json["weeks"][$weekIndex]["days"][$dayIndex][$place]["meals"][$k]["price"]= filterPrice($mealPrice[$i][$j]);
          }
          $theresSomethingToEatToday=TRUE;
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
   * 
   */
  function parsePlan ($json, $posx, $posy, $maxposx, $maxposy,
                      $week, $url, $place) {

    /* some elements are further left / right than the headline element for this
     * column or higher / lower than the headline element for this row. The 
     * buffer exists to account for this.
     */
    $buffer = 5; 

    //some with spaces b/c Bistro does that (wtf)
    $days = array("montag","dienstag","mittwoch","mitt woch","mittwoc h",
                  "donnerstag","freitag", "fre itag"); 

    $elements = array();
    
    $columns = array();
    $rows = array();
    $rowsNames = array();
    $rowPrice = array();

    $food = array(array());
    $mealPrice = array(array());
    $bold = array(array());
    
    // load xml file
    $site = simplexml_load_file($url);

    if ( $site == "" ) {
      echo "plan is broken, continue with next plan\ņ";
      return $json; 
    }

    /* get the elements that contain the needed data and ignore the ones that 
     * are empty
     */
    $elements = $site->xpath("//text");

    // get positions of rows and columns – building table

    foreach ( $elements as $element ){
      $top = getStyleAttribute("top",$element);
      $left = getStyleAttribute("left",$element);

      $text = filterHTML(getTextFromNode($element));
      
      if ( $text == "" ) continue;

      // column detection by day
      $tmp = $left-$buffer;
      if (in_array(strtolower(trim($text)),$days)) {
        array_push($columns, $tmp);
      }
      // row detection by whitelisted meal categories 
      // this doesn't work with Cafeteria B as the meal
      // names are pictures.
      if ( $left < $posx && $top > $posy && $top < $maxposy && $place != "CB") {    
        $tmp = $top-$buffer;
        if ( whitelisted(strtolower(filterHTML($text))) ){
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
      // detect end of table, change $maxposy if necessary
      if ((strpos(strtolower(filterHTML($text)),"wir verwenden") !== false ||
          strpos(strtolower(filterHTML($text)),"glich: ") !== false ||
          strpos(strtolower(filterHTML($text)),"=") !== false )
          && $top < $maxposy ) {
        if ( $place != "CB" ) $maxposy = $top;
      }
    }

    // guessing positions for Cafeteria B
    if ( $place == "CB" ) {
      array_push($rows, 0);  
      array_push($rowsNames, "Mensa Vital");  
      array_push($rows, 450);  //315
      array_push($rowsNames, "Aus Topf und Pfanne");  
    }

    // initialise arrays
    for ( $i = 0; $i < sizeof($columns); $i++){
      for ( $j = 0; $j < sizeof($rows); $j++){  
         $food[$i][$j]="";
         $bold[$i][$j]=false;
         $mealPrice[$i][$j]="";
      }
    }

    echo $place."\n";

    // get positions of elements and sort them to the right position
    foreach ( $elements as $element ){
      
      $position = getPositionOfElement($element, $posx, $posy, $maxposx, $maxposy,
                                $rows, $columns);

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
      if ( $element->xpath("./b") != array()) {
        $boldElement = true;
      } else {
        $boldElement = false;
      }

      /*
       * filterMeals($food[$i][$j]) needed b/c the day that gets 
       * filtered out later is bold
       */
      if ($bold[$x][$y] && !$boldElement && filterMeals($food[$x][$y]) != "" 
          && strpos(getTextFromNode($element),"€") === false 
          && filterMeals(getTextFromNode($element)) != ""){
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
      if ( strpos(filterHTML(getTextFromNode($element)),"€") !== false ){
        $mealPrice[$x][$y].=" ".filterHTML(getTextFromNode($element));
      } else {// if it's not a price, append it to the meal, 
        if ( $place != "CB" || strpos(filterHTML(getTextFromNode($element)),"Kilojoule") === false )
          $food[$x][$y] .= " " . filterHTML(getTextFromNode($element)); 
      }      
    }

    return jsonify($json, $rowsNames ,$food, $mealPrice, 
                   sizeof($columns),sizeof($rows),$place,
                   $week);
  }

  // get the URLs of the plans we want to parse
  $plans = getPlansURLs();

  // download plans and reformat them to XML
  $i = 0;
  foreach ($plans as $plan ) {
    exec("mkdir " . $pfad . "/plans");
    exec("wget --output-document ".$pfad."/plans/plan$i.pdf ".$plan);
    exec("pdftohtml -i -c -xml ".$pfad."/plans/plan$i.pdf");
    array_push($plansXML,"plans/plan$i.xml");
    $i++;
  }

  // parse plans
  $t = 0;
  foreach ( $plansXML as $planXML ) {
    preg_match_all('/\d+/', $plans[$t], $matches);
    $calendarWeek = array_pop($matches[0]);
    // cut out old weeks
    if ($calendarWeek >= date("W",time())) {
      // Mensa
      if ( (strpos($plans[$t], "mensa-uni") !== false || strpos($plans[$t], "UL") !== false) && file_exists($planXML) ) {
        $json=parsePlan($json,60,70,1500,2000,$calendarWeek,$planXML,"Mensa");
      // Bistro
      } else if ( (strpos($plans[$t], "uni-bistro") !== false || strpos($plans[$t], "Bistro") !== false) && file_exists($planXML) ){
        $json=parsePlan($json,120,120,1500,830,$calendarWeek,$planXML,"Bistro");
      // Cafeteria West
      } else if ( (strpos($plans[$t], "cafeteria-uni-west") !== false || strpos($plans[$t], "West") !== false)&& file_exists($planXML) ){
        $json=parsePlan($json,120,120,1500,800,$calendarWeek,$planXML,"West");
      // Prittwitzstrasse
      } else if (strpos($plans[$t], "rittwitzstr") !== false && file_exists($planXML) ){
        $json=parsePlan($json,120,120,1500,800,$calendarWeek,$planXML,"Prittwitzstr");
      //Hochschulleitung
      //} else if ( strpos($plans[$t], "HL") !== false && file_exists($planXML) ){
      //  $json=parsePlan($json,120,120,800,1500,$calendarWeek,$planXML,"Hochschulleitung",60);
      // HS Oberer Eselsberg
      //} else if ( strpos($plans[$t], "OE") !== false && file_exists($planXML) ){
      //  $json=parsePlan($json,120,120,800,1500,$calendarWeek,$planXML,"HSOE",60);
      // Cafeteria B
      } else if ( (strpos($plans[$t], "cafeteria-b-mensavital") !== false || strpos($plans[$t], "CB") !== false) && file_exists($planXML) ){
        //$json=parsePlan($json,180,120,700,710,$calendarWeek,$planXML,"CB");//quick fix for CW 15
        //$json=parsePlan($json,180,120,2000,450,$calendarWeek,$planXML,"CB");
        if ( $calendarWeek < 53 ) // workaround
          $json=parsePlan($json,180,120,2000,630,$calendarWeek,$planXML,"CB");
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
  $xml->asXML($outputDir."mensaplan.xml");

  // clean up
  exec("rm -rf ".$pfad."/plans");
  
  echo "done\n";
  
?>
