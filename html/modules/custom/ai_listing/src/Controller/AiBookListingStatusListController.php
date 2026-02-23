<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Controller;

use Drupal\ai_listing\ListBuilder\AiBookListingListBuilder;
use Drupal\Core\Controller\ControllerBase;

final class AiBookListingStatusListController extends ControllerBase {

  public function listStatus(string $status): array {
    $listBuilder = $this->entityTypeManager()->getListBuilder('ai_book_listing');

    if ($listBuilder instanceof AiBookListingListBuilder) {
      $listBuilder->setStatusFilter($status);
    }

    return $listBuilder->render();
  }

}
