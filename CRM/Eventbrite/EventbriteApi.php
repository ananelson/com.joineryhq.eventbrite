<?php

class EventbriteApiError extends Exception {
  public function __construct($eb_payload, $code = 0, Exception $previous = null) {
    $message = $eb_payload['error'] . ": " . $eb_payload['error_description'];
    $code = $eb_payload['status_code'];
    parent::__construct($message, $code, $previous);
  }
}

/**
 * Wrapper around Eventbrite API.
 */
class CRM_Eventbrite_EventbriteApi {

  private static $_singleton;
  private $token;
  const EVENTBRITE_APIv3_URL = 'https://www.eventbriteapi.com/v3';

  /**
   * Constructor.
   * @param string $token Eventbrite private OAuth token.
   */
  private function __construct($token = NULL, $orgId = NULL) {
    if (isset($token)) {
      $this->token = $token;
    }
    else {
      $this->token = _eventbrite_civicrmapi('Setting', 'getvalue', [
        'name' => "eventbrite_api_token",
      ]);
    }

    if (isset($orgId)) {
      $this->orgId = $orgId;
    } else {
      $this->orgId = _eventbrite_civicrmapi('Setting', 'getvalue', [
        'name' => "eventbrite_api_organization_id",
      ]);
    }
  }

  /**
   * Singleton pattern.
   *
   * @see __construct()
   *
   * @param string $token
   * @return object This
   */
  public static function singleton($token = NULL, $orgId = NULL) {
    if (self::$_singleton === NULL) {
      self::$_singleton = new CRM_Eventbrite_EventbriteApi($token, $orgId);
    }
    return self::$_singleton;
  }

  public function requestOptions($body = array(), $method='GET') {
    $options = array(
      'http' => array(
        'method' => $method,
        'header' => "content-type: application/json\r\n",
        'ignore_errors' => TRUE,
      ),
    );

    if ( $method == 'POST' || $method == 'PUT') {
      $options['http']['content'] = json_encode($body);
    }

    return $options;
  }

  public function ebUrl($path, $opts = array(), $expand = array()) {
    $path = '/' . trim($path, '/') . '/';


    $opts['token'] = $this->token;

    if (!empty($expand)) {
      $opts['expand'] = implode(',', $expand);
    }
    $query = http_build_query($opts);

    $url = self::EVENTBRITE_APIv3_URL . $path . '?' . $query;
    print("url is $url");
    return $url;
  }

  public function pathForOrg($path) {
      return "/organizations/$this->orgId/$path";
  }

  public function prepareErrorMessage($url, $method, $body, $message = NULL) {
    $bt = debug_backtrace();
    if (!empty($bt)) {
      $error_location = "{$bt[1]['file']}::{$bt[1]['line']}";
    } else {
      $error_location = "Unknown Location (no backtrace)";
    }

    $messageLines = array(
      "Eventbrite API error: $message",
      "Request URL: $url",
      "Method: $method",
      "Body: " . json_encode($body),
      "API called from: $error_location",
    );
    $messageString = implode("\n", $messageLines);
    return $messageString;
  }

  /*
   * Attempts to log error message to CRM_Eventbrite_BAO_EventbriteLog
   *
   * If not available (e.g. testing), returns the message string instead.
   *
   */
  public function logErrorMessage($url, $method, $body, $message = NULL) {
    $messageString = $this->prepareErrorMessage($url, $method, $body, $message);
    try {
      CRM_Eventbrite_BAO_EventbriteLog::create(array(
        'message' => $messageString,
        'message_type_id' => CRM_Eventbrite_BAO_EventbriteLog::MESSAGE_TYPE_ID_EVENTBRITE_API_ERROR,
      ));
    } catch (PHPUnit_Framework_Error_Notice $e) {
      return $messageString;
    }
  }

  public function handleEventbriteResponse($result, $url = NULL, $method = NULL, $body = NULL) {
    // Log error if $result is null, probably network is unreachable.
    if ($result == NULL) {
      $message = "No response returned. Suspect network connection is down.";
      $this->logErrorMessage($url, $method, $body, $message);
      throw new CRM_Core_Exception("Eventbrite API error: $message");
    }

    // decode the JSON
    $response = json_decode($result, TRUE);

    // log any Eventbrite error
    if ($error = CRM_Utils_Array::value('error', $response)) {
      $message = implode(": ", array(
        CRM_Utils_Array::value('status_code', $response),
        $error,
        CRM_Utils_Array::value('error_description', $response)));
      $this->logErrorMessage($url, $method, $body, $message);
      throw new EventbriteApiError($response);
    }

    return $response;
  }

  /**
   * Perform an HTTP request against the live Eventbrite API.
   *
   * @param string $path Endpoint, sans self::EVENTBRITE_APIv3_URL
   * @param array $body Optional body for POST and PUT requests. Array, will be
   *    json-encoded before sending.
   * @param array $expand Array of 'expand' options for Eventbrite API.
   *    See: https://www.eventbrite.com/platform/api#/introduction/expansions
   * @param string $method HTTP verb: GET, POST, etc.
   * @return array
   */
  public function request($path, $body = array(), $opts = array(), $expand = array(), $method = 'GET') {
    // prepare and send request
    $options = $this->requestOptions($body, $method);
    $url = $this->ebUrl($path, $opts, $expand);
    $context = stream_context_create($options);
    $result = @file_get_contents($url, FALSE, $context);

    return $this->handleEventbriteResponse($result, $url, $method, $body);
  }

  /**
   * Makes an Eventbrite API request, adjusting the provided $path to be at organizations/$orgId/$path
   */
  public function requestOrg($path, $body = array(), $opts = array(), $expand = array(), $method = 'GET') {
    return $this->request($this->pathForOrg($path), $body, $opts, $expand, $method);
  }
}
