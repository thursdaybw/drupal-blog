<?php

declare(strict_types=1);

namespace Drupal\Tests\listing_publishing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Service\SkuGenerator;

final class SkuGeneratorTest extends KernelTestBase {

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

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

  public function testGenerateDoesNotIncludeStorageLocation(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_review',
      'storage_location' => 'BDMAA09',
      'condition_grade' => 'good',
      'condition_note' => 'Test note',
      'ebay_title' => 'SKU generator test listing',
    ]);
    $listing->save();

    $generator = new SkuGenerator();
    $sku = $generator->generate($listing, 'ai-book-123', new \DateTimeImmutable('2026-03-19 10:00:00'));

    $this->assertSame('2026 Mar ai-book-123', $sku);
    $this->assertStringNotContainsString('BDMAA09', $sku);
  }

}
