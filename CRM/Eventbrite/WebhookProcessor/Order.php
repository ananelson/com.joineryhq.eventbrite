<?php

use CRM_Eventbrite_ExtensionUtil as E;

const IS_CLASS_CANCELLED_FIELD = 'custom_154';
const PAYPAL_PAYMENT_METHOD = 10;
const PAYPAL_FIXED = 0.30;
const PAYPAL_PERCENT = 0.022;

const SCHOLARSHIP_CREDIT_PAYMENT_METHOD = 11;
const VOUCHER_CREDIT_PAYMENT_METHOD = 7;

/**
 * Class for processing Eventbrite 'Order' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Order extends CRM_Eventbrite_WebhookProcessor {

  public $expansions = array('attendees', 'answers', 'promotional_code');
  private $order = NULL;
  private $eventId = NULL;
  private $event = NULL;

  protected function loadData() {
    $this->order = $this->generateData();
  }

  public function setCiviEventIdForOrder() {
    $this->eventId = _eventbrite_civicrmapi('EventbriteLink', 'getValue', array(
      'eb_entity_type' => 'Event',
      'civicrm_entity_type' => 'Event',
      'eb_entity_id' => CRM_Utils_Array::value('event_id', $this->order),
      'return' => 'civicrm_entity_id',
    ), "Processing Order {$this->entityId}, attempting to get linked event for order.");

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

  public function orderAttendeesWithValidTicketTypes() {
    // Determine a list of OrderAttendeeIds, for Attendees with valid Ticket Types.
    $orderAttendees = array();

    foreach ($this->order['attendees'] as $attendee) {
      if (CRM_Eventbrite_WebhookProcessor_Attendee::getRolePerTicketType(CRM_Utils_Array::value('ticket_class_id', $attendee))) {
        $orderAttendees[$attendee['id']] = $attendee;
      }
    }
    return $orderAttendees;
  }

  public function getExistingPrimaryParticipantId() {
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'eb_entity_type' => 'Order',
      'civicrm_entity_type' => 'PrimaryParticipant',
      'eb_entity_id' => $this->entityId,
      'sequential' => 1,
      'api.participant.get' => array(
        'id' => '$value.civicrm_entity_id',
      ),
    ), "Processing Order {$this->entityId}, attempting to get linked PrimaryParticipant for the order.");
    if ($link['count']) {
      $existingPrimaryParticipantLinkId = $link['id'];
      $existingPrimaryParticipantId = CRM_Utils_Array::value('id', $link['values'][0]['api.participant.get']);
      return $existingPrimaryParticipantId;
    }
  }

  public function getParticipantsRegisteredByPrimary() {
    $existingParticipantIds = array($this->primaryParticipantId);
    $participant = _eventbrite_civicrmapi('participant', 'get', array(
      'registered_by_id' => $this->primaryParticipantId,
      'options' => array(
        'limit' => 0,
      ),
    ), "Processing Order {$this->entityId}, attempting to get all participants currently associated with this order.");
    $existingParticipantIds += array_keys($participant['values']);
    return $existingParticipantIds;
  }

  /**
   * Returns a link to the eventbrite attendee ID corresponding to the civi participantID if a link is found
   */ 
  public function getExistingParticipantLink($participantId) {
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'civicrm_entity_type' => 'Participant',
      'civicrm_entity_id' => $participantId,
      'eb_entity_type' => 'Attendee',
      'sequential' => 1,
    ), "Processing Order {$this->entityId}, attempting to get Attendee linked to participant '$participantId'.");
    if ($link['count']) {
      return $link['values'][0]['eb_entity_id'];
    }
  }

  /**
   * Returns a link to the civi participant ID corresponding to the EB attendeeID if a link is found
   */ 
  public function getExistingAttendeeLink($attendeeId) {
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'civicrm_entity_type' => 'Participant',
      'eb_entity_type' => 'Attendee',
      'eb_entity_id' => $attendeeId,
      'sequential' => 1,
    ), "Processing Order {$this->entityId}, attempting to get Attendee linked to participant '$participantId'.");
    if ($link['count']) {
      return $link['values'][0]['civicrm_entity_id'];
    }
  }

  public function isRegisteredParticipantLinked($participantId) {
    $linkedAttendeeId = $this->getExistingParticipantLink($participantId);
    if ($linkedAttendeeId) {
      return in_array($linkedAttendeeId, $this->orderAttendeeIds);
    }
  }

  public function nullifyRegistration($existingParticipantId) {
    // if the participant is not linked to an attendee that's in OrderAttendeeIds, then
    // set status 'removed from eventbrite'
    CRM_Eventbrite_WebhookProcessor_Attendee::setParticipantStatusRemoved($existingParticipantId);
    // set registered_by_id = null
    _eventbrite_civicrmapi('participant', 'create', array(
      'id' => $existingParticipantId,
      'registered_by_id' => 'null',
    ), "Processing Order {$this->entityId}, attempting to unset registered_by_id for participant id '$existingParticipantId', previously associated with this order.");
  }

  public function updateRegisteredBy() {
    foreach ($this->orderParticipantIds as $orderParticipantId) {
      // For each pid in OrderParticipantIds, set registered_by_id = PrimaryParticipantId
      _eventbrite_civicrmapi('participant', 'create', array(
        'id' => $orderParticipantId,
        'registered_by_id' => $this->primaryParticipantId,
      ), "Processing Order {$this->entityId}, attempting to associate participant '$orderParticipantId' with order primary participant '$this->primaryParticipantId'.");
    }
  }

  public function updatePrimaryParticipant($existingPrimaryParticipantLinkId) {
    // Create/update link for PrimaryParticipant
    _eventbrite_civicrmapi('EventbriteLink', 'create', array(
      'id' => $existingPrimaryParticipantLinkId,
      'eb_entity_type' => 'Order',
      'civicrm_entity_type' => 'PrimaryParticipant',
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_id' => $this->primaryParticipantId,
    ), "Processing Order {$this->entityId}, attempting to create/update PrimaryParticipant link for this order.");
  }


  public function isEventMonetary() {
    // Handle contributions, but only if event is configured as is_monetary.
    return CRM_Utils_Array::value('is_monetary', $this->event);
  }

  public function updateContribution($grossSum, $feeSum, $creditSum, $scholarshipSum) {
    $isMonetary = $this->isEventMonetary();
    if (!$isMonetary) {
      return;
    }
    if ($grossSum == 0) {
      return;
    }

    $financialTypeId = CRM_Utils_Array::value('financial_type_id', $this->event);

    // original logic
    $isCheckPayment = $this->order['costs']['eventbrite_fee']['value']
      && $this->order['costs']['base_price']['value']
      && !$this->order['costs']['payment_fee']['value'];

    // TODO make this a setting
    $isCheckPayment = false;

    $contactId = _eventbrite_civicrmapi('participant', 'getValue', array(
      'return' => 'contact_id',
      'id' => $this->primaryParticipantId,
    ), "Processing Order {$this->entityId}, attempting to get Contact ID for order primary participant '$this->primaryParticipantId'.");

    // Define contribution params based on Order.costs
    $contributionParams = array(
      'receive_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->order)),
      'total_amount' => $grossSum,
      'fee_amount' => $feeSum,
      'financial_type_id' => $financialTypeId,
      'payment_instrument_id' => 'Credit Card',
      'contribution_status_id' => 'Pending',
      'source' => E::ts('Eventbrite Integration'),
      'contact_id' => $contactId,
    );

    // Determine which contribution is linked to this order, if any.
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'eb_entity_type' => 'Order',
      'civicrm_entity_type' => 'Contribution',
      'eb_entity_id' => $this->entityId,
      'sequential' => 1,
    ), "Processing Order {$this->entityId}, attempting to get linked contribution for this order, if any.");
    $linkId = CRM_Utils_Array::value('id', $link);

    if ($linkId) {
      $linkedContributionId = CRM_Utils_Array::value('civicrm_entity_id', $link['values'][0]);
      $result = _eventbrite_civicrmapi('Contribution', 'get', array('id' => $linkedContributionId));
      if ($result['count'] == 0) {
        print("\ncould not find linked contribution with ID $linkedContributionId, removing link\n");
        _eventbrite_civicrmapi('EventbriteLink', 'delete', array('id' => $linkId));
        unset($linkId);
        unset($linkedContributionId);
      } else {
        $contributionParams['id'] = $linkedContributionId;
        $contributionParams['contribution_status_id'] = $result['values'][0]['contribution_status_id'];
      }
    }

    $orderStatus = CRM_Utils_Array::value('status', $this->order);

    $isOrderCancelled = in_array($orderStatus, array('refunded', 'cancelled', 'deleted'));
    $isClassCancelled = $this->event[IS_CLASS_CANCELLED_FIELD];
    $isCancelled = $isOrderCancelled or $isClassCancelled;

    if ($isCancelled) {
      // If order status is 'deleted' or 'cancelled'/'refunded', contribution status = cancelled.
      $contributionParams['contribution_status_id'] = 'Cancelled';
    }
    elseif ($isCheckPayment) {
      // A pay_by_check order must have base amount > 0, eventbrite fee > 0,
      // and payment_fee = 0.
      //
      // However, it is possible to refund a CC-paid order down so low that these
      // conditions are met; in this case the order will appear to be pay_by_check,
      // and if the contribution has not yet beeen created by this point, it
      // will be created with a 'Pending' status. This seems fairly unlikely,
      // as you'd need an unusual sequence of events:
      // - order is placed and paid with CC
      // - order is NOT synced to CiviCRM, either because of some unexpected
      //   delay, or because the following steps just happen too quickly.
      // - order is refunded to an amount smaller than the EB fees.
      // - order is finally synced for the first time to CiviCRM.
      //
      if (empty($contributionParams['id'])) {
        // And, we only want to do this if it's a newly created contribution;
        // there's really no time when we'd be setting an existing contrib
        // to 'pending'.
        $contributionParams['payment_instrument_id'] = 'Check';
      }
    }

    $isExistingContribution = array_key_exists('id', $contributionParams);

    $msg = "Processing Order {$this->entityId}, attempting to create/update contribution record.";
    $contribution = _eventbrite_civicrmapi('Contribution', 'create', $contributionParams, $msg);
    $contributionId = array_keys($contribution['values'])[0];

    print("\nContribution status is\n");
    var_dump($contribution['contribution_status_id']);

    if (!isset($contribution['contribution_status_id']) or $contribution['contribution_status_id'] == 'Pending') {
      $creditCardPaymentAmount = $grossSum - $creditSum;

      if ($creditCardPaymentAmount > 0) {
        $paymentParams = array(
          'contribution_id' => $contributionId,
          'trxn_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->order)),
          'payment_instrument_id' => PAYPAL_PAYMENT_METHOD,
          'total_amount' => $grossSum,
          'fee_amount' => $feeSum,
          'net_amount' => $grossSum - $feeSum,
          'is_send_contribution_notification' => 0
        );

        $payment = _eventbrite_civicrmapi('Payment', 'create', $paymentParams);
      }

      if ($creditSum > 0) {
        $paymentParams = array(
          'contribution_id' => $contributionId,
          'trxn_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->order)),
          'payment_instrument_id' => VOUCHER_CREDIT_PAYMENT_METHOD,
          'trxn_id' => $this->discountId,
          'check_number' => $this->voucherCode,
          'total_amount' => $creditSum,
          'fee_amount' => 0,
          'net_amount' => $creditSum,
          'is_send_contribution_notification' => 0
        );
        $payment = _eventbrite_civicrmapi('Payment', 'create', $paymentParams);
      }
    }

    // TODO update payment if class is subsequently cancelled

    // Link primary participant to contribution as participantPayment.
    $participantPayment = _eventbrite_civicrmapi('ParticipantPayment', 'create', array(
      'participant_id' => $this->primaryParticipantId,
      'contribution_id' => $contributionId
    ));

    // Create new link between Order and ContributionId.
    _eventbrite_civicrmapi('EventbriteLink', 'create', array(
      'id' => $linkId,
      'civicrm_entity_type' => 'Contribution',
      'civicrm_entity_id' => $contributionId,
      'eb_entity_type' => 'Order',
      'eb_entity_id' => $this->entityId,
    ), "Processing Order {$this->entityId}, attempting to create/update Order/Contribution link.");
  }

  public function process() {
    $this->setCiviEventIdForOrder();
    if (!$this->eventId) {
      return;
    }

    $orderAttendees = $this->orderAttendeesWithValidTicketTypes();
    if (empty($orderAttendees)) {
      return;
    }

    $this->orderAttendeeIds = array_keys($orderAttendees);
    $primaryAttendeeId = min($this->orderAttendeeIds);

    // remove any participants previously linked who are no longer part of the order
    $this->primaryParticipantId = $this->getExistingPrimaryParticipantId();

    if ($this->primaryParticipantId) {
      $existingParticipantIds = $this->getParticipantsRegisteredByPrimary();

      foreach ($existingParticipantIds as $existingParticipantId) {
        $isOrderAttendee = $this->isRegisteredParticipantLinked($existingParticipantId);

        if (!$isOrderAttendee) {
          $this->nullifyRegistration($existingParticipantId);
        }
      }
    }

    // loop over order participants and add them if not already added
    $this->orderParticipantIds = array();
    $grossSum = $feesSum = $scholarshipSum = $creditSum = 0;

    foreach ($orderAttendees as $orderAttendeeId => $orderAttendee) {
      $orderParticipantId = $this->getExistingAttendeeLink($orderAttendeeId);

      if (!$orderParticipantId) {
        // process Attendee (this will create a linked participant)
        // need to create a fake payload isntead of pass $orderAttendee since
        // it won't provide promo code data otherwise
        $fakePayload = array(
          "config" =>  ["action" => "attendee.updated"],
          'api_url' => $orderAttendee['resource_uri']
        );
        $attendeeProcessor = new CRM_Eventbrite_WebhookProcessor_Attendee($fakePayload);
        $attendeeProcessor->process();
        $this->voucherCode = $attendeeProcessor->voucherCode;
        $this->discountId = $attendeeProcessor->discountId;
        $orderParticipantId = $attendeeProcessor->participantId;
      }

      $this->orderParticipantIds[] = $orderParticipantId;
      if ($orderAttendeeId == $primaryAttendeeId) {
        // save the primaryParticipantId for later
        $this->primaryParticipantId = $orderParticipantId;
      }

      // Add to gross and fee totals for this Attendee.
      $grossValue = $orderAttendee['costs']['gross']['major_value'];
      $ebFee = $orderAttendee['costs']['gross']['major_value'];
      $pmtFee = $orderAttendee['costs']['payment_fee']['major_value'];
  
      if ($attendeeProcessor->valueUsed > 0) {
        // TODO Figure out if credit or scholarship...
        $creditSum += $attendeeProcessor->valueUsed;
      }

      $grossSum += $grossValue;
      if ($pmtFee == 0.00 and $grossValue > 0.0) {
        $pmtFee = PAYPAL_FIXED + PAYPAL_PERCENT * $grossValue;
      }

      // TODO make this a setting - but we don't want EB fees deducted
      //$feeSum += $orderAttendee['costs']['eventbrite_fee']['major_value'];

      // add credit amount to gross sum (after calculating fees)
      $grossSum += $creditSum;
      $feeSum += $pmtFee;
    }

    $this->updateRegisteredBy();
    $this->updatePrimaryParticipant($existingPrimaryParticipantLinkId);
    $this->updateContribution($grossSum, $feeSum, $creditSum, $scholarshipSum);
  }
}
