<?php
/**
 * WooCommerce REST API HTTP Client
 *
 * @category HttpClient
 * @package  Automattic/WooCommerce
 */

namespace Automattic\WooCommerce\HttpClient;

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use Automattic\WooCommerce\HttpClient\OAuth;
use Automattic\WooCommerce\HttpClient\Options;
use Automattic\WooCommerce\HttpClient\Request;
use Automattic\WooCommerce\HttpClient\Response;

/**
 * REST API HTTP Client class.
 *
 * @package Automattic/WooCommerce
 */
class HttpClient
{

    /**
     * cURL handle.
     *
     * @var resource
     */
    protected $ch;

    /**
     * Store API URL.
     *
     * @var string
     */
    protected $url;

    /**
     * Consumer key.
     *
     * @var string
     */
    protected $consumerKey;

    /**
     * Consumer secret.
     *
     * @var string
     */
    protected $consumerSecret;

    /**
     * Client options.
     *
     * @var Options
     */
    protected $options;

    /**
     * Request.
     *
     * @var Request
     */
    private $request;

    /**
     * Response.
     *
     * @var Response
     */
    private $response;

    /**
     * Response headers.
     *
     * @var string
     */
    private $responseHeaders;

    /**
     * Initialize HTTP client.
     *
     * @param string $url            Store URL.
     * @param string $consumerKey    Consumer key.
     * @param string $consumerSecret Consumer Secret.
     * @param array  $options        Client options.
     */
    public function __construct($url, $consumerKey, $consumerSecret, $options)
    {
        if (!\function_exists('curl_version')) {
            throw new HttpClientException('cURL is NOT installed on this server', -1, new Request(), new Response());
        }

        $this->options        = new Options($options);
        $this->url            = $this->buildApiUrl($url);
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    /**
     * Check if is under SSL.
     *
     * @return bool
     */
    protected function isSsl()
    {
        return 'https://' === \substr($this->url, 0, 8);
    }

    /**
     * Build API URL.
     *
     * @param string $url Store URL.
     *
     * @return string
     */
    protected function buildApiUrl($url)
    {
        return \rtrim($url, '/') . '/wc-api/' . $this->options->getVersion() . '/';
    }

    /**
     * Build URL.
     *
     * @param string $url        URL.
     * @param array  $parameters Query string parameters.
     *
     * @return string
     */
    protected function buildUrlQuery($url, $parameters = [])
    {
        if (!empty($parameters)) {
            $url .= '?' . \http_build_query($parameters);
        }

        return $url;
    }

    /**
     * Authenticate.
     *
     * @param string $endpoint   Request endpoint.
     * @param string $method     Request method.
     * @param array  $parameters Request parameters.
     *
     * @return array
     */
    protected function authenticate($endpoint, $method, $parameters = [])
    {
        // Build URL.
        $url = $this->url . $endpoint;

        // Setup authentication.
        if ($this->isSsl()) {
            // Set query string for authentication.
            if ($this->options->isQueryStringAuth()) {
                $parameters['consumer_key']    = $this->consumerKey;
                $parameters['consumer_secret'] = $this->consumerSecret;
            } else {
                \curl_setopt($this->ch, CURLOPT_USERPWD, $this->consumerKey . ':' . $this->consumerSecret);
            }
        } else {
            $oAuth      = new OAuth($url, $this->consumerKey, $this->consumerSecret, $this->options->getVersion(), $method, $parameters);
            $parameters = $oAuth->getParameters();
        }

        return [
            'url'        => $this->buildUrlQuery($url, $parameters),
            'parameters' => $parameters,
        ];
    }

    /**
     * Setup method.
     *
     * @param string $method Request method.
     */
    protected function setupMethod($method)
    {
        if ('POST' == $method) {
            \curl_setopt($this->ch, CURLOPT_POST, true);
        } else if (\in_array($method, ['PUT', 'DELETE'])) {
            \curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        }
    }

    /**
     * Get request headers.
     *
     * @return array
     */
    protected function getRequestHeaders()
    {
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent'   => 'WooCommerce API Client-PHP/' . Client::VERSION,
        ];
    }

    /**
     * Create request.
     *
     * @param string $endpoint   Request endpoint.
     * @param string $method     Request method.
     * @param array  $data       Request data.
     * @param array  $parameters Request parameters.
     *
     * @return Request
     */
    protected function createRequest($endpoint, $method, $data = [], $parameters = [])
    {
        $body = '';

        // Setup authentication.
        $auth       = $this->authenticate($endpoint, $method, $parameters);
        $url        = $auth['url'];
        $parameters = $auth['parameters'];

        // Setup method.
        $this->setupMethod($method);

        // Include post fields.
        if (!empty($data)) {
            $body = \json_encode($data);
            \curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
        }

        $this->request = new Request($url, $method, $parameters, $this->getRequestHeaders(), $body);

        return $this->getRequest();
    }

    /**
     * Get response headers.
     *
     * @return array
     */
    public function getResponseHeaders()
    {
        $headers = [];
        $lines   = \explode("\n", $this->responseHeaders);
        $lines   = \array_filter($lines, 'trim');

        foreach ($lines as $index => $line) {
            // Remove HTTP/xxx param.
            if (0 === $index) {
                continue;
            }

            list($key, $value) = \explode(': ', $line);
            $headers[$key] = $value;
        }

        return $headers;
    }

    /**
     * Create response.
     *
     * @return Response
     */
    protected function createResponse()
    {

        // Set response headers.
        $this->responseHeaders = '';
        \curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, function ($_, $headers) {
            $this->responseHeaders .= $headers;
            return \strlen($headers);
        });

        // Get response data.
        $body    = \curl_exec($this->ch);
        $code    = \curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $headers = $this->getResponseHeaders();

        // Register response.
        $this->response = new Response($code, $headers, $body);

        return $this->getResponse();
    }

    /**
     * Set default cURL settings.
     *
     * @param Request $request Request data.
     */
    protected function setDefaultCurlSettings($request)
    {
        $verifySsl = $this->options->verifySsl();
        $timeout   = $this->options->getTimeout();

        \curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        \curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, $verifySsl);
        \curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_TIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($this->ch, CURLOPT_HTTPHEADER, $request->getRawHeaders());
        \curl_setopt($this->ch, CURLOPT_URL, $request->getUrl());
    }

    /**
     * Make requests.
     *
     * @param string $endpoint   Request endpoint.
     * @param string $method     Request method.
     * @param array  $data       Request data.
     * @param array  $parameters Request parameters.
     *
     * @return array
     */
    public function request($endpoint, $method, $data = [], $parameters = [])
    {
        // Initialize cURL.
        $this->ch = \curl_init();

        // Set request args.
        $request = $this->createRequest($endpoint, $method, $data, $parameters);

        // Default cURL settings.
        $this->setDefaultCurlSettings($request);

        // Get response.
        $response = $this->createResponse();

        // Check for cURL errors.
        if (\curl_errno($this->ch)) {
            throw new HttpClientException('cURL Error: ' . \curl_error($this->ch), 0, $request, $response);
        }

        \curl_close($this->ch);

        $parsedResponse = $this->decodeResponseBody($response->getBody());

        $this->lookForErrors($parsedResponse, $request, $response);

        return $parsedResponse;
    }

    /**
     * Look for errors in the request.
     *
     * @param array    $parsedResponse Parsed body response.
     * @param Request  $request        Request data.
     * @param Response $response       Response data.
     */
    protected function lookForErrors($parsedResponse, $request, $response)
    {
        // Test if return a valid JSON.
        if (null === $parsedResponse) {
            throw new HttpClientException('Invalid JSON returned', $response->getCode(), $request, $response);
        }

        // Any non-200/201/202 response code indicates an error.
        if (!\in_array($response->getCode(), ['200', '201', '202'])) {
            if (!empty($parsedResponse['errors'][0])) {
                $errorMessage = $parsedResponse['errors'][0]['message'];
                $errorCode    = $parsedResponse['errors'][0]['code'];
            } else {
                $errorMessage = $parsedResponse['errors']['message'];
                $errorCode    = $parsedResponse['errors']['code'];
            }

            throw new HttpClientException(\sprintf('Error: %s [%s]', $errorMessage, $errorCode), $response->getCode(), $request, $response);
        }
    }

    /**
     * Decode response body.
     *
     * @param  string $data Response in JSON format.
     *
     * @return array
     */
    protected function decodeResponseBody($data)
    {
        // Remove any HTML or text from cache plugins or PHP notices.
        \preg_match('/\{(?:[^{}]|(?R))*\}/', $data, $matches);
        $data = isset($matches[0]) ? $matches[0] : '';

        return \json_decode($data, true);
    }

    /**
     * Get request data.
     *
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get response data.
     *
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }
}