<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\compute_orchestrator\Service\RuntimeLeaseResponseMapper;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remote runtime lease endpoints for external compute clients.
 */
final class RuntimeLeaseController extends ControllerBase {

  public function __construct(
    private readonly VllmPoolManager $poolManager,
    private readonly RuntimeLeaseResponseMapper $mapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('compute_orchestrator.vllm_pool_manager'),
      $container->get('compute_orchestrator.runtime_lease_response_mapper'),
    );
  }

  /**
   * Acquires a runtime lease for a supported workload.
   */
  public function acquire(Request $request): JsonResponse {
    $payload = $this->decodePayload($request);
    if ($payload === NULL) {
      return $this->error('invalid_request', 'Request body must be JSON.', Response::HTTP_BAD_REQUEST);
    }

    $workload = trim((string) ($payload['workload'] ?? ''));
    if ($workload === '') {
      return $this->error('invalid_request', 'workload is required.', Response::HTTP_BAD_REQUEST);
    }

    $model = trim((string) ($payload['model'] ?? ''));
    $allowProvision = array_key_exists('allow_provision', $payload)
      ? (bool) $payload['allow_provision']
      : TRUE;

    try {
      $record = $this->poolManager->acquire(
        $workload,
        $model !== '' ? $model : NULL,
        $allowProvision,
      );
    }
    catch (\InvalidArgumentException $exception) {
      return $this->error('workload_unknown', $exception->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\RuntimeException $exception) {
      return $this->error(
        'runtime_unavailable',
        $exception->getMessage(),
        Response::HTTP_SERVICE_UNAVAILABLE,
        TRUE,
      );
    }

    return new JsonResponse([
      'lease' => $this->mapper->normalizeLease($record),
      'diagnostics' => $this->mapper->normalizeDiagnostics($record),
    ]);
  }

  /**
   * Inspects a runtime lease or pool record.
   */
  public function inspect(string $lease_id): JsonResponse {
    $record = $this->findRecord($lease_id);
    if ($record === NULL) {
      return $this->error('lease_not_found', 'Unknown runtime lease.', Response::HTTP_NOT_FOUND);
    }

    return new JsonResponse([
      'lease' => $this->mapper->normalizeLease($record, FALSE),
      'diagnostics' => $this->mapper->normalizeDiagnostics($record),
    ]);
  }

  /**
   * Renews a runtime lease.
   */
  public function renew(Request $request, string $lease_id): JsonResponse {
    $payload = $this->decodePayload($request);
    if ($payload === NULL) {
      return $this->error('invalid_request', 'Request body must be JSON.', Response::HTTP_BAD_REQUEST);
    }

    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));
    if ($leaseToken === '') {
      return $this->error('invalid_request', 'lease_token is required.', Response::HTTP_BAD_REQUEST);
    }

    $ttl = isset($payload['lease_ttl_seconds']) ? (int) $payload['lease_ttl_seconds'] : NULL;
    $contractId = $this->mapper->normalizeRouteLeaseId($lease_id);

    try {
      $record = $this->poolManager->renewLease($contractId, $leaseToken, $ttl);
    }
    catch (\RuntimeException $exception) {
      return $this->poolExceptionResponse($exception);
    }

    return new JsonResponse([
      'lease' => $this->mapper->normalizeLease($record),
      'diagnostics' => $this->mapper->normalizeDiagnostics($record),
    ]);
  }

  /**
   * Releases a runtime lease back to the reusable pool.
   */
  public function release(Request $request, string $lease_id): JsonResponse {
    $payload = $this->decodePayload($request);
    if ($payload === NULL) {
      return $this->error('invalid_request', 'Request body must be JSON.', Response::HTTP_BAD_REQUEST);
    }

    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));
    if ($leaseToken === '') {
      return $this->error('invalid_request', 'lease_token is required.', Response::HTTP_BAD_REQUEST);
    }

    $record = $this->findRecord($lease_id);
    if ($record === NULL) {
      return $this->error('lease_not_found', 'Unknown runtime lease.', Response::HTTP_NOT_FOUND);
    }
    if ((string) ($record['lease_status'] ?? '') !== 'leased') {
      return $this->error(
        'lease_already_released',
        'Runtime lease is not currently leased.',
        Response::HTTP_CONFLICT,
      );
    }

    $storedToken = trim((string) ($record['lease_token'] ?? ''));
    if ($storedToken !== '' && !hash_equals($storedToken, $leaseToken)) {
      return $this->error('lease_token_mismatch', 'Lease token mismatch.', Response::HTTP_FORBIDDEN);
    }

    $contractId = $this->mapper->normalizeRouteLeaseId($lease_id);
    try {
      $record = $this->poolManager->release($contractId);
    }
    catch (\RuntimeException $exception) {
      return $this->poolExceptionResponse($exception);
    }

    return new JsonResponse([
      'lease' => $this->mapper->normalizeLease($record, FALSE),
      'diagnostics' => $this->mapper->normalizeDiagnostics($record),
    ]);
  }

  /**
   * Decodes a JSON request body.
   *
   * @return array<string,mixed>|null
   *   Request payload, or NULL when the body is invalid.
   */
  private function decodePayload(Request $request): ?array {
    $content = trim($request->getContent());
    if ($content === '') {
      return $request->request->all();
    }

    try {
      $payload = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    return is_array($payload) ? $payload : NULL;
  }

  /**
   * Finds a pool record by remote route lease identifier.
   *
   * @return array<string,mixed>|null
   *   Matching pool record, or NULL.
   */
  private function findRecord(string $leaseId): ?array {
    $contractId = $this->mapper->normalizeRouteLeaseId($leaseId);
    foreach ($this->poolManager->listInstances() as $record) {
      if ((string) ($record['contract_id'] ?? '') === $contractId) {
        return $record;
      }
    }
    return NULL;
  }

  /**
   * Maps common pool exceptions into the remote error model.
   */
  private function poolExceptionResponse(\RuntimeException $exception): JsonResponse {
    $message = $exception->getMessage();
    if (str_contains($message, 'Unknown pooled instance')) {
      return $this->error('lease_not_found', $message, Response::HTTP_NOT_FOUND);
    }
    if (str_contains($message, 'Lease token mismatch')) {
      return $this->error('lease_token_mismatch', $message, Response::HTTP_FORBIDDEN);
    }
    if (str_contains($message, 'non-leased instance')) {
      return $this->error('lease_already_released', $message, Response::HTTP_CONFLICT);
    }

    return $this->error('provider_failure', $message, Response::HTTP_BAD_GATEWAY, TRUE);
  }

  /**
   * Builds a JSON error response.
   */
  private function error(
    string $code,
    string $message,
    int $status,
    bool $retryable = FALSE,
  ): JsonResponse {
    return new JsonResponse(
      $this->mapper->normalizeError($code, $message, $retryable),
      $status,
    );
  }

}
