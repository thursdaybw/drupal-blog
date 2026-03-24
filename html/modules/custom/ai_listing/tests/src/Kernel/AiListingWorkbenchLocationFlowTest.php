<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Form\AiBookListingPublishUpdateConfirmForm;
use Drupal\ai_listing\Form\AiListingLocationUpdateConfirmForm;
use Drupal\ai_listing\Form\AiListingWorkbenchForm;
use Drupal\Core\Form\FormState;
use Drupal\Core\KeyValueStore\DatabaseStorageExpirable;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests the server-side location update flow from the workbench.
 *
 * Why this test exists:
 * the browser page lets you pick listings, jump to the location confirm
 * screen, then submit a new location. The risky part is not the browser click.
 * The risky part is the server-side wiring:
 * - the workbench must carry the selected listing IDs into tempstore
 * - the confirm form must turn that into the location-only batch
 *
 * These tests prove that flow without touching real eBay.
 */
final class AiListingWorkbenchLocationFlowTest extends KernelTestBase {

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

  private ?PrivateTempStoreFactory $tempStoreFactory = null;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_listing_inventory_sku');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installEntitySchema('file');
    $this->installEntitySchema('listing_image');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['system', 'ai_listing', 'taxonomy']);
    $this->createStorageLocationVocabulary();
    $this->createBookType();
    $this->createBookField('field_title');
    $this->createBookField('field_full_title');

    $schema = $this->container->get('database')->schema();
    if (!$schema->tableExists('key_value_expire')) {
      $schema->createTable('key_value_expire', DatabaseStorageExpirable::schemaDefinition());
    }

    $this->tempStoreFactory = $this->container->get('tempstore.private');
    $this->clearWorkbenchTempstore();
    $this->clearBatchDefinition();
  }

  protected function tearDown(): void {
    $this->clearWorkbenchTempstore();
    $this->clearBatchDefinition();
    parent::tearDown();
  }

  /**
   * Checks that the workbench stores the selected batch before redirecting.
   */
  public function testWorkbenchRedirectsToLocationConfirmWithSelectedIds(): void {
    $formObject = AiListingWorkbenchForm::create($this->container);
    $formState = new FormState();
    $form = [];

    $formState->setValue(
      AiListingWorkbenchForm::SELECTED_LISTING_KEYS_FIELD,
      json_encode(['book:12', 'book_bundle:34'])
    );

    $form['actions']['update_location']['#name'] = 'update_location';
    $formState->setTriggeringElement($form['actions']['update_location']);

    $formObject->submitUpdateLocation($form, $formState);

    $payload = $this->getWorkbenchTempstore()->get(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);

    $this->assertIsArray($payload);
    $this->assertSame([12, 34], $payload['listing_ids']);
    $this->assertSame(2, $payload['selected_count']);

    $redirect = $formState->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('ai_listing.workbench.location_confirm', $redirect->getRouteName());
  }

  /**
   * Checks that the confirm form queues the generic location-only batch.
   */
  public function testLocationConfirmQueuesLocationOnlyBatch(): void {
    $this->getWorkbenchTempstore()->set(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY, [
      'selection' => [
        ['listing_type' => 'book', 'id' => 12],
        ['listing_type' => 'book_bundle', 'id' => 34],
      ],
      'listing_ids' => [12, 34],
      'selected_count' => 2,
      'created_at' => time(),
    ]);

    $formObject = AiListingLocationUpdateConfirmForm::create($this->container);
    $formState = new FormState();
    $locationTerm = $this->createStorageLocationTerm('BDMAA09');
    $formState->setValue('location_term', 'BDMAA09 (' . (int) $locationTerm->id() . ')');
    $build = [];

    $formObject->submitForm($build, $formState);

    $payload = $this->getWorkbenchTempstore()->get(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);
    $this->assertNull($payload);

    $redirect = $formState->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('ai_listing.workbench', $redirect->getRouteName());

    $batch = batch_get();
    $this->assertIsArray($batch);
    $this->assertArrayHasKey('sets', $batch);
    $this->assertNotEmpty($batch['sets']);

    $set = reset($batch['sets']);
    $this->assertIsArray($set);
    $this->assertSame('Updating listing locations', (string) $set['title']);
    $this->assertCount(2, $set['operations']);

    $firstOperation = $set['operations'][0];
    $this->assertSame([AiListingWorkbenchForm::class, 'processBatchOperation'], $firstOperation[0]);
    $this->assertSame(['book', 12, TRUE, 'BDMAA09', 'location_only', (int) $locationTerm->id()], $firstOperation[1]);
  }

  /**
   * Checks that the location-only batch marks ready-to-shelve listings ready to publish.
   */
  public function testLocationOnlyBatchSetsLocationAndMarksReadyToPublish(): void {
    $listing = $this->createBookListing('Location-only batch test', '');
    $listing->set('status', 'ready_to_shelve');
    $listing->save();
    $locationTerm = $this->createStorageLocationTerm('BDMAA09');

    $context = [];
    AiListingWorkbenchForm::processBatchOperation('book', (int) $listing->id(), TRUE, 'BDMAA09', 'location_only', (int) $locationTerm->id(), $context);

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('BDMAA09', (string) $reloaded->get('storage_location')->value);
    $this->assertSame((int) $locationTerm->id(), (int) $reloaded->get('storage_location_term')->target_id);
    $this->assertSame('ready_to_publish', (string) $reloaded->get('status')->value);
    $this->assertSame(1, (int) ($context['results']['success'] ?? 0));
  }

  /**
   * Checks that the location screen shows the selected listings to review.
   */
  public function testLocationConfirmShowsSelectedListings(): void {
    $firstListing = $this->createBookListing('Growing Better Vegetables', 'BDMAA05');
    $secondListing = $this->createBookListing('Birdy', 'BRNCBD004');

    $this->getWorkbenchTempstore()->set(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY, [
      'listing_ids' => [(int) $firstListing->id(), (int) $secondListing->id()],
      'selected_count' => 2,
      'created_at' => time(),
    ]);

    $formObject = AiListingLocationUpdateConfirmForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState);

    $this->assertArrayHasKey('summary', $form);
    $this->assertArrayHasKey('selected_listings', $form['summary']);
    $rows = $form['summary']['selected_listings']['#rows'];
    $this->assertCount(2, $rows);
    $this->assertSame((string) $firstListing->get('listing_code')->value, (string) $rows[0][2]);
    $this->assertStringContainsString('Growing Better Vegetables', (string) $rows[0][3]);
    $this->assertSame('BDMAA05', (string) $rows[0][4]);
    $this->assertSame((string) $secondListing->get('listing_code')->value, (string) $rows[1][2]);
    $this->assertStringContainsString('Birdy', (string) $rows[1][3]);
    $this->assertSame('BRNCBD004', (string) $rows[1][4]);
  }

  /**
   * Checks that the publish/update review screen shows the selected listings.
   */
  public function testPublishUpdateConfirmShowsSelectedListings(): void {
    $firstListing = $this->createBookListing('Growing Better Vegetables', 'BDMAA05');
    $secondListing = $this->createBookListing('Birdy', '');

    $this->getWorkbenchTempstore()->set(AiListingWorkbenchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY, [
      'listing_ids' => [(int) $firstListing->id(), (int) $secondListing->id()],
      'selected_count' => 2,
      'missing_location_ids' => [(int) $secondListing->id()],
      'missing_location_count' => 1,
      'set_location' => FALSE,
      'location' => '',
      'operation_mode' => 'publish_update',
      'created_at' => time(),
    ]);

    $formObject = AiBookListingPublishUpdateConfirmForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState);

    $this->assertArrayHasKey('selected_listings', $form);
    $rows = $form['selected_listings']['table']['#rows'];
    $this->assertCount(2, $rows);
    $this->assertSame((string) $firstListing->get('listing_code')->value, (string) $rows[0][2]);
    $this->assertStringContainsString('Growing Better Vegetables', (string) $rows[0][3]);
    $this->assertSame('BDMAA05', (string) $rows[0][4]);
    $this->assertSame((string) $secondListing->get('listing_code')->value, (string) $rows[1][2]);
    $this->assertStringContainsString('Birdy', (string) $rows[1][3]);
    $this->assertSame('Unset yet', (string) $rows[1][4]);
  }

  /**
   * Checks that publish/update also goes through its review screen first.
   */
  public function testWorkbenchRedirectsToPublishUpdateConfirmWithSelectedIds(): void {
    $formObject = AiListingWorkbenchForm::create($this->container);
    $formState = new FormState();
    $form = [];

    $formState->setValue(
      AiListingWorkbenchForm::SELECTED_LISTING_KEYS_FIELD,
      json_encode(['book:12', 'book_bundle:34'])
    );

    $form['actions']['publish_update']['#name'] = 'publish_update';
    $formState->setTriggeringElement($form['actions']['publish_update']);

    $formObject->submitPublishOrUpdate($form, $formState);

    $payload = $this->getWorkbenchTempstore()->get(AiListingWorkbenchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);

    $this->assertIsArray($payload);
    $this->assertSame([12, 34], $payload['listing_ids']);
    $this->assertSame(2, $payload['selected_count']);
    $this->assertSame('publish_update', $payload['operation_mode']);

    $redirect = $formState->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('ai_listing.workbench.publish_update_confirm', $redirect->getRouteName());
  }

  /**
   * Checks that archive and lost are available in the listing status model.
   */
  public function testCullStatusesAreAvailableInListingUi(): void {
    $listing = $this->createBookListing('Cull status test', 'BDMAA10');
    $statusOptions = $listing->getFieldDefinition('status')->getSetting('allowed_values');
    $this->assertIsArray($statusOptions);
    $this->assertArrayHasKey('archived', $statusOptions);
    $this->assertArrayHasKey('lost', $statusOptions);
  }

  /**
   * Checks that the workbench renders mobile listing cards.
   */
  public function testWorkbenchBuildsMobileCards(): void {
    $this->createBookListing('Mary Higgins Clark', 'BDMCC02');

    $formObject = AiListingWorkbenchForm::create($this->container);
    $formState = new FormState();
    $form = $formObject->buildForm([], $formState);

    $this->assertArrayHasKey('listings_container', $form);
    $this->assertArrayHasKey('mobile_cards', $form['listings_container']);
    $this->assertArrayHasKey('items', $form['listings_container']['mobile_cards']);

    $items = $form['listings_container']['mobile_cards']['items'];
    $this->assertNotEmpty($items);

    $firstCard = reset($items);
    $this->assertIsArray($firstCard);
    $this->assertSame(['ai-batch-mobile-card'], $firstCard['#attributes']['class']);

    $markup = (string) $this->container->get('renderer')->renderRoot($form['listings_container']['mobile_cards']);
    $this->assertStringContainsString('Mary Higgins Clark', $markup);
    $this->assertStringContainsString('No image', $markup);
  }

  private function getWorkbenchTempstore(): \Drupal\Core\TempStore\PrivateTempStore {
    return $this->tempStoreFactory->get(AiListingWorkbenchForm::WORKBENCH_TEMPSTORE_COLLECTION);
  }

  private function clearWorkbenchTempstore(): void {
    $store = $this->getWorkbenchTempstore();
    $store->delete(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);
    $store->delete(AiListingWorkbenchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);
  }

  private function clearBatchDefinition(): void {
    $batch = &batch_get();
    $batch = [];
  }

  private function createBookListing(string $title, string $storageLocation): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_review',
      'condition_grade' => 'good',
      'price' => '29.95',
      'storage_location' => $storageLocation,
    ]);
    $listing->set('field_title', $title);
    $listing->set('field_full_title', $title);
    $listing->save();

    return $listing;
  }

  private function createBookField(string $fieldName): void {
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

  private function createBookType(): void {
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

  private function createStorageLocationTerm(string $name): Term {
    $existing = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'storage_location',
      'name' => $name,
    ]);
    if ($existing !== []) {
      $term = reset($existing);
      assert($term instanceof Term);
      return $term;
    }

    $term = Term::create([
      'vid' => 'storage_location',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

}
