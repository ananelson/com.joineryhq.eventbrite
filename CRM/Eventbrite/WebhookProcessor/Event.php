<?php

use CRM_Eventbrite_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class for processing Eventbrite 'Event' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Event extends CRM_Eventbrite_WebhookProcessor {

  public $event = NULL;
  public $expansions = array();

  public $title = NULL;
  public $summary = NULL;
  public $description = NULL;

  /**
   * Fetch the Event's data from the Eventbrite API
   */
  protected function loadData() {
    $this->event = $this->generateData();

    // If event has structured_content (new Eventbrite format), apply this.
    if (!array_key_exists("structured_content", $this->event)) {
      try {
        $structuredContent = $this->fetchEntity("structured_content");
      } catch (EventbriteApiError $e) {
        // no structured content available (old style event)
        $structuredContent = NULL;
      }
      if (isset($structuredContent)) {
        $this->event['structured_content'] = $structuredContent;
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

  public function setEventTitle() {
    $this->title = $this->event['name']['text'];
  }

  public function resolveEventTypeId() {
    return _eventbrite_civicrmapi('Setting', 'getvalue', ['name' => "eventbrite_default_event_type"]);
  }

  public function setCiviEventParams() {
    $this->setEventTitle();
    $this->summary = $this->event['summary'];
    $this->description = $this->getDescriptionFromEvent();

    $this->civiEventParams = [
      'title' => $this->title,
      'summary' => $this->summary,
      'description' => $this->description,
      'event_full_text' => $event_full_text,
      'start_date' => date_create($this->event['start']['local'])->format("Y-m-d H:i:s"),
      'end_date' => date_create($this->event['end']['local'])->format("Y-m-d H:i:s"),
      'max_participants' => $this->event['capacity'],
      'event_type_id' => $this->resolveEventTypeId(),
    ];

    $this->dispatchSymfonyEvent("EventParamsSet");
  }

  /** 
   * Look up any previously linked event stored in EventbriteLink table
   */
  public function getLinkedCiviEvent() {
    $result = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_type' => 'event',
    ));

    \CRM_Core_Error::debug_log_message("\nresult from checkng for eventbrite entity {$this->entityId}\n");

    if ($result['count'] > 0) {
      $key = array_key_first($result['values']);
      $info = $result['values'][$key];

      $existingEvent = _eventbrite_civicrmapi('Event', 'getsingle', array(
        'id' => $info['civicrm_entity_id']
      ));
      return $existingEvent;
    }
  }

  public function createAndLinkNewCiviEvent() {
    $this->newEvent = _eventbrite_civicrmapi("Event", "Create", $this->civiEventParams);
    \CRM_Core_Error::debug_var("new event", $this->newEvent);
    $this->linkCiviEvent($this->newEvent['id']);
    $this->dispatchSymfonyEvent("NewCiviEventCreated");
  }

  public function updateCiviEvent() {
    $this->civiEventParams['id'] = $this->existingEvent['id'];

    $this->doUpdateCiviEvent = true;
    $this->dispatchSymfonyEvent("BeforeUpdateExistingCiviEvent");
    if ($this->doUpdateCiviEvent) {
      \CRM_Core_Error::debug_log_message("updating this event with latest civiEventParams");
      $response = _eventbrite_civicrmapi("Event", "Update", $this->civiEventParams);
    }
    $this->dispatchSymfonyEvent("AfterUpdateExistingCiviEvent");
  }

  public function linkCiviEvent($civiEventId) {
    // link Civi event to Eventbrite event
    $apiParams = array(
      'civicrm_entity_type' => 'event',
      'civicrm_entity_id' => $civiEventId,
      'eb_entity_type' => 'event',
      'eb_entity_id' => $this->entityId,
    );
    \CRM_Core_Error::debug_var("api params", $apiParams);
    $result = _eventbrite_civicrmapi('EventbriteLink', 'create', $apiParams);
  }

  public function process() {
    \CRM_Core_Error::debug_log_message("in process() method for Eventbrite Event");

    // Set up Event params (to be used to create or update)
    $this->setCiviEventParams();

    // Find exiting Civi event if exists.
    $this->existingEvent = $this->getLinkedCiviEvent();
    if (is_null($this->existingEvent)) {
      // allows custom code to identify an existing but unlinked Civi event
      $this->dispatchSymfonyEvent("FindExistingCiviEvent");
      if (!is_null($this->existingEvent)) {
        $this->linkCiviEvent($this->existingEvent['id']);
      }
    }

    // Create new or update existing event.
    if (is_null($this->existingEvent)) {
      $this->createAndLinkNewCiviEvent();
    } else {
      $this->updateCiviEvent();
    }
  }
}
