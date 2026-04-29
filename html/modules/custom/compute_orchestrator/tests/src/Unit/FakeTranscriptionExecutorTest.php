<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../../media_transcription/src/Service/TranscriptionExecutorInterface.php';
require_once __DIR__ . '/../../../../media_transcription/src/Service/FakeTranscriptionExecutor.php';

use Drupal\Core\File\FileSystemInterface;
use Drupal\media_transcription\Service\FakeTranscriptionExecutor;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\media_transcription\Service\FakeTranscriptionExecutor
 *
 * @group compute_orchestrator
 */
final class FakeTranscriptionExecutorTest extends TestCase {

  /**
   * @covers ::requiresRuntimeLease
   * @covers ::transcribe
   */
  public function testFakeExecutorReturnsKnownFixtureTranscript(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->expects($this->once())
      ->method('realpath')
      ->with('temporary://framesmith/framesmith-known-text.wav')
      ->willReturn('/tmp/framesmith-known-text.wav');

    $executor = new FakeTranscriptionExecutor($fileSystem);

    $this->assertFalse($executor->requiresRuntimeLease());

    $result = $executor->transcribe([], 'temporary://framesmith/framesmith-known-text.wav', 'task-1');

    $this->assertSame('fake', $result['mode']);
    $this->assertSame('Framesmith test one two three. The quick brown fox jumps over the lazy dog.', $result['json']['text']);
    $this->assertCount(1, $result['json']['segments']);
    $this->assertNull($result['lease_url']);
  }

}
