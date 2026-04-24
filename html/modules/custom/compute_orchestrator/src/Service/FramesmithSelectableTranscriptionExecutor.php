<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\State\StateInterface;

/**
 * Selects the active Framesmith transcription executor mode.
 */
final class FramesmithSelectableTranscriptionExecutor implements FramesmithTranscriptionExecutorInterface {

  private const STATE_KEY = 'compute_orchestrator.framesmith_transcription_executor_mode';

  public function __construct(
    private readonly FramesmithWhisperHttpTranscriptionExecutor $realExecutor,
    private readonly FramesmithFakeTranscriptionExecutor $fakeExecutor,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function requiresRuntimeLease(): bool {
    return $this->getActiveExecutor()->requiresRuntimeLease();
  }

  /**
   * {@inheritdoc}
   */
  public function transcribe(array $lease, string $localAudioPath, string $taskId): array {
    return $this->getActiveExecutor()->transcribe($lease, $localAudioPath, $taskId);
  }

  /**
   * Returns the currently active executor implementation.
   */
  private function getActiveExecutor(): FramesmithTranscriptionExecutorInterface {
    return $this->getConfiguredMode() === 'fake'
      ? $this->fakeExecutor
      : $this->realExecutor;
  }

  /**
   * Resolves the configured execution mode.
   */
  private function getConfiguredMode(): string {
    $env = trim((string) getenv('FRAMESMITH_TRANSCRIPTION_EXECUTOR_MODE'));
    if ($env !== '') {
      return $env === 'fake' ? 'fake' : 'real';
    }

    $stateValue = trim((string) $this->state->get(self::STATE_KEY, 'real'));
    return $stateValue === 'fake' ? 'fake' : 'real';
  }

}
