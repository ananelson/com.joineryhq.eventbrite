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
 * Populate the queue with to reproduce historical data.
 *
 * Queries the Eventbrite Event API to generate lists of events
 * Then calls populateorders for each event
 *
 */
function civicrm_api3_eventbrite_queue_populateevents($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();

  // filter $params for the ones relevant to EB events API
  $allowed_eb_params = array(
    'order_by', // this param DOES NOTHING grrrr eventbrite
    'status',
    'time_filter',
    'page' // this is the best one to use
  );
  $eb_params = array_intersect_key($params, array_flip($allowed_eb_params));

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

    // TODO add a populate_orders param to make this optional
    civicrm_api3_eventbrite_queue_populateorders(array("event_id" => $event_id));
  }

  return $eventsAdded;
}

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
  return $event_id;
}

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


function civicrm_api3_eventbrite_queue_dumporder($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $order_id = $params['order_id'];
  $response = $eb->request("orders/$order_id", NULL, NULL, array('attendees'));
  return $response;
}

function civicrm_api3_eventbrite_queue_dumpattendee($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $event_id = $params['event_id'];
  $attendee_id = $params['attendee_id'];
  $response = $eb->request("events/$event_id/attendees/$attendee_id", NULL, NULL, array('answers', 'promotional_code'));
  return $response;
}

function civicrm_api3_eventbrite_queue_dumpdiscount($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $event_id = $params['event_id'];
  $discount_id = $params['discount_id'];
  $response = $eb->request("events/$event_id/discounts/$discount_id");
  return $response;
}

function civicrm_api3_eventbrite_queue_dumptc($params) {
  $eb = CRM_Eventbrite_EventbriteApi::singleton();
  $event_id = $params['event_id'];
  $ticket_class_id = $params['tc_id'];
  $response = $eb->request("events/$event_id/ticket_classes/$ticket_class_id");
  return $response;
}


const CLASS_CODE_FIELD = 'custom_4';
const EB_EVENT_ID_FIELD = 'custom_5';
const CODE_FIELD = 'custom_157';
const EB_DISCOUNT_ID_FIELD = 'custom_158';
const LIMIT_TO_CLASS_CODE_FIELD = 'custom_159';
const DISCOUNT_REASON_FIELD = 'custom_160';
const CREDIT_AMOUNT_FIELD = 'custom_161';
const PERCENT_OFF_FIELD = 'custom_162';
const EB_DISCOUNT_STATUS_FIELD = 'custom_163';
const CONTRIBUTION_ID_FIELD = 'custom_171';
const EXPIRES_ON_FIELD = 'custom_172';
const ORIGIN_ID_FIELD = 'custom_173';
const ORIGINAL_VALUE_FIELD = 'custom_175';
const USED_ON_DATE_FIELD = 'custom_175';
const ERROR_MESSAGE_FIELD = 'custom_176';

const STATUS_NEW = 1;
const STATUS_READY = 2;
const STATUS_USED = 3;
const STATUS_ERROR = 4;
const STATUS_EXPIRED = 5;
const STATUS_IMPORTED = 6;

function getRandomString($n) {
    $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $randomString = '';

    for ($i = 0; $i < $n; $i++) {
        $index = rand(0, strlen($characters) - 1);
        $randomString .= $characters[$index];
    }

    return $randomString;
}

function processNewDiscounts() {
  $result = civicrm_api3('Activity', 'get', [
    'sequential' => 1,
    'activity_type_id' => "Discount Code/Credit",
    EB_DISCOUNT_STATUS_FIELD => ['IN' => [STATUS_NEW]],
  ]);

  foreach ($result['values'] as $id=>$newVoucher) {
    $discountParams = array();
    $errorMessage = NULL;

    if (is_null($newVoucher[CODE_FIELD])) {
      // set a random code
      $newVoucher[CODE_FIELD] = getRandomString(6);
    }
    if ($newVoucher[CREDIT_AMOUNT_FIELD] > 0 AND $newVoucher[ORIGINAL_VALUE_FIELD] = 0) {
      $newVoucher[ORIGINAL_VALUE_FIELD] = $newVoucher[CREDIT_AMOUNT_FIELD];
    }

    $discountParams['code'] = $newVoucher[CODE_FIELD];
    $discountParams['type'] = 'coded';
    $discountParams['quantity_available'] = 1;

    if ($newVoucher[CREDIT_AMOUNT_FIELD] > 0) {
      $discountParams['amount_off'] = $newVoucher[CREDIT_AMOUNT_FIELD];
    } else {
      $discountParams['percent_off'] = $newVoucher[PERCENT_OFF_FIELD];
    }

    if ($discountParams['amount_off'] > 0 AND $discountParams['percent_off'] > 0) {
      $errorMessage = "Can't set both amount_off and percent_off, only set one.";
    } else if ($discountParams['percent_off'] > 100) {
      $errorMessage = "Percent off should be a number between 1 and 100";
    }

    if (!is_null($newVoucher[LIMIT_TO_CLASS_CODE_FIELD])) {
      $classCode = $newVoucher[LIMIT_TO_CLASS_CODE_FIELD];
      $classCodeResult = civicrm_api3('Event', 'get', [
        'sequential' => 1,
        CLASS_CODE_FIELD => $classCode,
      ]);
      if ($classCodeResult['count'] == 1) {
        $limitToEventbriteEventId = $classCodeResult[EB_EVENT_ID_FIELD];
        $discountParams['event_id'] = $limitToEventbriteEventId;
      } else if ($classCodeResult['count'] > 1) {
        $errorMessage = "Found more than one class matching code $classCode";
      } else {
        $errorMessage = "Found no class matching code $classCode";
      }
    }

    if (is_null($errorMessage)) {
      try {
        print("\nabout to contact eventbriet API...");
        $eb = CRM_Eventbrite_EventbriteApi::singleton();
        print("\nabout to create discount, params are ...\n");
        var_dump($discountParams);
        $ebResp = $eb->requestOrg("discounts", array('discount' => $discountParams), NULL, NULL, "POST");
        print("\nresponse from creating discount\n");
        var_dump($ebResp);
        $newVoucher[EB_DISCOUNT_ID_FIELD] = $ebResp['id'];
          $newVoucher[EB_DISCOUNT_STATUS_FIELD] = STATUS_READY;
      } catch (Exception $e) {
        print("\nerror ocurred...\n");
        var_dump($e);
        $errorMessage = "EB API Error: {$e->getMessage()}";
      }
    }

    if (!is_null($errorMessage)) {
      $newVoucher[EB_DISCOUNT_STATUS_FIELD] = STATUS_ERROR;
      $newVoucher[ERROR_MESSAGE_FIELD] = $errorMessage;
    } else {
      if (strlen($newVoucher[ERROR_MESSAGE_FIELD]) > 0) {
        // reset error message if it's resolved now
        $newVoucher[ERROR_MESSAGE_FIELD] = NULL;
      }
    }
    $updateResult = civicrm_api3('Activity', 'update', $newVoucher);
  }
}

function expireOldDiscounts() {
  print("\n in expireOldDiscounts \n");
  $result = civicrm_api3('Activity', 'get', [
    'sequential' => 1,
    'activity_type_id' => "Discount Code/Credit",
    EB_DISCOUNT_STATUS_FIELD => ['IN' => [STATUS_READY, STATUS_IMPORTED]],
    EXPIRES_ON_FIELD => array("<" => date("Y-m-d")),
  ]);
  foreach ($result['values'] as $voucher) {
    print("\ndeleting voucher {$voucher[CODE_FIELD]}");
    $ebDiscountId = $voucher[EB_DISCOUNT_ID_FIELD];
    $eb = CRM_Eventbrite_EventbriteApi::singleton();
    try {
      $ebResp = $eb->request("discounts/$ebDiscountId", NULL, NULL, NULL, "DELETE");
    } catch (Exception $e) {
      // TODO if we're here, probably should still try to amend end date?
      // TODO validate specific error message for attempting to delete used discount...
    }
    $expireResult = civicrm_api3('Activity', 'update', [
      'id' => $voucher['id'],
      EB_DISCOUNT_STATUS_FIELD => STATUS_EXPIRED
    ]);
  }
}

// will move this but handy to put it here for now...
function civicrm_api3_eventbrite_queue_syncvouchers($params) {
  processNewDiscounts();
  expireOldDiscounts();
}

function validateVoucher($voucher) {
  $messages = [];
  if ($voucher[CREDIT_AMOUNT_FIELD] > 0 AND $voucher[PERCENT_OFF_FIELD] > 0) {
    $messages[] = "Can't set both amount_off and percent_off, only set one.";
  } else if ($voucher[PERCENT_OFF_FIELD] > 100) {
    $messages[] = "Percent off should be a number between 1 and 100";
  }

  if ($voucher[CREDIT_AMOUNT_FIELD] > 0 AND strlen($voucher[ORIGIN_ID_FIELD]) == 0) {
    $messages[] = "Must provide an Origin ID";
  }

  if (!isset($voucher[DISCOUNT_REASON_FIELD])) {
    $messages[] = "Must provide a discount reason!";
  }

  return implode(";", $messages);
}


function civicrm_api3_eventbrite_queue_validatevouchers($params) {
  // make sure our vouchers are up to date before validating
  //civicrm_api3_eventbrite_queue_syncvouchers([]);

  // TODO page over results...
  $result = civicrm_api3('Activity', 'get', [
    'sequential' => 1,
    'options' => ['limit' => '5'],
    'activity_type_id' => "Discount Code/Credit",
  ]);

  foreach ($result['values'] as $x=>$voucher) {
    print("\nvalidating voucher {$voucher['subject']};");
    $errorMessage = validateVoucher($voucher);
    if (strlen($errorMessage) > 0) {
      civicrm_api3('Activity', 'update', [
        'id' => $voucher['id'],
        EB_DISCOUNT_STATUS_FIELD => STATUS_ERROR,
        ERROR_MESSAGE_FIELD => $errorMessage
      ]);
    } else {
      // reset status to whatever it should be...
      $newStatus = NULL;
      if (isset($voucher[USED_ON_DATE_FIELD])) {
        $newStatus = STATUS_USED;
      } else if (isset($voucher[EB_DISCOUNT_ID_FIELD] )) {
        $newStatus = STATUS_READY;
      } else {
        $newStatus = STATUS_NEW;
      }
      civicrm_api3('Activity', 'update', [
        'id' => $voucher['id'],
        EB_DISCOUNT_STATUS_FIELD => $newStatus,
        ERROR_MESSAGE_FIELD => NULL,
      ]);
    }
  }
}
