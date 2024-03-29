<?php

namespace Drupal\crossref_api_client;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Messenger\Messenger;
use Drupal\guzzle_cache\DrupalGuzzleCache;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides methods for connecting to the Crossref API.
 */
class Client {

  /**
   * The base URI of the Crossref API.
   */
  const BASE_URI = 'https://api.crossref.org';

  /**
   * The cache TTL passed to the cache middleware.
   */
  const CACHE_TTL = 86400;

  /**
   * @var \Guzzlehttp\Client $guzzleClient
   */
  protected $guzzleClient;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig $config
   */
  protected $config;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface $cache
   */
  protected $cache;

  /**
   * @var \Drupal\Core\Messenger\Messenger $messenger
   */
  protected $messenger;

  /**
   * @var \Psr\Log\LoggerInterface $logger
   */
  protected $logger;

  /**
   * Constructs a new Client object.
   *
   * @param \Drupal\Core\Http\ClientFactory $client_factory
   *   The Guzzle HTTP Client factory service.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config.factory service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache.crossref_responses cache bin.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The Drupal messenger service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.channel.crossref_api_client service.
   */
  public function __construct(ClientFactory $client_factory, ConfigFactory $config_factory, CacheBackendInterface $cache, Messenger $messenger, LoggerInterface $logger) {
    $stack = HandlerStack::create();
    // The GreedyCacheStrategy is used here because Crossref does not send
    // Cache-Control headers. So we have to specify our own TTL.
    $stack->push(new CacheMiddleware(new GreedyCacheStrategy(new DrupalGuzzleCache($cache), self::CACHE_TTL)), 'cache');
    $this->guzzleClient = $client_factory->fromOptions([
      'base_uri' => self::BASE_URI,
      'handler' => $stack,
    ]);
    $this->config = $config_factory->get('crossref_api_client.settings');
    $this->cache = $cache;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * Sends a request to the API.
   *
   * Crossref recommends that all parameters be URL-encoded, especially DOIs
   * which frequently have invalid characters. All values in the $parameters
   * array will be URL-encoded. Calling functions should allow this method to
   * replace parameters in the endpoint path rather than doing it themselves.
   *
   * Responses are cached for one day.
   *
   * @param string $method
   *   The HTTP method of the request.
   * @param string $ndpoint
   *   The endpoint path to be queried. The endpoint should be written exactly
   *   as shown in the API documentation, for example '/works/{doi}', including
   *   a leading slash.
   * @param string[] $parameters
   *   An array of parameters to be replaced in the path. Array keys should
   *   exactly match the string to be replaced in the path, for example if the
   *   path is "/works/{doi}", then the $parameters array should contain
   *   "['{doi}' => $doi]".
   * @param string[] $extra_options
   *   Additional options to be added to the request.
   *
   * @return Psr\Http\Message\ResponseInterface
   *   The response to the request.
   *
   * @throws GuzzleHttp\Exception\TransferException
   */
  protected function request(string $method, string $endpoint, $parameters = [], $extra_options = []): ResponseInterface {
    foreach ($parameters as $key => $param) {
      $parameters[$key] = urlencode($param);
    }
    $request_path = strtr($endpoint, $parameters);

    $options = [];
    if ($email = $this->config->get('email')) {
      $options['query']['mailto'] = $email;
    }
    if ($token = $this->config->get('token')) {
      $options['headers']['Crossref-Plus-API-Token'] = 'Bearer ' . $token;
    }
    if ($this->config->get('debug')) {
      $options['on_stats'] = [__CLASS__, 'onStats'];
    }
    $options = NestedArray::mergeDeep($options, $extra_options);

    try {
      $response = $this->guzzleClient->request($method, $request_path, $options);
    }
    catch (ConnectException $e) {
      $this->messenger->addError('There was a problem while connecting to Crossref. Please try again later. Additional details have been logged.');
      $this->logger->error('The Crossref API Client module was unable to establish a connection to the API and a Guzzle ConnectException was thrown. The error occurred while connecting to @uri.', [
        '@uri' => (string) $e->getRequest()->getUri(),
      ]);
      throw $e;
    }
    catch (ServerException $e) {
      $this->messenger->addError('Crossref experienced an error while handling the request. Please try again later. Additional details have been logged.');
      $this->logger->error('The Crossref API returned a 5xx error for a request:<br>
        Status Code: @status_code<br>
        URI: @uri<br>
        Response Message: @message', [
          '@status_code' => $e->getResponse()->getStatusCode(),
          '@uri' => (string) $e->getRequest()->getUri(),
          '@message' => $e->getMessage(),
        ]);
      throw $e;
    }

    return $response;
  }

  /**
   * Requests data for a DOI.
   *
   * Makes a GET request to the /works/{doi} endpoint.
   *
   * @param string $doi
   *   The DOI to be queried.
   *
   * @return array
   *   The decoded JSON response from the API.
   *
   * @throws \GuzzleHttp\Exception\TransferException
   *   Re-throws the Guzzle exception so that calling code can react to it.
   */
  public function worksDoi(string $doi) {
    try {
      $response = $this->request('GET', '/works/{doi}', ['{doi}' => $doi]);
    }
    catch (ClientException $e) {
      $response = $e->getResponse();
      if ($response->getStatusCode() == 404) {
        $this->messenger->addWarning('The requested DOI could not be found in Crossref.');
      }
      throw $e;
    }
    return json_decode($response->getBody()->getContents());
  }

  /**
   * Checks to see if a DOI exists in Crossref.
   *
   * Performs a HEAD request to the /works/{doi} endpoint. A 200-status
   * response indicates that the DOI exists.  A 404-response indicates that the
   * DOI does not exist.
   *
   * @param string $doi
   *   The DOI to be queried.
   *
   * @return bool
   *   Returns TRUE if the DOI exists or FALSE if the DOI does not exist.
   *
   * @throws \GuzzleHttp\Exception\TransferException
   *   Re-throws the Guzzle exception so that calling code can react to it,
   *   except for 404-status ClientExceptions which are valid responses.
   */
  public function worksDoiExists(string $doi) {
    try {
      $response = $this->request('HEAD', '/works/{doi}', ['{doi}' => $doi]);
    }
    catch (ClientException $e) {
      $response = $e->getResponse();
      if ($response->getStatusCode() == 404) {
        return FALSE;
      }
      throw $e;
    }
    return TRUE;
  }

  /**
   * Logs data about requests and responses.
   *
   * @param \GuzzleHttp\TransferStats $stats
   *   The transfer statistics of the request.
   */
  public static function onStats(TransferStats $stats) {
    $log_message = 'This is debugging information releated to a Crossref API request.<br>
      Request URI: @uri<br>
      Request Method: @method<br>
      Transfer Time: @transfer_time';
    $replacements = [
      '@uri' => $stats->getEffectiveUri(),
      '@method' => $stats->getRequest()->getMethod(),
      '@transfer_time' => $stats->getTransferTime(),
    ];
    if ($response = $stats->getResponse()) {
      $log_message .= '<br>
        Response Status Code: @status_code<br>
        Response Reason: @reason<br>
        API pool that served the request: @api_pool';
      $replacements += [
        '@status_code' => $response->getStatusCode(),
        '@reason' => $response->getReasonPhrase(),
        '@api_pool' => implode(', ', $response->getHeader('x-api-pool')),
      ];
    }
    \Drupal::logger('crossref_api_client')->debug($log_message, $replacements);
  }

}

