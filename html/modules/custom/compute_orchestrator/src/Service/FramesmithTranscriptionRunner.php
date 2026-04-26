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
    $this->recordDebug($taskId, 'runner.run.begin', []);
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      throw new \RuntimeException('Unknown Framesmith transcription task: ' . $taskId);
    }
    $this->recordDebug($taskId, 'runner.task.loaded', [
      'status' => (string) ($task['status'] ?? ''),
      'launch_ready' => (bool) ($task['launch_ready'] ?? FALSE),
    ]);

    $localAudioPath = trim((string) ($task['local_audio_path'] ?? ''));
    if ($localAudioPath === '') {
      throw new \RuntimeException('Task has no uploaded audio to transcribe: ' . $taskId);
    }
    $this->recordDebug($taskId, 'runner.audio.confirmed', [
      'local_audio_path' => $localAudioPath,
    ]);

    $lease = [];
    $contractId = '';

    try {
      if ($this->executor->requiresRuntimeLease()) {
        $this->recordDebug($taskId, 'runner.lease.acquire.begin', []);
        $lease = $this->leaseManager->acquireWhisperRuntime();
        $contractId = trim((string) ($lease['contract_id'] ?? ''));
        if ($contractId === '') {
          throw new \RuntimeException('Whisper runtime acquisition did not return a contract_id.');
        }
        $this->recordDebug($taskId, 'runner.lease.acquire.succeeded', [
          'contract_id' => $contractId,
          'lease_url' => (string) ($lease['url'] ?? ''),
          'current_model' => (string) ($lease['current_model'] ?? ''),
        ]);
      }

      $this->recordDebug($taskId, 'runner.transition.running.begin', [
        'contract_id' => $contractId,
      ]);
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
      if ($lease !== []) {
        $this->recordDebug($taskId, 'runner.transition.acquiring_runtime.begin', [
          'contract_id' => $contractId,
        ]);
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

      $this->recordDebug($taskId, 'runner.transition.transcribing.begin', [
        'contract_id' => $contractId,
      ]);
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

      $this->recordDebug($taskId, 'runner.executor.begin', [
        'contract_id' => $contractId,
        'lease_url' => (string) ($lease['url'] ?? ''),
      ]);
      $result = $this->executor->transcribe($lease, $localAudioPath, $taskId);
      $this->recordDebug($taskId, 'runner.executor.succeeded', [
        'result_mode' => (string) ($result['mode'] ?? ''),
        'lease_url' => (string) ($result['lease_url'] ?? ''),
      ]);
      $releasedLease = [];
      if ($contractId !== '') {
        $this->recordDebug($taskId, 'runner.release.begin', [
          'contract_id' => $contractId,
        ]);
        $releasedLease = $this->leaseManager->releaseRuntime($contractId);
        $this->recordDebug($taskId, 'runner.release.succeeded', [
          'contract_id' => $contractId,
        ]);
      }
      $this->recordDebug($taskId, 'runner.transition.completed.begin', [
        'contract_id' => $contractId,
      ]);
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
      $this->recordDebug($taskId, 'runner.exception.caught', [
        'message' => $exception->getMessage(),
      ]);
      $releasedLease = [];
      if ($contractId !== '') {
        try {
          $this->recordDebug($taskId, 'runner.release.after_exception.begin', [
            'contract_id' => $contractId,
          ]);
          $releasedLease = $this->leaseManager->releaseRuntime($contractId);
          $this->recordDebug($taskId, 'runner.release.after_exception.succeeded', [
            'contract_id' => $contractId,
          ]);
        }
        catch (\Throwable $releaseException) {
          $this->recordDebug($taskId, 'runner.release.after_exception.failed', [
            'contract_id' => $contractId,
            'message' => $releaseException->getMessage(),
          ]);
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

  /**
   * Appends one structured debug event to the task record.
   *
   * @param string $taskId
   *   Task identifier.
   * @param string $event
   *   Debug event name.
   * @param array<string, mixed> $context
   *   Extra context.
   */
  private function recordDebug(string $taskId, string $event, array $context): void {
    $task = $this->taskStore->get($taskId);
    if ($task === NULL) {
      return;
    }
    $events = $task['debug_events'] ?? [];
    if (!is_array($events)) {
      $events = [];
    }
    $events[] = [
      'event' => $event,
      'timestamp' => time(),
      'context' => $context,
    ];
    if (count($events) > 50) {
      $events = array_slice($events, -50);
    }
    $this->taskStore->merge($taskId, ['debug_events' => $events]);
  }

}
