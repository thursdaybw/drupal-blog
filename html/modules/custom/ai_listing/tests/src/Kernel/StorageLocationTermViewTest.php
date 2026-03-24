<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_listing\Traits\InstallsBbAiListingKernelSchemaTrait;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

final class StorageLocationTermViewTest extends KernelTestBase {

  use InstallsBbAiListingKernelSchemaTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'taxonomy',
    'bb_platform',
    'ai_listing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installBbAiListingKernelSchema();
    $this->installConfig(['system', 'taxonomy', 'ai_listing']);

    $this->createBookType();
    $this->createBookField('field_title');
    $this->createBookField('field_full_title');
    $this->createStorageLocationVocabulary();
  }

  public function testStorageLocationTermPageShowsLinkedListings(): void {
    $term = $this->createStorageLocationTerm('BDMAA09');
    $firstListing = $this->createListing('Birdy', (int) $term->id(), 'BDMAA09', 'ready_to_publish', 'BIRDY001');
    $secondListing = $this->createListing('Growing Better Vegetables', (int) $term->id(), 'BDMAA09', 'shelved', 'GROW001');

    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('taxonomy_term')
      ->view($term, 'full');
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Listings At This Location', $markup);
    $this->assertStringContainsString('Location code:', $markup);
    $this->assertStringContainsString('BDMAA09', $markup);
    $this->assertStringContainsString('Linked listings:', $markup);
    $this->assertStringContainsString('2', $markup);
    $this->assertStringContainsString('Birdy', $markup);
    $this->assertStringContainsString('Growing Better Vegetables', $markup);
    $this->assertStringContainsString((string) $firstListing->id(), $markup);
    $this->assertStringContainsString((string) $secondListing->id(), $markup);
    $this->assertStringContainsString('ready_to_publish', $markup);
    $this->assertStringContainsString('shelved', $markup);
  }

  public function testNonStorageLocationTermDoesNotShowListingPanel(): void {
    Vocabulary::create([
      'vid' => 'topic',
      'name' => 'Topic',
    ])->save();
    $term = Term::create([
      'vid' => 'topic',
      'name' => 'Misc',
    ]);
    $term->save();

    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('taxonomy_term')
      ->view($term, 'full');
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringNotContainsString('Listings At This Location', $markup);
  }

  private function createListing(string $title, int $termId, string $legacyLocation, string $status, string $listingCode): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => $status,
      'storage_location' => $legacyLocation,
      'storage_location_term' => ['target_id' => $termId],
      'listing_code' => $listingCode,
    ]);
    $listing->set('field_title', $title);
    $listing->set('field_full_title', $title);
    $listing->save();
    return $listing;
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

  private function createBookField(string $fieldName): void {
    if (!FieldStorageConfig::loadByName('bb_ai_listing', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'type' => 'string',
        'settings' => ['max_length' => 255],
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

  private function createStorageLocationVocabulary(): void {
    if (Vocabulary::load('storage_location') !== NULL) {
      return;
    }

    Vocabulary::create([
      'vid' => 'storage_location',
      'name' => 'Storage location',
    ])->save();
  }

  private function createStorageLocationTerm(string $name): Term {
    $term = Term::create([
      'vid' => 'storage_location',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

}
