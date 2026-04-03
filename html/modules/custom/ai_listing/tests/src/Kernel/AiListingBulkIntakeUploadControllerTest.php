<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\MediaType;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class AiListingBulkIntakeUploadControllerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'text',
    'filter',
    'options',
    'dynamic_entity_reference',
    'taxonomy',
    'bb_platform',
    'ai_listing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('media_type');
    $this->installConfig(['field', 'media']);
    $this->createIntakeMediaBundle();

    $user = User::create([
      'name' => 'chunk-tester',
      'mail' => 'chunk-tester@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  public function testUploadChunkFinalizesToSingleIntakeMedia(): void {
    $controller = \Drupal\ai_listing\Controller\AiListingBulkIntakeUploadController::create($this->container);

    $suffix = (string) str_replace('.', '-', uniqid('', true));
    $setId = 'set_1-test-' . $suffix;
    $uploadId = 'upload_abc123_' . $suffix;
    $filename = 'example.jpg';
    $chunkOneData = 'hello-';
    $chunkTwoData = 'world';
    $fileSize = strlen($chunkOneData . $chunkTwoData);

    $chunkOneRequest = new Request(
      [],
      [
        'set_id' => $setId,
        'upload_id' => $uploadId,
        'chunk_index' => '0',
        'chunk_count' => '2',
        'chunk_start' => '0',
        'file_size' => (string) $fileSize,
        'file_name' => $filename,
      ],
      [],
      [],
      [
        'chunk' => $this->createUploadedChunk($chunkOneData, $filename),
      ],
    );
    $chunkOneResponse = $controller->uploadChunk($chunkOneRequest);
    $chunkOnePayload = json_decode((string) $chunkOneResponse->getContent(), TRUE);
    $this->assertSame(200, $chunkOneResponse->getStatusCode());
    $this->assertSame(TRUE, $chunkOnePayload['ok'] ?? FALSE);
    $this->assertSame('chunk_received', $chunkOnePayload['status'] ?? '');

    $chunkTwoRequest = new Request(
      [],
      [
        'set_id' => $setId,
        'upload_id' => $uploadId,
        'chunk_index' => '1',
        'chunk_count' => '2',
        'chunk_start' => (string) strlen($chunkOneData),
        'file_size' => (string) $fileSize,
        'file_name' => $filename,
      ],
      [],
      [],
      [
        'chunk' => $this->createUploadedChunk($chunkTwoData, $filename),
      ],
    );
    $chunkTwoResponse = $controller->uploadChunk($chunkTwoRequest);
    $chunkTwoPayload = json_decode((string) $chunkTwoResponse->getContent(), TRUE);
    $this->assertSame(200, $chunkTwoResponse->getStatusCode());
    $this->assertSame(TRUE, $chunkTwoPayload['ok'] ?? FALSE);
    $this->assertSame('file_staged', $chunkTwoPayload['status'] ?? '');

    $mediaIds = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'ai_listing_intake')
      ->condition('uid', (int) $this->container->get('current_user')->id())
      ->execute();
    $this->assertCount(1, $mediaIds, 'One intake media should be created after final chunk.');

    $media = $this->container->get('entity_type.manager')->getStorage('media')->load(reset($mediaIds));
    $file = $media?->get('field_media_image')->entity;
    $this->assertNotNull($file, 'Intake media should reference a file entity.');

    $uri = (string) $file->getFileUri();
    $this->assertStringContainsString('public://ai-intake/' . $setId . '/', $uri);
    $realpath = $this->container->get('file_system')->realpath($uri);
    $this->assertNotFalse($realpath);
    $this->assertSame($chunkOneData . $chunkTwoData, (string) file_get_contents((string) $realpath));
  }

  private function createUploadedChunk(string $data, string $originalName): UploadedFile {
    $tempPath = tempnam(sys_get_temp_dir(), 'aiintake-chunk-');
    file_put_contents((string) $tempPath, $data);
    return new UploadedFile(
      (string) $tempPath,
      $originalName,
      'application/octet-stream',
      null,
      true,
    );
  }

  private function createIntakeMediaBundle(): void {
    if (!MediaType::load('ai_listing_intake')) {
      MediaType::create([
        'id' => 'ai_listing_intake',
        'label' => 'AI Listing Intake',
        'description' => 'Bulk intake image media.',
        'source' => 'image',
        'source_configuration' => [
          'source_field' => 'field_media_image',
        ],
        'new_revision' => FALSE,
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('media', 'field_media_image')) {
      FieldStorageConfig::create([
        'field_name' => 'field_media_image',
        'entity_type' => 'media',
        'type' => 'image',
        'cardinality' => 1,
      ])->save();
    }

    if (!FieldConfig::loadByName('media', 'ai_listing_intake', 'field_media_image')) {
      FieldConfig::create([
        'field_name' => 'field_media_image',
        'entity_type' => 'media',
        'bundle' => 'ai_listing_intake',
        'label' => 'Image',
        'required' => TRUE,
      ])->save();
    }
  }

}
