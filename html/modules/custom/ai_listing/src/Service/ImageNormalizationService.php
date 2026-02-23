<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Process\Process;

final class ImageNormalizationService {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function normalize(string $uri): void {

    $realPath = $this->fileSystem->realpath($uri);

    if (!$realPath || !file_exists($realPath)) {
      return;
    }

    // Only normalize JPEG
    $mime = mime_content_type($realPath);
    if ($mime !== 'image/jpeg') {
      return;
    }

    // Check EXIF orientation
    $exif = @exif_read_data($realPath);
    if (empty($exif['Orientation']) || (int) $exif['Orientation'] === 1) {
      return;
    }

    $tmpPath = $realPath . '.norm.jpg';

    $process = new Process([
      '/usr/bin/convert',
      $realPath,
      '-auto-orient',
      '-strip',
      '-quality',
      '90',
      $tmpPath,
    ]);

    $process->setTimeout(30);
    $process->run();

    if (!$process->isSuccessful()) {
      return;
    }

    rename($tmpPath, $realPath);
  }
}
