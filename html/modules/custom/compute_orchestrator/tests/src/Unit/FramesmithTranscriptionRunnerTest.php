<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionTaskStoreInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionRunner.php';

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

    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $taskStore->expects($this->once())
      ->method('get')
      ->with($taskId)
      ->willReturn([
        'task_id' => $taskId,
        'status' => 'uploaded',
      ]);

    $taskStore->expects($this->exactly(4))
      ->method('transition')
      ->willReturnCallback(function (string $calledTaskId, string $status, array $extra = [], string $message = '') use ($taskId): array {
        TestCase::assertSame($taskId, $calledTaskId);

        static $index = 0;
        $index++;

        switch ($index) {
          case 1:
            TestCase::assertSame('running', $status);
            TestCase::assertArrayHasKey('runner_started_at', $extra);
            TestCase::assertIsInt($extra['runner_started_at']);
            TestCase::assertSame('Stub runner started immediately without cron.', $message);
            break;

          case 2:
            TestCase::assertSame('acquiring_runtime', $status);
            TestCase::assertSame([], $extra);
            TestCase::assertSame('Placeholder: compute_orchestrator whisper acquire will happen here.', $message);
            break;

          case 3:
            TestCase::assertSame('transcribing', $status);
            TestCase::assertSame([], $extra);
            TestCase::assertSame('Placeholder: remote whisper execution will happen here.', $message);
            break;

          case 4:
            TestCase::assertSame('completed', $status);
            TestCase::assertArrayHasKey('result', $extra);
            TestCase::assertSame('skeleton', $extra['result']['mode']);
            TestCase::assertSame(
              'Framesmith detached transcription skeleton completed successfully.',
              $extra['result']['json']['text'],
            );
            TestCase::assertSame([], $extra['result']['json']['segments']);
            TestCase::assertNull($extra['result']['json_url']);
            TestCase::assertArrayHasKey('completed_at', $extra['result']);
            TestCase::assertIsInt($extra['result']['completed_at']);
            TestCase::assertSame('Stub runner completed.', $message);
            break;
        }

        return [
          'task_id' => $calledTaskId,
          'status' => $status,
        ];
      });

    $runner = new FramesmithTranscriptionRunner($taskStore);
    $runner->run($taskId);
  }

  /**
   * @covers ::run
   */
  public function testRunThrowsForUnknownTask(): void {
    $taskStore = $this->createMock(FramesmithTranscriptionTaskStoreInterface::class);
    $taskStore->expects($this->once())
      ->method('get')
      ->with('missing-task')
      ->willReturn(NULL);
    $taskStore->expects($this->never())
      ->method('transition');

    $runner = new FramesmithTranscriptionRunner($taskStore);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unknown Framesmith transcription task: missing-task');
    $runner->run('missing-task');
  }

}
