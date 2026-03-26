<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Unit;

use Drupal\ai_listing\Service\UploadedFileTreeExtractor;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadedFileTreeExtractorTest extends UnitTestCase {

  public function testExtractsNestedUploadedFiles(): void {
    $firstPath = tempnam(sys_get_temp_dir(), 'ai_listing_upload_');
    $secondPath = tempnam(sys_get_temp_dir(), 'ai_listing_upload_');
    file_put_contents($firstPath, 'first');
    file_put_contents($secondPath, 'second');

    $first = new UploadedFile($firstPath, 'first.jpg', 'image/jpeg', null, true);
    $second = new UploadedFile($secondPath, 'second.jpg', 'image/jpeg', null, true);

    $extractor = new UploadedFileTreeExtractor();
    $uploads = $extractor->extract([
      'workspace' => [
        'upload' => [
          'file_input' => [$first, $second],
        ],
      ],
      'noise' => [
        'ignore' => 'value',
      ],
    ]);

    self::assertCount(2, $uploads);
    self::assertSame([$first, $second], $uploads);
  }

}
