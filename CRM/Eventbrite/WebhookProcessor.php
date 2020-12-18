<?php

use CRM_Eventbrite_ExtensionUtil as E;

/**
 * Processor for Eventbrite webhook messages.
 */
class CRM_Eventbrite_WebhookProcessor {

  protected $data = array();
  private $entityType;
  protected $entityId;

  /**
   * Initialize the processor.
   *
   * @param array $data Webhook payload, as received from Eventbrite webhook
   *  OR Eventbrite entity as received from Eventbrite API.
   */
  public function __construct($data) {
    // webhook payload
    $this->data = $data;
    $this->setEntityIdentifiers();
    // fetch the entity's actual data (if not included in webhook payload)
    $this->loadData();
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
  }

  protected function fetchEntity($additionalPath = NULL, $expansions = array()) {
    $eb = CRM_Eventbrite_EventbriteApi::singleton();
    $path = "{$this->entityType}/{$this->entityId}";
    if ($additionalPath) {
      $path .= "/" . $additionalPath;
    }
    return $eb->request($path, NULL, $expansions);
  }

  protected function generateData($expands = array()) {
    if (CRM_Utils_Array::value('resource_uri', $this->data)) {
      return $this->data;
    }
    else {
      return $this->fetchEntity(NULL, $this->$expansions);
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
