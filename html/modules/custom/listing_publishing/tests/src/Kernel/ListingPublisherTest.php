<?php

declare(strict_types=1);

namespace Drupal\Tests\listing_publishing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Service\AiListingInventorySkuResolver;
use Drupal\ai_listing\Service\MarketplaceLifecycleRecorder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Contract\SkuGeneratorInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;
use Drupal\listing_publishing\Service\BookListingAssembler;
use Drupal\listing_publishing\Service\ListingPublisher;
use Drupal\listing_publishing\Service\MarketplacePublicationRecorder;
use Drupal\listing_publishing\Service\MarketplacePublicationResolver;

/**
 * Tests the core publish and update rules in ListingPublisher.
 *
 * What this class is for:
 * `ListingPublisher` is the generic "send this listing to a marketplace" use
 * case.
 *
 * In plain terms, this class is testing the middle part of the publishing
 * flow. The listing has already been reviewed and approved. The marketplace
 * adapter already knows how to talk to eBay or some other marketplace.
 *
 * `ListingPublisher` sits in the middle and decides things like:
 * - is this the first publish, or an update?
 * - what SKU should be used?
 * - should an old SKU be deleted first?
 * - should we save a marketplace publication record in Drupal?
 *
 * What a "marketplace publication record" means here:
 * it is Drupal's local note that says "this listing was published to a
 * marketplace". It stores things like:
 * - which marketplace it went to
 * - which SKU was used
 * - which marketplace publication ID came back
 * - which marketplace listing ID came back
 * - whether Drupal thinks that publication is published or failed
 *
 * Two helper services appear a lot in this file:
 * - `MarketplacePublicationRecorder` writes those local publication rows
 * - `MarketplacePublicationResolver` reads those local publication rows back
 *
 * So:
 * - recorder = writer
 * - resolver = reader
 *
 * Why this is a kernel test:
 * these rules touch real Drupal entities. We want to see real SKU rows and
 * real marketplace publication rows being written and read back.
 *
 * We still use small fake helper classes at the bottom of this file for the
 * marketplace publisher and SKU generator. That keeps the test simple:
 * - Drupal stores the real entity rows
 * - fake collaborators stand in for the outside world
 */
final class ListingPublisherTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
  ];

  private MutableSkuGenerator $skuGenerator;
  private RecordingMarketplacePublisher $marketplacePublisher;
  private ListingPublisher $listingPublisher;
  private AiListingInventorySkuResolver $skuResolver;
  private MarketplacePublicationRecorder $publicationRecorder;
  private MarketplacePublicationResolver $publicationResolver;

  protected function setUp(): void {
    parent::setUp();

    // Install the real entity tables this use case writes to.
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_listing_inventory_sku');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installSchema('ai_listing', ['bb_ai_listing_marketplace_lifecycle']);

    // Install the field config needed for a basic book listing.
    $this->installConfig(['field', 'ai_listing']);
    $this->createBookBundleType();
    $this->createBookField('field_title');

    $entityTypeManager = $this->container->get('entity_type.manager');

    // Fake SKU generator.
    // We control this in each test so we can force "same SKU" or "new SKU".
    $this->skuGenerator = new MutableSkuGenerator('2026 Feb BDMAA05 ai-book-2');

    // Fake marketplace publisher.
    // This records what Drupal tried to publish or update.
    $this->marketplacePublisher = new RecordingMarketplacePublisher();

    // The assembler can load listing images.
    // These tests are not about image loading, so we switch that path off.
    $assemblerEntityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $assemblerEntityTypeManager->method('hasDefinition')
      ->with('listing_image')
      ->willReturn(FALSE);

    // Real assembler, fake image side of the entity manager.
    $assembler = new BookListingAssembler(
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->skuGenerator,
      $assemblerEntityTypeManager,
    );

    // These are the real Drupal services we want to exercise.
    $this->skuResolver = new AiListingInventorySkuResolver($entityTypeManager);

    // This service writes Drupal's local "published on a marketplace" row.
    $this->publicationRecorder = new MarketplacePublicationRecorder(
      $entityTypeManager,
      new MarketplaceLifecycleRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
      ),
    );

    // This service reads Drupal's local "published on a marketplace" row back
    // out of storage so the test can inspect it.
    $this->publicationResolver = new MarketplacePublicationResolver($entityTypeManager);

    // This is the actual use case under test.
    $this->listingPublisher = new ListingPublisher(
      $assembler,
      $this->marketplacePublisher,
      $this->skuResolver,
      $this->publicationRecorder,
      $this->publicationResolver,
    );
  }

  public function testFirstPublishStoresSkuAndPublication(): void {
    // Start with a normal reviewed listing that is ready to be published.
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    // Run the use case.
    $result = $this->listingPublisher->publish($listing);

    // The fake marketplace says publish succeeded.
    $this->assertTrue($result->isSuccess());

    // Drupal really did call the marketplace publish boundary once.
    $this->assertCount(1, $this->marketplacePublisher->publishedRequests);

    // Drupal saved the live SKU record for this listing.
    $this->assertSame('2026 Feb BDMAA05 ai-book-2', $this->skuResolver->getSku($listing));

    // Drupal also saved a marketplace publication row for later updates and
    // audits.
    //
    // We use the resolver here because it is the read side of that local
    // publication record.
    $publication = $this->publicationResolver->getPublishedPublicationForListing(
      $listing,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE'
    );

    // That publication row should reflect the successful publish result.
    $this->assertNotNull($publication);
    $this->assertSame('published', (string) $publication->get('status')->value);
    $this->assertSame('pub-1', (string) $publication->get('marketplace_publication_id')->value);
    $this->assertSame('listing-1', (string) $publication->get('marketplace_listing_id')->value);
    $this->assertSame('2026 Feb BDMAA05 ai-book-2', (string) $publication->get('inventory_sku_value')->value);

    $lifecycle = $this->loadLifecycleRow((int) $listing->id(), $this->marketplacePublisher->getMarketplaceKey());
    $this->assertNotNull($lifecycle);
    $this->assertGreaterThan(0, (int) $lifecycle->first_published_at);
    $this->assertSame((int) $lifecycle->first_published_at, (int) $lifecycle->last_published_at);
    $this->assertSame('listing-1', $lifecycle->last_marketplace_listing_id);
    $this->assertSame(0, (int) $lifecycle->relist_count);
  }

  public function testPublishOrUpdateUsesSavedSkuForPublishedListing(): void {
    // Start with a normal listing.
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    // Pretend this listing was already published earlier.
    // That means Drupal already has a saved SKU row.
    $inventorySku = $this->skuResolver->setSku($listing, 'saved-sku');

    // And Drupal already has a saved marketplace publication row.
    //
    // We use the recorder here because it is the write side of that local
    // publication record.
    $this->publicationRecorder->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE',
      'published',
      'existing-pub-id',
      'existing-listing-id'
    );

    // A normal update that does not change the location should still resolve
    // to the same live SKU.
    $this->skuGenerator->setNextSku('saved-sku');

    // In that case the update path should stay on the in-place update flow.
    $this->listingPublisher->publishOrUpdate($listing);

    // This must be an update, not a first publish.
    $this->assertCount(0, $this->marketplacePublisher->publishedRequests);
    $this->assertCount(1, $this->marketplacePublisher->updatedRequests);

    // The existing marketplace publication ID must be used.
    $this->assertSame('existing-pub-id', $this->marketplacePublisher->updatedRequests[0]['publicationId']);

    // The request sent to the marketplace must keep the saved live SKU.
    $this->assertSame('saved-sku', $this->marketplacePublisher->updatedRequests[0]['request']->getSku());

    // Drupal should still think the live SKU is the saved one.
    $this->assertSame('saved-sku', $this->skuResolver->getSku($listing));
  }

  public function testPublishDeletesOldSkuWhenGeneratedSkuChanges(): void {
    // Start with a listing that already has an old live SKU.
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    $this->skuResolver->setSku($listing, 'old-sku');
    $this->skuGenerator->setNextSku('new-sku');

    // Publish again.
    $this->listingPublisher->publish($listing);

    // The old SKU must be deleted through the marketplace boundary first.
    $this->assertSame(['old-sku'], $this->marketplacePublisher->deletedSkus);

    // Drupal should now store the replacement live SKU.
    $this->assertSame('new-sku', $this->skuResolver->getSku($listing));
  }

  public function testPublishOrUpdateRepublishesWhenSkuChanges(): void {
    // Start with a listing that Drupal believes is already published.
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    // The current live SKU still reflects the old location.
    $inventorySku = $this->skuResolver->setSku($listing, 'old-sku');

    // Drupal also has a current publication row for that old SKU.
    $this->publicationRecorder->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE',
      'published',
      'existing-pub-id',
      'existing-listing-id'
    );

    // A location change causes the SKU generator to produce a new value.
    $this->skuGenerator->setNextSku('new-sku');

    // This should not try an in-place update with the old SKU.
    // It should fall back to the publish path so the old SKU can be deleted
    // and the new SKU can become the live one.
    $this->listingPublisher->publishOrUpdate($listing);

    $this->assertSame(['old-sku'], $this->marketplacePublisher->deletedSkus);
    $this->assertCount(1, $this->marketplacePublisher->publishedRequests);
    $this->assertCount(0, $this->marketplacePublisher->updatedRequests);
    $this->assertSame('new-sku', $this->skuResolver->getSku($listing));
  }

  public function testLifecyclePreservesFirstPublishedAtAcrossRelist(): void {
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    $inventorySku = $this->skuResolver->setSku($listing, 'old-sku');
    $this->publicationRecorder->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE',
      'published',
      'existing-pub-id',
      'existing-listing-id',
      null,
      null,
      1700000000,
    );

    (new MarketplaceLifecycleRecorder(
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    ))->recordUnpublished((int) $listing->id(), $this->marketplacePublisher->getMarketplaceKey(), 1700000100);
    $publication = $this->publicationResolver->getPublishedPublicationForListing(
      $listing,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE'
    );
    $this->assertNotNull($publication);
    $publication->delete();

    $this->skuGenerator->setNextSku('new-sku');
    $this->listingPublisher->publish($listing);

    $lifecycle = $this->loadLifecycleRow((int) $listing->id(), $this->marketplacePublisher->getMarketplaceKey());
    $this->assertNotNull($lifecycle);
    $this->assertSame(1700000000, (int) $lifecycle->first_published_at);
    $this->assertGreaterThan(1700000100, (int) $lifecycle->last_published_at);
    $this->assertSame(1, (int) $lifecycle->relist_count);
  }

  public function testUpdateFailsClearlyWhenPublishedRecordHasNoPublicationId(): void {
    // Start with a normal listing.
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    // Pretend Drupal thinks this listing is already published...
    $inventorySku = $this->skuResolver->setSku($listing, 'saved-sku');

    // ...so we write a local publication row...
    $this->publicationRecorder->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      $this->marketplacePublisher->getMarketplaceKey(),
      'FIXED_PRICE',
      'published'
    );

    // ...but the saved row is broken because it has no marketplace publication
    // ID to update.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Marketplace publication record is missing publication ID.');

    $this->listingPublisher->publishOrUpdate($listing);
  }

  private function createBookListing(string $ebayTitle, string $fieldTitle, string $conditionNote): BbAiListing {
    // Create the smallest real listing entity this use case needs.
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'ebay_title' => $ebayTitle,
      'status' => 'ready_for_review',
      'condition_grade' => 'good',
      'condition_note' => $conditionNote,
      'price' => '29.95',
    ]);

    // The assembler still reads the plain book title and description fields.
    $listing->set('field_title', $fieldTitle);
    $listing->set('description', [
      'value' => 'A short description.',
      'format' => 'basic_html',
    ]);
    $listing->save();

    return $listing;
  }

  private function createBookField(string $fieldName): void {
    // Add the configurable title field used by the assembler.
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

  private function createBookBundleType(): void {
    // Create the bundle so the test can save real book listings.
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

  private function loadLifecycleRow(int $listingId, string $marketplaceKey): ?object {
    $row = $this->container->get('database')
      ->select('bb_ai_listing_marketplace_lifecycle', 'l')
      ->fields('l')
      ->condition('listing_id', $listingId)
      ->condition('marketplace_key', $marketplaceKey)
      ->execute()
      ->fetchObject();

    return $row !== FALSE ? $row : NULL;
  }

}

final class MutableSkuGenerator implements SkuGeneratorInterface {

  /**
   * Tiny fake SKU generator for tests.
   *
   * Why this exists:
   * the real generator bakes in dates and locations. That is fine in the app,
   * but noisy in tests. Here we just return whatever SKU the test says to use.
   */

  public function __construct(
    private string $nextSku,
  ) {}

  public function setNextSku(string $nextSku): void {
    $this->nextSku = $nextSku;
  }

  public function generate(BbAiListing $listing, string $uniqueSuffix, ?\DateTimeInterface $when = null): string {
    return $this->nextSku;
  }

}

final class RecordingMarketplacePublisher implements MarketplacePublisherInterface {

  /**
   * Tiny fake marketplace adapter for tests.
   *
   * Why this exists:
   * the real marketplace adapter talks to eBay. That would make these tests
   * slow and hard to reason about. This fake just records what Drupal tried to
   * do so the test can inspect it afterward.
   */

  public array $publishedRequests = [];
  public array $updatedRequests = [];
  public array $deletedSkus = [];

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
    // Record the request so the test can inspect it later.
    $this->publishedRequests[] = $request;

    // Pretend the marketplace publish succeeded and returned IDs.
    return new MarketplacePublishResult(
      TRUE,
      'Published',
      'listing-1',
      'pub-1',
      'FIXED_PRICE',
    );
  }

  public function updatePublication(
    string $marketplacePublicationId,
    ListingPublishRequest $request,
    ?string $publicationType = null,
  ): MarketplacePublishResult {
    // Record the update call so the test can inspect the ID and request.
    $this->updatedRequests[] = [
      'publicationId' => $marketplacePublicationId,
      'request' => $request,
      'publicationType' => $publicationType,
    ];

    // Pretend the marketplace update succeeded.
    return new MarketplacePublishResult(
      TRUE,
      'Updated',
      'listing-1',
      $marketplacePublicationId,
      $publicationType,
    );
  }

  public function deleteSku(string $sku): void {
    // Record old SKU deletions so the test can prove they happened.
    $this->deletedSkus[] = $sku;
  }

  public function getMarketplaceKey(): string {
    return 'test_marketplace';
  }

}
