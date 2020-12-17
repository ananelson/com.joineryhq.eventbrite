<?php

class api_v3_Eventbrite_EventbriteApiTest extends api_v3_Eventbrite_TestCase {
  public function setUp() {
    $this->eb = CRM_Eventbrite_EventbriteApi::singleton("foo", "12345");
  }

  public function testRequestUrl() {
    $url = $this->eb->ebUrl("bar");
    $this->assertEquals("https://www.eventbriteapi.com/v3/bar/?token=foo", $url);
  }

  public function testNullResponse() {
    $this->expectException(CRM_Core_Exception::class);
    $response = $this->eb->handleEventbriteResponse(null);
    $this->assertEquals("Eventbrite API error: No response returned. Suspect network connection is down.", $response);
  }

  public function testEmptyStringResponse() {
    $this->expectException(CRM_Core_Exception::class);
    $response = $this->eb->handleEventbriteResponse("");
    $this->assertEquals("Eventbrite API error: No response returned. Suspect network connection is down.", $response);
  }

  public function testErrorResponse() {
    $this->expectException(EventbriteApiError::class);
    $this->expectExceptionMessage("VENUE_AND_ONLINE: You cannot both specify a venue and set online_event");
    $this->expectExceptionCode(400);
    $httpResponse = $this->getMockHttpResponse('Error.txt');
    $response = $this->eb->handleEventbriteResponse($httpResponse->getBody()->getContents());
  }

  public function testWebhooks() {
    $httpResponse = $this->getMockHttpResponse('Webhooks.txt');
    $response = $this->eb->handleEventbriteResponse($httpResponse->getBody()->getContents());
    $this->assertCount(3, $response['webhooks']['actions']);
  }
}
