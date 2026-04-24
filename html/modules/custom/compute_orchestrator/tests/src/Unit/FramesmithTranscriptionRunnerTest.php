<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionTaskStoreInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithRuntimeLeaseManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionExecutorInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionRunner.php';

use Drupal\compute_orchestrator\Service\FramesmithRuntimeLeaseManagerInterface;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionExecutorInterface;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionRunner;
use Drupal\compute_orchestrator\Service\FramesmithTranscriptionTaskStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\compute_orchestrator\Service\FramesmithTranscriptionRunner
 *
 * @group compute_orchestrator
 */
final class FramesmithTranscriptionRunnerTest extends TestCase {

  /**
   * @covers ::run
   */
  public function testRunTransitionsTaskThroughRealExecutionLifecycle(): void {
    $taskId = 'task-123';
    $audioPath = 'temporary://framesmith-transcription/task-123/audio.wav';
    $lease = [
      'contract_id' => 'contract-9',
      'lease_token' => 'token-123',
      'url' => 'http://10.0.0.4:9000',
      'current_workload_mode' => 'whisper',
    ];
    $releasedLease = [
      'contract_id' => 'contract-9',
      'lease_status' => 'available',
    ];
    $result = [
      'mode' => 'whisper_http',
      'json' => [
        'text' => 'framesmith test one two three',
        'segments' => [],
      ],
      'completed_at' => 123456789,
    ];

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);
    $executor = $this->createMock(FramesmithTranscriptionExecutorInterface::class);

    $taskStore->expects($this->once())
      ->method('get')
      ->with($taskId)
      ->willReturn([
        'task_id' => $taskId,
        'status' => 'uploaded',
        'local_audio_path' => $audioPath,
      ]);

    $executor->expects($this->once())->method('requiresRuntimeLease')->willReturn(TRUE);
    $leaseManager->expects($this->once())
      ->method('acquireWhisperRuntime')
      ->willReturn($lease);
    $executor->expects($this->once())
      ->method('transcribe')
      ->with($lease, $audioPath, $taskId)
      ->willReturn($result);
    $leaseManager->expects($this->once())
      ->method('releaseRuntime')
      ->with('contract-9')
      ->willReturn($releasedLease);

    $taskStore->expects($this->exactly(4))
      ->method('transition')
      ->willReturnCallback(function (string $calledTaskId, string $status, array $extra = [], string $message = '') use ($taskId, $lease, $audioPath, $releasedLease, $result): array {
        TestCase::assertSame($taskId, $calledTaskId);

        static $index = 0;
        $index++;

        switch ($index) {
          case 1:
            TestCase::assertSame('running', $status);
            TestCase::assertArrayHasKey('runner_started_at', $extra);
            TestCase::assertSame($lease, $extra['lease']);
            TestCase::assertSame('Runner started immediately without cron.', $message);
            break;

          case 2:
            TestCase::assertSame('acquiring_runtime', $status);
            TestCase::assertSame($lease, $extra['lease']);
            TestCase::assertSame('Acquired pooled whisper runtime from compute_orchestrator.', $message);
            break;

          case 3:
            TestCase::assertSame('transcribing', $status);
            TestCase::assertSame($lease, $extra['lease']);
            TestCase::assertSame($audioPath, $extra['local_audio_path']);
            TestCase::assertSame('Submitting audio to selected transcription executor.', $message);
            break;

          case 4:
            TestCase::assertSame('completed', $status);
            TestCase::assertSame($lease, $extra['lease']);
            TestCase::assertSame($releasedLease, $extra['released_lease']);
            TestCase::assertSame($result, $extra['result']);
            TestCase::assertSame('Transcription completed and pooled runtime released.', $message);
            break;
        }

        return [
          'task_id' => $calledTaskId,
          'status' => $status,
        ];
      });
    $taskStore->expects($this->never())->method('fail');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager, $executor);
    $runner->run($taskId);
  }

  /**
   * @covers ::run
   */
  public function testRunSkipsLeaseInFakeMode(): void {
    $taskId = 'task-fake';
    $audioPath = 'temporary://framesmith-transcription/task-fake/framesmith-known-text.wav';
    $result = [
      'mode' => 'fake',
      'json' => [
        'text' => 'Framesmith test one two three. The quick brown fox jumps over the lazy dog.',
        'segments' => [],
      ],
      'completed_at' => 123456789,
    ];

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);
    $executor = $this->createMock(FramesmithTranscriptionExecutorInterface::class);

    $taskStore->expects($this->once())->method('get')->with($taskId)->willReturn([
      'task_id' => $taskId,
      'local_audio_path' => $audioPath,
    ]);
    $executor->expects($this->once())->method('requiresRuntimeLease')->willReturn(FALSE);
    $leaseManager->expects($this->never())->method('acquireWhisperRuntime');
    $leaseManager->expects($this->never())->method('releaseRuntime');
    $executor->expects($this->once())->method('transcribe')->with([], $audioPath, $taskId)->willReturn($result);

    $taskStore->expects($this->exactly(4))
      ->method('transition')
      ->willReturnCallback(function (string $calledTaskId, string $status, array $extra = [], string $message = '') use ($taskId, $audioPath, $result): array {
        TestCase::assertSame($taskId, $calledTaskId);
        static $index = 0;
        $index++;
        switch ($index) {
          case 2:
            TestCase::assertSame([], $extra['lease']);
            TestCase::assertSame('Fake transcription mode selected; skipping real runtime lease.', $message);

            break;

          case 3:
            TestCase::assertSame($audioPath, $extra['local_audio_path']);

            break;

          case 4:
            TestCase::assertSame([], $extra['released_lease']);
            TestCase::assertSame($result, $extra['result']);
            TestCase::assertSame('Fake transcription completed without real compute.', $message);
            break;
        }
        return ['task_id' => $calledTaskId, 'status' => $status];
      });
    $taskStore->expects($this->never())->method('fail');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager, $executor);
    $runner->run($taskId);
  }

  /**
   * @covers ::run
   */
  public function testRunThrowsForUnknownTask(): void {
    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);
    $executor = $this->createMock(FramesmithTranscriptionExecutorInterface::class);

    $taskStore->expects($this->once())
      ->method('get')
      ->with('missing-task')
      ->willReturn(NULL);
    $executor->expects($this->never())->method('requiresRuntimeLease');
    $leaseManager->expects($this->never())->method('acquireWhisperRuntime');
    $executor->expects($this->never())->method('transcribe');
    $taskStore->expects($this->never())->method('transition');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager, $executor);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unknown Framesmith transcription task: missing-task');
    $runner->run('missing-task');
  }

  /**
   * @covers ::run
   */
  public function testRunThrowsWhenTaskHasNoAudio(): void {
    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);
    $executor = $this->createMock(FramesmithTranscriptionExecutorInterface::class);

    $taskStore->expects($this->once())
      ->method('get')
      ->with('task-no-audio')
      ->willReturn(['task_id' => 'task-no-audio']);
    $executor->expects($this->never())->method('requiresRuntimeLease');
    $leaseManager->expects($this->never())->method('acquireWhisperRuntime');
    $executor->expects($this->never())->method('transcribe');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager, $executor);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Task has no uploaded audio to transcribe: task-no-audio');
    $runner->run('task-no-audio');
  }

  /**
   * @covers ::run
   */
  public function testRunMarksTaskFailedWhenExecutorFails(): void {
    $taskId = 'task-fail';
    $audioPath = 'temporary://framesmith-transcription/task-fail/audio.wav';
    $lease = [
      'contract_id' => 'contract-fail',
      'lease_token' => 'token-fail',
      'url' => 'http://10.0.0.5:9000',
    ];
    $releasedLease = [
      'contract_id' => 'contract-fail',
      'lease_status' => 'available',
    ];

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);
    $executor = $this->createMock(FramesmithTranscriptionExecutorInterface::class);

    $taskStore->method('get')->with($taskId)->willReturn([
      'task_id' => $taskId,
      'local_audio_path' => $audioPath,
    ]);
    $executor->expects($this->once())->method('requiresRuntimeLease')->willReturn(TRUE);
    $leaseManager->expects($this->once())->method('acquireWhisperRuntime')->willReturn($lease);
    $executor->expects($this->once())
      ->method('transcribe')
      ->with($lease, $audioPath, $taskId)
      ->willThrowException(new \RuntimeException('remote execution blew up'));
    $leaseManager->expects($this->once())->method('releaseRuntime')->with('contract-fail')->willReturn($releasedLease);

    $taskStore->expects($this->exactly(3))
      ->method('transition')
      ->willReturn(['task_id' => $taskId]);

    $taskStore->expects($this->once())
      ->method('fail')
      ->with(
        $taskId,
        'remote execution blew up',
        $this->callback(function (array $extra) use ($lease, $releasedLease): bool {
          return $extra['lease'] === $lease && $extra['released_lease'] === $releasedLease;
        }),
      )
      ->willReturn(['task_id' => $taskId, 'status' => 'failed']);

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager, $executor);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('remote execution blew up');
    $runner->run($taskId);
  }

}
