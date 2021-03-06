<?php
use CRM_Eventbrite_ExtensionUtil as E;

/**
 * EventbriteQueue.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_eventbrite_queue_create_spec(&$spec) {
  // $spec['some_parameter']['api.required'] = 1;
}

/**
 * EventbriteQueue.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eventbrite_queue_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * EventbriteQueue.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eventbrite_queue_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * EventbriteQueue.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_eventbrite_queue_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 *
 * Populate the queue to reproduce historical data.
 *
 * Queries the Eventbrite Event API to generate lists of events
 * Then calls populateorders for each event
 *
 */
function civicrm_api3_eventbrite_queue_populateevents($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();

  // filter $params for the ones relevant to EB events API
  // https://www.eventbrite.com/platform/api#/reference/event/update/list-events-by-organization
  $allowed_eb_params = array(
    'order_by', // start_asc , start_desc , created_asc , created_desc , name_asc , name_desc
    'status', // draft , live , started , ended , completed , canceled , all
    'venue_filter', // filter by venue ID
    'time_filter', // all, current_future, past
    'page_size', // default is 50
    'page' // page number for pagination
  );
  $eb_params = array_intersect_key($params, array_flip($allowed_eb_params));

  // TODO implement auto paginate to populate

  // fetch list of events from EB API
  $response = $eb->requestOrg('events', NULL, $eb_params);
  $eventsAdded = array();

  foreach ($response['events'] as $event) {
    $event_id = $event['id'];
    $fakeWebhookPayload = [
      "config" =>  ["action" => "event.updated"],
      "api_url" => "https://www.eventbriteapi.com/v3/events/$event_id/"
    ];
    $queueParams = [
      "message" => json_encode($fakeWebhookPayload)
    ];
    $result = _eventbrite_civicrmapi('EventbriteQueue', 'create', $queueParams);

    $eventsAdded[] = $event_id;

    if (!$params['no_orders']) {
      civicrm_api3_eventbrite_queue_populateorders(array("event_id" => $event_id));
    }
  }

  return $eventsAdded;
}

/**
 * Populates a single event specified by event_id
 */
function civicrm_api3_eventbrite_queue_populateevent($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();

  $event_id = $params['event_id'];

  $fakeWebhookPayload = [
    "config" =>  ["action" => "event.updated"],
    "api_url" => "https://www.eventbriteapi.com/v3/events/$event_id/"
  ];
  $queueParams = [
    "message" => json_encode($fakeWebhookPayload)
  ];
  $result = _eventbrite_civicrmapi('EventbriteQueue', 'create', $queueParams);

    if (!$params['no_orders']) {
      civicrm_api3_eventbrite_queue_populateorders(array("event_id" => $event_id));
    }
  return $event_id;
}

/**
 * Populates all orders for event specified by event_id
 */
function civicrm_api3_eventbrite_queue_populateorders($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $event_id = $params['event_id'];
  $response = $eb->request("events/$event_id/orders");

  $ordersAdded = array();
  foreach ($response['orders'] as $order) {
    $order_id = $order['id'];

    $fakeWebhookPayload = [
      "config" =>  ["action" => "order.updated"],
      "api_url" => "https://www.eventbriteapi.com/v3/orders/$order_id/"
    ];
    $queueParams = [
      "message" => json_encode($fakeWebhookPayload)
    ];
    $result = _eventbrite_civicrmapi('EventbriteQueue', 'create', $queueParams);

    $ordersAdded[] = $order_id;
  }

  return $ordersAdded;
}

/**
 * Populates the order specified by order_id
 */
function civicrm_api3_eventbrite_queue_populateorder($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $order_id = $params['order_id'];
  $response = $eb->request("orders/$order_id");

  $fakeWebhookPayload = [
    "config" =>  ["action" => "order.updated"],
    "api_url" => "https://www.eventbriteapi.com/v3/orders/$order_id/"
  ];
  $queueParams = [
    "message" => json_encode($fakeWebhookPayload)
  ];
  $result = _eventbrite_civicrmapi('EventbriteQueue', 'create', $queueParams);

  return $order_id;
}
