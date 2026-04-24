<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionTaskStoreInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithRuntimeLeaseManagerInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionRunner.php';

use Drupal\compute_orchestrator\Service\FramesmithRuntimeLeaseManagerInterface;
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
  public function testRunTransitionsTaskThroughSkeletonLifecycle(): void {
    $taskId = 'task-123';
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

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);

    $taskStore->expects($this->once())
      ->method('get')
      ->with($taskId)
      ->willReturn([
        'task_id' => $taskId,
        'status' => 'uploaded',
      ]);

    $leaseManager->expects($this->once())
      ->method('acquireWhisperRuntime')
      ->willReturn($lease);
    $leaseManager->expects($this->once())
      ->method('releaseRuntime')
      ->with('contract-9')
      ->willReturn($releasedLease);

    $taskStore->expects($this->exactly(4))
      ->method('transition')
      ->willReturnCallback(function (string $calledTaskId, string $status, array $extra = [], string $message = '') use ($taskId, $lease, $releasedLease): array {
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
            TestCase::assertSame('Placeholder: remote whisper execution will happen here.', $message);
            break;

          case 4:
            TestCase::assertSame('completed', $status);
            TestCase::assertSame($lease, $extra['lease']);
            TestCase::assertSame($releasedLease, $extra['released_lease']);
            TestCase::assertSame('skeleton', $extra['result']['mode']);
            TestCase::assertSame('Stub runner completed and released pooled runtime.', $message);
            break;
        }

        return [
          'task_id' => $calledTaskId,
          'status' => $status,
        ];
      });
    $taskStore->expects($this->never())->method('fail');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager);
    $runner->run($taskId);
  }

  /**
   * @covers ::run
   */
  public function testRunThrowsForUnknownTask(): void {
    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);

    $taskStore->expects($this->once())
      ->method('get')
      ->with('missing-task')
      ->willReturn(NULL);
    $leaseManager->expects($this->never())->method('acquireWhisperRuntime');
    $taskStore->expects($this->never())->method('transition');

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unknown Framesmith transcription task: missing-task');
    $runner->run('missing-task');
  }

  /**
   * @covers ::run
   */
  public function testRunMarksTaskFailedWhenTranscriptionStepFails(): void {
    $taskId = 'task-fail';
    $lease = [
      'contract_id' => 'contract-fail',
      'lease_token' => 'token-fail',
    ];
    $releasedLease = [
      'contract_id' => 'contract-fail',
      'lease_status' => 'available',
    ];

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $leaseManager = $this->createMock(FramesmithRuntimeLeaseManagerInterface::class);

    $taskStore->method('get')->with($taskId)->willReturn(['task_id' => $taskId]);
    $leaseManager->expects($this->once())->method('acquireWhisperRuntime')->willReturn($lease);
    $leaseManager->expects($this->once())->method('releaseRuntime')->with('contract-fail')->willReturn($releasedLease);

    $taskStore->expects($this->exactly(3))
      ->method('transition')
      ->willReturnCallback(function (string $calledTaskId, string $status, array $extra = [], string $message = '') {
        static $index = 0;
        $index++;
        if ($index === 3) {
          throw new \RuntimeException('remote execution blew up');
        }
        return ['task_id' => $calledTaskId, 'status' => $status];
      });

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

    $runner = new FramesmithTranscriptionRunner($taskStore, $leaseManager);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('remote execution blew up');
    $runner->run($taskId);
  }

}
