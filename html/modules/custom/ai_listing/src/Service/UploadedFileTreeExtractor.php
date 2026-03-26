<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadedFileTreeExtractor {

  /**
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile[]
   */
  public function extract(mixed $value): array {
    $uploads = [];
    $this->appendUploadedFiles($value, $uploads);
    return $uploads;
  }

  /**
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile[] $uploads
   */
  private function appendUploadedFiles(mixed $value, array &$uploads): void {
    if ($value instanceof UploadedFile) {
      $uploads[] = $value;
      return;
    }

    if (!is_array($value)) {
      return;
    }

    foreach ($value as $child) {
      $this->appendUploadedFiles($child, $uploads);
    }
  }

}
