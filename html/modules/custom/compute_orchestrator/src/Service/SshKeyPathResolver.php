<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Resolves the SSH private key path used for Vast instance control.
 */
final class SshKeyPathResolver implements SshKeyPathResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function getCandidatePath(): string {
    $keyPath = getenv('VAST_SSH_PRIVATE_KEY_CONTAINER_PATH') ?: '';
    if ($keyPath !== '') {
      return $keyPath;
    }

    $home = getenv('HOME') ?: '';
    if ($home === '') {
      return '';
    }

    return rtrim($home, '/') . '/.ssh/id_rsa_vastai';
  }

  /**
   * {@inheritdoc}
   */
  public function resolvePath(): ?string {
    $candidate = $this->getCandidatePath();
    if ($candidate === '') {
      return NULL;
    }

    return file_exists($candidate) ? $candidate : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveRequiredPath(): string {
    $path = $this->resolvePath();
    if ($path !== NULL) {
      return $path;
    }

    throw new \RuntimeException('VAST_SSH_PRIVATE_KEY_CONTAINER_PATH is not set to a readable private key.');
  }

}
