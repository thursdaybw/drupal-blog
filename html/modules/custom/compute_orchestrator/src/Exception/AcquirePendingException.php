<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Exception;

/**
 * Retryable pooled-acquire state.
 *
 * Used when acquire is still warming up (for example: SSH not yet reachable, or
 * the vLLM API is not yet listening) and the caller should retry.
 */
final class AcquirePendingException extends \RuntimeException {

  /**
   * Operator-facing progress snapshot.
   *
   * @var array<string, string|int>
   *   Operator-facing progress snapshot.
   */
  private array $progress = [];

  /**
   * Contract ID associated with the pending acquire attempt.
   */
  private ?string $contractId = NULL;

  /**
   * Factory for retryable acquire states with operator-facing progress info.
   *
   * @param string $message
   *   Operator-facing summary message.
   * @param string $contractId
   *   Vast contract ID.
   * @param array<string, string|int> $progress
   *   Keys are intended for UI rendering (step/label/result/next/etc).
   * @param \Throwable|null $previous
   *   Previous exception.
   */
  public static function fromProgress(
    string $message,
    string $contractId,
    array $progress = [],
    ?\Throwable $previous = NULL,
  ): self {
    $exception = new self($message, 0, $previous);
    $exception->contractId = $contractId;
    $exception->progress = $progress;
    return $exception;
  }

  /**
   * Returns the Vast contract ID when available.
   */
  public function getContractId(): ?string {
    return $this->contractId;
  }

  /**
   * Returns an operator-facing progress snapshot for UI status messages.
   */
  public function getProgress(): array {
    return $this->progress;
  }

}
