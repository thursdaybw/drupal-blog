<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Functional;

use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Entity\ListingImage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies publishing from the review form against the marketplace stub.
 *
 * @group ai_listing
 */
final class AiListingPublishReviewBrowserTest extends BrowserTestBase {

  private const STUB_STATE_KEY = 'ai_listing_marketplace_test_stub.publications';

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
    'listing_publishing',
    'ai_listing_marketplace_test_stub',
  ];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();

    $this->container->get('state')->delete(self::STUB_STATE_KEY);

    $this->drupalLogin($this->drupalCreateUser([
      'administer ai listings',
      'access administration pages',
    ]));

    $this->createBookType();
    $this->createBookField('field_title');
    $this->createBookField('field_full_title');
    $this->createBookField('field_author');
  }

  public function testReviewFormPublishUsesMarketplaceStubAndCreatesPublication(): void {
    $listing = $this->createPublishReadyListing();

    $this->drupalGet('/admin/ai-listings/' . (int) $listing->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Publish to eBay');

    $this->submitForm([], 'Publish to eBay');

    $this->assertSession()->pageTextContains('Published listing stub-listing-1 for entity ' . (int) $listing->id() . '.');

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('shelved', (string) $reloaded->get('status')->value);

    $publication = $this->loadPublishedPublication((int) $listing->id());
    $this->assertInstanceOf(AiMarketplacePublication::class, $publication);
    $this->assertSame('ebay', (string) $publication->get('marketplace_key')->value);
    $this->assertSame('published', (string) $publication->get('status')->value);
    $this->assertSame('stub-publication-1', (string) $publication->get('marketplace_publication_id')->value);
    $this->assertSame('stub-listing-1', (string) $publication->get('marketplace_listing_id')->value);

    $stubPublications = $this->container->get('state')->get(self::STUB_STATE_KEY, []);
    $this->assertIsArray($stubPublications);
    $this->assertCount(1, $stubPublications);

    $stubPublication = reset($stubPublications);
    $this->assertIsArray($stubPublication);
    $this->assertSame('Publish review browser test', $stubPublication['title'] ?? null);
    $this->assertSame('29.95', $stubPublication['price'] ?? null);
    $this->assertSame(1, $stubPublication['image_url_count'] ?? null);
  }

  private function createPublishReadyListing(): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_to_publish',
      'ebay_title' => 'Publish review browser test',
      'listing_code' => 'BROWSERPUB1',
      'price' => '29.95',
      'condition_grade' => 'good',
      'condition_note' => 'Clean and complete test copy.',
      'description' => [
        'value' => '<p>Browser test listing description.</p>',
        'format' => 'basic_html',
      ],
    ]);
    $listing->set('field_title', 'Publish review browser test');
    $listing->set('field_full_title', 'Publish review browser test');
    $listing->set('field_author', 'Browser Test Author');
    $listing->save();

    $file = $this->createImageFile('public://browser-publish-test.png');
    $listingImage = ListingImage::create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => (int) $listing->id(),
      ],
      'file' => (int) $file->id(),
      'is_metadata_source' => TRUE,
      'weight' => 0,
    ]);
    $listingImage->save();

    return $listing;
  }

  private function createImageFile(string $uri): File {
    $fileSystem = $this->container->get('file_system');
    $directory = dirname($uri);
    $fileSystem->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

    $realPath = $fileSystem->realpath($uri);
    $this->assertNotFalse($realPath);
    file_put_contents($realPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnSUswAAAAASUVORK5CYII='));

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

  private function loadPublishedPublication(int $listingId): ?AiMarketplacePublication {
    $ids = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication')->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->condition('marketplace_key', 'ebay')
      ->condition('status', 'published')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $publication = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication')->load((int) reset($ids));
    return $publication instanceof AiMarketplacePublication ? $publication : NULL;
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

}
