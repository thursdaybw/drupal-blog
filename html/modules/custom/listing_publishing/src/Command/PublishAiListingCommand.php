<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Command;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\listing_publishing\Service\ListingPublisher;
use Drush\Commands\DrushCommands;

final class PublishAiListingCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingPublisher $publisher,
  ) {
    parent::__construct();
  }

  /**
   * Build a publish request for an AI book listing.
   *
   * @command listing-publishing:publish-ai-listing
   * @param int $id
   *   AI book listing entity ID.
   */
  public function publish(int $id): void {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');

    /** @var \Drupal\ai_listing\Entity\BbAiListing|null $listing */
    $listing = $storage->load($id);

    if (!$listing) {
      $this->output()->writeln('<error>AI Book Listing not found.</error>');
      return;
    }

    $status = (string) $listing->get('status')->value;
    if ($status !== 'ready') {
      $this->output()->writeln(sprintf(
        '<error>Listing %d is not ready to publish (status: %s).</error>',
        $listing->id(),
        $status
      ));
      return;
    }

    try {
      $result = $this->publisher->publish($listing);
    }
    catch (\Throwable $e) {
      $this->markFailure($listing, 'Publish failed: ' . $e->getMessage());
      return;
    }

    if (!$result->isSuccess()) {
      $this->markFailure($listing, 'Publish failed: ' . $result->getMessage());
      return;
    }

    $this->markPublished($listing, $result->getMarketplaceId());

    $this->output()->writeln(sprintf(
      'Published listing %s for entity %d.',
      $result->getMarketplaceId(),
      $listing->id()
    ));
  }

  private function markFailure(BbAiListing $listing, string $message): void {
    $this->output()->writeln('<error>' . $message . '</error>');
    $listing->set('status', 'failed');
    $listing->save();
  }

  private function markPublished(BbAiListing $listing, string $marketplaceId): void {
    $listing->set('status', 'shelved');
    $listing->save();
  }

}
