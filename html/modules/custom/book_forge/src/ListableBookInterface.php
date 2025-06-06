<?php

declare(strict_types=1);

namespace Drupal\book_forge;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a listable book entity type.
 */
interface ListableBookInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
