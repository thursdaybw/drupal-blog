<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Exception;

/**
 * Exception thrown when pooled acquire is still progressing asynchronously.
 */
final class AcquirePendingException extends \RuntimeException {

}
