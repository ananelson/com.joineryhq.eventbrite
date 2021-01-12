<?php

use CRM_Eventbrite_ExtensionUtil as E;

// constants
const IN_PERSON_CLASS_EVENT_TYPE = 6;
const ONLINE_CLASS_EVENT_TYPE = 15;

const CLASS_NAME_FIELD = 'custom_3';
const CLASS_CODE_FIELD = 'custom_4';

const EVENTBRITE_ID_FIELD = 'custom_5';

const IS_CLASS_CANCELLED_FIELD = 'custom_154';
const EVENTBRITE_CHANGED_FIELD = 'custom_155';
const PREFERRED_COACHING_NAME = 'custom_156';

const COACH_PARTICIPANT_ROLE = 5;


/**
 * Class for processing Eventbrite 'Event' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_BATSEvent extends CRM_Eventbrite_WebhookProcessor_Event {
  protected $coachCache = [];

  public function setClassNameOptionValues() {
      if (!isset($this->classNameOptionValues)) {
          $result = civicrm_api3('OptionValue', 'get', [
              'sequential' => 1,
              'option_group_id' => "class_name_20200615184556",
          ]);
          $this->classNameOptionValues = $result['values'];
      }
  }

  public function resolveClassName() {
    print("\nresolving class name for $this->title");
    $alternateNames = array(
      "Studio Scene Work" => "Studio Scenework",
      "Intro" => "Shy People"
    );
    $this->setClassNameOptionValues();

    foreach($this->classNameOptionValues as $optionValueInfo) {
      $thisLabel = $optionValueInfo['label'];
      $altLabel = CRM_Utils_Array::value($thisLabel, $alternateNames);
      print("checking if $thisLabel or $altLabel are relevant");
      print("pos " . strpos($this->title, $thisLabel));
      if (!is_null($altLabel)) {
        print("pos " . strpos($this->title, $altLabel));
      };

      if (strpos($this->title, $thisLabel) !== false || (!is_null($altLabel) && strpos($this->title, $altLabel) !== false)) {
        return $optionValueInfo['value'];
      }
    }
  }

  public function resolveIsClassCancelled() {
    print("\nin resolveIsClassCancelled\n");
    print("strpos " . strpos($this->title, 'CX'));

    return (strpos($this->title, 'CX') !== false) ? 1 : 0;
  }

  public function parseClassCode() {
    $codeRegex = '/\((#[0-9]{2}-[0-9]{2}-[0-9]{4})\)/';
    $codeRegexMonthOnly = '/\((#[0-9]{2}-[0-9]{4})\)/';

    $codeRegexNoDashes = '/\(#([0-9]{2})([0-9]{2})([0-9]{4})\)/';

    // check title for full format ID
    preg_match($codeRegex, $this->title, $match);
    if (!empty($match)) {
      print("\nfound full code in title");
      return $match[1];
    }

    // check summary for full format ID
    preg_match($codeRegex, $this->summary, $match);
    if (!empty($match)) {
      print("\nfound full code in summary");
      return $match[1];
    }

    // check title for old (partial) format ID
    preg_match($codeRegexMonthOnly, $this->title, $match);
    if (!empty($match)) {
      print("\nfound partial code in title");
      return $match[1];
    }

    // check title for no dash format ID
    preg_match($codeRegexNoDashes, $this->title, $match);
    if (!empty($match)) {
      print("\nfound full code in title");
      return '#' . $match[1] . "-" . $match[2] . "-" . $match[3];
    }
  }

  // customize this function to support custom Event fields or override any defaults
  public function customizeCiviEventParams(&$params) {
    print("\nin customizeCiviEventParams\n");

    // non default settings
    $params['is_public'] = 0;

    // monetary settings
    $params['is_monetary'] = 1;
    $params['financial_type_id'] = 7; // tuition

    $classCode = $this->parseClassCode();
    print("\nclass code found is $classCode");
    if (!is_null($classCode)) {
      $params[CLASS_CODE_FIELD] = $classCode;

      // set event type to online or in person class
      if (strpos($this->title, "Online") !== false) {
        $params['event_type_id'] = ONLINE_CLASS_EVENT_TYPE;
        // don't show location for online courses
        $params['is_show_location'] = 0;
      } else {
        $params['event_type_id'] = IN_PERSON_CLASS_EVENT_TYPE;
        // DO show location for in person courses
        $params['is_show_location'] = 1;
      }
    } else {
      print("\nno class code detected.\n");
    }

    $params[CLASS_NAME_FIELD] = $this->resolveClassName();
    $params[EVENTBRITE_ID_FIELD] = $this->entityId;
    $params[EVENTBRITE_CHANGED_FIELD] = $this->event['changed'];
    $params[IS_CLASS_CANCELLED_FIELD] = $this->resolveIsClassCancelled();

    if (strpos($this->title, $classCode) == false) {
      // add class code to title for convenience
      $params['title'] .= " (" . $classCode . ")";
    }
  }

  public function updateExistingCiviEvent($existing) {
    print("in updateExistingCiviEvent!");
    if (!isset($existing[EVENTBRITE_CHANGED_FIELD]) || $existing[EVENTBRITE_CHANGED_FIELD] <= $this->event['changed']) {
      print("updating this event!\n");

      print("existing event is...\n");
      var_dump($existing);

      $eventParams = $this->civiEventParams();
      $eventParams['id'] = $existing['id'];

      print("\nevent params for update...\n");
      var_dump($eventParams);

      $response = _eventbrite_civicrmapi("Event", "Update", $eventParams);
      print("\nupdate response...\n");
      var_dump($response);

    } else {
      print("event is up to date, no action needed");
    }
    $this->updateCoachesIfMissing($existing['id']);
  }

  // customize to link to existing events via a custom field or other parameters
  public function findOrCreateCiviEvent() {
    print("in findOrCreateCiviEvent()");
    var_dump($this->event);

    // check for existing Civi event matching this Eventbrite ID
    $result = _eventbrite_civicrmapi("Event", "get", [EVENTBRITE_ID_FIELD => $this->entityId]);

    if ($result['count'] == 1) {
      print("found existing Event to match!");
      $existingRecords = $result['values'];
      foreach ($existingRecords as $key=>$existing) {
        print("\nexisting var dum...\n");
        var_dump($existing);
        $this->updateExistingCiviEvent($existing);
        return $existing;
      }

    } else if ($result['count'] == 0) {
      // no existing event - create a new Civi event
      print("event status " . $this->event['status']);
      if (in_array($this->event['status'], array('draft'))) {
        // ignore this event
        print("ignoring event with status" . $this->event['status']);
      } else {
        print("creating new event");
        $eventParams = $this->civiEventParams();
        $newEvent = _eventbrite_civicrmapi("Event", "Create", $eventParams);
        $this->registerEventCoaches($newEvent['id']);
        return $newEvent;
      }
    }
  }

  public function populateCoachCache() {
    if (empty($this->coachCache)) {
      $contactsInCoachGroup = _eventbrite_civicrmapi('GroupContact', 'get', [
        'group_id' => "BATS_Coaches_14",
        'status' => 'Added'
      ]);
      foreach ($contactsInCoachGroup['values'] as $groupContact) {
        $contact = _eventbrite_civicrmapi("Contact", 'getSingle', [
          'return' => ['display_name', PREFERRED_COACHING_NAME],
          "id" => $groupContact['contact_id']
        ]);

        $this->coachCache[] = $contact;
      }
    }
  }

  public function updateCoachesIfMissing($event_id) {
    $this->setEventTitle();
    $coachCount = civicrm_api3('Participant', 'getcount', [
      'event_id' => $event_id,
      'status_id' => 1,
      'role_id' => COACH_PARTICIPANT_ROLE,
    ]);

    print("\nin updateCoachesIfMissing - found $coachCount coaches\n");

    if ($coachCount == 0) {
      $this->registerEventCoaches($event_id);
    }
  }

  public function registerEventCoaches($event_id) {
    print("in registerEventCoaches for event $event_id");
    $this->populateCoachCache();

    $registeredCoachCount = 0;

    foreach ($this->coachCache as $contact) {
      if (strpos($this->title, $contact['display_name']) !== false || 
        (!empty($contact[PREFERRED_COACHING_NAME]) && strpos($this->title, $contact[PREFERRED_COACHING_NAME]) !== false))  {

        $registerDate = date_create($this->event['start']['local'])->format("Y-m-d H:i:s");

        $participantParams = array(
          'event_id' => $event_id,
          'contact_id' => $contact['id'],
          'status_id' => 1,
          'role_id' => COACH_PARTICIPANT_ROLE,
          'source' => "Automatically created based on Eventbrite",
          'participant_register_date' => $registerDate
        );
        $response = _eventbrite_civicrmapi("Participant", "Create", $participantParams);
        var_dump($response);
        $registeredCoachCount += 1;
      }
    }

    print("\nregistered $registeredCoachCount  coaches for this class\n");
  }
}
