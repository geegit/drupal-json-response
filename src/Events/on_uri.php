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
class on_uri  {

 public static function doWork($key,$value,&$output,$source) {

    //example just overwriting the key "field_propmaster_color3 with new hardcoded value : yellow"
    //echo "Doing work on Yo mama! {$key} and ${value}\n<br>";
    $output[$key] = "https://i.cdn.turner.com/v5cache/CARTOON/site/Images/i182/".substr($value,strrpos($value,"/")+1);
    //https://i.cdn.turner.com/v5cache/CARTOON/site/Images/i182/ben17_180x180.png

  }
}
