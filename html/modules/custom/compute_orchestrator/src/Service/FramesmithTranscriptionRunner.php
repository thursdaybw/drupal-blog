<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Runs one Framesmith transcription task.
 */
final class FramesmithTranscriptionRunner {

  public function __construct(
    private readonly FramesmithTranscriptionTaskStoreInterface $taskStore,
    private readonly FramesmithRuntimeLeaseManagerInterface $leaseManager,
  ) {}

  /**
   * Executes one Framesmith transcription task.
   *
   * This is currently a skeleton used to prove immediate detached kickoff
   * before wiring in real remote execution.
   *
   * @param string $taskId
   *   Task identifier.
   */
  public function run(string $taskId): void {
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }

    $lease = $this->leaseManager->acquireWhisperRuntime();
    $contractId = trim((string) ($lease['contract_id'] ?? ''));
    if ($contractId === '') {
      throw new \RuntimeException('Whisper runtime acquisition did not return a contract_id.');
    }

    $this->taskStore->transition(
      $taskId,
      'running',
      [
        'runner_started_at' => time(),
        'lease' => $lease,
      ],
      'Runner started immediately without cron.',
    );

    try {
      $this->taskStore->transition(
        $taskId,
        'acquiring_runtime',
        ['lease' => $lease],
        'Acquired pooled whisper runtime from compute_orchestrator.',
      );

      usleep(250000);
      $this->taskStore->transition(
        $taskId,
        'transcribing',
        ['lease' => $lease],
        'Placeholder: remote whisper execution will happen here.',
      );

      usleep(250000);
      $releasedLease = $this->leaseManager->releaseRuntime($contractId);
      $this->taskStore->transition(
        $taskId,
        'completed',
        [
          'lease' => $lease,
          'released_lease' => $releasedLease,
          'result' => [
            'mode' => 'skeleton',
            'message' => 'Detached Drush runner executed successfully. Replace this stub with real compute_orchestrator-backed transcription.',
            'json' => [
              'text' => 'Framesmith detached transcription skeleton completed successfully.',
              'segments' => [],
            ],
            'json_url' => NULL,
            'completed_at' => time(),
          ],
        ],
        'Stub runner completed and released pooled runtime.',
      );
    }
    catch (\Throwable $exception) {
      try {
        $releasedLease = $this->leaseManager->releaseRuntime($contractId);
      }
      catch (\Throwable) {
        $releasedLease = [
          'contract_id' => $contractId,
          'release_failed' => TRUE,
        ];
      }

      $this->taskStore->fail(
        $taskId,
        $exception->getMessage(),
        [
          'lease' => $lease,
          'released_lease' => $releasedLease,
        ],
      );
      throw $exception;
    }
  }

}
