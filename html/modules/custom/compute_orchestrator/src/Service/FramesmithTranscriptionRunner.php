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
    private readonly FramesmithTranscriptionExecutorInterface $executor,
  ) {}

  /**
   * Executes one Framesmith transcription task.
   *
   * @param string $taskId
   *   Task identifier.
   */
  public function run(string $taskId): void {
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }

    $localAudioPath = trim((string) ($task['local_audio_path'] ?? ''));
    if ($localAudioPath === '') {
      throw new \RuntimeException('Task has no uploaded audio to transcribe: ' . $taskId);
    }

    $lease = [];
    $contractId = '';

    if ($this->executor->requiresRuntimeLease()) {
      $lease = $this->leaseManager->acquireWhisperRuntime();
      $contractId = trim((string) ($lease['contract_id'] ?? ''));
      if ($contractId === '') {
        throw new \RuntimeException('Whisper runtime acquisition did not return a contract_id.');
      }
    }

    $this->taskStore->transition(
      $taskId,
      'running',
      [
        'runner_started_at' => time(),
        'runtime_contract_id' => $contractId,
        'runtime_lease_snapshot' => $lease,
      ],
      'Runner started immediately without cron.',
    );

    try {
      if ($lease !== []) {
        $this->taskStore->transition(
          $taskId,
          'acquiring_runtime',
          [
            'runtime_contract_id' => $contractId,
            'runtime_lease_snapshot' => $lease,
          ],
          'Acquired pooled whisper runtime from compute_orchestrator.',
        );
      }
      else {
        $this->taskStore->transition(
          $taskId,
          'acquiring_runtime',
          [
            'runtime_contract_id' => '',
            'runtime_lease_snapshot' => [],
          ],
          'Fake transcription mode selected; skipping real runtime lease.',
        );
      }

      $this->taskStore->transition(
        $taskId,
        'transcribing',
        [
          'runtime_contract_id' => $contractId,
          'runtime_lease_snapshot' => $lease,
          'local_audio_path' => $localAudioPath,
        ],
        'Submitting audio to selected transcription executor.',
      );

      $result = $this->executor->transcribe($lease, $localAudioPath, $taskId);
      $releasedLease = [];
      if ($contractId !== '') {
        $releasedLease = $this->leaseManager->releaseRuntime($contractId);
      }
      $this->taskStore->transition(
        $taskId,
        'completed',
        [
          'runtime_contract_id' => $contractId,
          'runtime_lease_snapshot' => $lease,
          'runtime_release_snapshot' => $releasedLease,
          'result' => $result,
        ],
        $contractId !== ''
          ? 'Transcription completed and pooled runtime released.'
          : 'Fake transcription completed without real compute.',
      );
    }
    catch (\Throwable $exception) {
      $releasedLease = [];
      if ($contractId !== '') {
        try {
          $releasedLease = $this->leaseManager->releaseRuntime($contractId);
        }
        catch (\Throwable) {
          $releasedLease = [
            'contract_id' => $contractId,
            'release_failed' => TRUE,
          ];
        }
      }

      $this->taskStore->fail(
        $taskId,
        $exception->getMessage(),
        [
          'runtime_contract_id' => $contractId,
          'runtime_lease_snapshot' => $lease,
          'runtime_release_snapshot' => $releasedLease,
        ],
      );
      throw $exception;
    }
  }

}
