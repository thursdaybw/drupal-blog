<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;

final class IntakeSetListingMaterializerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'dynamic_entity_reference',
    'bb_platform',
    'ai_listing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('listing_image');
    $this->installConfig(['field', 'ai_listing']);

    $this->createBookType();
    $this->createBookField('field_title');
  }

  public function testMaterializeNewBookListingCreatesListingAndAttachesAllFiles(): void {
    $fileOne = $this->createPermanentFile('public://ai-intake/set-a/one.jpg');
    $fileTwo = $this->createPermanentFile('public://ai-intake/set-a/two.jpg');

    $materializer = $this->container->get('ai_listing.intake_set_listing_materializer');
    $listing = $materializer->materializeNewBookListing([(int) $fileOne->id(), (int) $fileTwo->id()]);

    $this->assertSame('book', $listing->bundle());
    $this->assertSame('new', (string) $listing->get('status')->value);

    $imageIds = $this->container->get('entity_type.manager')
      ->getStorage('listing_image')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->execute();

    $this->assertCount(2, $imageIds);

    $images = $this->container->get('entity_type.manager')->getStorage('listing_image')->loadMultiple($imageIds);
    $images = array_values($images);

    $this->assertSame((int) $fileOne->id(), (int) $images[0]->get('file')->target_id);
    $this->assertSame('0', (string) $images[0]->get('is_metadata_source')->value);
    $this->assertSame('0', (string) $images[0]->get('weight')->value);

    $this->assertSame((int) $fileTwo->id(), (int) $images[1]->get('file')->target_id);
    $this->assertSame('0', (string) $images[1]->get('is_metadata_source')->value);
    $this->assertSame('1', (string) $images[1]->get('weight')->value);
  }

  private function createPermanentFile(string $uri): File {
    $directory = dirname($uri);
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($uri), 'test');

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

  private function createBookField(string $fieldName): void {
    if (!FieldStorageConfig::loadByName('bb_ai_listing', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'type' => 'string',
        'settings' => [
          'max_length' => 255,
        ],
        'cardinality' => 1,
      ])->save();

      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'bundle' => 'book',
        'label' => 'Title',
      ])->save();
    }
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

}
