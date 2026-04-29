<?php

declare(strict_types=1);

namespace Drupal\media_transcription\Service;

use Drupal\Core\File\FileSystemInterface;

/**
 * Returns deterministic fake transcripts for frontend and dev work.
 */
final class FakeTranscriptionExecutor implements TranscriptionExecutorInterface {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function requiresRuntimeLease(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function transcribe(array $lease, string $localAudioPath, string $taskId): array {
    $resolvedPath = $this->fileSystem->realpath($localAudioPath) ?: $localAudioPath;
    $filename = basename($resolvedPath);

    $text = 'Fake transcription transcript for ' . $filename . '.';
    if (str_contains($filename, 'framesmith-known-text')) {
      $text = 'Transcription test one two three. The quick brown fox jumps over the lazy dog.';
    }

    usleep(200000);

    return [
      'mode' => 'fake',
      'task_id' => $taskId,
      'lease_url' => NULL,
      'message' => 'Fake transcription executor completed without real compute.',
      'json' => [
        'text' => $text,
        'segments' => [
          [
            'id' => 0,
            'start' => 0.0,
            'end' => 5.0,
            'text' => $text,
          ],
        ],
        'language' => 'en',
        'duration' => 5.0,
      ],
      'json_url' => NULL,
      'raw_response' => [
        'fake' => TRUE,
        'source_file' => $filename,
      ],
      'completed_at' => time(),
    ];
  }

}
