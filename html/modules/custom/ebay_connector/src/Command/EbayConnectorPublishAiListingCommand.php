<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Command;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ebay_connector\Model\BookListingData;
use Drupal\ebay_connector\Service\BookListingPublisher;
use Drupal\file\Entity\File;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EbayConnectorPublishAiListingCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BookListingPublisher $publisher,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Publish an AI book listing through the Sell API.
   *
   * @command ebay-connector:publish-ai-listing
   * @param int $id
   *   AI book listing entity ID.
   */
  public function publish(string $id): void {
    $storage = $this->entityTypeManager->getStorage('ai_book_listing');

    /** @var \Drupal\ai_listing\Entity\AiBookListing|null $listing */
    $listing = $storage->load($id);

    if (!$listing) {
      $this->output()->writeln('<error>AI Book Listing not found.</error>');
      return;
    }

    $data = $this->buildBookListingData($listing);

    try {
      $listingId = $this->publisher->publish($data);
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>Publish failed: ' . $e->getMessage() . '</error>');
      return;
    }

    $listing->set('ebay_item_id', $listingId);
    $listing->set('status', 'published');
    $listing->save();

    $this->output()->writeln(sprintf(
      'Published listing %s for entity %d.',
      $listingId,
      $listing->id()
    ));
  }

  private function buildBookListingData(AiBookListing $listing): BookListingData {
    $title = (string) $listing->get('title')->value ?: (string) $listing->get('full_title')->value ?: 'Untitled AI Listing';
    $description = (string) $listing->get('description')->value ?: "AI-assisted metadata for {$title}.";
    $author = (string) $listing->get('author')->value ?: 'Unknown';
    $sku = 'ai-book-' . $listing->id();
    $price = '29.95';
    $quantity = 1;
    $condition = 'good';
    $imageUrl = $this->resolveImageUrl($listing);

    return new BookListingData(
      $sku,
      $title,
      $description,
      $author,
      $price,
      $imageUrl,
      $quantity,
      $condition
    );
  }

  private function resolveImageUrl(AiBookListing $listing): string {
    $files = $listing->get('images')->referencedEntities();

    foreach ($files as $file) {
      if ($file instanceof File) {
        return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    return 'https://via.placeholder.com/1024';
  }

}
