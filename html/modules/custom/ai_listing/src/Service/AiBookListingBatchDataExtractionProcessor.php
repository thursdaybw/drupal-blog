<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ai_listing\Service\AiBookListingDataExtractionProcessor;

final class AiBookListingBatchDataExtractionProcessor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiBookListingDataExtractionProcessor $processor,
  ) {}

  public function processAllNew(): int {
    $ids = $this->getNewListingIds();
    if (empty($ids)) {
      return 0;
    }

    $count = 0;
    foreach ($ids as $id) {
      $listing = $this->entityTypeManager->getStorage('ai_book_listing')->load($id);
      if (!$listing) {
        continue;
      }

      $this->processListing($listing);
      $count++;
    }

    return $count;
  }

  public function getNewListingIds(): array {
    return array_values($this->entityTypeManager->getStorage('ai_book_listing')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'new')
      ->execute());
  }

  public function loadListing(string|int $id): ?AiBookListing {
    /** @var AiBookListing|null $listing */
    return $this->entityTypeManager->getStorage('ai_book_listing')->load((int) $id);
  }

  public function processListing(AiBookListing $listing): void {
    $listing->set('status', 'processing');
    $listing->save();

    try {
      $this->processor->process($listing);
    }
    catch (\Throwable $e) {
      $listing->set('status', 'new');
      $listing->save();
      throw $e;
    }
  }
}
