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

    $storage = $this->entityTypeManager->getStorage('ai_book_listing');

    $ids = $this->entityTypeManager->getStorage('ai_book_listing')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'new')
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    $count = 0;

    foreach ($ids as $id) {

      /** @var AiBookListing|null $listing */
      $listing = $storage->load($id);

      if (!$listing) {
        continue;
      }

      $listing->set('status', 'processing');
      $listing->save();

      try {
        $this->processor->process($listing);
        $count++;
      }
      catch (\Throwable $e) {
        $listing->set('status', 'new');
        $listing->save();
        throw $e;
      }
    }

    return $count;
  }
}
