<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Functional;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Form\AiListingWorkbenchForm;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies browser submit behavior for the bulk location confirm form.
 *
 * @group ai_listing
 */
final class AiListingLocationUpdateConfirmBrowserTest extends BrowserTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'dynamic_entity_reference',
    'taxonomy',
    'bb_platform',
    'ai_listing',
  ];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->drupalCreateUser([
      'administer ai listings',
      'access administration pages',
    ]));

    $this->createBookType();
    $this->createBookField('field_title');
    $this->createBookField('field_full_title');
    $this->createStorageLocationVocabulary();
  }

  public function testSubmitAcceptsAutocompleteLabelWithEntityId(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_to_shelve',
      'ebay_title' => 'Browser location update listing',
      'listing_code' => 'BRWLOC01',
    ]);
    $listing->set('field_title', 'Browser location update listing');
    $listing->set('field_full_title', 'Browser location update listing');
    $listing->save();

    $locationTerm = $this->createStorageLocationTerm('BDMAA02');

    $this->container->get('tempstore.private')
      ->get(AiListingWorkbenchForm::WORKBENCH_TEMPSTORE_COLLECTION)
      ->set(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY, [
        'selection' => [
          ['listing_type' => 'book', 'id' => (int) $listing->id()],
        ],
        'listing_ids' => [(int) $listing->id()],
        'selected_count' => 1,
        'created_at' => time(),
      ]);

    $this->drupalGet('/admin/ai-listings/workbench/location/confirm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Browser location update listing');

    $this->submitForm([
      'Storage location' => 'BDMAA02 (' . (int) $locationTerm->id() . ')',
    ], 'Update location');

    $this->assertSession()->pageTextNotContains('Select an existing registered storage location before submitting.');

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('BDMAA02', (string) $reloaded->get('storage_location')->value);
    $this->assertSame((int) $locationTerm->id(), (int) $reloaded->get('storage_location_term')->target_id);
    $this->assertSame('ready_to_publish', (string) $reloaded->get('status')->value);
  }

  private function createBookType(): void {
    if (BbAiListingType::load('book') !== NULL) {
      return;
    }

    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
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
    }

    if (!FieldConfig::loadByName('bb_ai_listing', 'book', $fieldName)) {
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'bb_ai_listing',
        'bundle' => 'book',
        'label' => ucfirst(str_replace('_', ' ', $fieldName)),
      ])->save();
    }
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
