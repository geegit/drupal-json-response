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
class on_field_cn_dm_p_curl  {

 public function doWork($key,$value,&$output,$source) {

    //example just overwriting the key "field_propmaster_color3 with new hardcoded value : yellow"
    //echo "Doing work on Yo mama! {$key} and ${value}\n<br>";
    $output[$key] = "theymomma.jpg";

  }
}
