<?php

namespace Tamara\Checkout\Model\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tamara\Exception\RequestException;
use Tamara\HttpClient\ClientInterface;

class PreCheckoutEligibilityTransport implements ClientInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var float
     */
    private $timeout;

    public function __construct($timeout)
    {
        $this->client = new Client();
        $this->timeout = $timeout;
    }

    public function createRequest(
        string $method,
        $uri,
        array $headers = [],
        $body = null,
        $version = '1.1'
    ): RequestInterface {
        return new Request($method, $uri, $headers, $body, $version);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->send(
                $request,
                [
                    'connect_timeout' => $this->timeout,
                    'timeout' => $this->timeout
                ]
            );
        } catch (\Exception $exception) {
            $response = method_exists($exception, 'getResponse')
                ? $exception->getResponse()
                : null;

            throw new RequestException(
                $exception->getMessage(),
                $exception->getCode(),
                $request,
                $response,
                $exception
            );
        }
    }
}
