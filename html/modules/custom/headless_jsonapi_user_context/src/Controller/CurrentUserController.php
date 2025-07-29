<?php

namespace Drupal\headless_jsonapi_user_context\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns JSON data about the current user for headless frontends.
 */
class CurrentUserController extends ControllerBase {

  public function handle(): JsonResponse {
    $account = $this->currentUser();

    return new JsonResponse([
      'uid' => $account->id(),
      'name' => $account->getDisplayName(),
      'roles' => $account->getRoles(),
    ]);
  }

}

