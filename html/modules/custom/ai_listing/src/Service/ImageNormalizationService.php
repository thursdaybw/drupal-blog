<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class ImageNormalizationService {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_listing');
  }

  private readonly LoggerInterface $logger;

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

    $exif = @exif_read_data($realPath);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    if ($orientation === 1) {
      return;
    }

    if ($this->canUseImagick()) {
      $imagickResult = $this->normalizeWithImagick($realPath, $uri);
      if ($imagickResult !== NULL) {
        return;
      }
    }

    if (!$this->canUseConvert()) {
      $this->logger->warning('Image normalization skipped for {uri}: no supported backend is available.', [
        'uri' => $uri,
      ]);
      return;
    }

    $tmpPath = $realPath . '.norm.jpg';
    $this->normalizeWithConvert($realPath, $tmpPath, $uri);
  }

  private function canUseImagick(): bool {
    return extension_loaded('imagick') && class_exists(\Imagick::class);
  }

  private function canUseConvert(): bool {
    return is_executable('/usr/bin/convert');
  }

  private function normalizeWithImagick(string $realPath, string $uri): ?bool {
    try {
      $image = new \Imagick($realPath);
      if ($image->getImageOrientation() === \Imagick::ORIENTATION_TOPLEFT) {
        $image->clear();
        $image->destroy();
        return TRUE;
      }
      $image->autoOrient();
      $image->stripImage();
      $image->setImageCompressionQuality(90);
      $image->writeImage($realPath);
      $image->clear();
      $image->destroy();
      return TRUE;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Imagick image normalization failed for {uri}: {message}', [
        'uri' => $uri,
        'message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  private function normalizeWithConvert(string $realPath, string $tmpPath, string $uri): void {
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
      $this->logger->warning('Convert image normalization failed for {uri}: exit={exit_code} stderr={stderr}', [
        'uri' => $uri,
        'exit_code' => $process->getExitCode(),
        'stderr' => trim($process->getErrorOutput()),
      ]);
      return;
    }

    if (!rename($tmpPath, $realPath)) {
      $this->logger->warning('Convert image normalization could not replace original file for {uri}.', [
        'uri' => $uri,
      ]);
    }
  }

}
