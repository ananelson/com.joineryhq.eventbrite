<?php

use CRM_Eventbrite_ExtensionUtil as E;

// constants
const IN_PERSON_CLASS_EVENT_TYPE = 6;
const ONLINE_CLASS_EVENT_TYPE = 15;

/**
 * Class for processing Eventbrite 'Event' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Event extends CRM_Eventbrite_WebhookProcessor {

  private $event = NULL;
  public $expansions = array();

  protected function loadData() {
    $this->event = $this->generateData();
    var_dump($this->event);
    if (!array_key_exists("structured_content", $this->event)) {
      $this->event['structured_content'] = $this->fetchEntity("structured_content");
    }
  }

  public function process() {
    // check if we have already linked to this event
    $result = _eventbrite_civicrmapi('EventbriteLink', 'getSingle', array(
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_type' => 'event',
    ));

    if ($result) {
      // existing link - update the event
      print("found an existing link to a Civi event!");
    } else {
      $civiEvent = $this->findOrCreateCiviEvent();
      print("civi event from findOrCreateCiviEvent...\n");
      var_dump($civiEvent);

      // link Civi event to Eventbrite event
      $apiParams = array(
        'civicrm_entity_type' => 'event',
        'civicrm_entity_id' => $civiEvent['id'],
        'eb_entity_type' => 'event',
        'eb_entity_id' => $this->entityId,
      );

      // FIXME what does this do?
      if ($this->_action & CRM_Core_Action::UPDATE) {
        $apiParams['id'] = $this->_id;
      }

      $result = _eventbrite_civicrmapi('EventbriteLink', 'create', $apiParams);
    }
  }

  /**
   * Do not modify civiEventParams in order to customize output
   *
   * To add additional fields, use customizeCiviEventParams()
   * To fine-tune the event type, use resolveEventTypeId()
   */
  public function civiEventParams() {
    $title = $this->event['name']['text'];
    $summary = $this->event['summary'];
    $description = $this->event['structured_content']['modules'][0]['data']['body']['text'];

    $eventParams =  array(
      'title' => $title,
      'summary' => $summary,
      'description' => $description,
      'event_full_text' => $event_full_text,
      'start_date' => date_create($this->event['start']['local'])->format("Y-m-d H:i:s"),
      'end_date' => date_create($this->event['end']['local'])->format("Y-m-d H:i:s"),
      'max_participants' => $this->event['capacity'],
      'event_type_id' => $this->resolveEventTypeId(),
    );

    print("event type ID from system default...");
    print($eventParams['event_type_id']);
    $this->customizeCiviEventParams($eventParams);

    print("event params...\n");
    var_dump($eventParams);
    return $eventParams;
  }


  // By default, returns the setting. Can be customized.
  public function resolveEventTypeId() {
    return _eventbrite_civicrmapi('Setting', 'getvalue', ['name' => "eventbrite_default_event_type"]);
  }


  public function resolveClassName($title) {
      if (strpos("Foundation 1", $title) > 0) {
          return 2; // F1
      }
  }

  // Any changes to $params will be persisted
  public function customizeCiviEventParams(&$params) {
    $title = $this->event['name']['text'];
    $description = $this->event['description']['text'];

    // non default settings
    $params['is_public'] = 0;

    // class code - custom_4
    preg_match('/\((#[0-9]{2}-[0-9]{2}-[0-9]{4})\)/', $description, $matches);

    if (isset($matches[1])) {
      $classCode = $matches[1];
      $params['custom_4'] = $classCode;

      // set event type to online or in person class
      if ($this->event['online_event']) {
        $params['event_type_id'] = ONLINE_CLASS_EVENT_TYPE;
        $params['is_show_location'] = 0;
      } else {
        $params['event_type_id'] = IN_PERSON_CLASS_EVENT_TYPE;
        $params['is_show_location'] = 1;
      }
    }

    // class name (e.g. Foundation 1, Foundation 2) - custom 3
    $params["custom_3"] = $this->resolveClassName($title);

    // eventbrite event ID - custom 5
    $params['custom_5'] = $this->entityId;

    //"custom_6": "1",
    //"custom_10": "2",
    //"custom_12": "7",
    //"custom_14": "3",
  }

  // can customize to link to existing events via a custom field or other parameters
  public function findOrCreateCiviEvent() {
    // no existing event - create a new Civi event
    $result = civicrm_api3("Event", "get", ["custom_5" => $this->entityId]);
    if ($result['count'] == 1) {
      print("found existing Event to match!");
      $existing = $result['values'];
      var_dump($existing);
      return current($existing);
    } else if ($result['count'] == 0) {
      print("creating new event");
      return civicrm_api3("Event", "Create", $this->civiEventParams());
    }
  }
}
