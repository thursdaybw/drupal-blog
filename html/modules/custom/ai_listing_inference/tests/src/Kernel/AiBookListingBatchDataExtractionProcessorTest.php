<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing_inference\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Service\BookExtractionInterface;
use Drupal\ai_listing_inference\Service\AiBookListingBatchDataExtractionProcessor;
use Drupal\ai_listing_inference\Service\AiBookListingDataExtractionProcessor;
use Drupal\KernelTests\KernelTestBase;

final class AiBookListingBatchDataExtractionProcessorTest extends KernelTestBase {

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

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('listing_image');
    $this->installConfig(['ai_listing']);

    $this->createBookType();
  }

  public function testReadyForInferenceQueueOnlyTargetsReadyListings(): void {
    $newListing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $newListing->save();

    $readyListing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_inference',
    ]);
    $readyListing->save();

    $reviewListing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_review',
    ]);
    $reviewListing->save();

    $processor = new AiBookListingBatchDataExtractionProcessor(
      $this->container->get('entity_type.manager'),
      $this->buildDataExtractionProcessor(),
    );

    $ids = array_map('intval', $processor->getReadyForInferenceListingIds());
    $this->assertSame([(int) $readyListing->id()], $ids);
  }

  public function testFailureResetsListingToReadyForInference(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_inference',
    ]);
    $listing->save();

    $processor = new AiBookListingBatchDataExtractionProcessor(
      $this->container->get('entity_type.manager'),
      $this->buildDataExtractionProcessor(),
    );

    try {
      $processor->processListing($listing);
      $this->fail('Expected inference failure was not thrown.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('No images attached.', $exception->getMessage());
    }

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('ready_for_inference', (string) $reloaded->get('status')->value);
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

  private function buildDataExtractionProcessor(): AiBookListingDataExtractionProcessor {
    $bookExtraction = new class implements BookExtractionInterface {
      public function extract(array $imagePaths, ?array $metadataImagePaths = NULL): array {
        return [
          'metadata' => [],
          'metadata_raw' => '',
          'condition' => ['issues' => []],
          'condition_raw' => '',
        ];
      }
    };

    return new AiBookListingDataExtractionProcessor(
      $bookExtraction,
      $this->container->get('entity_type.manager'),
      $this->container->get('file_system'),
      $this->container->get('ai_listing.bundle_ebay_title_builder'),
    );
  }

}
