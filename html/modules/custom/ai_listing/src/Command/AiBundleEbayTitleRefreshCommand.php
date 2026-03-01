<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Command;

use Drupal\ai_listing\Service\BundleEbayTitleRefreshService;
use Drush\Commands\DrushCommands;

final class AiBundleEbayTitleRefreshCommand extends DrushCommands {

  public function __construct(
    private readonly BundleEbayTitleRefreshService $bundleEbayTitleRefreshService,
  ) {
    parent::__construct();
  }

  /**
   * Refresh derived eBay titles for book bundles.
   *
   * @command ai-listing:refresh-bundle-ebay-titles
   * @param string $status
   *   Listing status filter. Defaults to ready_for_review.
   */
  public function refresh(string $status = 'ready_for_review'): void {
    $ids = $this->bundleEbayTitleRefreshService->getBundleListingIdsByStatus($status);
    $total = count($ids);

    if ($total === 0) {
      $this->output()->writeln(sprintf('No book bundles found with status %s.', $status));
      return;
    }

    $updated = 0;
    $unchanged = 0;

    foreach ($ids as $index => $listingId) {
      $listing = $this->bundleEbayTitleRefreshService->loadListing((int) $listingId);
      if ($listing === NULL) {
        $this->output()->writeln(sprintf('%d/%d Listing %d not found, skipping.', $index + 1, $total, $listingId));
        continue;
      }

      $changed = $this->bundleEbayTitleRefreshService->refreshListing($listing);
      $ebayTitle = trim((string) ($listing->get('ebay_title')->value ?? ''));

      if ($changed) {
        $updated++;
        $this->output()->writeln(sprintf('%d/%d Listing %d updated: %s', $index + 1, $total, $listingId, $ebayTitle));
        continue;
      }

      $unchanged++;
      $this->output()->writeln(sprintf('%d/%d Listing %d unchanged: %s', $index + 1, $total, $listingId, $ebayTitle));
    }

    $this->output()->writeln(sprintf('Updated: %d', $updated));
    $this->output()->writeln(sprintf('Unchanged: %d', $unchanged));
  }

}
