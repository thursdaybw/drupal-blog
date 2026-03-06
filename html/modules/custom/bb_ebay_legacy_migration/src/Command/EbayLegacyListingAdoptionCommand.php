<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Command;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyListingAdoptionService;
use Drush\Commands\DrushCommands;

final class EbayLegacyListingAdoptionCommand extends DrushCommands {

  public function __construct(
    private readonly EbayLegacyListingAdoptionService $adoptionService,
  ) {
    parent::__construct();
  }

  /**
   * Adopt one mirrored migrated eBay listing into bb_ai_listing.
   *
   * This first pass is book-only and conservative:
   * - creates one local bb_ai_listing row
   * - creates one local active SKU row
   * - creates one local published eBay publication row
   * - writes one provenance row to bb_ebay_legacy_listing_link
   *
   * @command bb-ebay-legacy-migration:adopt-book
   *
   * @usage bb-ebay-legacy-migration:adopt-book 176604590528
   *   Adopt one mirrored migrated eBay listing into bb_ai_listing.
   */
  public function adoptBook(string $ebayListingId, ?int $accountId = NULL): void {
    $result = $this->adoptionService->adoptBookListing($ebayListingId, $accountId);

    $this->output()->writeln(sprintf(
      'Adopted eBay listing %s into bb_ai_listing %d (listing code %s, SKU %s, offer %s).',
      $result['ebay_listing_id'],
      $result['local_listing_id'],
      $result['local_listing_code'] ?? 'none',
      $result['sku'],
      $result['offer_id']
    ));
  }

  /**
   * Adopt one mirrored migrated non-book eBay listing into bb_ai_listing.
   *
   * @command bb-ebay-legacy-migration:adopt-generic
   *
   * @usage bb-ebay-legacy-migration:adopt-generic 176577811710
   *   Adopt one mirrored migrated non-book eBay listing into bb_ai_listing.
   */
  public function adoptGeneric(string $ebayListingId, ?int $accountId = NULL): void {
    $result = $this->adoptionService->adoptGenericListing($ebayListingId, $accountId);

    $this->output()->writeln(sprintf(
      'Adopted eBay listing %s into bb_ai_listing %d (listing code %s, SKU %s, offer %s).',
      $result['ebay_listing_id'],
      $result['local_listing_id'],
      $result['local_listing_code'] ?? 'none',
      $result['sku'],
      $result['offer_id']
    ));
  }

  /**
   * Adopt migrated legacy listings that are ready in mirror data.
   *
   * Routing rule:
   * - book categories => adopt-book path
   * - everything else => adopt-generic path
   *
   * @command bb-ebay-legacy-migration:adopt-ready-batch
   * @option account-id Adopt from this eBay account ID (defaults to primary).
   * @option limit Maximum number of listings to adopt in this run.
   * @option dry-run Print what would be adopted without writing local entities.
   *
   * @usage bb-ebay-legacy-migration:adopt-ready-batch --limit=25 --dry-run
   *   Preview the next 25 ready legacy listings and their adoption route.
   */
  public function adoptReadyBatch(array $options = [
    'account-id' => NULL,
    'limit' => 0,
    'dry-run' => FALSE,
  ]): void {
    $accountId = isset($options['account-id']) && $options['account-id'] !== NULL
      ? (int) $options['account-id']
      : NULL;
    $limit = (int) ($options['limit'] ?? 0);
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);

    $rows = $this->adoptionService->findReadyToAdoptListings($accountId);
    if ($limit > 0) {
      $rows = array_slice($rows, 0, $limit);
    }

    if ($rows === []) {
      $this->output()->writeln('No migrated legacy listings are ready for adoption.');
      return;
    }

    $this->output()->writeln(sprintf(
      'Processing %d ready listing(s). dryRun=%s',
      count($rows),
      $dryRun ? 'true' : 'false'
    ));

    $summary = [
      'book_adopted' => 0,
      'generic_adopted' => 0,
      'failed' => 0,
    ];

    foreach ($rows as $row) {
      $listingId = (string) ($row['ebay_listing_id'] ?? '');
      if ($listingId === '') {
        continue;
      }

      $categoryId = is_string($row['category_id'] ?? NULL) ? $row['category_id'] : NULL;
      $adoptionType = $this->adoptionService->resolveAdoptionTypeForCategory($categoryId);

      if ($dryRun) {
        $this->output()->writeln(sprintf(
          '- [dry-run] listingId %s | type %s | category %s | sku %s | offer %s | %s',
          $listingId,
          $adoptionType,
          $categoryId ?? 'none',
          (string) ($row['sku'] ?? ''),
          (string) ($row['offer_id'] ?? ''),
          (string) ($row['title'] ?? '')
        ));
        continue;
      }

      try {
        $result = $adoptionType === 'book'
          ? $this->adoptionService->adoptBookListing($listingId, $accountId)
          : $this->adoptionService->adoptGenericListing($listingId, $accountId);

        if ($adoptionType === 'book') {
          $summary['book_adopted']++;
        }
        else {
          $summary['generic_adopted']++;
        }

        $this->output()->writeln(sprintf(
          '- listingId %s | adopted_as %s | local %d | sku %s | offer %s',
          $result['ebay_listing_id'],
          $adoptionType,
          $result['local_listing_id'],
          $result['sku'],
          $result['offer_id']
        ));
      }
      catch (\Throwable $e) {
        $summary['failed']++;
        $this->output()->writeln(sprintf(
          '- listingId %s | failed | %s',
          $listingId,
          $e->getMessage()
        ));
      }
    }

    if ($dryRun) {
      $this->output()->writeln(sprintf('Dry run complete. Would process %d listing(s).', count($rows)));
      return;
    }

    $this->output()->writeln('Batch summary:');
    $this->output()->writeln(sprintf('- book_adopted: %d', $summary['book_adopted']));
    $this->output()->writeln(sprintf('- generic_adopted: %d', $summary['generic_adopted']));
    $this->output()->writeln(sprintf('- failed: %d', $summary['failed']));
  }

}
