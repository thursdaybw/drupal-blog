<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Service\AiListingInventorySkuResolver;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use GuzzleHttp\ClientInterface;
use Drupal\listing_publishing\Service\MarketplacePublicationRecorder;
use InvalidArgumentException;
use Throwable;

final class EbayLegacyListingAdoptionService {

  /**
   * eBay AU Books, Comics & Magazines > Books.
   *
   * Legacy adoption is category-led. If we later expand book categories, extend
   * this list in one place.
   */
  private const BOOK_CATEGORY_IDS = [
    '261186',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EbayAccountManager $accountManager,
    private readonly AiListingInventorySkuResolver $inventorySkuResolver,
    private readonly MarketplacePublicationRecorder $publicationRecorder,
    private readonly ClientInterface $httpClient,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
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

    $this->assertMirrorRowIsBookCategory($normalizedListingId, $mirrorRow);
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
      $mirrorRow['ebay_listing_id'],
      NULL,
      'legacy_adopted',
      $mirrorRow['ebay_listing_started_at']
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
   *   image_urls_json:?string,
   *   price_value:?string,
   *   offer_id:string,
   *   ebay_listing_id:string,
   *   publication_type:string,
   *   ebay_listing_started_at:?int,
   *   category_id:?string
   * }|null
   */
  private function loadMirrorRow(EbayAccount $account, string $ebayListingId): ?array {
    $query = $this->database->select('bb_ebay_offer', 'offer');
    $query->innerJoin(
      'bb_ebay_inventory_item',
      'inventory',
      'inventory.account_id = offer.account_id AND inventory.sku = offer.sku'
    );
    $query->leftJoin(
      'bb_ebay_legacy_listing',
      'legacy',
      'legacy.account_id = offer.account_id AND legacy.ebay_listing_id = offer.listing_id'
    );
    $query->fields('offer', ['sku', 'listing_description', 'price_value', 'offer_id', 'listing_id', 'format', 'category_id']);
    $query->fields('inventory', ['title', 'condition', 'condition_description', 'aspects_json', 'image_urls_json']);
    $query->fields('legacy', ['ebay_listing_started_at']);
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
      'image_urls_json' => $this->normalizeNullableString($row['image_urls_json'] ?? NULL),
      'price_value' => $this->normalizeNullableString($row['price_value'] ?? NULL),
      'offer_id' => (string) ($row['offer_id'] ?? ''),
      'ebay_listing_id' => (string) ($row['listing_id'] ?? ''),
      'publication_type' => (string) ($row['format'] ?? 'FIXED_PRICE'),
      'ebay_listing_started_at' => $this->normalizeNullableInt($row['ebay_listing_started_at'] ?? NULL),
      'category_id' => $this->normalizeNullableString($row['category_id'] ?? NULL),
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
   * @param array{category_id:?string} $mirrorRow
   */
  private function assertMirrorRowIsBookCategory(string $ebayListingId, array $mirrorRow): void {
    $categoryId = (string) ($mirrorRow['category_id'] ?? '');
    if ($categoryId !== '' && in_array($categoryId, self::BOOK_CATEGORY_IDS, TRUE)) {
      return;
    }

    $readableCategory = $categoryId === '' ? 'none' : $categoryId;
    throw new InvalidArgumentException(sprintf(
      'eBay listing %s is category %s and is not eligible for adopt-book.',
      $ebayListingId,
      $readableCategory
    ));
  }

  /**
   * @param array{
   *   sku:string,
   *   ebay_title:string,
   *   listing_description:string,
   *   condition:?string,
   *   condition_description:?string,
   *   aspects_json:?string,
   *   image_urls_json:?string,
   *   price_value:?string,
   *   offer_id:string,
   *   ebay_listing_id:string,
   *   publication_type:string,
   *   ebay_listing_started_at:?int
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
    $this->importMirrorImagesIntoListing($listing, $mirrorRow['image_urls_json'] ?? NULL);

    return $listing;
  }

  private function importMirrorImagesIntoListing(BbAiListing $listing, ?string $imageUrlsJson): void {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return;
    }

    $imageUrls = $this->decodeImageUrls($imageUrlsJson);
    if ($imageUrls === []) {
      return;
    }

    $targetDirectory = 'public://ai-listings/' . $listing->uuid();
    $this->fileSystem->prepareDirectory(
      $targetDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    $weight = 0;
    foreach ($imageUrls as $imageUrl) {
      $downloadedFile = $this->downloadImageFile($imageUrl, $targetDirectory, $weight);
      if ($downloadedFile === NULL) {
        continue;
      }

      $this->createListingImageRecord((int) $listing->id(), (int) $downloadedFile->id(), $weight, TRUE);
      $weight++;
    }
  }

  /**
   * @return string[]
   */
  private function decodeImageUrls(?string $imageUrlsJson): array {
    if ($imageUrlsJson === NULL || trim($imageUrlsJson) === '') {
      return [];
    }

    $decoded = json_decode($imageUrlsJson, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $urls = [];
    foreach ($decoded as $url) {
      $normalized = trim((string) $url);
      if ($normalized === '') {
        continue;
      }

      $urls[] = $normalized;
    }

    return array_values(array_unique($urls));
  }

  private function downloadImageFile(string $imageUrl, string $targetDirectory, int $index): ?\Drupal\file\FileInterface {
    try {
      $response = $this->httpClient->request('GET', $imageUrl, [
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (Throwable) {
      return NULL;
    }

    if ($response->getStatusCode() !== 200) {
      return NULL;
    }

    $bytes = (string) $response->getBody();
    if ($bytes === '') {
      return NULL;
    }

    $extension = $this->resolveImageExtension(
      $imageUrl,
      (string) $response->getHeaderLine('Content-Type')
    );
    $filename = sprintf('legacy-%03d.%s', $index + 1, $extension);
    $destination = $targetDirectory . '/' . $filename;

    try {
      $file = $this->fileRepository->writeData($bytes, $destination, FileExists::Rename);
    }
    catch (FileException|Throwable) {
      return NULL;
    }

    if ($file === FALSE) {
      return NULL;
    }

    $file->setPermanent();
    $file->save();

    return $file;
  }

  private function resolveImageExtension(string $imageUrl, string $contentTypeHeader): string {
    $path = (string) parse_url($imageUrl, PHP_URL_PATH);
    $pathExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($pathExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'], TRUE)) {
      return $pathExtension === 'jpeg' ? 'jpg' : $pathExtension;
    }

    $contentType = strtolower(trim(explode(';', $contentTypeHeader)[0] ?? ''));
    return match ($contentType) {
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
      'image/bmp' => 'bmp',
      'image/tiff' => 'tif',
      default => 'jpg',
    };
  }

  private function createListingImageRecord(int $listingId, int $fileId, int $weight, bool $isMetadataSource): void {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return;
    }

    $this->entityTypeManager->getStorage('listing_image')->create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => $listingId,
      ],
      'file' => $fileId,
      'weight' => $weight,
      'is_metadata_source' => $isMetadataSource,
    ])->save();
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
   *   publication_type:string,
   *   ebay_listing_started_at:?int
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
        'ebay_listing_started_at' => $mirrorRow['ebay_listing_started_at'],
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

  private function normalizeNullableInt(mixed $value): ?int {
    if ($value === NULL) {
      return NULL;
    }

    $normalized = (int) $value;
    return $normalized > 0 ? $normalized : NULL;
  }

}
