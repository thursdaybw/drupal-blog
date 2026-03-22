<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class IntakeSetListingMaterializer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @param int[] $fileIds
   */
  public function materializeNewBookListing(array $fileIds): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $listing->save();

    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return $listing;
    }

    $storage = $this->entityTypeManager->getStorage('listing_image');
    $weight = 0;

    foreach (array_values(array_unique(array_map('intval', $fileIds))) as $fileId) {
      if ($fileId <= 0) {
        continue;
      }

      $storage->create([
        'owner' => [
          'target_type' => 'bb_ai_listing',
          'target_id' => (int) $listing->id(),
        ],
        'file' => $fileId,
        'weight' => $weight,
        'is_metadata_source' => FALSE,
      ])->save();
      $weight++;
    }

    return $listing;
  }

}
