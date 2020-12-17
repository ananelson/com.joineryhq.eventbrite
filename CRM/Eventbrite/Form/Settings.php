<?php

require_once 'CRM/Core/Form.php';

use CRM_Eventbrite_ExtensionUtil as E;

/**
 * Form controller class for extension Settings form.
 * Borrowed heavily from
 * https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/blob/master/CRM/Civixero/Form/XeroSettings.php
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Eventbrite_Form_Settings extends CRM_Core_Form {

  public static $settingFilter = array('group' => 'eventbrite');
  public static $extensionName = 'com.joineryhq.eventbrite';
  private $_submittedValues = array();
  private $_settings = array();

  public function __construct(
  $state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL
  ) {

    $this->setSettings();

    parent::__construct(
      $state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL
    );
  }

  public function buildQuickForm() {
    $settings = $this->_settings;
    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        switch ($setting['html_type']) {
          case 'Select':
            $this->add(
              // field type
              $setting['html_type'],
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              $this->getSettingOptions($setting), NULL, $setting['html_attributes']
            );
            break;

          case 'CheckBox':
            $this->addCheckBox(
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              array_flip($this->getSettingOptions($setting))
            );
            break;

          case 'Radio':
            $this->addRadio(
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              $this->getSettingOptions($setting)
            );
            break;

          default:
            $add = 'add' . $setting['quick_form_type'];
            if ($add == 'addElement') {
              $this->$add($setting['html_type'], $name, ts($setting['title']), CRM_Utils_Array::value('html_attributes', $setting, array()));
            }
            else {
              $this->$add($name, ts($setting['title']));
            }
            break;

        }
      }
      $descriptions[$setting['name']] = ts($setting['description']);

      if (!empty($setting['X_form_rules_args'])) {
        $rules_args = (array) $setting['X_form_rules_args'];
        foreach ($rules_args as $rule_args) {
          array_unshift($rule_args, $setting['name']);
          call_user_func_array(array($this, 'addRule'), $rule_args);
        }
      }
    }
    $this->assign("descriptions", $descriptions);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    $style_path = CRM_Core_Resources::singleton()->getPath(self::$extensionName, 'css/extension.css');
    if ($style_path) {
      CRM_Core_Resources::singleton()->addStyleFile(self::$extensionName, 'css/extension.css');
    }

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    if ($this->_validateTokenOnFormLoad()) {
      $this->_confirmWebhookOnFormLoad();
    }

    $breadCrumb = array(
      'title' => E::ts('Eventbrite Settings'),
      'url' => CRM_Utils_System::url('civicrm/admin/eventbrite/settings', 'reset=1'),
    );
    CRM_Utils_System::appendBreadCrumb(array($breadCrumb));

    parent::buildQuickForm();
  }

  public function postProcess() {
    $this->_submittedValues = $this->exportValues();
    $this->saveSettings();
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/eventbrite/settings', 'reset=1'));
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  private function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons". These
    // items don't have labels. We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * Define the list of settings we are going to allow to be set on this form.
   */
  private function setSettings() {
    if (empty($this->_settings)) {
      $this->_settings = self::getSettings();
    }
  }

  private static function getSettings() {
    $settings = _eventbrite_civicrmapi('setting', 'getfields', array('filters' => self::$settingFilter));
    return $settings['values'];
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   */
  private function saveSettings() {
    $settings = $this->_settings;
    $values = array_intersect_key($this->_submittedValues, $settings);
    _eventbrite_civicrmapi('setting', 'create', $values);

    // Save any that are not submitted, as well (e.g., checkboxes that aren't checked).
    $unsettings = array_fill_keys(array_keys(array_diff_key($settings, $this->_submittedValues)), NULL);
    _eventbrite_civicrmapi('setting', 'create', $unsettings);

    // Assume the token is only for one Eventbrite organization;
    // fetch and record that organization ID.
    // TODO only fetch this if needed, don't fetch if nothing has changed
    $eb = CRM_Eventbrite_EventbriteApi::singleton();
    if ($organizations = CRM_Utils_Array::value('organizations', $eb->request('users/me/organizations'))) {
      $organizationId = $organizations[0]['id'];
      _eventbrite_civicrmapi('setting', 'create', array(
        'eventbrite_api_organization_id' => $organizationId,
      ));
    }

    CRM_Core_Session::setStatus(" ", ts('Settings saved.'), "success");
  }

  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  public function setDefaultValues() {
    static $ret;
    if (!isset($ret)) {
      $result = _eventbrite_civicrmapi('setting', 'get', array(
        'return' => array_keys($this->_settings),
        'sequential' => 1,
      ));
      $ret = CRM_Utils_Array::value(0, $result['values']);
    }
    return $ret;
  }

  public static function getGroupOptions() {
    $options = array();
    $result = _eventbrite_civicrmapi('Group', 'get', array(
      'is_active' => 1,
      'options' => array('limit' => 0),
    ));
    foreach ($result['values'] as $id => $value) {
      $options[$id] = $value['title'];
    }
    asort($options);
    $options = array(0 => '- ' . ts('none') . ' -') + $options;
    return $options;
  }

  public static function getActivityTypeOptions() {
    $options = array();
    $result = _eventbrite_civicrmapi('OptionValue', 'get', array(
      'option_group_id' => "activity_type",
      'is_active' => 1,
      'options' => array('limit' => 0),
    ));
    foreach ($result['values'] as $id => $value) {
      $options[$value['value']] = $value['label'];
    }
    asort($options);
    return $options;
  }

  public static function getActivityStatusOptions() {
    $options = array();
    $result = _eventbrite_civicrmapi('OptionValue', 'get', array(
      'option_group_id' => "activity_status",
      'is_active' => 1,
      'options' => array('limit' => 0),
    ));
    foreach ($result['values'] as $id => $value) {
      $options[$value['value']] = $value['label'];
    }
    asort($options);
    return $options;
  }

  public function getSettingOptions($setting) {
    if (!empty($setting['X_options_callback']) && is_callable($setting['X_options_callback'])) {
      return call_user_func($setting['X_options_callback']);
    }
    else {
      return CRM_Utils_Array::value('X_options', $setting, array());
    }
  }

  /**
   * Upon displaying the form (i.e., only if it's not being submitted now),
   * perform a validation check on the saved Eventbrite token (if there is one)
   * and print a message if it's invalid.
   */
  private function _validateTokenOnFormLoad() {
    $isPass = TRUE;
    if (!$this->_flagSubmitted) {
      if ($token = CRM_Utils_Array::value('eventbrite_api_token', $this->setDefaultValues())) {
        try {
          $eb = CRM_Eventbrite_EventbriteApi::singleton();
          $result = $eb->request('users/me');
          if ($error = CRM_Utils_Array::value('error', $result)) {
            $isPass = FALSE;
            $error_message = CRM_Utils_Array::value('status_code', $result);
            $error_message .= ': ' . $error;
            $error_message .= ': ' . CRM_Utils_Array::value('error_description', $result);
            $msg = E::ts('Eventbrite said: <em>%1</em>', array('1' => $error_message));
            CRM_Core_Session::setStatus($msg, E::ts('Eventbrite token'), 'error');
          }
        }
        catch (CRM_Core_Exception $e) {
          CRM_Core_Session::setStatus($e->getMessage(), E::ts('Eventbrite API Exception'), 'error');
          $isPass = FALSE;
        }
      }
    }
    return $isPass;
  }

  private function deleteInvalidWebhooks($invalidWebhooks) {
    foreach ($invalidWebhooks as $webhookId) {
      $body = array('id' => $webhookId);
      try {
        $result = $eb->requestOrg('webhooks', $body, NULL, 'DELETE');
      } catch (EventbriteApiError $e) {}
    }
  }

  private function createWebhook($webhookActions) {
    $listener = CRM_Eventbrite_Utils::getWebhookListenerUrl();
    $body = array(
        'endpoint_url' => $listener,
        'actions' => implode(",", $webhookActions),
    );
    try {
        $result = $eb->requestOrg('webhooks', $body, NULL, 'POST');
        return $result['id'];
    } catch (EventbriteApiError $e) {
        return null;
    }
  }

  private function _confirmWebhookOnFormLoad() {
    if (!$this->_flagSubmitted) {
      if ($token = CRM_Utils_Array::value('eventbrite_api_token', $this->setDefaultValues())) {
        try {
          // the actions we want the webhook to have
          $webhookActions = array("attendee.updated", "order.updated");

          $webhookListenerUrl = CRM_Eventbrite_Utils::getWebhookListenerUrl();

          $validWebhookId = NULL;
          $invalidWebhooks = array();
          $webhookStatusMessages = array();

          // check if webhooks are already set up ok
          $eb = CRM_Eventbrite_EventbriteApi::singleton();
          $result = $eb->requestOrg('webhooks');
          foreach ($result['webhooks'] as $webhook) {
            // Webhook may be for another system, ignore.
            if ($webhook['endpoint_url'] != $webhookListenerUrl) {
              continue;
            }

            if (sort($webhook['actions']) == $webhookActions) {
              // Webhook is already correctly configured!
              if (!isset($validWebhookId)) {
                $validWebhookId = $webhook['id'];
              } else {
                $webhookStatusMessages[] = "More than one webhook is configured, will try to delete webhook #" . $webhook['id'];
                $invalidWebhooks[] = $webhook['id'];
              }
            } else {
              // Webhook does not have the correct actions.
              $webhookStatusMessages[] = "Webhook found, but not set up correctly, will try to delete webhook #" . $webhook['id'];
              $invalidWebhooks[] = $webhook['id'];
            }
          }
        
          // attempt to delete the invalid webhooks
          $this->deleteInvalidWebhooks($invalidWebhooks);

          // attempt to create a valid webhook if none exists
          if (!isset($validWebhookId)) {
            // create webhooks
            $webhookStatusMessages[] = "No previous valid webhook found, will try to create one.";
            $validWebhookId = $this->createWebhook($webhookActions);
          } else {
            $webhookStatusMessages[] = "Eventbrite webhooks appear to be correctly configured.";
          }

        } catch (EventbriteApiError $e) {
          $webhookStatusMessages[] = "Error connecting to Eventbrite webhook API.";
          $webhookStatusMessages[] = $e->message;
          $webhookStatusMessages[] = "You can view and manage webhooks manually at " .
            "https://www.eventbrite.com/account-settings/webhooks";
          $webhookStatusMessages[] = "Webhooks should have a Payload URI of " . 
            $webhookListenerUrl . " and actions " . implode(", ", $webhookActions);
        }

        // combine all the status messages into a single notification
        $statusMessage = implode("\n", $webhookStatusMessages);

        if (isset($validWebhookId)) {
          // things are basically ok
          CRM_Core_Session::setStatus($statusMessage, E::ts('Eventbrite webhook setup'), 'success');
        } else {
          // things are not okay
          CRM_Core_Session::setStatus($statusMessage, E::ts('Eventbrite webhook'), 'error');
        }
      }
    }
  }
}
