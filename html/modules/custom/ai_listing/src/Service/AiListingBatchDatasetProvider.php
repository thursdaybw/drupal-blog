<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Model\AiListingBatchDataset;
use Drupal\ai_listing\Model\AiListingBatchFilter;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class AiListingBatchDatasetProvider {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function buildDataset(AiListingBatchFilter $filter): AiListingBatchDataset {
    $storageLocationOptions = $this->buildStorageLocationFilterOptions();
    $allRows = $this->loadFilteredRows('any', 'any', 'any', '', '');
    $filteredRows = $this->loadFilteredRows(
      $filter->status,
      $filter->bargainBinFilterMode,
      $filter->publishedToEbayFilterMode,
      $filter->searchQuery,
      $filter->storageLocationFilter,
    );
    $this->sortRows($filteredRows, $filter->sortField, $filter->sortDirection);

    $totalCount = count($allRows);
    $filteredCount = count($filteredRows);
    $currentPage = $this->resolveCurrentPage($filter->currentPage, $filteredCount, $filter->itemsPerPage);
    $offset = $currentPage * $filter->itemsPerPage;
    $pagedRows = array_slice($filteredRows, $offset, $filter->itemsPerPage, TRUE);

    return new AiListingBatchDataset(
      totalCount: $totalCount,
      filteredCount: $filteredCount,
      currentPage: $currentPage,
      storageLocationOptions: $storageLocationOptions,
      pagedRows: $pagedRows,
    );
  }

  /**
   * @return array<string,string>
   */
  public function buildStorageLocationFilterOptions(): array {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $listingIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('storage_location')
      ->condition('storage_location', '', '<>')
      ->sort('storage_location', 'ASC')
      ->execute();

    $options = ['' => 'Any'];
    if ($listingIds === []) {
      return $options;
    }

    $listings = $storage->loadMultiple($listingIds);
    $locations = [];

    foreach ($listings as $listing) {
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $location = trim((string) ($listing->get('storage_location')->value ?? ''));
      if ($location === '') {
        continue;
      }

      $locations[$location] = $location;
    }

    ksort($locations);

    return $options + $locations;
  }

  /**
   * @return array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}>
   */
  private function loadFilteredRows(
    string $status,
    string $bargainBinFilterMode,
    string $publishedToEbayFilterMode,
    string $searchQuery,
    string $storageLocationFilter,
  ): array {
    $properties = $this->buildEntityPropertyFilter($status, $bargainBinFilterMode);
    $rows = $this->loadCandidateRows($properties);
    $listingIds = $this->extractListingIdsFromRows($rows);
    $skuLookup = $this->buildActiveSkuLookup($listingIds);
    $publishedLookup = $this->buildPublishedToEbayLookup($listingIds);
    $ebayListingIdLookup = $this->buildEbayMarketplaceListingIdLookup($listingIds);

    $filteredRows = [];

    foreach ($rows as $row) {
      $listing = $row['entity'] ?? NULL;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $listingId = (int) $listing->id();
      $isPublishedToEbay = $publishedLookup[$listingId] ?? FALSE;
      if (!$this->matchesPublishedToEbayFilter($isPublishedToEbay, $publishedToEbayFilterMode)) {
        continue;
      }
      if (!$this->listingMatchesSearchQuery($listing, $searchQuery, $skuLookup[$listingId] ?? '')) {
        continue;
      }
      if (!$this->listingMatchesStorageLocationFilter($listing, $storageLocationFilter)) {
        continue;
      }

      $listingType = (string) $listing->bundle();

      $filteredRows[$this->buildSelectionKey($listingType, $listingId)] = [
        'selection_key' => $this->buildSelectionKey($listingType, $listingId),
        'listing_type' => $listingType,
        'listing_id' => $listingId,
        'entity' => $listing,
        'created' => (int) $row['created'],
        'sku' => $skuLookup[$listingId] ?? '',
        'is_published_to_ebay' => $isPublishedToEbay,
        'ebay_listing_id' => $ebayListingIdLookup[$listingId] ?? NULL,
      ];
    }

    return $filteredRows;
  }

  /**
   * @return array<string,int|bool>
   */
  private function buildEntityPropertyFilter(string $status, string $bargainBinFilterMode): array {
    $properties = [];
    if ($status !== 'any') {
      $properties['status'] = $status;
    }
    if ($bargainBinFilterMode === 'yes') {
      $properties['bargain_bin'] = 1;
    }
    if ($bargainBinFilterMode === 'no') {
      $properties['bargain_bin'] = 0;
    }

    return $properties;
  }

  /**
   * @param array<string,int|bool> $properties
   *
   * @return array<int,array{listing_type:string,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int}>
   */
  private function loadCandidateRows(array $properties): array {
    $rows = [];
    $items = $this->entityTypeManager->getStorage('bb_ai_listing')->loadByProperties($properties);

    foreach ($items as $listing) {
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $rows[] = [
        'listing_type' => (string) $listing->bundle(),
        'entity' => $listing,
        'created' => (int) $listing->get('created')->value,
      ];
    }

    usort($rows, static fn(array $a, array $b): int => $a['created'] <=> $b['created']);

    return $rows;
  }

  private function matchesPublishedToEbayFilter(bool $isPublishedToEbay, string $filterMode): bool {
    if ($filterMode === 'yes') {
      return $isPublishedToEbay;
    }
    if ($filterMode === 'no') {
      return !$isPublishedToEbay;
    }

    return TRUE;
  }

  /**
   * @param array<string,array{
   *   selection_key:string,
   *   listing_type:string,
   *   listing_id:int,
   *   entity:\Drupal\ai_listing\Entity\BbAiListing,
   *   created:int,
   *   sku:string,
   *   is_published_to_ebay:bool,
   *   ebay_listing_id:?string
   * }> $rows
   */
  private function sortRows(array &$rows, string $requestedSortField, string $requestedSortDirection): void {
    if ($rows === []) {
      return;
    }

    $sortField = $this->normalizeSortField($requestedSortField);
    $sortDirection = $this->normalizeSortDirection($requestedSortDirection);

    uasort(
      $rows,
      function (array $left, array $right) use ($sortField, $sortDirection): int {
        $leftValue = $this->extractSortValue($left, $sortField);
        $rightValue = $this->extractSortValue($right, $sortField);

        $comparison = $this->compareSortValues($leftValue, $rightValue);
        if ($comparison === 0) {
          $leftFallback = (int) ($left['listing_id'] ?? 0);
          $rightFallback = (int) ($right['listing_id'] ?? 0);
          $comparison = $leftFallback <=> $rightFallback;
        }

        return $sortDirection === 'asc' ? $comparison : -$comparison;
      }
    );
  }

  private function normalizeSortField(string $requestedSortField): string {
    $allowedFields = [
      'type',
      'entity_id',
      'listing_code',
      'sku',
      'status',
      'title',
      'author',
      'price',
      'location',
      'published_to_ebay',
      'created',
    ];

    return in_array($requestedSortField, $allowedFields, TRUE)
      ? $requestedSortField
      : 'created';
  }

  private function normalizeSortDirection(string $requestedSortDirection): string {
    return strtolower($requestedSortDirection) === 'desc' ? 'desc' : 'asc';
  }

  /**
   * @param array{
   *   selection_key:string,
   *   listing_type:string,
   *   listing_id:int,
   *   entity:\Drupal\ai_listing\Entity\BbAiListing,
   *   created:int,
   *   sku:string,
   *   is_published_to_ebay:bool,
   *   ebay_listing_id:?string
   * } $row
   *
   * @return int|float|string
   */
  private function extractSortValue(array $row, string $sortField): int|float|string {
    $listing = $row['entity'] ?? NULL;
    if (!$listing instanceof BbAiListing) {
      return '';
    }

    return match ($sortField) {
      'type' => (string) ($row['listing_type'] ?? ''),
      'entity_id' => (int) ($row['listing_id'] ?? 0),
      'listing_code' => trim((string) ($listing->get('listing_code')->value ?? '')),
      'sku' => trim((string) ($row['sku'] ?? '')),
      'status' => trim((string) ($listing->get('status')->value ?? '')),
      'title' => $this->buildSortTitle($listing),
      'author' => trim((string) ($listing->get('field_author')->value ?? '')),
      'price' => (float) ($listing->get('price')->value ?? 0),
      'location' => trim((string) ($listing->get('storage_location')->value ?? '')),
      'published_to_ebay' => !empty($row['is_published_to_ebay']) ? 1 : 0,
      'created' => (int) ($row['created'] ?? 0),
      default => (int) ($row['created'] ?? 0),
    };
  }

  private function buildSortTitle(BbAiListing $listing): string {
    $label = trim((string) ($listing->label() ?? ''));
    if ($label !== '') {
      return $label;
    }

    return trim((string) ($listing->get('ebay_title')->value ?? ''));
  }

  /**
   * @param int|float|string $leftValue
   * @param int|float|string $rightValue
   */
  private function compareSortValues(int|float|string $leftValue, int|float|string $rightValue): int {
    if (is_int($leftValue) && is_int($rightValue)) {
      return $leftValue <=> $rightValue;
    }

    if ((is_int($leftValue) || is_float($leftValue)) && (is_int($rightValue) || is_float($rightValue))) {
      return $leftValue <=> $rightValue;
    }

    return strnatcasecmp((string) $leftValue, (string) $rightValue);
  }

  private function resolveCurrentPage(int $requestedPage, int $filteredCount, int $itemsPerPage): int {
    if ($requestedPage <= 0) {
      return 0;
    }

    if ($filteredCount <= 0 || $itemsPerPage <= 0) {
      return 0;
    }

    $offset = $requestedPage * $itemsPerPage;
    if ($offset < $filteredCount) {
      return $requestedPage;
    }

    return 0;
  }

  /**
   * @param array<int,array{listing_type:string,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int}> $rows
   *
   * @return int[]
   */
  private function extractListingIdsFromRows(array $rows): array {
    $listingIds = [];

    foreach ($rows as $row) {
      $entity = $row['entity'] ?? NULL;
      if (!$entity instanceof BbAiListing) {
        continue;
      }

      $listingId = (int) $entity->id();
      if ($listingId > 0) {
        $listingIds[] = $listingId;
      }
    }

    return $listingIds;
  }

  /**
   * @param int[] $listingIds
   *
   * @return array<int,bool>
   */
  private function buildPublishedToEbayLookup(array $listingIds): array {
    if ($listingIds === [] || !$this->entityTypeManager->hasDefinition('ai_marketplace_publication')) {
      return [];
    }

    $publicationStorage = $this->entityTypeManager->getStorage('ai_marketplace_publication');
    $publicationIds = $publicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingIds, 'IN')
      ->condition('marketplace_key', 'ebay')
      ->condition('status', 'published')
      ->execute();
    if ($publicationIds === []) {
      return [];
    }

    $lookup = [];
    $publications = $publicationStorage->loadMultiple($publicationIds);
    foreach ($publications as $publication) {
      $listingId = (int) ($publication->get('listing')->target_id ?? 0);
      if ($listingId > 0) {
        $lookup[$listingId] = TRUE;
      }
    }

    return $lookup;
  }

  /**
   * @param int[] $listingIds
   *
   * @return array<int,string>
   */
  private function buildActiveSkuLookup(array $listingIds): array {
    if ($listingIds === [] || !$this->entityTypeManager->hasDefinition('ai_listing_inventory_sku')) {
      return [];
    }

    $skuRows = $this->entityTypeManager->getStorage('ai_listing_inventory_sku')->loadByProperties([
      'listing' => $listingIds,
      'status' => 'active',
    ]);
    if ($skuRows === []) {
      return [];
    }

    $lookup = [];
    foreach ($skuRows as $skuRow) {
      $listingId = (int) ($skuRow->get('listing')->target_id ?? 0);
      $skuValue = trim((string) ($skuRow->get('sku')->value ?? ''));
      if ($listingId <= 0 || $skuValue === '') {
        continue;
      }

      $lookup[$listingId] = $skuValue;
    }

    return $lookup;
  }

  /**
   * @param int[] $listingIds
   *
   * @return array<int,string>
   */
  private function buildEbayMarketplaceListingIdLookup(array $listingIds): array {
    if ($listingIds === [] || !$this->entityTypeManager->hasDefinition('ai_marketplace_publication')) {
      return [];
    }

    $lookup = [];
    $this->addEbayMarketplaceListingIdsToLookup($listingIds, 'published', $lookup);
    $this->addEbayMarketplaceListingIdsToLookup($listingIds, NULL, $lookup);

    return $lookup;
  }

  /**
   * @param int[] $listingIds
   * @param array<int,string> $lookup
   */
  private function addEbayMarketplaceListingIdsToLookup(array $listingIds, ?string $status, array &$lookup): void {
    $query = $this->entityTypeManager->getStorage('ai_marketplace_publication')->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingIds, 'IN')
      ->condition('marketplace_key', 'ebay')
      ->condition('marketplace_listing_id', '', '<>')
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC');
    if ($status !== NULL) {
      $query->condition('status', $status);
    }

    $publicationIds = array_values($query->execute());
    if ($publicationIds === []) {
      return;
    }

    $publications = $this->entityTypeManager->getStorage('ai_marketplace_publication')->loadMultiple($publicationIds);
    foreach ($publicationIds as $publicationId) {
      $publication = $publications[$publicationId] ?? NULL;
      if ($publication === NULL) {
        continue;
      }

      $listingId = (int) ($publication->get('listing')->target_id ?? 0);
      if ($listingId <= 0 || isset($lookup[$listingId])) {
        continue;
      }

      $marketplaceListingId = trim((string) ($publication->get('marketplace_listing_id')->value ?? ''));
      if ($marketplaceListingId === '') {
        continue;
      }

      $lookup[$listingId] = $marketplaceListingId;
    }
  }

  private function listingMatchesSearchQuery(BbAiListing $listing, string $searchQuery, string $sku): bool {
    $normalizedSearchQuery = $this->normalizeForSearch($searchQuery);
    if ($normalizedSearchQuery === '') {
      return TRUE;
    }

    $fullTitle = $this->getStringFieldValueIfExists($listing, 'field_full_title');
    $title = $this->getStringFieldValueIfExists($listing, 'field_title');
    $author = $this->getStringFieldValueIfExists($listing, 'field_author');
    $description = $this->getStringFieldValueIfExists($listing, 'description');
    $storageLocation = trim((string) ($listing->get('storage_location')->value ?? ''));

    $searchableText = trim($fullTitle . ' ' . $title . ' ' . $author . ' ' . $description . ' ' . $storageLocation . ' ' . $sku);
    $normalizedSearchableText = $this->normalizeForSearch($searchableText);
    if ($normalizedSearchableText === '') {
      return FALSE;
    }

    return str_contains($normalizedSearchableText, $normalizedSearchQuery);
  }

  private function listingMatchesStorageLocationFilter(BbAiListing $listing, string $storageLocationFilter): bool {
    $normalizedFilter = $this->normalizeForSearch($storageLocationFilter);
    if ($normalizedFilter === '') {
      return TRUE;
    }

    $storageLocation = trim((string) ($listing->get('storage_location')->value ?? ''));
    $normalizedStorageLocation = $this->normalizeForSearch($storageLocation);
    if ($normalizedStorageLocation === '') {
      return FALSE;
    }

    return str_contains($normalizedStorageLocation, $normalizedFilter);
  }

  private function getStringFieldValueIfExists(BbAiListing $listing, string $fieldName): string {
    if (!$listing->hasField($fieldName)) {
      return '';
    }

    return trim((string) ($listing->get($fieldName)->value ?? ''));
  }

  private function normalizeForSearch(string $value): string {
    $trimmedValue = trim($value);
    if ($trimmedValue === '') {
      return '';
    }

    return mb_strtolower($trimmedValue);
  }

  private function buildSelectionKey(string $listingType, int $listingId): string {
    return $listingType . ':' . $listingId;
  }

}
