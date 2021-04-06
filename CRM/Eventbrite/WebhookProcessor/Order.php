<?php

use CRM_Eventbrite_ExtensionUtil as E;

const DEDUPE_RULE_ID = 1;
const DEFAULT_PAYMENT_PROCESSOR = 1;

/**
 * Class for processing Eventbrite 'Order' webhook events.
 *
 */
class CRM_Eventbrite_WebhookProcessor_Order extends CRM_Eventbrite_WebhookProcessor {

  public $expansions = array('attendees', 'answers', 'promotional_code');
  public $eventId = NULL;
  public $event = NULL;
  public $order = NULL;

  protected function loadData() {
    $this->order = $this->generateData();
  }

  /**
   * Set the eventId and event attributes.
   */
  public function setCiviEventIdForOrder() {
    $this->eventId = _eventbrite_civicrmapi('EventbriteLink', 'getValue', [
      'eb_entity_type' => 'Event',
      'civicrm_entity_type' => 'Event',
      'eb_entity_id' => CRM_Utils_Array::value('event_id', $this->order),
      'return' => 'civicrm_entity_id',
    ], "Processing Order {$this->entityId}, attempting to get linked event for order.");

    if (!$this->eventId) {
      CRM_Eventbrite_BAO_EventbriteLog::create(array(
        'message' => "Could not find EventbriteLink record 'Event' for order {$this->entityId} with 'event_id': " . CRM_Utils_Array::value('event_id', $this->order) . "; skipping Order. In method " . __METHOD__ . ", file " . __FILE__ . ", line " . __LINE__,
        'message_type_id' => CRM_Eventbrite_BAO_EventbriteLog::MESSAGE_TYPE_ID_GENERAL,
      ));
    } else {
      $this->event = _eventbrite_civicrmapi('Event', 'getSingle', array(
        'id' => $this->eventId,
      ), "Processing Order {$this->entityId}, attempting to get the CiviCRM Event for this order.");
      \CRM_Core_Error::debug_var("event", $this->event);
    }
  }

  /**
   * Populates the $orderAttendees list with attendees having a valid ticket type.
   */
  public function setOrderAttendeesList() {
    $this->orderAttendees = array();

    foreach ($this->order['attendees'] as $attendee) {
      $ticket_class_id = CRM_Utils_Array::value('ticket_class_id', $attendee);
      $isValidTicketType = !is_null(CRM_Eventbrite_WebhookProcessor_Attendee::readTicketType($ticket_class_id));
      if ($isValidTicketType) {
        $this->orderAttendees[$attendee['id']] = $attendee;
      }
    }
  }

  public function getExistingPrimaryParticipantId() {
    \CRM_Core_Error::debug_log_message("in getExistingPrimaryParticipantId");
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'eb_entity_type' => 'Order',
      'eb_entity_id' => $this->entityId,
      'civicrm_entity_type' => 'PrimaryParticipant',
      'sequential' => 1,
      'api.participant.get' => array(
        'id' => '$value.civicrm_entity_id',
      ),
    ), "Processing Order {$this->entityId}, attempting to get linked PrimaryParticipant for the order.");

    \CRM_Core_Error::debug_var("existing primary participant link", $link);
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
    \CRM_Core_Error::debug_log_message("in getExistingParticipantLink");
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'civicrm_entity_type' => 'Participant',
      'civicrm_entity_id' => $participantId,
      'eb_entity_type' => 'Attendee',
      'sequential' => 1,
    ), "Processing Order {$this->entityId}, attempting to get Attendee linked to participant '$participantId'.");
    \CRM_Core_Error::debug_var("existing participant ", $link);
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

  public function assignContributionParams() {
    $this->financialTypeId = CRM_Utils_Array::value('financial_type_id', $this->event);

    $this->isCheckPayment = $this->order['costs']['eventbrite_fee']['value']
      && $this->order['costs']['base_price']['value']
      && !$this->order['costs']['payment_fee']['value'];


    $this->contributionParams = array(
      'receive_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->order)),
      'total_amount' => $this->grossSum,
      'fee_amount' => $this->feesSum,
      'net_amount' => $this->grossSum - $this->feesSum,
      'financial_type_id' => $this->financialTypeId,
      'contribution_status_id' => 'Pending',
      'source' => E::ts("Eventbrite Order {$this->entityId}"),
      'contact_id' => $this->orderPurchaserContact['id'],
    );

    if ($this->isCheckPayment) {
      $this->contributionParams['payment_instrument_id'] = 'Check';
    } else {
      $this->contributionParams['payment_instrument_id'] = 'Credit Card';
    }
  }

  /**
   * Updates $this->contributionParams with existing contrib ID and status if found.
   *
   */
  public function assignExistingContribution() {
    \CRM_Core_Error::debug_log_message("in assignExistingContribution");
    // Determine which Civi contribution is linked to this EB order, if any.
    $link = _eventbrite_civicrmapi('EventbriteLink', 'get', array(
      'eb_entity_type' => 'Order',
      'civicrm_entity_type' => 'Contribution',
      'eb_entity_id' => $this->entityId,
      'sequential' => 1,
    ), "Processing Order {$this->entityId}, attempting to get linked contribution for this order, if any.");
    $linkId = CRM_Utils_Array::value('id', $link);
    \CRM_Core_Error::debug_log_message("existing contribution linkid $linkId");

    $this->existingPayments = null;
    $this->existingPaymentsTotalValue = 0;
    $this->existingRefundsTotalValue = 0;

    if ($linkId) {
      $linkedContributionId = CRM_Utils_Array::value('civicrm_entity_id', $link['values'][0]);
      $result = _eventbrite_civicrmapi('Contribution', 'get', array('id' => $linkedContributionId));
      if ($result['count'] == 0) {
        // Existing link points to nothing, remove it.
        \CRM_Core_Error::debug_log_message("existing contribution link no longer valid, remove it");
        _eventbrite_civicrmapi('EventbriteLink', 'delete', array('id' => $linkId));
        unset($linkId);
        unset($linkedContributionId);
      } else {
        // Link points to valid contribution.
        $this->contributionParams['id'] = $linkedContributionId;
        $this->contributionParams['contribution_status_id'] = $result['values'][0]['contribution_status_id'];
        $this->contributionParams['payment_instrument_id'] = $result['values'][0]['payment_instrument_id'];
        $this->isExistingContribution = true;
        \CRM_Core_Error::debug_log_message("found existing valid contribution with status {$this->contributionParams['contribution_status_id']}");
      }
    }
  }

  public function createOrUpdateContribution() {
    \CRM_Core_Error::debug_log_message("in createOrUpdateContribution");
    $msg = "Processing Order {$this->entityId}, attempting to create/update contribution record.";
    $result = _eventbrite_civicrmapi('Contribution', 'create', $this->contributionParams, $msg);
    $this->contribution = array_values($result['values'])[0];

    \CRM_Core_Error::debug_var("participant", $this->primaryParticipantId);

    // Link primary participant to contribution as participantPayment.
    $participantPayment = _eventbrite_civicrmapi('ParticipantPayment', 'create', array(
      'participant_id' => $this->primaryParticipantId,
      'contribution_id' => $this->contribution['id']
    ));

    if (!$this->isExistingContribution) {
      // Create new link between Order and ContributionId.
      _eventbrite_civicrmapi('EventbriteLink', 'create', array(
        'id' => $linkId,
        'civicrm_entity_type' => 'Contribution',
        'civicrm_entity_id' => $this->contribution['id'],
        'eb_entity_type' => 'Order',
        'eb_entity_id' => $this->entityId,
      ), "Processing Order {$this->entityId}, attempting to create/update Order/Contribution link.");
    }
  }

  public function primaryPaymentParams() {
    if ($this->grossSum > 0) {
      return array(
        'contribution_id' => $this->contribution['id'],
        'trxn_date' => CRM_Utils_Date::processDate(CRM_Utils_Array::value('created', $this->order)),
        'payment_processor_id' => DEFAULT_PAYMENT_PROCESSOR,
        'total_amount' => $this->grossSum,
        'fee_amount' => $this->feesSum,
        'net_amount' => $this->grossSum - $this->feesSum,
        'is_send_contribution_notification' => 0
      );
    }
  }

  public function assignPaymentParams() {
    $this->proposedPayments = [];
    $this->proposedPayments[] = $this->primaryPaymentParams();
    \CRM_Core_Error::debug_var("proposed payments in assignPaymentParams", $this->proposedPayments);
  }

  public function assignExistingPayments() {
    \CRM_Core_Error::debug_log_message("in assignExistingPayments checking for existing payments");
    // check for existing payments
    $result = civicrm_api3('Payment', 'get', [
      'sequential' => 1,
      'entity_id' => $this->contribution['id'],
    ]);
    \CRM_Core_Error::debug_var("payment result", $result);
    if ($result['count'] > 0) {
      $this->existingPayments = $result['values'];
      foreach ($this->existingPayments as $paymentId=>$paymentInfo) {
        \CRM_Core_Error::debug_var("existing pmt", $paymentInfo);
        if ($paymentInfo['status_id'] == 1)  {
          $this->existingPaymentsTotalValue += $paymentInfo['total_amount'];
        } else if ($paymentInfo['status_id'] == 7) {
          $this->existingRefundsTotalValue += $paymentInfo['total_amount'];
        }
      }
    }
    \CRM_Core_Error::debug_log_message("existing payments value {$this->existingPaymentsTotalValue}");
    \CRM_Core_Error::debug_log_message("existing refunds value {$this->existingRefundsTotalValue}");
  }

  /**
   *
   */
  public function applyPayments() {
    \CRM_Core_Error::debug_log_message("in applyPayments");
    $this->assignExistingPayments();

    \CRM_Core_Error::debug_var("contribution", $this->contribution);

    if ($this->isExistingContribution and $this->isOrderCancelled) {
      $fullyRefunded = abs($this->existingPaymentsTotalValue) == abs($this->existingRefundsTotalValue);
      if ($fullyRefunded) {
        \CRM_Core_Error::debug_log_message("this cancelled contribution is fully refunded, don't change payments");
        return;
      }
    }

    $this->assignPaymentParams();
    $this->dispatchSymfonyEvent("PaymentParamsAssigned");

    
    $this->payments = array();
    foreach ($this->proposedPayments as $paymentInfo) {
      \CRM_Core_Error::debug_log_message("looping over proposed payments..");
      \CRM_Core_Error::debug_var("paymentInfo", $paymentInfo);
      $result = _eventbrite_civicrmapi('Payment', 'create', $paymentInfo);
      \CRM_Core_Error::debug_var("result of payment create", $result);
      $payment = $result['values'][array_key_first($result['values'])];
      \CRM_Core_Error::debug_var("payment is..", $payment);
      $this->payments[] = $payment;
    }
    \CRM_Core_Error::debug_var("all payments", $this->payments);
  }

  public function cancelOrder() {
    \CRM_Core_Error::debug_log_message("in cancelOrder");
    $cancel_date = CRM_Utils_Date::processDate(CRM_Utils_Array::value('changed', $this->order));
    // marking contribution as cancelled will make a refund on cancel_date
    $result = _eventbrite_civicrmapi('Contribution', 'update', array(
      'id' => $this->contribution['id'],
      'contribution_status_id' => 'Refunded',
      'cancel_date' => $cancel_date // cancel or refund date
    ));

    \CRM_Core_Error::debug_var("result of refunding contribution", $result);

    // mark attendee status as cancelled
    foreach ($this->orderParticipantIds as $participantId) {
      $result = _eventbrite_civicrmapi('Participant', 'create', array(
        'id' => $participantId,
        'participant_status' => 'Cancelled'
      ));
    }
  }

  public function updateContribution() {
    \CRM_Core_Error::debug_log_message("in updateContribution");
    if (!$this->isEventMonetary()) {
      return;
    }
    if ($this->grossSum == 0) {
      return;
    }

    $this->assignContributionParams();
    $this->dispatchSymfonyEvent("ContributionParamsAssigned");

    $this->assignExistingContribution();

    $orderStatus = CRM_Utils_Array::value('status', $this->order);
    $this->isOrderCancelled = in_array($orderStatus, array('refunded', 'cancelled'));
    $this->isOrderDeleted = $orderStatus == 'deleted';

    $this->createOrUpdateContribution();
    $this->applyPayments();
    \CRM_Core_Error::debug_log_message("done iwth applyPaments");

    if ($this->isOrderCancelled) {
      $this->cancelOrder();
    }
    \CRM_Core_Error::debug_log_message("done with updateContribution");
  }

  /*
      get a Civi contact for the person who placed the order
      (may or may not be an attendee)
   */
  public function getOrderPurchaser() {
    // TODO unify this with Attendee contact Params()
    $contactParams =  array(
      'contact_type' => 'Individual',
      'first_name' => $this->order['first_name'],
      'last_name' => $this->order['last_name'],
      'email' => $this->order['email'],
    );
    $result = _eventbrite_civicrmapi('Contact', 'duplicatecheck', array(
      'dedupe_rule_id' => DEDUPE_RULE_ID,
      'match' => $contactParams,
    ));
    if ($result['count'] > 0) {
      $contactId = array_keys($result['values'])[0];
      return _eventbrite_civicrmapi('Contact', 'getsingle', array('id' => $contactId));
    } else {
      return  _eventbrite_civicrmapi('Contact', 'create', $contactParams);
    }
  }

  /**
   * Removes any previous participants who are no longer on the order.
   */
  public function setPurchaserAndPrimaryAttendee() {
    // the person who purchased the order need not be an attendee
    $this->orderPurchaserContact = $this->getOrderPurchaser();

    $this->orderAttendeeIds = array_keys($this->orderAttendees);
    $this->primaryAttendeeId = min($this->orderAttendeeIds);

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
  }

  public function setupFees() {
    $this->grossSum = 0;
    $this->feesSum = 0;
  }

  public function addAttendeeFees($orderAttendee) {
      // Add to gross and fee totals for this Attendee.
      $this->grossValue = $orderAttendee['costs']['gross']['major_value'];
      $this->feesValue = $orderAttendee['costs']['payment_fee']['major_value'];

      $this->grossSum += $this->grossValue;
      $this->feesSum += $this->feesValue;
  }

  public function createAttendeeProcessor($orderAttendee) {
    // Need to create a fake payload to trigger EB API call so promo code will be included
    $fakePayload = array(
      "config" =>  ["action" => "attendee.updated"],
      'api_url' => $orderAttendee['resource_uri']
    );
    return new CRM_Eventbrite_WebhookProcessor_Attendee($fakePayload);
  }

  public function processAttendees() {
    $this->orderParticipantIds = array();

    foreach ($this->orderAttendees as $orderAttendeeId => $orderAttendee) {
      $orderParticipantId = $this->getExistingAttendeeLink($orderAttendeeId);

      $this->currentAttendeeProcessor = $this->createAttendeeProcessor($orderAttendee);

      if (!$orderParticipantId) {
        $this->currentAttendeeProcessor->process();
        $orderParticipantId = $this->currentAttendeeProcessor->participantId;
      }

      $this->orderParticipantIds[] = $orderParticipantId;
      if ($orderAttendeeId == $this->primaryAttendeeId) {
        \CRM_Core_Error::debug_var("orderParticipantId", $orderParticipantId);
        $this->primaryParticipantId = $orderParticipantId;
      }

      $this->addAttendeeFees($orderAttendee);
      $this->dispatchSymfonyEvent("ProcessCurrentAttendeeFees");
    }
  }

  public function process() {
    \CRM_Core_Error::debug_log_message("in process() for ORder");
    \CRM_Core_Error::debug_var("order", $this->order);
    $this->setCiviEventIdForOrder();

    if (!$this->eventId) {
      return;
    }

    $this->setOrderAttendeesList();
    $this->dispatchSymfonyEvent("OrderAttendeesListSet");
    if (empty($this->orderAttendees)) {
      return;
    }

    $this->setupFees();
    $this->dispatchSymfonyEvent("FeesSetup");

    $this->setPurchaserAndPrimaryAttendee();
    $this->processAttendees();

    $this->updateRegisteredBy();
    $this->updatePrimaryParticipant($existingPrimaryParticipantLinkId);
    $this->updateContribution();
    \CRM_Core_Error::debug_log_message("done iwth updatin contrib..");
  }
}
