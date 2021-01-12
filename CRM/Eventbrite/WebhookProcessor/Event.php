<?php

use CRM_Eventbrite_ExtensionUtil as E;

/**
 * Class for processing Eventbrite 'Event' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Event extends CRM_Eventbrite_WebhookProcessor {

  protected $event = NULL;
  public $expansions = array();

  public $title = NULL;
  public $summary = NULL;
  public $description = NULL;

  protected function loadData() {
    print("in loadData\n");
    $this->event = $this->generateData();
    if (!array_key_exists("structured_content", $this->event)) {
      try {
        $structuredContent = $this->fetchEntity("structured_content");
      } catch (EventbriteApiError $e) {
        // no structured content available (old style event)
        $structuredContent = NULL;
      }
      if (isset($structuredContent)) {
        print("setting structured content...\n");
        $this->event['structured_content'] = $structuredContent;
      } else {
        print("no structured content\n");
      }
    }
  }

  protected function getDescriptionFromEvent() {
    if (array_key_exists('structured_content', $this->event)) {
      // new style descriptions using Structured Content
      return $this->event['structured_content']['modules'][0]['data']['body']['text'];
    } else {
      // old style descriptions using the Description field
      return $this->event['description']['html'];
    }
  }

  public function process() {
    print("in process() function...\n");
    // check if we have already linked to this event
    $result = _eventbrite_civicrmapi('EventbriteLink', 'getSingle', array(
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_type' => 'event',
    ));

    if ($result) {
      var_dump($result);
      print("found an existing link to a Civi event!\n");

      $existingEvent = _eventbrite_civicrmapi('Event', 'getsingle', array(
        'id' => $result['civicrm_entity_id']
      ));
      $this->updateExistingCiviEvent($existingEvent);
      return;

    } else {
      $civiEvent = $this->findOrCreateCiviEvent();

      print("civi event from findOrCreateCiviEvent...\n");
      var_dump($civiEvent);

      if (is_null($civiEvent)) {
          print("no event created, so nothing to link to");
          return;
      }

      // link Civi event to Eventbrite event
      $apiParams = array(
        'civicrm_entity_type' => 'event',
        'civicrm_entity_id' => $civiEvent['id'],
        'eb_entity_type' => 'event',
        'eb_entity_id' => $this->entityId,
      );
      var_dump($apiParams);

      // FIXME what does this do?
      //if ($this->_action & CRM_Core_Action::UPDATE) {
      //  $apiParams['id'] = $this->_id;
      //}

      $result = _eventbrite_civicrmapi('EventbriteLink', 'create', $apiParams);
      var_dump($result);
    }
  }

  public function setEventTitle() {
    if (is_null($this->title)) {
      $this->title = $this->event['name']['text'];
    }
  }

  /**
   * Do not modify civiEventParams in order to customize output
   *
   * To fine-tune the event type, use resolveEventTypeId() and/or customizeCiviEventParams()
   * To add additional fields, use customizeCiviEventParams()
   */
  public function civiEventParams() {
    print("in civiEventParams...\n");
    $this->setEventTitle();
    $this->summary = $this->event['summary'];
    $this->description = $this->getDescriptionFromEvent();

    $eventParams =  array(
      'title' => $this->title,
      'summary' => $this->summary,
      'description' => $this->description,
      'event_full_text' => $event_full_text,
      'start_date' => date_create($this->event['start']['local'])->format("Y-m-d H:i:s"),
      'end_date' => date_create($this->event['end']['local'])->format("Y-m-d H:i:s"),
      'max_participants' => $this->event['capacity'],
      'event_type_id' => $this->resolveEventTypeId(),
    );

    $this->customizeCiviEventParams($eventParams);

    print("event params after customization...\n");
    var_dump($eventParams);
    return $eventParams;
  }


  // By default, returns the eventbrite_default_event_type setting. Can be customized.
  public function resolveEventTypeId() {
    return _eventbrite_civicrmapi('Setting', 'getvalue', ['name' => "eventbrite_default_event_type"]);
  }

  // customize this function to support custom Event fields or override any defaults
  public function customizeCiviEventParams(&$params) {
  }

  // customize to link to existing events via a custom field or other parameters
  public function findOrCreateCiviEvent() {
  }

  // write custom code to update the existing event if it has changed externally
  public function updateExistingCiviEvent($existing) {
  }
}
