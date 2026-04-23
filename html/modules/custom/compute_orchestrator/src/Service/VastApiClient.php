<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Executes authenticated low-level requests against the Vast REST API.
 */
final class VastApiClient implements VastApiClientInterface {

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly VastCredentialProviderInterface $credentials,
  ) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $method, string $uri, array $options = []): array {
    $apiKey = $this->credentials->getApiKey();
    if ($apiKey === NULL || $apiKey === '') {
      $this->logger->warning('Vast API key is not configured. Vast API calls are disabled.');
      throw new \RuntimeException('Vast API key is not configured.');
    }

    try {
      $response = $this->httpClient->request(
        $method,
        'https://console.vast.ai/api/v0/' . ltrim($uri, '/'),
        array_merge_recursive([
          'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
          ],
          'timeout' => 20,
          'connect_timeout' => 10,
        ], $options),
      );

      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('Invalid JSON response from Vast API.');
      }

      return $decoded;
    }
    catch (GuzzleException $exception) {
      if ($exception instanceof ClientException) {
        $response = $exception->getResponse();
        if ($response !== NULL) {
          $body = (string) $response->getBody();
          throw new \RuntimeException('Vast API error response: ' . $body, 0, $exception);
        }
      }

      throw new \RuntimeException('Vast API request failed: ' . $exception->getMessage(), 0, $exception);
    }
  }

}
