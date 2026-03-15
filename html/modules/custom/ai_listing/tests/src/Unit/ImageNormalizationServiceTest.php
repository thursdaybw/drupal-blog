<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Unit;

use Drupal\ai_listing\Service\ImageNormalizationService;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

final class ImageNormalizationServiceTest extends UnitTestCase {

  private string $tempDirectory;

  protected function setUp(): void {
    parent::setUp();
    $this->tempDirectory = sys_get_temp_dir() . '/ai-listing-image-normalization-' . uniqid('', TRUE);
    mkdir($this->tempDirectory, 0777, TRUE);
  }

  protected function tearDown(): void {
    foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
      @unlink($file);
    }
    @rmdir($this->tempDirectory);
    parent::tearDown();
  }

  public function testNormalizeLeavesAlreadyUprightJpegUnchanged(): void {
    if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
      $this->markTestSkipped('Imagick extension is required for this test.');
    }

    $path = $this->tempDirectory . '/upright.jpg';
    $this->createUprightJpeg($path);

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturnCallback(static fn(string $uri): string => $uri);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('ai_listing')->willReturn($logger);

    $before = new \Imagick($path);
    $beforeWidth = $before->getImageWidth();
    $beforeHeight = $before->getImageHeight();
    $beforeOrientation = $before->getImageOrientation();
    $before->clear();
    $before->destroy();

    $service = new ImageNormalizationService($fileSystem, $loggerFactory);
    $service->normalize($path);

    $after = new \Imagick($path);
    $this->assertSame($beforeWidth, $after->getImageWidth());
    $this->assertSame($beforeHeight, $after->getImageHeight());
    $this->assertSame($beforeOrientation, $after->getImageOrientation());
    $after->clear();
    $after->destroy();
  }

  public function testNormalizeIgnoresMissingFile(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn(FALSE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('ai_listing')->willReturn($logger);

    $service = new ImageNormalizationService($fileSystem, $loggerFactory);
    $service->normalize('public://missing.jpg');

    $this->addToAssertionCount(1);
  }

  private function createUprightJpeg(string $path): void {
    $image = new \Imagick();
    $image->newImage(10, 20, new \ImagickPixel('red'));
    $image->setImageFormat('jpeg');
    $image->writeImage($path);
    $image->clear();
    $image->destroy();
  }

}
