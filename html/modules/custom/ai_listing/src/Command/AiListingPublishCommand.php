<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Command;

use Drush\Commands\DrushCommands;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\listing_publishing\Service\ListingPublisher;
use Drupal\listing_publishing\Model\MarketplacePublishResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Throwable;

final class AiListingPublishCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingPublisher $listingPublisher,
  ) {
    parent::__construct();
  }

  /**
   * Publish an AI listing.
   *
   * @command ai-listing:publish
   * @param int $listing_id
   *   Listing entity ID to publish.
   */
  public function publish(int $listing_id): void {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');
    /** @var \Drupal\ai_listing\Entity\BbAiListing|null $listing */
    $listing = $storage->load($listing_id);

    if (!$listing) {
      $this->output()->writeln(sprintf('Listing %d not found.', $listing_id));
      return;
    }

    try {
      $result = $this->listingPublisher->publish($listing);
    }
    catch (Throwable $e) {
      $this->markAsFailed($listing, 'Publish failed: ' . $e->getMessage());
      return;
    }

    if (!$result->isSuccess()) {
      $this->markAsFailed($listing, 'Publish failed: ' . $result->getMessage());
      return;
    }

    $this->markAsPublished($listing, $result);
    $this->output()->writeln('Published listing ' . $result->getMarketplaceId() . '.');
  }

  private function markAsFailed(BbAiListing $listing, string $message): void {
    $listing->set('status', 'failed');
    $listing->save();
    $this->output()->writeln('<error>' . $message . '</error>');
  }

  private function markAsPublished(BbAiListing $listing, MarketplacePublishResult $result): void {
    $listing->set('status', 'shelved');
    $listing->save();
  }

}
