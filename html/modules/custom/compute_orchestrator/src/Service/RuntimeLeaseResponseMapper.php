<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Maps internal pool records to the remote runtime lease contract.
 */
final class RuntimeLeaseResponseMapper {

  /**
   * Builds the client-facing runtime lease payload.
   *
   * @param array<string,mixed> $record
   *   Internal pool record.
   * @param bool $includeToken
   *   Whether to include the opaque lease token.
   *
   * @return array<string,mixed>
   *   Remote lease payload.
   */
  public function normalizeLease(array $record, bool $includeToken = TRUE): array {
    $lease = [
      'lease_id' => $this->normalizeLeaseId($record),
      'workload' => (string) ($record['current_workload_mode'] ?? ''),
      'model' => (string) ($record['current_model'] ?? ''),
      'endpoint_url' => (string) ($record['url'] ?? ''),
      'lease_status' => $this->normalizeLeaseStatus((string) ($record['lease_status'] ?? '')),
      'runtime_state' => $this->normalizeRuntimeState((string) ($record['runtime_state'] ?? '')),
      'expires_at' => $this->formatTimestamp((int) ($record['lease_expires_at'] ?? 0)),
    ];

    if ($includeToken) {
      $lease['lease_token'] = (string) ($record['lease_token'] ?? '');
    }

    return $lease;
  }

  /**
   * Builds operator diagnostics for a lease response.
   *
   * @param array<string,mixed> $record
   *   Internal pool record.
   *
   * @return array<string,mixed>
   *   Diagnostics payload.
   */
  public function normalizeDiagnostics(array $record): array {
    $diagnostics = [
      'source' => (string) ($record['source'] ?? ''),
      'provider_id' => (string) ($record['contract_id'] ?? ''),
      'last_operation' => $this->formatLastOperation($record),
      'last_seen_at' => $this->formatTimestamp((int) ($record['last_seen_at'] ?? 0)),
      'last_error' => (string) ($record['last_error'] ?? ''),
    ];

    return array_filter($diagnostics, static fn($value): bool => $value !== NULL && $value !== '');
  }

  /**
   * Builds the common remote error payload.
   *
   * @param string $code
   *   Contract error code.
   * @param string $message
   *   Operator-facing message.
   * @param bool $retryable
   *   Whether the caller may retry.
   * @param array<string,mixed> $diagnostics
   *   Optional diagnostics.
   *
   * @return array<string,mixed>
   *   Error response payload.
   */
  public function normalizeError(
    string $code,
    string $message,
    bool $retryable = FALSE,
    array $diagnostics = [],
  ): array {
    $error = [
      'code' => $code,
      'message' => $message,
      'retryable' => $retryable,
    ];
    if ($diagnostics !== []) {
      $error['diagnostics'] = $diagnostics;
    }

    return ['error' => $error];
  }

  /**
   * Normalizes an incoming route lease identifier.
   */
  public function normalizeRouteLeaseId(string $leaseId): string {
    $leaseId = trim($leaseId);
    if (str_starts_with($leaseId, 'vast:')) {
      return substr($leaseId, 5);
    }
    return $leaseId;
  }

  /**
   * Builds the external lease identifier.
   *
   * @param array<string,mixed> $record
   *   Internal pool record.
   */
  private function normalizeLeaseId(array $record): string {
    return (string) ($record['contract_id'] ?? '');
  }

  /**
   * Maps internal lease state to client-facing lease state.
   */
  private function normalizeLeaseStatus(string $status): string {
    return match ($status) {
      'leased' => 'leased',
      'available' => 'released',
      'bootstrapping' => 'provisioning',
      'unavailable', 'rented_elsewhere' => 'unavailable',
      default => $status !== '' ? $status : 'unavailable',
    };
  }

  /**
   * Maps internal runtime state to client-facing runtime state.
   */
  private function normalizeRuntimeState(string $state): string {
    return match ($state) {
      'starting', 'running', 'stopped', 'destroyed' => $state,
      default => 'unknown',
    };
  }

  /**
   * Formats a unix timestamp as an external contract timestamp.
   */
  private function formatTimestamp(int $timestamp): ?string {
    if ($timestamp <= 0) {
      return NULL;
    }
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

  /**
   * Formats transitional phase/action fields as one operation label.
   *
   * @param array<string,mixed> $record
   *   Internal pool record.
   */
  private function formatLastOperation(array $record): string {
    $phase = trim((string) ($record['last_phase'] ?? ''));
    $action = trim((string) ($record['last_action'] ?? ''));
    if ($phase === '') {
      return $action;
    }
    if ($action === '') {
      return $phase;
    }
    return $phase . ': ' . $action;
  }

}
