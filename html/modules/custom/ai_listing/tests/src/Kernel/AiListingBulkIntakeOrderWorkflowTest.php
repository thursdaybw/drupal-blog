<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Form\AiListingBulkIntakeForm;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\Tests\ai_listing\Traits\InstallsBbAiListingKernelSchemaTrait;
use Drupal\user\Entity\User;
use Drupal\Core\Form\FormState;

final class AiListingBulkIntakeOrderWorkflowTest extends KernelTestBase {

  use InstallsBbAiListingKernelSchemaTrait;

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
    $this->installBbAiListingKernelSchema();
    $this->installEntitySchema('listing_image');
    $this->installConfig(['field', 'media', 'ai_listing']);

    $this->createBookType();
    $this->createIntakeMediaBundle();

    $user = User::create([
      'name' => 'bulk-order-tester',
      'mail' => 'bulk-order-tester@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  public function testProcessStagedSetsMaterializesListingImagesInFilenameOrder(): void {
    $setId = 'set_order_test';
    $uid = (int) $this->container->get('current_user')->id();

    // Intentionally ingest out-of-order and with conflicting created timestamps.
    $this->createIntakeMediaForSet($setId, '003.jpg', $uid, 1003);
    $this->createIntakeMediaForSet($setId, '001.jpg', $uid, 1002);
    $this->createIntakeMediaForSet($setId, '002.jpg', $uid, 1001);

    $form = AiListingBulkIntakeForm::create($this->container);
    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'process_staged_sets']);
    $built = [];
    $form->submitForm($built, $formState);

    $listingIds = $this->container->get('entity_type.manager')
      ->getStorage('bb_ai_listing')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing_type', 'book')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    $this->assertCount(1, $listingIds, 'Exactly one listing should be created from one ready set.');
    $listingId = (int) reset($listingIds);

    $imageIds = $this->container->get('entity_type.manager')
      ->getStorage('listing_image')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', $listingId)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    $this->assertCount(3, $imageIds);

    $listingImages = $this->container->get('entity_type.manager')->getStorage('listing_image')->loadMultiple($imageIds);
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');
    $orderedFilenames = [];
    foreach ($listingImages as $listingImage) {
      $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
      $file = $fileStorage->load($fileId);
      $orderedFilenames[] = $file instanceof File ? (string) $file->getFilename() : '';
    }

    $this->assertSame(['001.jpg', '002.jpg', '003.jpg'], $orderedFilenames);
  }

  private function createIntakeMediaForSet(string $setId, string $filename, int $uid, int $created): void {
    $uri = 'public://ai-intake/' . $setId . '/' . $filename;
    $directory = dirname($uri);
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($uri), 'test');

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
      'uid' => $uid,
    ]);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => 'ai_listing_intake',
      'name' => $filename,
      'uid' => $uid,
      'status' => 1,
      'created' => $created,
      'field_media_image' => [
        'target_id' => (int) $file->id(),
        'alt' => $filename,
        'title' => $filename,
      ],
    ]);
    $media->save();
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
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
