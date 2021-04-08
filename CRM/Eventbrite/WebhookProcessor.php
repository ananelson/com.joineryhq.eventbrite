<?php

use CRM_Eventbrite_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\GenericEvent;

const DEDUPE_RULE_ID = 1;

/**
 * Processor for Eventbrite webhook messages.
 */
class CRM_Eventbrite_WebhookProcessor {
  protected $data = array();
  private $entityType;
  public $entityId;

  /**
   * Initialize the processor.
   *
   * @param array $data Webhook payload, as received from Eventbrite webhook
   *  OR Eventbrite entity as received from Eventbrite API.
   */
  public function __construct($data) {
    \CRM_Core_Error::debug_log_message("in _construct for processor");
    // webhook payload
    $this->data = $data;
    \CRM_Core_Error::debug_var("data", $data);
    $this->setEntityIdentifiers();
    // fetch the entity's actual data (if not included in webhook payload)
    $this->loadData();
    $this->dispatchSymfonyEvent("DataLoaded");
  }

  /**
   * Dispatch a Symfony GenericEvent with this Civi Event Webhook Processor as subject
   * to allow other extensions to customize aspects of behavior.
   */
  protected function dispatchSymfonyEvent($eventName) {
    $symfonyEvent = new GenericEvent($this);
    $qualifiedEventName = "eventbrite.processor.$eventName";
    \CRM_Core_Error::debug_log_message("about to dispatch event $qualifiedEventName");
    // See https://lab.civicrm.org/dev/core/-/issues/2316 re Symfony 4.3 compat issues
    Civi::dispatcher()->dispatch($qualifiedEventName, $symfonyEvent);
  }

  private function setEntityIdentifiers() {
    if (
      !($apiUrl = CRM_utils_array::value('api_url', $this->data))
      && !($apiUrl = CRM_utils_array::value('resource_uri', $this->data))
    ) {
      throw new CRM_Core_Exception('Bad data. Missing parameter "api_url" or "resource_url" in message');
    }
    $path = rtrim(parse_url($apiUrl, PHP_URL_PATH), '/');
    $pathElements = array_reverse(explode('/', $path));
    $this->entityId = $pathElements[0];
    $this->entityType = $pathElements[1];

    $this->path = implode("/", array_slice(explode('/', $path), 2));
  }

  protected function fetchDuplicateContacts($firstName, $lastName, $email) {
    $this->tempContactParams =  array(
      'contact_type' => 'Individual',
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email
    );
    $this->dispatchSymfonyEvent("TempContactParamsAssigned");
    return _eventbrite_civicrmapi('Contact', 'duplicatecheck', array(
      'dedupe_rule_id' => DEDUPE_RULE_ID,
      'match' => $this->tempContactParams,
    ));
  }

  protected function findOrCreateContact($firstName, $lastName, $email) {
    \CRM_Core_Error::debug_log_message("in findOrCreateContact");
    $result = $this->fetchDuplicateContacts($firstName, $lastName, $email);
    \CRM_Core_Error::debug_var("result", $result);
    if ($result['count'] > 0) {
      $contactId = $result['id'];
      return _eventbrite_civicrmapi('Contact', 'getsingle', array('id' => $contactId));
    } else {
      return  _eventbrite_civicrmapi('Contact', 'create', $contactParams);
    }
  }

  protected function fetchEntity($additionalPath = NULL, $expansions = array()) {
    $eb = CRM_Eventbrite_EventbriteApi::singleton();
    $path = $this->path;
    if ($additionalPath) {
      $path .= "/" . $additionalPath;
    }
    return $eb->request($path, NULL, NULL, $expansions);
  }

  protected function generateData($expands = array()) {
    if (CRM_Utils_Array::value('resource_uri', $this->data)) {
      return $this->data;
    }
    else {
      return $this->fetchEntity(NULL, $this->expansions);
    }
  }

  public function process() {
  }

  public function getEntityIdentifier() {
    return "{$this->entityType}_{$this->entityId}";
  }

  public function get($property) {
    return $this->$property;
  }

  public function getData($property) {
    return $this->data[$property];
  }

}
