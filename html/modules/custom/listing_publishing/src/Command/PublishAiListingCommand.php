<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Command;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Service\BookListingAssembler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

final class PublishAiListingCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BookListingAssembler $assembler,
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
    $storage = $this->entityTypeManager->getStorage('ai_book_listing');

    /** @var \Drupal\ai_listing\Entity\AiBookListing|null $listing */
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

    $request = $this->assembler->assemble($listing);

    $this->output()->writeln(sprintf(
      'Listing publish request built for entity %d.',
      $listing->id()
    ));
    $this->output()->writeln(json_encode($request->toArray(), JSON_PRETTY_PRINT));
  }

}
