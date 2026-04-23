<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Resolves the SSH private key path used for compute orchestration.
 */
interface SshKeyPathResolverInterface {

  /**
   * Returns the candidate SSH private key path, which may not exist.
   */
  public function getCandidatePath(): string;

  /**
   * Returns the SSH private key path when it exists, otherwise NULL.
   */
  public function resolvePath(): ?string;

  /**
   * Returns the SSH private key path or throws when unavailable.
   */
  public function resolveRequiredPath(): string;

}
