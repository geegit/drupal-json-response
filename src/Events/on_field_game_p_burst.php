<?php

namespace Drupal\drupal_json_response\Events;

  /*
  * listen for path event and call your function when the event is triggered
  * 
  *   your function will be called with (key,value,output,source)
  * Class name = "on_" + field name you want to modify
  * Class method body the statements you want executed when this event is triggered
  *
  */
class on_field_game_p_burst  {

 public static function doWork($key,$value,&$output,$source) {

    //example just overwriting the key "field_propmaster_color3 with new hardcoded value : yellow"
    //echo "Doing work on the games {$key} and ${value}\n<br>";
    //var_dump($source[$key]);
    //$output["tacos"] = [];
  /*
    
    foreach ($source[$key] as $index => $pGameBurst) {
    	//echo "\n<br>Looping {$pGameBurst}\n<br>";
    	if(!isset($pGameBurst["field_game_p_burst_burst"])){
        continue;
      }
    	# code...
    	$images = $pGameBurst["field_game_p_burst_burst"];
    	//var_dump($images);
    	//$output[$key][$index] = array();
    	$image["uri"] = $images[0]["uri"];

    	
    	$output[$key][$index] = $image;
    }

    $output["burstImgs"] = $output[$key];
    unset($output[$key]);
    */

   // array_push($output[$key],$source[$key][0]["field_propmaster_game_game"]);

  }
}
