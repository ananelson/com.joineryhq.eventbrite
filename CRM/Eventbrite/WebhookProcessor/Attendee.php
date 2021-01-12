<?php

use CRM_Eventbrite_ExtensionUtil as E;

const STUDENT_ROLE = 7;
const CODE_FIELD = 'custom_157';
const CREDIT_AMOUNT_FIELD = 'custom_161';
const EVENTBRITE_DISCOUNT_ID_FIELD = 'custom_158';
const USED_IN_EVENTBRITE_FIELD = 'custom_163';
const USED_IN_EVENTBRITE = 3;
const VALUE_USED_FIELD = 'custom_164';

/**
 * Class for processing Eventbrite 'Attendee' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Attendee extends CRM_Eventbrite_WebhookProcessor {

  public $expansions = array('attendee-answers', 'promotional_code');
  private $attendee;

  public $eventId = NULL;
  public $eventParticipants = NULL;

  public $contactId = NULL;
  public $participantId = NULL;
  public $linkId = NULL;

  public $voucherCode = NULL;
  public $discountId = NULL;

  protected function loadData() {
    $this->attendee = $this->generateData();
  }

  public function contactParams() {
    return array(
      'contact_type' => 'Individual',
      'first_name' => $this->attendee['profile']['first_name'],
      'last_name' => $this->attendee['profile']['last_name'],
      'email' => $this->attendee['profile']['email'],
    );
  }

  /**
   * Returns a list of contacts matching this attendee's name and email.
   */
  public function matchingContacts() {
    $contactParams = $this->contactParams();
    $result = _eventbrite_civicrmapi('Contact', 'duplicatecheck', array(
      'match' => $this->contactParams(),
    ), "Processing Attendee {$this->entityId}.");
    $duplicateCheckContactIds = array_keys($result['values']);
    return $duplicateCheckContactIds;
  }

  public function setCiviEventIdForAttendee() {
    $this->eventId = _eventbrite_civicrmapi('EventbriteLink', 'getValue', array(
      'eb_entity_type' => 'Event',
      'civicrm_entity_type' => 'Event',
      'eb_entity_id' => CRM_Utils_Array::value('event_id', $this->attendee),
      'return' => 'civicrm_entity_id',
    ), "Processing Attendee {$this->entityId}, attempting to get linked event for attendee.");

    if (!$this->eventId) {
      CRM_Eventbrite_BAO_EventbriteLog::create(array(
        'message' => "Could not find EventbriteLink record 'Event' for order {$this->entityId} with 'event_id': " . CRM_Utils_Array::value('event_id', $this->order) . "; skipping Order. In method " . __METHOD__ . ", file " . __FILE__ . ", line " . __LINE__,
        'message_type_id' => CRM_Eventbrite_BAO_EventbriteLog::MESSAGE_TYPE_ID_GENERAL,
      ));
    } else {
      $this->event = _eventbrite_civicrmapi('Event', 'getSingle', array(
        'id' => $this->eventId,
      ), "Processing Order {$this->entityId}, attempting to get the CiviCRM Event for this order.");
    }
  }

  public function getExistingAttendeeLink() {
    // Also start with a Participant linked to the Attendee ID, if any.
    $linkParams = array(
      'eb_entity_type' => 'Attendee',
      'civicrm_entity_type' => 'Participant',
      'eb_entity_id' => $this->entityId,
      'sequential' => 1,
      'api.Participant.get' => ['id' => '$value.civicrm_entity_id'],
    );
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', $linkParams, "Processing Attendee {$this->entityId}, attempting to get linked Participant.");
    $this->linkId = CRM_Utils_Array::value('id', $link);
    if (!empty($link['values'])) {
      $linkedParticipantId = CRM_Utils_Array::value('civicrm_entity_id', $link['values'][0]);
      $linkedContactId = $link['values'][0]['api.Participant.get']['values'][0]['contact_id'];
      return [$linkedParticipantId, $linkedContactId];
    } else {
      return [null, null];
    }
  }

  public function process() {
    $this->setCiviEventIdForAttendee();
    if (!$this->eventId) {
      return;
    }

    if (!self::getRolePerTicketType(CRM_Utils_Array::value('ticket_class_id', $this->attendee))) {
      print("could not find valid participant role\n");
      return;
    }

    $matchingContacts = $this->matchingContacts();
    list($linkedParticipantId, $linkedContactId) = $this->getExistingAttendeeLink();

    if ($linkedParticipantId) {
      if (in_array($linkedContactId, $matchingContacts)) {
        // use that contactId / participantId.
        $this->contactId = $linkedContactId;
        $this->participantId = $linkedParticipantId;
        $this->updateContact();
        $this->updateParticipantParams();
      } else {
        // Else, it means identifying info (name, email) HAS changed;
        if (!empty($matchingContacts)) {
          // Use a matched contact if one exists
          $this->contactId = min($matchingContacts);
          $this->updateContact();
        } else {
          // Otherwise make a new one
          $this->updateContact();
        }

        // remove the previously linked participant
        print("\nremoving previously linked participant");
        $this->setParticipantStatusRemoved($linkedParticipantId);
        $this->updateParticipantParams();
      }
    } else {
      print("\nno linked participnat found, adding...");
      // no linked participant
      // use the lowest duplicatecheck ContactId if any.
      $this->contactId = empty($matchingContacts) ? NULL : min($matchingContacts);
      $this->updateContact();
      $this->updateParticipantParams();
    }

    if (!empty($this->attendee['promotional_code'])) {
      $this->processPromoCode($this->attendee['promotional_code']);
    }
    print("\nabout to call updateParticipant...");
    $this->updateParticipant();
    $this->updateParticipantLink();
    $this->updateCustomFields();

  }

  public function processPromoCode($promoCode) {
    print("\n in processPromoCode\n");
    $eb = CRM_Eventbrite_EventbriteApi::singleton();

    $discountCode = $promoCode['code'];
    $this->discountId = $promoCode['id'];
    $this->voucherCode = $discountCode;
    $discountDescription = "Eventbrite Discount $discountCode";

    if (array_key_exists('amount_off', $promoCode)) {
      $discountDescription .= " ({$promoCode['amount_off']['display']})";
      $amountOff = $promoCode['amount_off']['major_value'];
      $ticketClassId = $this->attendee['ticket_class_id'];

      $path = "events/{$this->attendee['event_id']}/ticket_classes/$ticketClassId";
      $ticketClass = $eb->request($path);

      $this->ticketPrice = $ticketClass['cost']['major_value'];
      $paidPrice = $this->attendee['costs']['gross']['major_value'];
      $this->valueUsed = $this->ticketPrice - $paidPrice;

      // update participant fee level
      $this->participantParams['participant_fee_level'] = $ticketClass['cost']['display'];
      $this->participantParams['participant_fee_amount'] = $ticketClass['cost']['major_value'];
    } else {
      $amountOff = NULL;
      // TODO add percent off to description
      $discountDescription .= " ({$promoCode['percent_off']}%)";
      $this->valueUsed = NULL;
    }

    $result = _eventbrite_civicrmapi('Activity', 'get', array(
      'activity_type_id' => 89,
      EVENTBRITE_DISCOUNT_ID_FIELD => $promoCode['id']
    ));

    if ($result['count'] > 0) {
      // update existing discount code
      $discountCodeParams = array(
        USED_IN_EVENTBRITE_FIELD => USED_IN_EVENTBRITE,

      );
    } else {
      // create new discount code activity
      $discountCodeParams = array(
        'activity_type_id' => 89,
        CODE_FIELD => $discountCode, 
        'status_id' => 'Completed',
        'source_contact_id' => 2,
        'target_id' => $this->contactId,
        'subject' => "Eventbrite Discount $discountCode",
        CREDIT_AMOUNT_FIELD => $amountOff,
        EVENTBRITE_DISCOUNT_ID_FIELD => $promoCode['id'],
        USED_IN_EVENTBRITE_FIELD => USED_IN_EVENTBRITE,
        VALUE_USED_FIELD => $this->valueUsed,
      );

      print("\nabout to create activity");
      var_dump($discountCodeParams);
      $result = _eventbrite_civicrmapi('Activity', 'create', $discountCodeParams);
      var_dump($result);
    }
  }

  public function updateParticipantLink() {
    // Create a new Participant/Attendee link using ParticipantId with the latest Attendee data.
    $link = _eventbrite_civicrmapi('EventbriteLink', 'create', array(
      'id' => $this->linkId,
      'eb_entity_type' => 'Attendee',
      'civicrm_entity_type' => 'Participant',
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_id' => $this->participantId,
    ), "Processing Attendee {$this->entityId}, attempting to create/update Attendee/Participant link.");
  }

  private function updateContact() {
    $apiParams = array(
      'contact_type' => 'Individual',
      'first_name' => $this->attendee['profile']['first_name'],
      'last_name' => $this->attendee['profile']['last_name'],
      'email' => $this->attendee['profile']['email'],
      'id' => $this->contactId,
    );
    if (empty($this->contactId)) {
      $apiParams['source'] = E::ts('Eventbrite Integration');
    }
    $contactCreate = _eventbrite_civicrmapi('Contact', 'create', $apiParams, "Processing Attendee {$this->entityId}, attempting to update contact record.");
    $this->contactId = CRM_Utils_Array::value('id', $contactCreate);
    $this->updateContactAddresses();
    $this->updateContactPhone('work', CRM_Utils_Array::value('work_phone', $this->attendee['profile']));
    $this->updateContactPhone('home', CRM_Utils_Array::value('home_phone', $this->attendee['profile']));
  }

  private function updateParticipantParams() {
    $roleId = self::getRolePerTicketType(CRM_Utils_Array::value('ticket_class_id', $this->attendee));

    // Create/update the participant record.
    $this->participantParams = array(
      'id' => $this->participantId,
      'event_id' => $this->eventId,
      'contact_id' => $this->contactId,
      'participant_fee_level' => $this->attendee['costs']['gross']['display'],
      'participant_fee_amount' => $this->attendee['costs']['gross']['major_value'],
      'participant_register_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->attendee)),
      'role_id' => $roleId,
      'source' => E::ts('Eventbrite Integration'),
    );
    if (CRM_Utils_Array::value('checked_in', $this->attendee)) {
      $this->participantParams['participant_status'] = 'Attended';
    }
    elseif (CRM_Utils_Array::value('cancelled', $this->attendee)) {
      $this->participantParams['participant_status'] = 'Cancelled';
    }
    else {
      $this->participantParams['participant_status'] = 'Registered';
    }
  }

  public function updateParticipant() {
    print("\nchecking for existing participant...\n");
    $result = _eventbrite_civicrmapi('Participant', 'get', array(
      'event_id' => $this->eventId,
      'contact_id' => $this->contactId
    ));

    if ($result['count'] == 0) {
      print("\nabout to create participant with params...\n");
      var_dump($this->participantParams);

      $participant = _eventbrite_civicrmapi('Participant', 'create', $this->participantParams, 
        "Processing Attendee {$this->entityId}, attempting to create/update Participant record.");
    } else {
      $participant = $result['values'][0];
    }

    $this->participantId = CRM_Utils_Array::value('id', $participant);
    print("\nparticipant id is {$this->participantId}");

    // If participant status is canceled, also cancel the payment record.
    if ($apiParams['participant_status'] == 'Cancelled') {
      self::cancelParticipantPayments($participant['id']);
    }
  }

  private function updateContactAddresses() {
    if (!empty($this->attendee['profile']['addresses'])) {
      // Get active and default locationTypes.
      $result = _eventbrite_civicrmapi('LocationType', 'get', array(
        'return' => ["name", "is_default"],
        'is_active' => 1,
      ));
      $locationTypes = CRM_Utils_Array::rekey($result['values'], 'name');
      $defaultLocationType = array_filter($locationTypes, function($value) {
        return (CRM_Utils_Array::value('is_default', $value, 0));
      });
      $defaultLocationTypeIds = array_keys($defaultLocationType);
      $defaultLocationTypeId = array_shift($defaultLocationTypeIds);

      // Loop through all provided addresses. For each, if the location type is
      // one of our supported locations -- and it's otherwise a good address --
      // queue it up for adding. But onsider the possibility that a supported
      // location type (e.g., "work") may be disabled in civicrm; in that
      // case we'll try to add this address with the civicrm-default location
      // type (e.g., "home").
      // Also consider that the Attended may specify a "Home" address separately;
      // this case we risk having two "home" addresses, which is unsupported in
      // CiviCRM. So in this case, we prefer to use the specified
      // "Home" address and drop the "Work" address entirely. To manage this,
      // we need to distinguish between the work-defaulting-to-home address and
      // the actual-home address, so we collect them separately here, then
      // merge the arrays together below, thus selecting the specified-home
      // over the default-to-home.
      $defaultLocationAddresses = $specifiedLocationAddresses = array();
      foreach ($this->attendee['profile']['addresses'] as $addressType => $address) {
        $address['ebAddressType'] = $addressType;
        $supportedLocationTypeId = NULL;
        switch ($addressType) {
          case 'work':
            $supportedLocationTypeId = 'Work';
            break;

          case 'bill':
            $supportedLocationTypeId = 'Billing';
            break;

          case 'home':
            $supportedLocationTypeId = 'Home';
            break;

        }
        if ($supportedLocationTypeId) {
          if (!self::isAddressValid($address)) {
            // This address is poorly formatted or missing important info. Skip it entirely.
            continue;
          }
          if (!array_key_exists($supportedLocationTypeId, $locationTypes)) {
            $defaultLocationAddresses[$defaultLocationTypeId] = $address;
          }
          else {
            $specifiedLocationAddresses[$supportedLocationTypeId] = $address;
          }
        }
      }
      $finalAddresses = $specifiedLocationAddresses + $defaultLocationAddresses;
      // Now we have a final list of address to add. Add them, being sure to
      // first remove any existing address with that locationType.
      foreach ($finalAddresses as $locationTypeId => $address) {
        $addresses = _eventbrite_civicrmapi('Address', 'get', array(
          'return' => array('id'),
          'location_type_id' => $locationTypeId,
          'contact_id' => $this->contactId,
        ), "Processing Attendee {$this->entityId}, attempting to get existing '{$address['ebAddressType']}' address.");
        if ($addresses['count']) {
          $addressId = max(array_keys($addresses['values']));
          if ($addressId) {
            _eventbrite_civicrmapi('Address', 'delete', array(
              'id' => $addressId,
            ), "Processing Attendee {$this->entityId}, attempting to delete existing '{$address['ebAddressType']}' address.");
          }
        }
        $addressCreate = _eventbrite_civicrmapi('Address', 'create', array(
          'location_type_id' => $locationTypeId,
          'contact_id' => $this->contactId,
          'city' => $address['city'],
          'country' => $address['country'],
          'state_province' => $address['region'],
          'postal_code' => $address['postal_code'],
          'street_address' => $address['address_1'],
          'supplemental_address_1' => $address['address_2'],
        ), "Processing Attendee {$this->entityId}, attempting to create new '{$address['ebAddressType']}' address");
        if ($addressCreate['id']) {
          CRM_Eventbrite_BAO_EventbriteLog::create(array(
            'message' => "Address id '{$addressCreate['id']}' created from EB '{$address['ebAddressType']}' address in Attendee {$this->entityId}.",
            'message_type_id' => CRM_Eventbrite_BAO_EventbriteLog::MESSAGE_TYPE_ID_GENERAL,
          ));
        }
      }
    }
  }

  private function updateContactPhone($locationType, $phone = NULL) {
    if ($phone) {
      $phones = _eventbrite_civicrmapi('Phone', 'get', array(
        'return' => array('id'),
        'location_type_id' => $locationType,
        'contact_id' => $this->contactId,
      ), "Processing Attendee {$this->entityId}, attempting to get existing '{$locationType}' phone.");
      if ($phones['count']) {
        $phoneId = max(array_keys($phones['values']));
        if ($phoneId) {
          _eventbrite_civicrmapi('Phone', 'delete', array(
            'id' => $phoneId,
          ), "Processing Attendee {$this->entityId}, attempting to delete existing '{$locationType}' phone.");
        }
      }
      $phoneCreate = _eventbrite_civicrmapi('Phone', 'create', array(
        'location_type_id' => $locationType,
        'contact_id' => $this->contactId,
        'phone' => $phone,
      ), "Processing Attendee {$this->entityId}, attempting to create new existing '{$locationType}' phone.");
    }
  }

  public static function getRolePerTicketType($ticketClassId, $attendeeId = NULL) {
    return STUDENT_ROLE;
  }

  //public static function getRolePerTicketType($ticketClassId, $attendeeId = NULL) {
  //  print("in getRolePerTicketType...\n");
  //  static $roleIds = array();
  //  if (!isset($roleIds[$ticketClassId])) {
  //    $roleIds[$ticketClassId] = NULL;

  //    // Get role from ticket class.
  //    $role = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
  //      'return' => 'civicrm_entity_id',
  //      'civicrm_entity_type' => 'ParticipantRole',
  //      'eb_entity_type' => 'TicketType',
  //      'eb_entity_id' => $ticketClassId,
  //      'sequential' => 1,
  //    ), "Processing Attendee '{$attendeeId}', attempting to determine configured RoleID for attendee Ticket Type ID " . $ticketClassId);

  //    if ($role['count']) {
  //      $roleIds[$ticketClassId] = CRM_Utils_Array::value('civicrm_entity_id', $role['values'][0]);
  //    }
  //  }
  //  var_dump($roleIds);
  //  return $roleIds[$ticketClassId];
  //}

  public static function setParticipantStatusRemoved($participantId) {
    _eventbrite_civicrmapi('participant', 'create', array(
      'id' => $participantId,
      'participant_status' => 'Removed_in_EventBrite',
    ), "Processing Participant {$participantId}, attempting to mark participant as 'Removed in Eventbrite'.");
    self::cancelParticipantPayments($participantId);
  }

  public static function cancelParticipantPayments($participantId) {
    $participantPayments = _eventbrite_civicrmapi('participantPayment', 'get', array(
      'participant_id' => $participantId,
    ), "Processing Participant {$participantId}, attempting to get existing contribution.");
    foreach ($participantPayments['values'] as $value) {
      _eventbrite_civicrmapi('contribution', 'create', array(
        'id' => $value['contribution_id'],
        'contribution_status_id' => 'cancelled',
      ), "Processing Participant {$participantId}, attempting to get existing contribution.");
    }
  }

  private function updateCustomFields() {
    $questions = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'parent_id' => $this->eventId,
      'eb_entity_type' => 'Question',
      'civicrm_entity_type' => 'CustomField',
    ), "Processing Attendee {$this->entityId}, attempting to get all custom fields configured for event '{$this->eventId}'.");

    if (!$questions['count']) {
      // No questions configured for this event, so just return.
      return;
    }

    $keyedQuestions = CRM_Utils_Array::rekey($this->attendee['answers'], 'question_id');

    $contactValues = $participantValues = array();

    foreach ($questions['values'] as $value) {
      $questionId = CRM_Utils_Array::value('eb_entity_id', $value);
      if (!array_key_exists($questionId, $keyedQuestions)) {
        continue;
      }
      $answerValue = CRM_Utils_Array::value('answer', $keyedQuestions[$questionId]);

      $field = civicrm_api3('CustomField', 'getSingle', [
        'sequential' => 1,
        'id' => CRM_Utils_Array::value('civicrm_entity_id', $value),
        'api.CustomGroup.getsingle' => [],
      ]);
      $fieldId = $field['id'];
      $extends = $field['api.CustomGroup.getsingle']['extends'];
      if ($extends == 'Individual' || $extends == 'Contact') {
        $contactValues['custom_' . $fieldId] = $answerValue;
      }
      elseif ($extends == 'Individual' || $extends == 'Contact') {
        $participantValues['custom_' . $fieldId] = $answerValue;
      }
    }

    if (!empty($participantValues)) {
      $participantValues['id'] = $this->participantId;
      $participant = _eventbrite_civicrmapi('participant', 'create', $participantValues, "Processing Attendee {$this->entityId}, attempting to update participant custom fields.");
    }

    if (!empty($contactValues)) {
      $contactValues['id'] = $this->contactId;
      $contact = _eventbrite_civicrmapi('contact', 'create', $contactValues, "Processing Attendee {$this->entityId}, attempting to update contact custom fields.");
    }
  }

  private static function isAddressValid($address) {
    return (!empty(CRM_Utils_Array::value('address_1', $address)));
  }
}
