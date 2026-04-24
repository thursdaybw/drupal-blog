<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Calls the leased Whisper runtime over HTTP to produce transcripts.
 */
final class FramesmithWhisperHttpTranscriptionExecutor implements FramesmithTranscriptionExecutorInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function requiresRuntimeLease(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function transcribe(array $lease, string $localAudioPath, string $taskId): array {
    $baseUrl = rtrim(trim((string) ($lease['url'] ?? '')), '/');
    if ($baseUrl === '') {
      throw new \RuntimeException('Lease did not include a runtime URL.');
    }

    $resolvedPath = $this->fileSystem->realpath($localAudioPath) ?: $localAudioPath;
    if ($resolvedPath === '' || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
      throw new \RuntimeException('Local audio path is not readable: ' . $localAudioPath);
    }

    $handle = fopen($resolvedPath, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException('Failed to open audio file for transcription: ' . $resolvedPath);
    }

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/v1/audio/transcriptions', [
        'timeout' => 600,
        'http_errors' => TRUE,
        'multipart' => [
          [
            'name' => 'model',
            'contents' => (string) ($lease['current_model'] ?? 'openai/whisper-large-v3-turbo'),
          ],
          [
            'name' => 'response_format',
            'contents' => 'verbose_json',
          ],
          [
            'name' => 'temperature',
            'contents' => '0',
          ],
          [
            'name' => 'language',
            'contents' => 'en',
          ],
          [
            'name' => 'file',
            'contents' => $handle,
            'filename' => basename($resolvedPath),
            'headers' => [
              'Content-Type' => $this->guessContentType($resolvedPath),
            ],
          ],
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      throw new \RuntimeException('Whisper transcription request failed: ' . $exception->getMessage(), 0, $exception);
    }
    finally {
      if (is_resource($handle)) {
        fclose($handle);
      }
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Whisper transcription response was not valid JSON.');
    }

    return [
      'mode' => 'whisper_http',
      'task_id' => $taskId,
      'lease_url' => $baseUrl,
      'message' => 'Remote Whisper transcription completed successfully.',
      'json' => [
        'text' => trim((string) ($decoded['text'] ?? '')),
        'segments' => is_array($decoded['segments'] ?? NULL) ? $decoded['segments'] : [],
        'language' => (string) ($decoded['language'] ?? 'en'),
        'duration' => $decoded['duration'] ?? NULL,
      ],
      'json_url' => NULL,
      'raw_response' => $decoded,
      'completed_at' => time(),
    ];
  }

  /**
   * Guesses a sensible content type for an audio file.
   */
  private function guessContentType(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
      'wav' => 'audio/wav',
      'mp3' => 'audio/mpeg',
      'm4a' => 'audio/mp4',
      'flac' => 'audio/flac',
      default => 'application/octet-stream',
    };
  }

}
