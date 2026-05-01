<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\compute_orchestrator\Exception\AcquirePendingException;

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
    catch (AcquirePendingException $exception) {
      $this->recordRuntimeAcquirePending($taskId, $contractId, $lease, $exception);
      throw $exception;
    }
    catch (\Throwable $exception) {
      $this->recordTerminalRunnerFailure($taskId, $contractId, $lease, $exception);
      throw $exception;
    }
  }

  /**
   * Records retryable runtime-acquire progress without failing the task.
   *
   * A runtime warmup probe is allowed to fail many times before the service is
   * ready. Those probe failures belong to the acquire attempt, not to the
   * Framesmith transcription task as a whole. Keep the task in an active state
   * and store the operator-facing probe detail separately so status polling can
   * say "still warming" instead of "failed".
   *
   * @param string $taskId
   *   Framesmith task ID.
   * @param string $contractId
   *   Current runtime contract ID, if already known.
   * @param array<string,mixed> $lease
   *   Current lease snapshot, if one has been allocated.
   * @param \Drupal\compute_orchestrator\Exception\AcquirePendingException $exception
   *   Retryable pool-acquire progress exception.
   */
  private function recordRuntimeAcquirePending(
    string $taskId,
    string $contractId,
    array $lease,
    AcquirePendingException $exception,
  ): void {
    $resolvedContractId = $contractId !== ''
      ? $contractId
      : (string) ($exception->getContractId() ?? '');
    $progress = $exception->getProgress();
    $this->recordDebug($taskId, 'runner.lease.acquire.pending', [
      'contract_id' => $resolvedContractId,
      'message' => $exception->getMessage(),
      'progress' => $progress,
    ]);

    $this->taskStore->transition(
      $taskId,
      'acquiring_runtime',
      [
        'runtime_contract_id' => $resolvedContractId,
        'runtime_lease_snapshot' => $lease,
        'runtime_progress' => [
          'retryable' => TRUE,
          'message' => $exception->getMessage(),
          'progress' => $progress,
          'updated_at' => time(),
        ],
        'last_error' => '',
      ],
      'Runtime is still warming; waiting for the next acquire retry.',
    );
  }

  /**
   * Records a terminal runner failure and releases any owned lease.
   *
   * This is deliberately separate from retryable acquire progress: once the
   * runner has a real terminal exception, the task should move to failed and
   * any runtime lease must be released so paid capacity is not stranded.
   *
   * @param string $taskId
   *   Framesmith task ID.
   * @param string $contractId
   *   Runtime contract ID to release, if present.
   * @param array<string,mixed> $lease
   *   Current lease snapshot.
   * @param \Throwable $exception
   *   Terminal runner exception.
   */
  private function recordTerminalRunnerFailure(
    string $taskId,
    string $contractId,
    array $lease,
    \Throwable $exception,
  ): void {
    $this->recordDebug($taskId, 'runner.exception.caught', [
      'message' => $exception->getMessage(),
    ]);
    $releasedLease = [];
    if ($contractId !== '') {
      $releasedLease = $this->releaseRuntimeAfterTerminalFailure($taskId, $contractId);
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
  }

  /**
   * Releases a runtime lease after terminal task failure.
   *
   * Release failures are recorded on the task debug stream instead of masking
   * the original transcription failure that operators need to diagnose first.
   *
   * @param string $taskId
   *   Framesmith task ID for debug events.
   * @param string $contractId
   *   Runtime contract ID to release.
   *
   * @return array<string,mixed>
   *   Release snapshot or failure marker.
   */
  private function releaseRuntimeAfterTerminalFailure(string $taskId, string $contractId): array {
    try {
      $this->recordDebug($taskId, 'runner.release.after_exception.begin', [
        'contract_id' => $contractId,
      ]);
      $releasedLease = $this->leaseManager->releaseRuntime($contractId);
      $this->recordDebug($taskId, 'runner.release.after_exception.succeeded', [
        'contract_id' => $contractId,
      ]);
      return $releasedLease;
    }
    catch (\Throwable $releaseException) {
      $this->recordDebug($taskId, 'runner.release.after_exception.failed', [
        'contract_id' => $contractId,
        'message' => $releaseException->getMessage(),
      ]);
      return [
        'contract_id' => $contractId,
        'release_failed' => TRUE,
      ];
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
