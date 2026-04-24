<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Service/FramesmithTranscriptionExecutorInterface.php';
require_once __DIR__ . '/../../../src/Service/FramesmithWhisperHttpTranscriptionExecutor.php';

use Drupal\Core\File\FileSystemInterface;
use Drupal\compute_orchestrator\Service\FramesmithWhisperHttpTranscriptionExecutor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\compute_orchestrator\Service\FramesmithWhisperHttpTranscriptionExecutor
 *
 * @group compute_orchestrator
 */
final class FramesmithWhisperHttpTranscriptionExecutorTest extends TestCase {

  /**
   * @covers ::transcribe
   */
  public function testTranscribePostsAudioToWhisperEndpoint(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'framesmith-audio-');
    if ($tmpFile === FALSE) {
      $this->fail('Failed to create temp audio file.');
    }
    file_put_contents($tmpFile, 'fake-audio');

    $httpClient = $this->createMock(ClientInterface::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->expects($this->once())->method('realpath')->with('temporary://task/audio.wav')->willReturn($tmpFile);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'http://10.0.0.4:9000/v1/audio/transcriptions',
        $this->callback(function (array $options): bool {
          return isset($options['multipart']) && $options['timeout'] === 600;
        }),
      )
      ->willReturn(new Response(200, [], json_encode([
        'text' => 'Framesmith test one two three.',
        'segments' => [['id' => 0, 'text' => 'Framesmith test one two three.']],
        'language' => 'en',
        'duration' => 5.7,
      ], JSON_THROW_ON_ERROR)));

    $executor = new FramesmithWhisperHttpTranscriptionExecutor($httpClient, $fileSystem);
    $result = $executor->transcribe([
      'url' => 'http://10.0.0.4:9000',
      'current_model' => 'openai/whisper-large-v3-turbo',
    ], 'temporary://task/audio.wav', 'task-1');

    $this->assertSame('whisper_http', $result['mode']);
    $this->assertSame('Framesmith test one two three.', $result['json']['text']);
    $this->assertSame('en', $result['json']['language']);
    $this->assertCount(1, $result['json']['segments']);
    $this->assertSame('http://10.0.0.4:9000', $result['lease_url']);

    @unlink($tmpFile);
  }

  /**
   * @covers ::transcribe
   */
  public function testTranscribeToleratesConsumedMultipartStream(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'framesmith-audio-');
    if ($tmpFile === FALSE) {
      $this->fail('Failed to create temp audio file.');
    }
    file_put_contents($tmpFile, 'fake-audio');

    $httpClient = $this->createMock(ClientInterface::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->expects($this->once())->method('realpath')->with('temporary://task/audio.wav')->willReturn($tmpFile);

    $httpClient->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options): Response {
        $this->assertSame('POST', $method);
        $this->assertSame('http://10.0.0.4:9000/v1/audio/transcriptions', $url);
        $this->assertArrayHasKey('multipart', $options);
        foreach ($options['multipart'] as $part) {
          if (($part['name'] ?? '') === 'file') {
            $this->assertTrue(is_resource($part['contents']));
            fclose($part['contents']);
          }
        }

        return new Response(200, [], json_encode([
          'text' => 'Framesmith test one two three.',
          'segments' => [['id' => 0, 'text' => 'Framesmith test one two three.']],
          'language' => 'en',
          'duration' => 5.7,
        ], JSON_THROW_ON_ERROR));
      });

    $executor = new FramesmithWhisperHttpTranscriptionExecutor($httpClient, $fileSystem);
    $result = $executor->transcribe([
      'url' => 'http://10.0.0.4:9000',
      'current_model' => 'openai/whisper-large-v3-turbo',
    ], 'temporary://task/audio.wav', 'task-2');

    $this->assertSame('Framesmith test one two three.', $result['json']['text']);

    @unlink($tmpFile);
  }

}
