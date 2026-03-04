<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Service\AiListingInventorySkuResolver;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\listing_publishing\Service\MarketplacePublicationRecorder;
use InvalidArgumentException;

final class EbayLegacyListingAdoptionService {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EbayAccountManager $accountManager,
    private readonly AiListingInventorySkuResolver $inventorySkuResolver,
    private readonly MarketplacePublicationRecorder $publicationRecorder,
  ) {}

  /**
   * Adopt one mirrored migrated eBay listing into bb_ai_listing.
   *
   * This first pass is conservative:
   * - one eBay listing becomes one local listing
   * - the mirrored SKU becomes the local current SKU
   * - the mirrored offer becomes the local current publication row
   * - legacy provenance is stored in bb_ebay_legacy_listing_link
   *
   * @return array{
   *   local_listing_id:int,
   *   local_listing_code:?string,
   *   ebay_listing_id:string,
   *   sku:string,
   *   offer_id:string
   * }
   */
  public function adoptBookListing(string $ebayListingId, ?int $accountId = NULL): array {
    $normalizedListingId = trim($ebayListingId);
    if ($normalizedListingId === '') {
      throw new InvalidArgumentException('eBay listing ID cannot be empty.');
    }

    $account = $this->resolveAccount($accountId);
    $mirrorRow = $this->loadMirrorRow($account, $normalizedListingId);

    if ($mirrorRow === NULL) {
      throw new InvalidArgumentException(sprintf('No mirrored published offer was found for eBay listing %s.', $normalizedListingId));
    }

    $this->assertListingIsNotAlreadyAdopted($normalizedListingId, $mirrorRow['sku']);

    $listing = $this->createLocalListingFromMirrorRow($mirrorRow);
    $inventorySku = $this->inventorySkuResolver->setSku($listing, $mirrorRow['sku']);
    $this->publicationRecorder->recordPublicationSnapshot(
      $listing,
      $inventorySku,
      'ebay',
      $mirrorRow['publication_type'],
      'published',
      $mirrorRow['offer_id'],
      $mirrorRow['ebay_listing_id']
    );
    $this->insertLegacyLinkRow($listing, $account, $mirrorRow);

    return [
      'local_listing_id' => (int) $listing->id(),
      'local_listing_code' => $this->normalizeNullableString($listing->get('listing_code')->value ?? NULL),
      'ebay_listing_id' => $mirrorRow['ebay_listing_id'],
      'sku' => $mirrorRow['sku'],
      'offer_id' => $mirrorRow['offer_id'],
    ];
  }

  private function resolveAccount(?int $accountId): EbayAccount {
    if ($accountId !== NULL) {
      $account = $this->entityTypeManager->getStorage('ebay_account')->load($accountId);
      if (!$account instanceof EbayAccount) {
        throw new InvalidArgumentException(sprintf('eBay account %d was not found.', $accountId));
      }

      return $account;
    }

    return $this->accountManager->loadPrimaryAccount();
  }

  /**
   * @return array{
   *   sku:string,
   *   ebay_title:string,
   *   listing_description:string,
   *   condition:?string,
   *   condition_description:?string,
   *   aspects_json:?string,
   *   price_value:?string,
   *   offer_id:string,
   *   ebay_listing_id:string,
   *   publication_type:string
   * }|null
   */
  private function loadMirrorRow(EbayAccount $account, string $ebayListingId): ?array {
    $query = $this->database->select('bb_ebay_offer', 'offer');
    $query->innerJoin(
      'bb_ebay_inventory_item',
      'inventory',
      'inventory.account_id = offer.account_id AND inventory.sku = offer.sku'
    );
    $query->fields('offer', ['sku', 'listing_description', 'price_value', 'offer_id', 'listing_id', 'format']);
    $query->fields('inventory', ['title', 'condition', 'condition_description', 'aspects_json']);
    $query->condition('offer.account_id', (int) $account->id());
    $query->condition('offer.listing_id', $ebayListingId);
    $query->condition('offer.status', 'PUBLISHED');
    $query->range(0, 1);

    $row = $query->execute()->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }

    return [
      'sku' => (string) ($row['sku'] ?? ''),
      'ebay_title' => (string) ($row['title'] ?? ''),
      'listing_description' => (string) ($row['listing_description'] ?? ''),
      'condition' => $this->normalizeNullableString($row['condition'] ?? NULL),
      'condition_description' => $this->normalizeNullableString($row['condition_description'] ?? NULL),
      'aspects_json' => $this->normalizeNullableString($row['aspects_json'] ?? NULL),
      'price_value' => $this->normalizeNullableString($row['price_value'] ?? NULL),
      'offer_id' => (string) ($row['offer_id'] ?? ''),
      'ebay_listing_id' => (string) ($row['listing_id'] ?? ''),
      'publication_type' => (string) ($row['format'] ?? 'FIXED_PRICE'),
    ];
  }

  private function assertListingIsNotAlreadyAdopted(string $ebayListingId, string $sku): void {
    $existingLegacyLink = $this->database->select('bb_ebay_legacy_listing_link', 'legacy_link')
      ->fields('legacy_link', ['id'])
      ->condition('ebay_listing_id', $ebayListingId)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($existingLegacyLink !== FALSE) {
      throw new InvalidArgumentException(sprintf('eBay listing %s has already been adopted.', $ebayListingId));
    }

    $existingPublication = $this->database->select('ai_marketplace_publication', 'publication')
      ->fields('publication', ['id'])
      ->condition('marketplace_key', 'ebay')
      ->condition('inventory_sku_value', $sku)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($existingPublication !== FALSE) {
      throw new InvalidArgumentException(sprintf('SKU %s is already linked to a local eBay publication row.', $sku));
    }
  }

  /**
   * @param array{
   *   sku:string,
   *   ebay_title:string,
   *   listing_description:string,
   *   condition:?string,
   *   condition_description:?string,
   *   aspects_json:?string,
   *   price_value:?string,
   *   offer_id:string,
   *   ebay_listing_id:string,
   *   publication_type:string
   * } $mirrorRow
   */
  private function createLocalListingFromMirrorRow(array $mirrorRow): BbAiListing {
    $aspects = $this->decodeAspects($mirrorRow['aspects_json']);
    $bookTitle = $this->extractFirstAspectValue($aspects, 'Book Title');
    $author = $this->joinAspectValues($aspects, 'Author');
    $isbn = $this->extractFirstAspectValue($aspects, 'ISBN');

    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_review',
      'ebay_title' => $mirrorRow['ebay_title'],
      'description' => [
        'value' => $mirrorRow['listing_description'],
        'format' => 'basic_html',
      ],
      'price' => $mirrorRow['price_value'],
      'condition_grade' => $this->mapConditionGrade($mirrorRow['condition']),
      'condition_note' => $mirrorRow['condition_description'] ?? '',
      'metadata_json' => $mirrorRow['aspects_json'] ?? '',
    ]);

    $this->setFieldIfAvailable($listing, 'field_title', $bookTitle ?? $mirrorRow['ebay_title']);
    $this->setFieldIfAvailable($listing, 'field_full_title', $mirrorRow['ebay_title']);
    $this->setFieldIfAvailable($listing, 'field_author', $author);
    $this->setFieldIfAvailable($listing, 'field_isbn', $isbn);

    $listing->save();

    return $listing;
  }

  /**
   * @param array{
   *   sku:string,
   *   ebay_title:string,
   *   listing_description:string,
   *   condition:?string,
   *   condition_description:?string,
   *   aspects_json:?string,
   *   price_value:?string,
   *   offer_id:string,
   *   ebay_listing_id:string,
   *   publication_type:string
   * } $mirrorRow
   */
  private function insertLegacyLinkRow(BbAiListing $listing, EbayAccount $account, array $mirrorRow): void {
    $timestamp = time();

    $this->database->insert('bb_ebay_legacy_listing_link')
      ->fields([
        'listing' => (int) $listing->id(),
        'account_id' => (int) $account->id(),
        'origin_type' => 'legacy_ebay_migrated',
        'ebay_listing_id' => $mirrorRow['ebay_listing_id'],
        'ebay_listing_started_at' => NULL,
        'source_sku' => $mirrorRow['sku'],
        'created' => $timestamp,
        'changed' => $timestamp,
      ])
      ->execute();
  }

  /**
   * @return array<string, array<int, string>>
   */
  private function decodeAspects(?string $aspectsJson): array {
    if ($aspectsJson === NULL || trim($aspectsJson) === '') {
      return [];
    }

    $decodedAspects = json_decode($aspectsJson, TRUE);

    return is_array($decodedAspects) ? $decodedAspects : [];
  }

  /**
   * @param array<string, array<int, string>> $aspects
   */
  private function extractFirstAspectValue(array $aspects, string $aspectName): ?string {
    $values = $aspects[$aspectName] ?? NULL;
    if (!is_array($values) || $values === []) {
      return NULL;
    }

    $value = trim((string) ($values[0] ?? ''));
    return $value === '' ? NULL : $value;
  }

  /**
   * @param array<string, array<int, string>> $aspects
   */
  private function joinAspectValues(array $aspects, string $aspectName): ?string {
    $values = $aspects[$aspectName] ?? NULL;
    if (!is_array($values) || $values === []) {
      return NULL;
    }

    $normalizedValues = [];

    foreach ($values as $value) {
      $trimmedValue = trim((string) $value);
      if ($trimmedValue === '') {
        continue;
      }

      $normalizedValues[] = $trimmedValue;
    }

    if ($normalizedValues === []) {
      return NULL;
    }

    return implode(', ', $normalizedValues);
  }

  private function mapConditionGrade(?string $ebayCondition): string {
    return match ($ebayCondition) {
      'USED_ACCEPTABLE' => 'acceptable',
      'USED_VERY_GOOD' => 'very_good',
      'USED_EXCELLENT', 'USED_LIKE_NEW' => 'like_new',
      default => 'good',
    };
  }

  private function setFieldIfAvailable(BbAiListing $listing, string $fieldName, ?string $value): void {
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions('bb_ai_listing', 'book');
    if (!isset($fieldDefinitions[$fieldName])) {
      return;
    }

    if ($value === NULL || trim($value) === '') {
      return;
    }

    $listing->set($fieldName, $value);
  }

  private function normalizeNullableString(?string $value): ?string {
    $normalizedValue = trim((string) $value);
    return $normalizedValue === '' ? NULL : $normalizedValue;
  }

}
