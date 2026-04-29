<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Remote HTTP Framesmith compute client backed by runtime lease endpoints.
 */
final class FramesmithHttpComputeRuntimeClient implements FramesmithComputeRuntimeClientInterface {

  public const STATE_BASE_URL = 'compute_orchestrator.framesmith_http_compute_runtime.base_url';
  public const STATE_ACCESS_TOKEN = 'compute_orchestrator.framesmith_http_compute_runtime.access_token';

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    LoggerChannelFactoryInterface|LoggerInterface|null $logger = NULL,
  ) {
    if ($logger instanceof LoggerChannelFactoryInterface) {
      $this->logger = $logger->get('compute_orchestrator');
    }
    elseif ($logger instanceof LoggerInterface) {
      $this->logger = $logger;
    }
    else {
      $this->logger = new NullLogger();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function acquireWhisperRuntime(): array {
    $payload = $this->requestJson('POST', '/api/compute-orchestrator/runtime-leases', [
      'json' => [
        'workload' => 'whisper',
        'client' => 'framesmith',
        'purpose' => 'transcription',
        'allow_provision' => TRUE,
      ],
    ]);

    return $this->normalizeLeasePayload($payload);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseRuntime(string $contractId, ?string $leaseToken = NULL): array {
    $leaseToken = trim((string) $leaseToken);
    if ($leaseToken === '') {
      throw new \RuntimeException('Cannot release remote Framesmith runtime lease without a lease token.');
    }

    $payload = $this->requestJson(
      'POST',
      '/api/compute-orchestrator/runtime-leases/' . rawurlencode($contractId) . '/release',
      [
        'json' => [
          'lease_token' => $leaseToken,
          'reason' => 'framesmith transcription task finished',
        ],
      ],
    );

    return $this->normalizeLeasePayload($payload);
  }

  /**
   * Sends a JSON request to the compute runtime lease API.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path relative to the configured base URL.
   * @param array<string,mixed> $options
   *   Guzzle request options.
   *
   * @return array<string,mixed>
   *   Decoded JSON response.
   */
  private function requestJson(string $method, string $path, array $options): array {
    $baseUrl = $this->getBaseUrl();
    $token = $this->getAccessToken();
    $url = $baseUrl . $path;

    $headers = [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ];
    $options['headers'] = ($options['headers'] ?? []) + $headers;

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Framesmith remote compute runtime request failed: @message', [
        '@message' => $exception->getMessage(),
        'method' => $method,
        'path' => $path,
      ]);
      throw new \RuntimeException('Framesmith remote compute runtime request failed: ' . $exception->getMessage(), 0, $exception);
    }

    return $this->decodeJsonResponse($response, $method, $path);
  }

  /**
   * Decodes a JSON response body.
   *
   * @return array<string,mixed>
   *   Decoded JSON response.
   */
  private function decodeJsonResponse(ResponseInterface $response, string $method, string $path): array {
    $body = (string) $response->getBody();
    try {
      $payload = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException(sprintf('Invalid JSON from compute runtime lease API for %s %s.', $method, $path), 0, $exception);
    }

    if (!is_array($payload)) {
      throw new \RuntimeException(sprintf('Unexpected JSON shape from compute runtime lease API for %s %s.', $method, $path));
    }

    if (isset($payload['error']) && is_array($payload['error'])) {
      $message = (string) ($payload['error']['message'] ?? 'Compute runtime lease API returned an error.');
      $code = (string) ($payload['error']['code'] ?? 'unknown_error');
      throw new \RuntimeException($code . ': ' . $message);
    }

    return $payload;
  }

  /**
   * Converts the remote lease payload into the Framesmith backend lease shape.
   *
   * @param array<string,mixed> $payload
   *   Remote runtime lease response payload.
   *
   * @return array<string,mixed>
   *   Framesmith backend lease details.
   */
  private function normalizeLeasePayload(array $payload): array {
    $lease = $payload['lease'] ?? NULL;
    if (!is_array($lease)) {
      throw new \RuntimeException('Compute runtime lease API response did not include a lease object.');
    }

    $leaseId = (string) ($lease['lease_id'] ?? '');
    if (str_starts_with($leaseId, 'vast:')) {
      $leaseId = substr($leaseId, 5);
    }

    return [
      'contract_id' => $leaseId,
      'lease_token' => (string) ($lease['lease_token'] ?? ''),
      'host' => parse_url((string) ($lease['endpoint_url'] ?? ''), PHP_URL_HOST) ?: '',
      'port' => (string) (parse_url((string) ($lease['endpoint_url'] ?? ''), PHP_URL_PORT) ?: ''),
      'url' => (string) ($lease['endpoint_url'] ?? ''),
      'current_workload_mode' => (string) ($lease['workload'] ?? 'whisper'),
      'current_model' => (string) ($lease['model'] ?? ''),
      'pool_record' => $payload,
    ];
  }

  /**
   * Returns the configured compute API base URL.
   */
  private function getBaseUrl(): string {
    $baseUrl = rtrim(trim((string) $this->state->get(self::STATE_BASE_URL, '')), '/');
    if ($baseUrl === '') {
      throw new \RuntimeException('Framesmith remote compute runtime base URL is not configured.');
    }
    return $baseUrl;
  }

  /**
   * Returns the configured OAuth access token.
   */
  private function getAccessToken(): string {
    $token = trim((string) $this->state->get(self::STATE_ACCESS_TOKEN, ''));
    if ($token === '') {
      throw new \RuntimeException('Framesmith remote compute runtime OAuth access token is not configured.');
    }
    return $token;
  }

}
