<?php

use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Http\Mock\Client as MockClient;



/**
 * Base class for all Omnipay tests
 *
 * Guzzle mock methods area based on those in GuzzleTestCase
 */
abstract class api_v3_Eventbrite_TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    /** @var  RequestInterface */
    private $mockRequest;

    /** @var  MockClient */
    private $mockClient;

    /** @var ClientInterface */
    private $httpClient;

    /** @var HttpRequest */
    private $httpRequest;

    /**
     * Converts a string to camel case
     *
     * @param string $str
     * @return string
     */
    public function camelCase($str)
    {
        return preg_replace_callback(
            '/_([a-z])/',
            function ($match) {
                return strtoupper($match[1]);
            },
            $str
        );
    }


    /**
     * Get all of the mocked requests
     *
     * @return array
     */
    public function getMockedRequests()
    {
        return $this->mockClient->getRequests();
    }

    /**
     * Get a mock response for a client by mock file name
     *
     * @param string $path Relative path to the mock response file
     *
     * @return ResponseInterface
     */
    public function getMockHttpResponse($path)
    {
        if ($path instanceof ResponseInterface) {
            return $path;
        }

        $ref = new ReflectionObject($this);
        $dir = dirname($ref->getFileName());

        // if mock file doesn't exist, check parent directory
        if (!file_exists($dir.'/Mock/'.$path) && file_exists($dir.'/../Mock/'.$path)) {
            return \GuzzleHttp\Psr7\parse_response(file_get_contents($dir.'/../Mock/'.$path));
        }

        return \GuzzleHttp\Psr7\parse_response(file_get_contents($dir.'/Mock/'.$path));
    }

    /**
     * Set a mock response from a mock file on the next client request.
     *
     * This method assumes that mock response files are located under the
     * Mock/ subdirectory of the current class. A mock response is added to the next
     * request sent by the client.
     *
     * An array of path can be provided and the next x number of client requests are
     * mocked in the order of the array where x = the array length.
     *
     * @param array|string $paths Path to files within the Mock folder of the service
     *
     * @return void returns the created mock plugin
     */
    public function setMockHttpResponse($paths)
    {
        foreach ((array) $paths as $path) {
            $this->mockClient->addResponse($this->getMockHttpResponse($path));
        }
    }

    public function getMockRequest()
    {
        if (null === $this->mockRequest) {
            $this->mockRequest = m::mock(RequestInterface::class);
        }

        return $this->mockRequest;
    }

    public function getMockClient()
    {
        if (null === $this->mockClient) {
            $this->mockClient = new MockClient();
        }

        return $this->mockClient;
    }

    public function getHttpClient()
    {
        if (null === $this->httpClient) {
            $this->httpClient = new Client(
                $this->getMockClient()
            );
        }

        return $this->httpClient;
    }

    public function getHttpRequest()
    {
        if (null === $this->httpRequest) {
            $this->httpRequest = new HttpRequest;
        }

        return $this->httpRequest;
    }
}
