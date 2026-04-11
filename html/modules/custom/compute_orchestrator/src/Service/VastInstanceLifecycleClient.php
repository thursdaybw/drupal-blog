<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Issues start/stop lifecycle transitions against existing Vast instances.
 */
final class VastInstanceLifecycleClient implements VastInstanceLifecycleClientInterface {

  /**
   * Vast API bearer token.
   */
  private ?string $apiKey = NULL;

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

    $apiKey = $this->credentials->getApiKey();
    if ($apiKey === NULL || $apiKey === '') {
      $this->logger->warning('Vast API key is not configured. Vast API calls are disabled.');
      return;
    }

    $this->apiKey = $apiKey;
  }

  /**
   * {@inheritdoc}
   */
  public function startInstance(string $instanceId): array {
    return $this->changeState($instanceId, 'running');
  }

  /**
   * {@inheritdoc}
   */
  public function stopInstance(string $instanceId): array {
    return $this->changeState($instanceId, 'stopped');
  }

  /**
   * Requests a state transition for an instance.
   */
  private function changeState(string $instanceId, string $state): array {
    return $this->request('PUT', 'instances/' . (int) $instanceId . '/', [
      'json' => [
        'state' => $state,
      ],
    ]);
  }

  /**
   * Executes an authenticated Vast REST request.
   */
  private function request(string $method, string $uri, array $options = []): array {
    if ($this->apiKey === NULL || $this->apiKey === '') {
      throw new \RuntimeException('Vast API key is not configured.');
    }

    try {
      $response = $this->httpClient->request(
        $method,
        'https://console.vast.ai/api/v0/' . ltrim($uri, '/'),
        array_merge_recursive([
          'headers' => [
            'Authorization' => 'Bearer ' . $this->apiKey,
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
      $message = $exception->getMessage();
      if (method_exists($exception, 'getResponse')) {
        $response = $exception->getResponse();
        if ($response !== NULL) {
          $message = (string) $response->getBody();
        }
      }
      throw new \RuntimeException('Vast instance state change failed: ' . $message, 0, $exception);
    }
  }

}
