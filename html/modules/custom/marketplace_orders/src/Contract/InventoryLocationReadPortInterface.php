<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Contract;

/**
 * Port for resolving current storage location by listing UUID.
 */
interface InventoryLocationReadPortInterface {

  /**
   * Resolves current storage location for a listing UUID.
   *
   * @return string|null
   *   Location code when known, NULL when unresolved.
   */
  public function getCurrentLocationForListingUuid(string $listingUuid): ?string;

}
