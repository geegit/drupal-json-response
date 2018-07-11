<?php

namespace Drupal\drupal_json_response\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
*
*
* Class CustomRestController.
*
*
* A - Kickoff process to generate object graph of SQL statements::generateSQLPlan() -
* 1.  Read config table to determine all child fields of type 
* 2.  For each child field
*     - unserialize child specs
*     - if child.type != entity or entity_reference it is a plain type
*       --  Generate SQL -> SELECT  "child.field_name" FROM "child.entity_type"
*           --- If user supplied an alias for child.field_name, GENERATE "child.field_name" AS 'alias'
*       --  Generate WHERE -> WHERE node.nid = child.entity_id
*       --  SAVE GENERATED SQL STATEMENT to SQLDEF[key] = value
*       --  RETURN SQLDEF
*     - if child.type is complex (ie. entity or entity_ref) 
*       --  For each child field
*           --- GOTO 3.2
*           --- Save RETURNED child SQLDEFintion to parent $SQLDEF[child.key.name]
* 3.  RETURN content type SQLDEF array (contains all SQL to populate the content type and subcontent)
*
*
* B.  - Kickoff process to iterate over Generated object graph of SQL statements and execute::walkTreeAndRunSQL() -
* 1.  Iterate over  SQLDEFintions 
* 2.  FOR each sqlDef
*     - If sqlDef is top
*       -- RUN/Execute SQL statement for content type
*       -- capture rows returned
*       -- append rows to 'Content' array
*       -- return content array
*     - If sqlDef is for child content
*       --  for each 
*           --- GOTO B.2.1
*           --- Save returned child content array to 'Content[key]' 
* 3.  JSON_ENCODE('Content')
* 4.  Return JSON to Browser
* 
*/
class DrupalJsonResponseController extends ControllerBase {


public $count = 0;


//Contains the content, subcontent relationship graph for the supplied node type
private $tree = array();

public function hook_element_info_alter(array &$info) {
  //var_dump($info);
  // Decrease the default size of textfields.
  if (isset($info['textfield']['#size'])) {
    $info['textfield']['#size'] = 40;
  }
}
/**
*
* Generate SQL statements for the main content type node (or paragraph). Called recursively to generate SQL statements for
* child content types
*/
public function generateSQLPlan($contentTypeName,$nodeType,$parentEntityType,$parentEntityTable,$theParentJoin,$excludeFields,$alias){

  $config = \Drupal::service('config.factory')->getEditable('drupal_json_response.settings');

  $connection = \Drupal::database();

  $parentTable = "node";

  $prefix = '';

  $selectFields = '';

  $whereClause = '';

  $joinTablesArr = array();

  
  $sqlDef = array();

  $sqlDef[$contentTypeName."_sql"] = "node.nid,node.type,";

  $sqlDef[$contentTypeName."_where"] = " LEFT JOIN node_field_data ON node_field_data.nid=node.nid ";

  switch ($nodeType) {
    case 'paragraph':
      # code...
      $prefix = '%field.field.paragraph.';
      break;
    
    default:
      # code...
      $prefix = '%field.field.node.';
      break;
  }

  $tSql = "SELECT name,data FROM config where name like '" . $prefix.$contentTypeName.".%'";

    //echo "<h3>Running SQL ".$tSql."</h3>";

  $records = $this->getQueryResults($tSql);
 
  $field_array = array();



  foreach ($records as $record) {
    $column = unserialize($record["data"]);
     //Now loop over field type array and convert to sql select column and joins if simple type. otherwise recurse.
     // //echo "Looking ". $column["field_name"]. "\n";
    //  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. " node_field_data.title,";
    //  $sqlDef[$contentTypeName."_where"] = $sqlDef[$contentTypeName."_where"]. " LEFT JOIN node_field_data on node_field_data.nid = node.nid";
     // array_push($field_array,unserialize($record->data));
    switch($column["field_type"]){
        //if simple type
        case "image":

          $table = $column["entity_type"]."__".$column["field_name"];

          if(isset($parentEntityTable)){
          $start = $this->tree[$parentEntityTable];

            $theParent = $start["content"];
          }

          $ancestors = array();

          if(isset($theParent)){
            array_push($ancestors,$start["content"]);
          }

          //var_dump($table);

          while(isset($start["previous"]) ){

              //echo "Parent of ".$start["content"]["table"]." is ...<br>\n";

              array_push($ancestors,$start["previous"]);

              $start = array_key_exists($start["previous"]["table"], $this->tree) ? $this->tree[$start["previous"]["table"]] : [];

              //echo "...".$start["content"]["table"]."<br>\n";
          }

          $ancestors = array_reverse($ancestors);


          $jStr = " LEFT JOIN node_field_data ON node_field_data.nid = node.nid ";
          $bTable = "";

          $index = 0;

          foreach ($ancestors as $ancestor) {

              //var_dump($ancestor);
           
              $aTable = $ancestor["table"];

             if($aTable == "node"){
                continue;
              }

              if($index == 0 || $ancestors[$index-1]["table"] == "node"){
                $bTable = "node.nid";
              } else {
                $bTable = $ancestors[$index-1]["table"].".".$ancestors[$index-1]["field_name"]."_target_id";
              }

              $jStr = $jStr ." LEFT JOIN ".$ancestor["table"]. " ON ".$aTable.".entity_id=".$bTable;
              $index++;
          }

          if(isset($theParent["table"])){
            $jStr = $jStr.   " left JOIN ".$table. " ON " .$table.".entity_id=".$theParent["table"].".".$theParent["field_name"]."_target_id".
                " LEFT JOIN file_managed ON ".$table.".".$column["field_name"]."_target_id=file_managed.fid ";

            $sqlDef[$column["field_name"]."_sql"] = $theParent["table"].".entity_id as foreignKeyId,node.nid,node.type, file_managed.uri,file_managed.filename,";
          } else {
              $jStr = $jStr.   " left JOIN ".$table. " ON " .$table.".entity_id=node.nid".
                " LEFT JOIN file_managed ON ".$table.".".$column["field_name"]."_target_id=file_managed.fid ";

            $sqlDef[$column["field_name"]."_sql"] = "node.nid,node.type, node.nid AS foreignKeyId, file_managed.uri,file_managed.filename,";
          }

          //Get table fields
          $schemaRows = $this->getQueryResults("Describe {$table}");

          
          foreach ($schemaRows as $row) {
            $tt = isset($theParent) ? substr($theParent["table"],strpos($theParent["table"],"__")+2) : $table;
            $aliasKey = isset($alias["{$tt}__{$row['Field']}"]) ? $alias["{$tt}__{$row['Field']}"] : null;

            //Only Print non-excluded fields
            if(!isset($excludeFields["{$tt}__{$row['Field']}"])){

              if($aliasKey != null){

                $sqlDef[$column["field_name"]."_sql"] = $sqlDef[$column["field_name"]."_sql"]."{$table}.{$row['Field']} as {$aliasKey},";
              }else {
               
                $sqlDef[$column["field_name"]."_sql"] = $sqlDef[$column["field_name"]."_sql"]."{$table}.{$row['Field']},";
              }
            }
          }

        
        //Paragraph type images are easier to find the specific image
        if($column["entity_type"] == "paragraph"){


           $sqlDef[$column["field_name"]."_where"] = $jStr;
           
        } else if($column["entity_type"] == "node") {
          //echo "\n<br>Ignoring image. ".$parentEntityTable." processed in entity reference revisions\n<br>";

           if(isset($theParent["table"])){
           $sqlDef[$column["field_name"]."_where"] = $jStr;

           $sqlDef[$column["field_name"]."_where"] = $sqlDef[$column["field_name"]."_where"]. " WHERE " .$table.".entity_id IS NOT NULL and ".$theParent["table"].".entity_id IS NOT NULL";
          } else {
             $sqlDef[$column["field_name"]."_where"] = $jStr;

           $sqlDef[$column["field_name"]."_where"] = $sqlDef[$column["field_name"]."_where"]. " WHERE " .$table.".entity_id IS NOT NULL";
          }

         

        } else {
          //echo "<h1>Encountered New Image Type I can't handle : [".$column["field_name"].":".$column["entity_type"]."]</h1>";
        }
        

        break;   
        
        case "entity_reference":

         $handler = $column["settings"]["handler"];
            $table = $column["entity_type"]."__".$column["field_name"];

            $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. " node_field_data.title,node.type,";
          //echo "Reading entity ".$table."<br>\n"; 

             $leaf = array();
            
              if($parentEntityTable && $this->tree[$parentEntityTable] && $parentEntityTable != "node") {
                $leaf["previous"] = $this->tree[$parentEntityTable]["content"];
              } else {
                $leaf["previous"] = array("field_name" => $contentTypeName,"table"=>"node","entity_type"=>null);
              }
              $leaf["content"] = $column;
              $leaf["content"]["table"] = $table;

              $this->tree[$table] = $leaf;

        
     
            //When we encounter a child Paragraph.  Treat as association and get it's child fields 
            //normally by passing the field name to config table via getChildTypes
            
              
            //If we encounter a child type of node, Recurse also but realize we need to start
              //over and walk node tree via config table passing the node type to getChildTypes
                //echo  "<div style='color:red'>I found a different node type ".$column["field_name"]."</div>";
                //echo "Parent Entity ".$parentEntityTable . "<br>";
                if($column["settings"]["handler"] == "default:node"){
                  //echo " Starting All Over  with ".reset($column["settings"]["handler_settings"]["target_bundles"]);
                  $cType = reset($column["settings"]["handler_settings"]["target_bundles"]);

                   //echo "<div style='color:orange'>Count of sqlDef default:node before : ".count($sqlDef)."</div>";
                  //echo "The current field ".$column["field_name"]. " <br>\n";
                  //echo "The new node type ".$cType. "<br>\n";
                  $myRecords = $this->generateSQLPlan($cType,"node",$table.".".$column["field_name"]."_target_id",$table,null,$excludeFields,$alias);
                   //echo "<div style='color:orange'>Count of sqlDef default:node after : ".count($sqlDef)."</div>";
                  //var_dump($myRecords);
                //  var_dump($sqlDef);

                  //$theAlias = $config->get("{$contentTypeName}.alias.{$column["field_name"]}");
                  //$theAlias = $alias[$column["field_name"]];

                  $theLookupKey = substr($parentEntityTable,strpos($parentEntityTable,"__")+2) . "__".$column["field_name"];


                  $theAlias = isset($alias[$theLookupKey]) ?  $alias[$theLookupKey] . ".child" : $column["field_name"].".child"; 

                  //echo "<br>Entity Reference lookup key: $theLookupKey\n<br>"; 
                 
                 if(!isset($excludeFields[$theLookupKey])){ 
                 
                    $sqlDef[$theAlias] = $myRecords;

                  }

                 
                  
                  
                } else {
                  //echo " i don't know what to do with this ".$column["field_name"]." ".$column["settings"]["handler"]."<br>\n";
                }
               
                //var_dump($sqlDef);
        break;   
        case "entity_reference_revisions":

            //This is always a entity_type == "node"
            
            $handler = $column["settings"]["handler"];
            $bundle = reset($column["settings"]["handler_settings"]["target_bundles"]);
            $table = $column["entity_type"]."__".$column["field_name"];
            
             $leaf = array();
            
              if($parentEntityTable && $this->tree[$parentEntityTable]) {
                $leaf["previous"] = $this->tree[$parentEntityTable]["content"];
              } 
              $leaf["content"] = $column;
              $leaf["content"]["table"] = $table;

              $this->tree[$table] = $leaf;



        
            //When we encounter a child Paragraph.  Treat as association and get it's child fields 
            //normally by passing the field name to config table via getChildTypes
            if($handler == "default:paragraph"){
             // echo "The bundle is ".$bundle. "<br>\n";
             //echo "<div style='color:green'>Calling getChildFields() for type ".$column["field_name"]."</div>";
             //echo "<div style='color:orange'>Count of sqlDef default:paragraph before : ".count($sqlDef)."</div>";

            //Look up Config for this cType and set it as current
            //$theAlias = $config->get("{$contentTypeName}.alias.{$column["field_name"]}");

              $theLookupKey = isset($parentEntityTable)? substr($parentEntityTable,strpos($parentEntityTable,"__")+2) . "__".$column["field_name"] :$column["field_name"] ;




              $theAlias = isset($alias[$theLookupKey]) ?  $alias[$theLookupKey] . ".child" : $column["field_name"].".child"; 

             // echo "Lookup config is ".$config->get("$contentTypeName.deletes.$theLookupKey")."<br>\n";

                //$theAlias = isset($alias[$column["field_name"]]) ?  $alias[$column["field_name"]] . ".child" : $column["field_name"].".child";

                //echo "Ref Revisions Alias is $theAlias\n";


              if(!isset($excludeFields[$theLookupKey])){
           
                $sqlDef[$theAlias] = $this->generateSQLPlan($bundle,"paragraph",$table.".".$column["field_name"]."_target_id",$table,null,$excludeFields,$alias);
              }

                
             //echo "<div style='color:orange'>Count of sqlDef default:paragraph after : ".count($sqlDef)."</div>";
            } else {
              echo "Don't know this type ".$handler."for field ".$column["field_name"]." <br>\n";
            }
        break;
        case  "comment":
            $table = $column["entity_type"]."__".$column["field_name"];
             //This is the associative table(s) with st/end date. This is usually a paragraph type
            if($parentEntityType != null && !array_search($parentEntityTable, $excludeFields)){
             
              //echo "The parent for the join is ".$this->tree[$parentEntityTable]["content"]["table"]. "<br>\n";
              $start = $this->tree[$parentEntityTable];

              $theParent = $start["content"];

              $ancestors = array();

             array_push($ancestors,$start["content"]);

              //var_dump($table);
             
                while(isset($start["previous"]) ){

                    //echo "Parent of ".$start["content"]["table"]." is ...<br>\n";
                    //if($start["table"] != "node")
                    array_push($ancestors,$start["previous"]);

                    $start = $this->tree[$start["previous"]["table"]];

                    //echo "...".$start["content"]["table"]."<br>\n";
                }

              $ancestors = array_reverse($ancestors);

              $jStr = " ";
              $bTable = "";

              $index = 0;

              foreach ($ancestors as $ancestor) {

                 // var_dump($ancestor["table"]);
               
                  $aTable = $ancestor["table"];

                  if($aTable == "node"){
                    continue;
                  }

                  if($index == 0 || $ancestors[$index-1]["table"]  == "node"){

                    $bTable = "node.nid";
                  } else {
                    $bTable = $ancestors[$index-1]["table"].".".$ancestors[$index-1]["field_name"]."_target_id";
                  }

                  if(!strpos($sqlDef[$contentTypeName. "_where"],"LEFT JOIN ".$aTable) )
                  {
                    
                    $jStr = $jStr ." LEFT JOIN ".$aTable. " ON ".$aTable.".entity_id=".$bTable;
                  }
                  $index++;
              }

                   $sqlDef[$contentTypeName. "_where"] = $sqlDef[$contentTypeName. "_where"].
                   $jStr.
              " leFt JOIN ".$table. " ON " .$parentEntityType."=" .$table. ".entity_id";

                //filter or search for target id join field
              if(!array_search($column["field_name"], $excludeFields) ){
                $lookupKey = str_replace(".","__",$parentEntityType);

                $lookupKey = substr($lookupKey,strpos($lookupKey,"__")+2);

                //echo "Look {$lookupKey}\n<br>";

                $aliasKey = isset($alias[$lookupKey]) ? $alias[$lookupKey] : null; 

                if($aliasKey){
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. $parentEntityType." AS {$aliasKey},".$parentEntityTable.".entity_id AS foreignKeyId,";
                } else {
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. $parentEntityType.",".$parentEntityTable.".entity_id AS foreignKeyId,";
                }
              }
 
            //This is a regular table(s) with _value columns
            }else {
             // echo "Regular table ".$table . " booyah\n";
              $sqlDef[$contentTypeName. "_where"] = $sqlDef[$contentTypeName. "_where"]." LEFT JOIN ".$table. " ON " .$parentTable.".nid=" .$table. ".entity_id";

              $commentStr = " LEFT JOIN comment_field_data ON comment_field_data.entity_id = {$table}.entity_id" 
              . " LEFT JOIN comment ON comment.cid = comment_field_data.cid "
              . " LEFT JOIN comment__comment_body ON comment__comment_body.entity_id = comment_field_data.cid ";

              $sqlDef[$contentTypeName. "_where"] = $sqlDef[$contentTypeName. "_where"].$commentStr;

            }



             $lookupKey = $column["field_name"];

             $tt = isset($theParent) ? substr($theParent["table"],strpos($theParent["table"],"__")+2) : null;
               
              if($tt){
                  $lookupKey = "{$tt}__{$column["field_name"]}";
              }
            //Whew, finally found the simple column
            //Append SQL columns for this field which just has _value appended to it.
            //Drupal, drupal, drupal, smh

            //If this is a regular field AND NOT 'text_with_summary' (body) or 'comment' field
             if(!array_search($column["field_name"], $excludeFields) ){
                $aliasKey = isset($alias[$lookupKey]) ? $alias[$lookupKey] : null;
            //  var_dump($alias);
             // var_dump($column["field_name"]);
               // var_dump($alias);

                if($aliasKey != null){
                 //   echo " Boo yah ".$column["field_name"]." =>".$alias[$aliasKey]."\n<br>";
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. "".$column["field_name"] ."_body_value AS ".$aliasKey.",";
                } else {

                $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. "".$column["field_name"] ."_body_value AS ".$column["field_name"].",";
                }
            }


            
        break;
        default:
             $table = $column["entity_type"]."__".$column["field_name"];
             //This is the associative table(s) with st/end date. This is usually a paragraph type
            if($parentEntityType != null && !array_search($parentEntityTable, $excludeFields)){
             
              //echo "The parent for the join is ".$this->tree[$parentEntityTable]["content"]["table"]. "<br>\n";
              $start = $this->tree[$parentEntityTable];

              $theParent = $start["content"];

              $ancestors = array();

              array_push($ancestors,$start["content"]);
             
              while(isset($start["previous"])){
                $tmpT = $start["previous"]["table"];
                //echo "$tmpT \n<br>";
                  //echo "Parent of ".$start["content"]["table"]." is ...<br>\n";
                  //if($start["table"] != "node")
                  array_push($ancestors,$start["previous"]);
                  if(array_key_exists($tmpT, $this->tree)){
                    $start = $this->tree[$tmpT];
                  } else {
                    $start = [];
                  }
                  //echo "...".$start["content"]["table"]."<br>\n";
              }

              $ancestors = array_reverse($ancestors);

              $jStr = " ";
              $bTable = "";

              $index = 0;

              foreach ($ancestors as $ancestor) {

                 // var_dump($ancestor["table"]);
               
                  $aTable = $ancestor["table"];

                  if($aTable == "node"){
                    continue;
                  }

                  if($index == 0 || $ancestors[$index-1]["table"]  == "node"){

                    $bTable = "node.nid";
                  } else {
                    $bTable = $ancestors[$index-1]["table"].".".$ancestors[$index-1]["field_name"]."_target_id";
                  }

                  if(!strpos($sqlDef[$contentTypeName. "_where"],"LEFT JOIN ".$aTable) )
                  {
                    
                    $jStr = $jStr ." LEFT JOIN ".$aTable. " ON ".$aTable.".entity_id=".$bTable;
                  }
                  $index++;
              }

                   $sqlDef[$contentTypeName. "_where"] = $sqlDef[$contentTypeName. "_where"].
                   $jStr.
              " leFt JOIN ".$table. " ON " .$parentEntityType."=" .$table. ".entity_id";

                //filter or search for target id join field
              if(!array_search($column["field_name"], $excludeFields) ){
                $lookupKey = str_replace(".","__",$parentEntityType);

                $lookupKey = substr($lookupKey,strpos($lookupKey,"__")+2);

                //echo "Look {$lookupKey}\n<br>";

                $aliasKey = isset($alias[$lookupKey]) ? $alias[$lookupKey] : null; 

                if($aliasKey){
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. $parentEntityType." AS {$aliasKey},".$parentEntityTable.".entity_id AS foreignKeyId,";
                } else {
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. $parentEntityType.",".$parentEntityTable.".entity_id AS foreignKeyId,";
                }
              }
 
            //This is a regular table(s) with _value columns
            }else {
             // echo "Regular table ".$table . " booyah\n";
              $sqlDef[$contentTypeName. "_where"] = $sqlDef[$contentTypeName. "_where"]." LEFT JOIN ".$table. " ON " .$parentTable.".nid=" .$table. ".entity_id"; 
            }

            

             $lookupKey = $column["field_name"];

             $tt = isset($theParent) ? substr($theParent["table"],strpos($theParent["table"],"__")+2) : null;
               
              if($tt){
                  $lookupKey = "{$tt}__{$column["field_name"]}";
              }
            //Whew, finally found the simple column
            //Append SQL columns for this field which just has _value appended to it.
            //Drupal, drupal, drupal, smh

            //If this is a regular field AND NOT 'text_with_summary' (body) or 'comment' field
             if(!array_search($lookupKey, $excludeFields) ){
                $aliasKey = isset($alias[$lookupKey]) ? $alias[$lookupKey] : null;
            //  var_dump($alias);
             // var_dump($column["field_name"]);
               // var_dump($alias);

                if($aliasKey != null){
                 //   echo " Boo yah ".$column["field_name"]." =>".$alias[$aliasKey]."\n<br>";
                  $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. "".$column["field_name"] ."_value AS ".$aliasKey.",";
                } else {

                $sqlDef[$contentTypeName."_sql"] = $sqlDef[$contentTypeName."_sql"]. "".$column["field_name"] ."_value AS ".$column["field_name"].",";
                }
            }

            // $table = $column["entity_type"]."__".$column["field_name"];
           

           

            break;
            
    } 

  }  


  return $sqlDef;

}

 /*
 *  Add Results of Query to Returned Array.  Array will become JSON object
 *
 */
 private function getQueryResults($queryStr){

    $config = \Drupal::service('config.factory')->getEditable('drupal_json_response.settings');

    $connection = \Drupal::database();

    $query = $connection->query($queryStr);

    $records = $query->fetchAll();

    $ret = array();


    foreach ($records as $key => $value) {
      if($config->get('debugSQL') == 1){
        $value->sql = $queryStr;
      }

      array_push($ret,(array)$value);
    }

    return $ret;

 } 

 /* Recursively create empty child objects on node if the path does not exist */
 public function createPath(&$node,$keys){ 
     $key = explode("]",$keys);
     $path = "";   
     foreach($key as $k){
      $k = $k."]";
       if($k != "]"){
          $path = $path . $k;
           //echo "path is ".$path."<br>\n";
           $tool = eval("return isset(\$node{$path});");
         if(!$tool){
           //echo "Tool ".$path." does not exist<br>\n";
           eval("\$node{$path} = array();");
         }
       }
     }
   }

 //http://thehighcastle.com/blog/38/php-dynamic-access-array-elements-arbitrary-nesting-depth


 /* Recursively walk SQL hiearchical array starting at node and populate with query results
 */
 private function walkTreeAndRunSQL($node,$saveUnderkey,$filters,$path,$destinationMatchLookupKey,$limit){

      $dest = [];

      $foundSelect = false;
      $foundJoin = false;
      $theSelectFields = "";
      $theJoinFields = "";
      $dKey = null;
      //iterate keys of $sqlDef looking for pair of _sql and _where clauses to execute
      foreach (array_keys($node) as $key) {
        //If this is a child object, recurse
      
        if(strpos($key,".")){
          $childKeyName = substr($key, 0, strpos($key,"."));
         
          $path =  "[\"".$childKeyName."\"]";

              //$dest[$childKeyName] = array_merge($dest[$childKeyName],(array)$records);
          if(isset($destinationMatchLookupKey)){
              
            
              if(isset($dest["nid"])){
                $this->createPath($dest,"[\"".$destinationMatchLookupKey."\"]".$path);
              }
              
          } else{
            $this->createPath($dest,$path);
          }

          //echo "<br>The child key name is ".$childKeyName." AND KEY IS ".$key."<br>";
          //$dest[$saveUnderkey][$childKeyName]  
          $records = $this->walkTreeAndRunSQL($node[$key],$childKeyName,$filters,$path,$childKeyName,$limit);
       
          //This is a child so merge with parent
      
          

          foreach ($records as $key => $value) {
            
              if(!isset($destinationMatchLookupKey)){
                  //Hide children that don't have a value.  usually this means bundle attribute not set
                  //but we don't want to accidentally hide children that don't have this attribute at all
                  if(array_key_exists("bundle", $value)){  
                      if($value["bundle"]){
                        array_push($dest[$childKeyName],$value);
                      }
                  } else {
                    array_push($dest[$childKeyName],$value);
                  }
                  
              } else {
                  $config = \Drupal::service('config.factory')->getEditable('drupal_json_response.settings');
                  $crazyKey = $value["type"].".alias.".$destinationMatchLookupKey."__".$destinationMatchLookupKey."_target_id";
                  //echo "Looking for $crazyKey";
                  $alias = $config->get($crazyKey);
              if($alias){
               // echo $alias;
                $dKey = $alias;
              } else {
                   $dKey =  $destinationMatchLookupKey."_target_id";
                 //  echo " CONTROLS IS ".$dKey."\n<br>";
              }
                //do lookup
                  //echo "Subcontent Looking for ".$destinationMatchLookupKey."_target_id"."\n";
                  //array_push($dest[$destinationMatchLookupKey][$childKeyName],$value);
                //str_replace("field_", "", $destinationMatchLookupKey."_target_id")
                  $parents = array_column($dest,  $dKey);
                   //var_dump($parents);
                  if($value["foreignKeyId"] != null){
                       $found = array_search($value["foreignKeyId"], $parents);
                    if(!isset($dest[$found][$childKeyName])){
                      $dest[$found][$childKeyName] = array();
                    }
                    
                    array_push($dest[$found][$childKeyName], (array)$value);

                  }
               
              }
              
          }
          //Child array populated now issue callback
          //echo "Done populating ".$childKeyName . " on object ".$destinationMatchLookupKey. "<br>\n";


          //rewind path
          $path = substr($path,0,strpos($path,"[".$childKeyName));


          //var_dump($node[$childKeyName]);
        } else {
          //Simple Field, just copy sql def to sql string
         
          if(strpos($key,"_sql") > -1){
            $theSelectFields = $node[$key];
            $foundSelect = true;

          } else if(strpos($key,"_where") > -1){
            $theJoinFields = $node[$key];
            $foundJoin = true;
            
          }
        }

        if($foundSelect && $foundJoin){
          $foundSelect = false;
          $foundJoin = false;

          //TODO try catch
          $selectStatement = "SELECT ".rtrim($theSelectFields,",").",'".$path."' as path FROM node ".$theJoinFields;

          $whereClause = strpos($theJoinFields,"WHERE") ? "": " where 1=1 ";

            //apply filters
            foreach($filters as $filter){
              //make sure table name from filter is available in list of joined tables
              if(strpos($filter,".")){
                  $filterTable = substr($filter, 0, strpos($filter,"."));
                  if(strpos($theJoinFields,$filterTable)){
                   // echo "Found Filter for ".$filterTable;
                    $whereClause = $whereClause . " AND ".$filter;
                  }
                  //strpos($theSelectFields,)
              } else{
                echo "Error reading filter in wrong format: expect {table}.{field_name} as key. Instead, saw :[".$filter."]";
              }
            }

            if($limit){
              //used for config screen only
              $whereClause = $whereClause. " LIMIT 1";
            }
          // echo $sql;

            $dest = $this->getQueryResults($selectStatement.$whereClause);

            //$dest["sql"] = ["a"];
            //var_dump($dest)
            //array_push($dest["sql"],["a"]); 
            //= [$selectStatement.$whereClause];
            
        }
      }



      return $dest;
 }



 public function decorate($src, &$output){

    $noprint = ["field_game_p_controls_controlz"=>"1","nid"=>"1","path"=>"1","fforeignKeyId"=>"1","entity_id"=>"1","revision_id"=>"1","deleted"=>"1","langcode"=>"1","delta"=>"1","bundle"=>"1"];
  //var_dump($src);

    foreach($src as $k => $v){

      if(array_key_exists($k, $noprint)){
        
        continue;
      }

      if(!is_array($v)){
      
        $output[$k] = $src[$k];        
   
        if(class_exists("\Drupal\drupal_json_response\Events\on_{$k}")){
           $func = array("\Drupal\drupal_json_response\Events\on_{$k}", "doWork");

          $func($k,$v,$output,$src);
        } else {
          
          //echo " Class on_{$k} does not exist<br>\n";
        }
        
      } else {
        $output[$k] = [];

        if(!$this->is_assoc($v)){

          foreach ($v as $key => $value) {
            # code...
             $output[$k][$key] = array();
             $this->decorate($value,$output[$k][$key]);
          }
          //Allow user to callback on entire List
          if(class_exists("\Drupal\drupal_json_response\Events\on_{$k}")){
             $func = array("\Drupal\drupal_json_response\Events\on_{$k}", "doWork");

            $func($k,$v,$output,$src);
          }
        } else {
          unset($output[$k]);
          if(class_exists("\Drupal\drupal_json_response\Events\on_{$k}")){
             $func = array("\Drupal\drupal_json_response\Events\on_{$k}", "doWork");

            $func($k,$v,$output,$src);
          }
          $this->decorate($v,$output);
        }
       
       
      }
    }

 }


 /**
 * Return the 10 most recently updated nodes in a formatted JSON response.
 *
 * @return \Symfony\Component\HttpFoundation\JsonResponse
 * The formatted JSON response.
 */
 public function getLatestNodes($contentType,$contentName) {

  $request =  \Drupal::request();

  $config = \Drupal::service('config.factory')->getEditable('drupal_json_response.settings');

  // Set and save new message value.
  //$config->set('message', 'Hi')->save();

  // Now will print 'Hi'.
  $ex = $config->get('exclude_fields');

    //echo "Content Name is ".$contentName;
  //Setup some default AND clauses that will be appended to SQL to filter results 
  //page content types are actually articles in drupal.  So forcing this so rows are returned in json.
  $filterType = $contentType == "page" ? "article" : $contentType;
  $theFilters = array("node.type"=>"node.type ='".$filterType."'");
  if(isset($contentName) && $contentName != ""){
    $theFilters = array("node_field_data"=> "node_field_data.title like '%".$contentName."%'");
  }

  //Read any additional Top Level filters from User request parameters
  if($request->query->get('filters') != ''){
    $tmp = $request->query->get('filters');
    foreach($tmp as $k=>$v){
      $theFilters["node__".$k] = "node__".$k.".".$k."_value='".$v."'";
    }
  }

  $excludeFields = $config->get("$contentType.deletes");
  //array("comment"=>"comment","field_cn_game_cnid"=>"field_cn_game_cnid","field_pgrph_m_intro_end_date"=>"field_pgrph_m_intro_end_date","field_pgrph_fmbl_gm_end_date"=>"field_pgrph_fmbl_gm_end_date","field_pm_cnvd_char_grpng_1358x10"=>"field_pm_cnvd_char_grpng_1358x10","field_pm_cnvd_ads_show_id"=>"field_pm_cnvd_ads_show_id","field_page_url"=>"field_page_url","field_pm_cnvd_featured_message"=>"field_pm_cnvd_featured_message");

  //$alias = array("p250tId"=>"field_game_p_250x225_image_target_id","coke"=>$ex,"collection"=>"field_pm_cnvd_ovrit_collec_name","collapsable"=>"field_pm_cnvd_collapsable");

  $alias = $config->get($contentType.".alias");

  //1. Read Drupal Config tables and organize content into content type / subcontent type tree that we can walk
  //2. While creating tree, create SQL statements can be used to populate tree
  $sqlDef = $this->generateSQLPlan($contentType,'',null,null,null,$excludeFields,$alias);

  //var_dump($sqlDef);

  //3. Walk tree for root content and then walk remaining child content types and execute SQL to populate tree
  $rootArray = array($contentType."_sql"=>$sqlDef[$contentType."_sql"],$contentType."_where"=>$sqlDef[$contentType."_where"]);

  $rootRecords = $this->walkTreeAndRunSQL($rootArray,$contentType,$theFilters,null,null,false);

  unset($sqlDef[$contentType."_sql"]);

  unset($sqlDef[$contentType."_where"]);

  $i = 0;

  $outp = [];

  foreach($rootRecords as $k){
    //$theNodeIds = "node.id = ".$k[$v]["node.id"];
    
    
    if(isset($k["nid"])){


     
       //Add Filter to return only this nodes children
       $theFilters["node.nid"] = "node.nid = ".$k["nid"];
       
       $objs = (array)$this->walkTreeAndRunSQL($sqlDef,$contentType,$theFilters,null,null,false);
       //echo " Count is $objs ". count($objs). " <br>\n";
       unset($objs["path"]);
     
      $rootRecords[$i] = array_merge((array)$rootRecords[$i],$objs);
     //  var_dump($objs);
      //Add any user specified decorations
      $this->decorate($rootRecords[$i], $outp[$i],null);
       $i = $i + 1;

    }
   
  }


  
// $myObj = $rootArray;
  $myObj = ["$contentType"=>$outp];
  //$myObj = $this->tree;

/*
  $sqlDef[$contentType] = $this->getQueryResults($fieldQuery);

  unset($sqlDef[$contentType."_sql"]);
  unset($sqlDef[$contentType."_where"]);*/
  
  
  //echo "SELECT " .  rtrim($sqlDef[$contentTypeName."_sql"],",\n") . " FROM NODE " . $sqlDef[$column["field_name"]."_where"];

  //unset($sqlDef[$contentType."_sql"]);
  //unset($sqlDef[$contentType."_where"]);
  //var_dump($sqlDef);

  $response_array[] = [];

/*
  
   // Load the 10 most recently updated nodes and build an array of titles to be
   // returned in the JSON response.
   // NOTE: Entity Queries will automatically honor site content permissions when
   // determining whether or not to return nodes. If this is not desired, adding
   // accessCheck(FALSE) to the query will bypass these permissions checks.
   // USE WITH CAUTION.
   $node_query = $this->entityQuery->get('node')
     ->condition('status', 1)
     ->sort('changed', 'DESC')
     ->range(0, 10)
     ->execute();
   if ($node_query) {
     $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($node_query);
     foreach ($nodes as $node) {
       $response_array[] = [
         'mytitle' => $node->title->value
       ];
     }
   }
   else {
     // Set the default response to be returned if no results can be found.
     $response_array = ['message' => 'No new nodes.'];
   }
*/
   // Add the node_list cache tag so the endpoint results will update when nodes are
   // updated.
   //$cache_metadata = new CacheableMetadata();
   //$cache_metadata->setCacheTags(['drupal_json_response_stuff']);

   // Create the JSON response object and add the cache metadata.
  // $response = new CacheableJsonResponse($sqlDef);
   //$response->addCacheableDependency($cache_metadata);

   $response = new Response();
   $response->setContent(json_encode($myObj,JSON_UNESCAPED_UNICODE));
   //$response = new CacheableJsonResponse(json_encode($myObj,JSON_UNESCAPED_UNICODE));
   $response->headers->set('Content-type','application/json');

   return $response;
 }

 /**
   * Displays the path administration overview page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array as expected by drupal_render().
   */
  public function adminOverview(Request $request) {

    $i = 0;

    $nodeTypeRequest = $request->get('nodetype');

    //Get Aliases
    $config = \Drupal::service('config.factory')->getEditable('drupal_json_response.settings');

    if($request->get('save')){
     // var_dump($request->request->all());
      if($request->get('debugSQL')){
        $config->set('debugSQL',$request->get('debugSQL'));
      } else {
        $config->clear('debugSQL');
      }

      $config->clear("$nodeTypeRequest.deletes");

      foreach ($request->request->all() as $key => $value) {
        if(strpos($key,"__delete") > -1){
          $simpleKey = substr($key, strpos($key,"__delete")+9);
          $config->set("$nodeTypeRequest.deletes.$simpleKey",$simpleKey);
        } else {
          $config->set("$nodeTypeRequest.alias.$key",$value);
        }
      }

      $config->save();

      drupal_set_message(t("Successfully saved alias for {$nodeTypeRequest}"), 'status', TRUE);
    }



    $contentTypeAttributeType = null;

    if($nodeTypeRequest){
      $contentTypeAttributeType = $this->generateSQLPlan($nodeTypeRequest,'',null,null,null,[],[]);
      //3. Walk tree for root content and then walk remaining child content types and execute SQL to populate tree
      $rootArray = array($nodeTypeRequest."_sql"=>$contentTypeAttributeType[$nodeTypeRequest."_sql"],$nodeTypeRequest."_where"=>$contentTypeAttributeType[$nodeTypeRequest."_where"]);

      $rootRecords = $this->walkTreeAndRunSQL($rootArray,$nodeTypeRequest,[],null,null,true);

      unset($contentTypeAttributeType[$nodeTypeRequest."_sql"]);

      unset($contentTypeAttributeType[$nodeTypeRequest."_where"]);



      foreach($rootRecords as $k){
        //$theNodeIds = "node.id = ".$k[$v]["node.id"];
        unset($rootRecords["path"]);
        
        if(isset($k["nid"])){
         
           //Add Filter to return only this nodes children
           $theFilters["node.nid"] = "node.nid = ".$k["nid"];
           
           $objs = (array)$this->walkTreeAndRunSQL($contentTypeAttributeType,$nodeTypeRequest,[],null,null,true);
           //echo " Count is $objs ". count($objs). " <br>\n";
           unset($objs["path"]);
          
         
          $rootRecords[$i] = array_merge($rootRecords[$i],$objs);
          
         //  var_dump($objs);
          //Add any user specified decorations
         // $this->decorate($rootRecords[$i], $outp[$i],null);
           $i = $i + 1;

        }
       
      }

      

      //$instanceObj = $this->walkTreeAndRunSQL($contentTypeAttributeType,$nodeTypeRequest,[],null,null,true);

      //$instanceObj = ["bob"=>"town","scott"=>["id"=>"larock"]];
    }
    //instanceObj[0];
   // var_dump($keys);
    // Add the filter form above the overview table.
    //$build['path_admin_filter_form'] = [];
    $build = ['#type'=>'form', '#form_id'=>'alias_form'];
   
    $header = [];
    $header[] = ['data' => array('data'=>array('#type'=>'checkbox','#attributes' => array('size'=>1, 'name'=>"_group_".$nodeTypeRequest."__","delete_key"=>"__delete_"),'#value'=>"0",'#title'=>'Hide All'))];
    $header[] = ['data' => $this->t('Alias'), 'field' => 'alias'];
    $header[] = ['data' => $this->t('System Field'), 'field' => 'source'];
    $header[] = ['data' => $this->t('Example Data'), 'field' => 'example'];
    //$header[] = $this->t('Operations');

    $rows = [];
    $destination = $this->getDestinationArray();

    if(isset($rootRecords)){
      foreach ($rootRecords as $member) {
          //var_dump($nodeTypeRequest);

         $this->walkItLikeITalkIt($member,$rows,$config,$nodeTypeRequest,"");
       }
    }

    $results = $this->getQueryResults("Select name from config where name like '%node.type%'");

    $types = ["none"=>"Choose A Node Type"];

    foreach ($results as $row) {

        $nodeName = substr($row["name"],strrpos($row["name"],".")+1);

       $types[$nodeName] = t($nodeName);
    }

    //var_dump($types);

    $form['type_options'] = [
      '#type' => 'value',
      '#value' => $types
    ];

    $build['debugSQL'] = 
    [
      "#title" => 'Turn on SQL Debugging?',
      "#type" => 'checkbox',
      "#name" => 'debugSQL',
      "#attributes" =>  $config->get('debugSQL') == "1" ? array('checked' =>  'checked' ) : []
    ];

  

    $build['type_select'] = [
      '#title' => 'Choose the Node Type',
      '#name' => 'nodetype',
      '#type' => 'select',
      '#description_display' => 'Select the node type you wish to override',
      '#options' => $form['type_options']['#value'],
      '#value' => $nodeTypeRequest
    ];

    $build['actions'] = [
        '#type' => 'actions'
    ];

    $build['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load'),

    ];

    $build['path_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No Field aliases available. <a href=":link">Add Field alias</a>.', [':link' => $this->url('drupal_json_response.admin_overview')]),
      
    ];
    $build['path_table']['actions']['save'] =  ['#type'=>'submit','#name'=>'save','#value'=>$this->t('Save Changes')];

    $build['path_pager'] = ['#type' => 'pager'];

    //https://www.drupal.org/docs/8/creating-custom-modules/adding-stylesheets-css-and-javascript-js-to-a-drupal-8-module

    $build['#attached'] = array(
            'library' =>  array(
                'drupal_json_response/drupal_json_response'
            ),
        );

    return $build;
  }

  public function is_assoc($array){
    return array_values($array)!==$array;
  }


  public function walkItLikeITalkIt($node, &$rows,$config,$nodeTypeRequest,$nodeParent){
        ;
        //echo "Key is ".$nodeParent;
        // @todo Should Path module store leading slashes? See
        //   https://www.drupal.org/node/2430593.

        //var_dump($node);
        $noprint = ["nid"=>"1","path"=>"1","foreignKeyId"=>"1","title"=>"1"];

        //echo "Found a key ".$key;

        foreach ($node as $key => $data) {
        
            if(array_key_exists($key, $noprint)){
              
              continue;
            }

           $theAlias = strpos($key,"_") ? substr($key,strpos($key,"_")+1) : $key;

          // echo "The alias is {$theAlias} and the key ${key}\n";

          if( is_array($data)){
            
            if(!$this->is_assoc($data)){
            
               $lookupKey = $nodeParent != "" ? $nodeParent . "__" . $key : $key;

            //see if we have override from file
            $theOverride = $config->get($nodeTypeRequest.".alias.".$lookupKey);

            $theAlias = $theOverride ? $theOverride : $theAlias;

            $row['data']['hide'] = array('data'=>array('#type'=>'checkbox','#attributes' => array('size'=>5, 'name'=>"__delete_".$lookupKey),'#value'=>$theAlias,'#title'=>'','#title_display'=>'hidden'));

            $config->get("$nodeTypeRequest.deletes.$lookupKey") != null ? $row['data']['hide']['data']['#attributes']['checked'] = 'checked' : [];


            $row['data']['alias'] = array('data'=>array('#type'=>'textfield','#attributes' => array( 'size' => 30, 'name'=>$lookupKey),'#value'=>$theAlias,'#title'=>'','#title_display'=>'hidden'));
            $row['data']['source'] = $key;
            $row['data']['example'] = "";

          
            $rows[] = $row;
              
              $crows = [];

             

              $subTable = ['#type'=>'table','#attributes'=>["style"=>"text-align:center"],'#header'=>[array('data'=>array('#type'=>'checkbox','#attributes' => array('size'=>1, 'name'=>"_group_".$key."__","delete_key"=>"__delete_$key"),'#value'=>"0",'#title'=>'Hide All')),"Child Alias->$key","Child Field->$key"],"#rows"=>[]];
           
               foreach ($data as $ckey => $child) {  
                $this->walkItLikeITalkIt($child,$crows,$config,$nodeTypeRequest,"{$key}");
                //var_dump($crows);
                foreach ($crows as $crow) {

                  $lookupKey = $crow["data"]["source"];

                  $srow = ["data"=>
                    ["hide"=>
                        array('data'=>array('#type'=>'checkbox','#attributes' => array('size'=>5, 'name'=>"__delete_".$crow["data"]["source"]),'#value'=>$crow["data"]["source"],'#title'=>'','#title_display'=>'hidden')),
                      "c1"=>$crow["data"]["alias"],
                      "c2"=>$crow["data"]["source"]
                    ]
                  ];

                  if($config->get("$nodeTypeRequest.deletes.$lookupKey") != null){
                    $srow['data']['hide']['data']['#attributes']['checked'] = 'checked';
                  } 

                  $subTable["#rows"][] = $srow;
              
                }
              }
              $row['data']['hide'] = '';
              $row['data']['alias'] = ['data'=>$subTable];
              $row['data']['source'] = "";
              $row['data']['example'] = [];
              $rows[] = $row;
       
            } else {
              echo "Found associative array\n<br>";
              $this->walkItLikeITalkIt($data,$rows,$config,$nodeTypeRequest,"{$key}");

            }
            

          } else if($key != "sql"){

           
            $lookupKey = $nodeParent != "" ? $nodeParent . "__" . $key : $key;

            //see if we have override from file
            $theOverride = $config->get($nodeTypeRequest.".alias.".$lookupKey);

            $theAlias = $theOverride ? $theOverride : $theAlias;
             $row['data']['hide'] = array('data'=>array('#type'=>'checkbox','#attributes' => array( 'name'=>"__delete_".$lookupKey),'#value'=>$theAlias,'#title'=>'','#title_display'=>'hidden'));

             if($config->get("$nodeTypeRequest.deletes.$lookupKey") != null){
                $row['data']['hide']['data']['#attributes']['checked'] = 'checked';
             } 
             
            $row['data']['alias'] = array('data'=>array('#type'=>'textfield','#attributes' => array( 'size' => 30, 'name'=>$lookupKey),'#value'=>$theAlias,'#title'=>'','#title_display'=>'hidden'));
            $row['data']['source'] = $lookupKey;
            $row['data']['example'] = $data;


            $operations = [];
            $operations['edit'] = [
              'title' => $this->t('Edit'),
              'url' => $key,
            ];
            $operations['delete'] = [
              'title' => $this->t('Delete'),
              'url' => $key,
            ];
     
            $rows[] = $row;
          }
        }
      
  }

}