<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

interface BookExtractionInterface {

  /**
   * @param string[] $imagePaths
   * @param string[]|null $metadataImagePaths
   *
   * @return array{
   *   metadata: array<string,mixed>|null,
   *   metadata_raw: string,
   *   condition: array{issues: string[]}|null,
   *   condition_raw: string
   * }
   */
  public function extract(array $imagePaths, ?array $metadataImagePaths = NULL): array;

}
