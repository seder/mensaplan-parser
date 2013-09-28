<?php
  include("./libs/simple_html_dom.php");
  
  // path for wget
  $pfad = "/users/student1/s_sfuchs/public_html/mensaplan-parser/";
  
  // save XML and JSON to this directory
  $outputDir = "/soft/www/root/mensaplan/data/";
  
  $plans = array();
  $plansHtml = array();
  $plans = getPlans();

  $timestamp = time();
  $week = date("W", $timestamp); 

  function whitelisted($url){
    $whitelist = array ( 'lecker und fein', 'gut und g', 'pizza', 
                         'pasta', 'schneller teller', 'wok und grill',
                         'buffet', 'vegetarisch', 'bio', 'eintopfgerichte',
                         'aktion', 'tagessuppe');
    foreach ( $whitelist as $fooditem )
      if ( strpos($url, $fooditem) !== FALSE ) return true;
    return false;
  }
   
  function filterMeals($meal) {
    $meal = str_replace(array("/ Bed."," Gast", "Stud.", "  .", "  ,", "g ="),"",$meal);
    return str_replace(" , ",", ",$meal);
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
           strpos($a->href,"Bistro") !== false || 
           strpos($a->href,"West") !== false || 
           strpos($a->href,"Prittwitzstr") !== false )
        array_push($urls,$domain.$a->href."\n");
    }
    return $urls;
  }

  function parsePlan ($posy, $posx, $maxposy, $maxposx,
                      $timestamp, $week, $url, $place, $json, $extraYbuffer) {

    // some elements are further left / right than the headline element for this
    // row, the buffer exists to account for this.
    $buffer = 10;  
    
    $days = array("montag","dienstag","mittwoch","mitt woch","donnerstag","freitag", "fre itag"); //some with spaces b/c Bistro does that (wtf)
    
    $site = new simple_html_dom();  
    $site->load_file($url);

    $Ps = $site->find("DIV");
    //$Ps = $site->find("P");
    $elements = array();
    
    $rows = array();
    $rowsNames = array();
    $columns = array();
    $columnsNames = array();

    $food = array(array());
    
    foreach ( $Ps as $P ){
      $P->innertext=strip_tags($P->innertext);
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
      // rows detection with heading
      $tmp = $left-$buffer;
      if (in_array(strtolower(trim($element->innertext)),$days)) {
        array_push($rows, $tmp);  
        array_push($rowsNames,$element->innertext);
      }
      // columns 
      if ( $left < $posx && $top > $posy && $top < $maxposy) {    
        $tmp = $top-$buffer;
        if ( whitelisted(trim(strtolower($element->innertext))) ){
          array_push($columns, $tmp);  
          array_push($columnsNames, $element->innertext);  
        } else {
			echo "$place: not found: (".$element->innertext.") <br>";
		}
      }
    }
	//print_r($rowsNames);
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
                && $top < $columns[sizeof($columns)-1] + 3*$buffer + $extraYbuffer ) {
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
			if ($columnsNames[$j] != "Salatbuffet") {
               $json[$place][$week][date("Y-m-d", $timestamp)][$columnsNames[$j]]= filterMeals($food[$i][$j]);
            }
        }
      }
      $timestamp = $timestamp + 24*60*60;
    }
    return $json;
  }

  // download & parse
  $i = 0;
  foreach ($plans as $plan ) {
	echo "getting $plan";
    exec("mkdir " . $pfad . "plans");
    exec("wget --output-document ".$pfad."/plans/plan$i.pdf ".$plan);
    exec("pdftohtml -c plans/plan$i.pdf");
    array_push($plansHtml,"plans/plan$i-1.html");
    $i++;
  }

  $json = array();

  $t = 0;
  foreach ( $plansHtml as $planHtml ) {
    preg_match_all('/\d+/', $plans[$t], $matches);
    $cw = array_pop($matches[0]);
    $year = date("Y",time());
    $timestamp = strtotime($year."W".$cw);
    // cut out old weeks
    if ($cw >= date("W",time())) {   
      // mensa
      if ( strpos($plans[$t], "UL") !== false ) {
        $json=parsePlan(120,60,650,1500,$timestamp,$cw,$planHtml,"Mensa",$json, 0);
      // bistro
      } else if ( strpos($plans[$t], "Bistro") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$cw,$planHtml,"Bistro",$json, 0);
      } else if ( strpos($plans[$t], "West") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$cw,$planHtml,"West",$json, 20);
      } else if ( strpos($plans[$t], "Prittwitzstr") !== false ){
        $json=parsePlan(120,120,600,1500,$timestamp,$cw,$planHtml,"Prittwitzstr",$json, 20);
      }     
      
       
    }
    $t++;
  }

  
  //print_r($json);
  
  // Save as JSON
  $fp = fopen($outputDir.'mensaplan.json', 'w');
  fwrite($fp, json_encode($json));
  fclose($fp);
  echo '<pre>';
  print_r($json);
  echo  '</pre>';
  
  // Save as XML for compatibility reasons
  $xml = new SimpleXMLElement('<mensaplan/>');
  foreach ( $json['Mensa'] as $weekkey => $weekvalue ) {
    // add weeks
    $xmlweek = $xml->addChild("week");
    $xmlweek->addAttribute('weekOfYear', $weekkey);
    foreach ($weekvalue as $daykey => $dayvalue) {
      // add days
      $xmlday = $xmlweek->addChild("day");
      $xmlday->addAttribute('date', $daykey); 
      $xmlday->addAttribute('open', "1");      
      // mark today day as today b/c htmlifier needs this..
      if ($daykey==date("Y-m-d")) {
        $xmlday->addAttribute('today', "today");    
      }
      // add meals
      foreach ($dayvalue as $mealtype => $meal) {
        $xmlmeal = $xmlday->addChild("meal");
        $xmlmeal->addAttribute('type', $mealtype); 
        $xmlmeal->addChild('item', $meal);  
	  }
	}
  } 
  
  //print($xml->asXML());
  $xml->asXML($outputDir."/mensaplan.xml");
  
  exec("rm -rf ".$pfad."/plans");
  
  echo "done\n";
  
?>
