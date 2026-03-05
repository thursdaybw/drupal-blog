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

}
