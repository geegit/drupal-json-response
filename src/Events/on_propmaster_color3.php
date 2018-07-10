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
class on_propmaster_color3  {

 public static function doWork($key,$value,&$output,$source) {

    //example just overwriting the key "field_propmaster_color3 with new hardcoded value : yellow"
    $output[$key] = "yellow";

    
    //Add new key named 'colors' to output user sees
    $output["Property_Color_Palette"] =  array("primary_color"=>$source["propmaster_color1"],"secondary_color"=>$source["propmaster_color2"],"tertiary_color"=>$source["propmaster_color3"]);

    //remove old keys with ugly names
    unset($output["propmaster_color1"]);
    unset($output["propmaster_color2"]);
    unset($output["propmaster_color3"]);


  }
}
