<?php

namespace WordPressBimserver;

class BimServerApi {

   private $baseUrl;
   private $token;

   function __construct($baseUrl, $token = '') {
      $this->baseUrl = $baseUrl;
      $this->token = $token;
   }

   private function do_post_request( $url, $data ) {
      $ch = curl_init($url);

      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data );
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
              'Content-Length: ' . strlen($data))
      );
      $response = curl_exec($ch);
      if ($response === false) {
         throw new \Exception(curl_error($ch));
      }
      curl_close($ch);
      return $response;
   }

   private function convertJsonDecodeError($error) {
      switch ($error) {
         case JSON_ERROR_NONE:
            return ' - No errors';
            break;
         case JSON_ERROR_DEPTH:
            return ' - Maximum stack depth exceeded';
            break;
         case JSON_ERROR_STATE_MISMATCH:
            return ' - Underflow or the modes mismatch';
            break;
         case JSON_ERROR_CTRL_CHAR:
            return ' - Unexpected control character found';
            break;
         case JSON_ERROR_SYNTAX:
            return ' - Syntax error, malformed JSON';
            break;
         case JSON_ERROR_UTF8:
            return ' - Malformed UTF-8 characters, possibly incorrectly encoded';
            break;
         default:
            return ' - Unknown error';
            break;
      }
   }

   private function call($request) {
      $data = json_encode( $request );
      $resultText = $this->do_post_request($this->baseUrl . "/json", $data);
      $response = json_decode($resultText, true);
      if ($response == NULL) {
         if (function_exists("json_last_error")) {
            throw new \Exception("JSON could not be decoded " . $this->convertJsonDecodeError(json_last_error()));
         } else {
            throw new \Exception("JSON could not be decoded - Unknown error");
         }
      }
      if (array_key_exists("responses", $response)) {
         $responses = $response["responses"];
         if (count($responses) > 0) {
            $firstResponse = $responses[0];
            if (array_key_exists("result", $firstResponse)) {
               return $firstResponse["result"];
            } else if (array_key_exists("exception", $firstResponse)) {
               $exception = $firstResponse["exception"];
               if (array_key_exists("message", $exception)) {
                  throw new \Exception($exception["message"]);
               } else {
                  throw new \Exception("Unknown exception (no message)");
               }
            }
         } else {
            throw new \Exception("No response");
         }
      } elseif( isset( $response, $response['response'], $response['response']['exception'], $response['response']['exception']['message'] ) ) {
         throw new \Exception( $response['response']['exception']['message'] );
      }
      return isset( $response['result'] ) ? $response['result'] : $response;
   }

   private function buildRequest($interface, $method, $parameters) {
      $request = array(
          'request' => array(
            'interface' => $interface,
            'method' => $method,
            'parameters' => $parameters
          )
      );
      if( is_array( $parameters ) && count( $parameters ) == 0 ) {
         $request['request']['parameters'] = new \stdClass();
      } else {
         $request['request']['parameters'] = $parameters;
      }
      if( $this->token != '' ) {
         $request['token'] = $this->token;
      }
      return $request;
   }

   public function processIncoming($data, $handler) {
      $input = json_decode($data, true);
      if ($input == null) {
         if (function_exists("json_last_error")) {
            error_log(json_last_error());
         } else {
            error_log("Unknown JSON error");
         }
      }
      $responseObject = null;
      if (array_key_exists("request", $input)) {
         $request = $input["request"];
         $responseObject["response"] = $this->processRequest($request, $handler);
      } else if (array_key_exists("requests", $input)) {
         $requests = $input["requests"];
         $responses = array();
         foreach ($requests as $key => $request) {
            $responses[] = $this->processRequest($request, $handler);
         }
         $responseObjects["responses"] = $responses;
      }
      $json = json_encode($responseObject);
      if ($json == FALSE) {
         throw new \Exception("Error encoding JSON");
      }
      return $json;
   }

   private function processRequest($request, $handler) {
      $method = $request["method"];
      $reflectionMethod = new \ReflectionMethod(get_class($handler), $method);
      $result = $reflectionMethod->invokeArgs($handler, $request["parameters"]);

      $response = array(
          "result" => $result
      );
      return $response;
   }

   public function getRevision($roid) {
      $request = $this->buildRequest("ServiceInterface", "getRevision", array(
          "roid" => $roid
      ));
      return $this->call($request);
   }

   public function getService($soid) {
      $request = $this->buildRequest("ServiceInterface", "getService", array(
          "soid" => $soid
      ));
      return $this->call($request);
   }

   public function registerProgressOnRevisionTopic($type, $poid, $roid, $description) {
      $request = $this->buildRequest("RegistryInterface", "registerProgressOnRevisionTopic", array(
          "type" => $type,
          "poid" => $poid,
          "roid" => $roid,
          "description" => $description
      ));
      return $this->call($request);
   }

   public function registerProgressTopic($type, $description) {
      $request = $this->buildRequest("RegistryInterface", "registerProgressTopic", array(
          "type" => $type,
          "description" => $description
      ));
      return $this->call($request);
   }

   public function updateProgressTopic($topicId, $state, $title, $start, $end, $progress) {
      $request = $this->buildRequest("RegistryInterface", "updateProgressTopic", array(
          "topicId" => $topicId,
          "state" => array(
              "__type" => "SLongActionState",
              "state" => $state,
              "title" => $title,
              "start" => $start,
              "end" => $end,
              "progress" => $progress
          )
      ));
      return $this->call($request);
   }

   public function unregisterProgressTopic($topicId) {
      $request = $this->buildRequest("RegistryInterface", "unregisterProgressTopic", array(
          "topicId" => $topicId
      ));
      $this->call($request);
   }

   public function getProject($oid) {
      $request = $this->buildRequest("ServiceInterface", "getProjectByPoid", array(
          "poid" => $oid
      ));
      return $this->call($request);
   }

   public function getFile($fileId) {
      $request = $this->buildRequest("ServiceInterface", "getFile", array(
          "fileId" => $fileId
      ));
      return $this->call($request);
   }

   public function getExtendedData($edid) {
      $request = $this->buildRequest("ServiceInterface", "getExtendedData", array(
          "oid" => $edid
      ));
      return $this->call($request);
   }

   public function getUserByUoid($uoid) {
      $request = $this->buildRequest("ServiceInterface", "getUserByUoid", array(
          "uoid" => $uoid
      ));
      return $this->call($request);
   }

   public function getExtendedDataSchemaByNamespace($ns) {
      $request = $this->buildRequest("ServiceInterface", "getExtendedDataSchemaByNamespace", array(
          "namespace" => $ns
      ));
      return $this->call($request);
   }

   public function getExtendedDataSchema($oid) {
      $request = $this->buildRequest("ServiceInterface", "getExtendedDataSchemaById", array(
          "oid" => $oid
      ));
      return $this->call($request);
   }

   public function addExtendedDataToRevision($roid, $title, $data, $schemaId) {
      $request = $this->buildRequest("ServiceInterface", "uploadFile", array(
          "file" => array(
              "__type" => "SFile",
              "data" => base64_encode($data),
              "mime" => "text/html",
              "filename" => "test.html"
          )
      ));
      $fileId = $this->call($request);

      $request = $this->buildRequest("ServiceInterface", "addExtendedDataToRevision", array(
          "roid" => $roid,
          "extendedData" => array(
              "__type" => "SExtendedData",
              "fileId" => $fileId,
              "title" => $title,
              "schemaId" => $schemaId
          )
      ));
      return $this->call($request);
   }

   public function getSuggestedDeserializerForExtension($extension) {
      $request = $this->buildRequest("ServiceInterface", "getSuggestedDeserializerForExtension", array(
          "extension" => $extension
      ));
      return $this->call($request);
   }

   public function checkin($poid, $comment, $filename, $deserializerOid, $filename) {
      $url = $this->baseUrl . "/upload";
      $ch = curl_init($url);

      $fields = array(
          "token" => $this->token,
          "poid" => $poid,
          "comment" => $comment,
          "merge" => false,
          "deserializerOid" => $deserializerOid,
          "file" => "@" . $filename,
          "sync" => "true"
      );

      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $response = curl_exec($ch);
      if ($response == FALSE) {
         throw new \Exception(curl_error($ch));
      }
      curl_close($ch);
   }

   public function getRevisionSummary($roid) {
      $request = $this->buildRequest("ServiceInterface", "getRevisionSummary", array(
          "roid" => $roid
      ));
      return $this->call($request);
   }

   public function getDataObjects($roid) {
      $request = $this->buildRequest("ServiceInterface", "getDataObjects", array(
          "roid" => $roid
      ));
      return $this->call($request);
   }

   /**
    * @return string
    */
   public function getBaseUrl() {
      return $this->baseUrl;
   }

   /**
    * @param string $baseUrl
    */
   public function setBaseUrl( $baseUrl ) {
      $this->baseUrl = $baseUrl;
   }

   /**
    * @return string
    */
   public function isToken() {
      return $this->token;
   }

   /**
    * @param string $token
    */
   public function setToken( $token ) {
      $this->token = $token;
   }

   /**
    * @param string  $interface
    * @param string  $method
    * @param array   $parameters
    *
    * @return array|mixed|object
    * @throws \Exception
    */
   public function apiCall( $interface, $method, $parameters = Array() ) {
      $request = $this->buildRequest( $interface, $method, $parameters );
      return $this->call( $request );
   }
}
?>