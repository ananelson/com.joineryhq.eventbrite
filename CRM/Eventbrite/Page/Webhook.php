<?php
use CRM_Eventbrite_ExtensionUtil as E;

class CRM_Eventbrite_Page_Webhook extends CRM_Core_Page {

  public function run() {

    $input = file_get_contents("php://input");

    if (!($json = json_decode($input, TRUE))) {
      $this->respond('Bad data. Could not parse JSON.', 422);
    }
    else {
      // Send this to the queue, which will be processed entry-by-entry on a
      // schedule, to avoid duplicate handling of identical webhook events.
      $params = array(
        'message' => $input,
      );

      try {
        $result = _eventbrite_civicrmapi('EventbriteQueue', 'create', $params);
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Eventbrite_BAO_EventbriteLog::create(array(
          'message_type_id' => CRM_Eventbrite_BAO_EventbriteLog::MESSAGE_TYPE_ID_INBOUND_WEBHOOK,
          'message' => 'Failed writing webhook to queue.
             Error: ' . $e->getMessage() . '
             Webhook input: ' . $input . '
          ',
        ));
        $this->respond('CiviCRM API error.', 500);
      }

      $this->respond('Done.', 200);
    }
  }

  private static function respond($msg, $response) {
    http_response_code($response);
    echo $msg;
    CRM_Utils_System::civiExit();
  }

}
