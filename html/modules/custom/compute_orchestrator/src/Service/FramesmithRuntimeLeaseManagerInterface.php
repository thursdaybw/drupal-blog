<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Backward-compatible name for the Framesmith compute runtime client.
 *
 * New code should type against WhisperRuntimeClientInterface.
 */
interface FramesmithRuntimeLeaseManagerInterface extends WhisperRuntimeClientInterface {

}
