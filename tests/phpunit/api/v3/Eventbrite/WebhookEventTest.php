<?php

class api_v3_Eventbrite_WebhookEventTest extends api_v3_Eventbrite_TestCase {
  public function setUp() {
    $httpResponse = $this->getMockHttpResponse('Event.txt');
    $data = $httpResponse->getBody()->getContents();
    $this->whp = new CRM_Eventbrite_WebhookProcessor_Event(json_decode($data, true));
  }

  public function testInitialize() {
    $this->assertEquals("events_9876", $this->whp->getEntityIdentifier());
    $this->assertEquals("9876", $this->whp->getData('id'));
  }

  public function testCiviEventParams() {
    $ep = $this->whp->civiEventParams();
    $this->assertEquals("Online:  Foundation 2 with Zoe Galvez", $ep['title']);
    $this->assertEquals("BATS School of Improv (#20-11-3267)", $ep['summary']);
    $this->assertStringStartsWith("<p><strong>Class Date/Time: ", $ep['description']);
    $this->assertEquals(14, $ep['max_participants']);
    $this->assertEquals("2020-11-03 19:49:59", $ep['start_date']);
  }
}
