<?php

/**
 * Processor for Eventbrite webhook messages.
 */
class CRM_Eventbrite_WebhookProcessorFactory {

  public static function create($webhookData) {
    if (!$data = json_decode($webhookData, TRUE)) {
      throw new CRM_Exception('Bad data. Could not parse JSON.');
    }
    if (!CRM_utils_array::value('config', $data)) {
      throw new CRM_Exception('Bad data. Missing parameter "config" in message');
    }

    $autocreateEvents =  _eventbrite_civicrmapi('Setting', 'getvalue', array('name' => "eventbrite_autocreate_events"));

    switch (CRM_Utils_Array::value('action', $data['config'])) {
      case 'order.updated':
        $processor = new CRM_Eventbrite_WebhookProcessor_Order($data);
        break;

      case 'attendee.updated':
        $processor = new CRM_Eventbrite_WebhookProcessor_Attendee($data);
        break;

      case 'event.created':
      case 'event.updated':
        if ($autocreateEvents) {
          $processor = new CRM_Eventbrite_WebhookProcessor_BATSEvent($data);
        } else {
          // ignore event related hooks
          $processor = FALSE;
        }
        break;

      default:
        $processor = FALSE;
        break;

    }

    return $processor;
  }

}
