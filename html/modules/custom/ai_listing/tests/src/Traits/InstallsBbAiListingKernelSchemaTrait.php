<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Traits;

/**
 * Shares the schema install contract for bb_ai_listing kernel tests.
 *
 * Kernel tests that install bb_ai_listing must also enable the taxonomy module
 * because the storage_location_term base field references taxonomy terms.
 */
trait InstallsBbAiListingKernelSchemaTrait {

  protected function installBbAiListingKernelSchema(): void {
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('bb_ai_listing');
  }

}
