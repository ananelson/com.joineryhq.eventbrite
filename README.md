# com.joineryhq.eventbrite

Provides synchronization to CiviCRM for participants and payments registered through Eventbrite.

The extension is licensed under [GPL-3.0](LICENSE.txt).

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl com.joineryhq.eventbrite@https://github.com/twomice/com.joineryhq.eventbrite/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/twomice/com.joineryhq.eventbrite.git
cv en eventbrite
```

## Usage

Configuration options are available at Administer > CiviEvent > Eventbrite Integration

* The extension asks for your "Personal OAuth Token"; this may also be called the "Private API key" under the properties of the API Key in your [Eventbrite account settings](https://www.eventbrite.com/account-settings/apps).

To test, run the Scheduled Job called Call Eventbrite.Runqueue API manually. 

## Upgrading

New settings for autoimport events will not be visible until you

```drush cvapi System.flush```

## Extending

This extension implements some Symfony hooks to allow you to customize its
behavior for how your organization uses Eventbrite.

#### DataLoaded

Fired after Processor is initialized and data is loaded. This Event has same
name regardless of data type of subject.

### Event

#### EventParamsSet

After the Eventbrite Event information is used to create the civiEventParams
array, the EventParamsSet event provides an opportunity to modify
civiEventParams before events are created/modified.

#### FindExistingCiviEvent

Once an Event is correctly set up, the EventbriteLink table will store a link
between the Eventbrite Event and the corresponding Civi Event. You can use this
Symfony Event to assign a Civi event to the processor's $existingEvent variable
and this event will then be linked.

#### BeforeUpdateExistingCiviEvent

By default events are updated.

You can choose to not update an existing event by setting
$processor->doUpdateCiviEvent to false in your handler for
BeforeUpdateExistingCiviEvent.

#### AfterUpdateExistingCiviEvent

You can do custom update code in your handler for
BeforeUpdateExistingCiviEvent, you can also do further processing in
AfterUpdateExistingCiviEvent. This runs no matter what, you can always make use
of $this->doUpdateCiviEvent in your handler to restrict this to run only if the
event was updated.

### Order

#### OrderAttendeesListSet

The setOrderAttendeesList() method will initialize a list of valid
orderAttendees which is used in subsequent processing. By default it includes
all attendees with a valid ticket type. This event allows you to customize this
behavior.

#### FeesSetup

If you have additional initialization of fee-related accumulator variables, you
can do so here.

#### ProcessCurrentAttendeeFees

This event is called for each order attendee after attendee fees have been added.

#### ContributionParamsAssigned

Contribution parameters are assigned to $processor->contributionParams and these
can be modified before contribution is created/updated.

#### PaymentParamsAssigned

Payment parameters are assigned to $processor->proposedPayments and this array
can then be modified, or cleared if no payments should be created.

## Support
![screenshot](/images/joinery-logo.png)

Joinery provides services for CiviCRM including custom extension development, training, data migrations, and more. We aim to keep this extension in good working order, and will do our best to respond appropriately to issues reported on its [github issue queue](https://github.com/twomice/com.joineryhq.eventbrite/issues). In addition, if you require urgent or highly customized improvements to this extension, we may suggest conducting a fee-based project under our standard commercial terms.  In any case, the place to start is the [github issue queue](https://github.com/twomice/com.joineryhq.eventbrite/issues) -- let us hear what you need and we'll be glad to help however we can.

And, if you need help with any other aspect of CiviCRM -- from hosting to custom development to strategic consultation and more -- please contact us directly via https://joineryhq.com
