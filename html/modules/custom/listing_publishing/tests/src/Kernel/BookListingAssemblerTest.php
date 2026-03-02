<?php

declare(strict_types=1);

namespace Drupal\Tests\listing_publishing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\SkuGeneratorInterface;
use Drupal\listing_publishing\Service\BookListingAssembler;

/**
 * Tests the core publishing rules in BookListingAssembler.
 *
 * This is a kernel test because the assembler reads real listing entities and
 * real Drupal field data. The important book title field is a configurable
 * field, so a plain unit test would miss too much truth.
 */
final class BookListingAssemblerTest extends KernelTestBase {

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
    $this->installConfig(['field', 'ai_listing']);
    $this->createBookBundleType();
    $this->createBookField('field_title');
  }

  public function testUsesEbayTitleForListingTitle(): void {
    $assembler = $this->createAssembler();
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    $request = $assembler->assemble($listing);

    $this->assertSame('Birdy by William Wharton Paperback Book', $request->getTitle());
  }

  public function testRejectsMissingConditionNote(): void {
    $assembler = $this->createAssembler();
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: ''
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Listing is missing a condition note.');

    $assembler->assemble($listing);
  }

  public function testUsesPlainBookTitleForBookTitleAttribute(): void {
    $assembler = $this->createAssembler();
    $listing = $this->createBookListing(
      ebayTitle: 'Birdy by William Wharton Paperback Book',
      fieldTitle: 'Birdy',
      conditionNote: 'Clean copy with light edge wear.'
    );

    $request = $assembler->assemble($listing);
    $attributes = $request->getAttributes();

    $this->assertSame('Birdy', $attributes['book_title']);
    $this->assertNotSame($request->getTitle(), $attributes['book_title']);
  }

  private function createAssembler(): BookListingAssembler {
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);

    $skuGenerator = $this->createMock(SkuGeneratorInterface::class);
    $skuGenerator->method('generate')
      ->willReturn('2026 Feb BDMAA05 ai-book-2');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')
      ->with('listing_image')
      ->willReturn(FALSE);

    return new BookListingAssembler(
      $fileUrlGenerator,
      $skuGenerator,
      $entityTypeManager,
    );
  }

  private function createBookListing(string $ebayTitle, string $fieldTitle, string $conditionNote): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'ebay_title' => $ebayTitle,
      'status' => 'ready_for_review',
      'condition_grade' => 'good',
      'condition_note' => $conditionNote,
      'price' => '29.95',
    ]);
    $listing->set('field_title', $fieldTitle);
    $listing->set('description', [
      'value' => 'A short description.',
      'format' => 'basic_html',
    ]);
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

  private function createBookBundleType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

}
