<?php

declare(strict_types=1);

namespace Drupal\bb_platform\Controller;

use Drupal\Core\Controller\ControllerBase;

final class ApplicationsDashboardController extends ControllerBase {

  public function page(): array {
    return [
      '#markup' => $this->t('Applications'),
    ];
  }

}
