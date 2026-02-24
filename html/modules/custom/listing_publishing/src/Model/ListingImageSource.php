<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

use Drupal\file\Entity\File;

final class ListingImageSource {

  public function __construct(
    private readonly string $uri,
    private readonly string $filename,
  ) {}

  public static function fromFile(File $file): self {
    return new self(
      (string) $file->getFileUri(),
      (string) $file->getFilename(),
    );
  }

  public function getUri(): string {
    return $this->uri;
  }

  public function getFilename(): string {
    return $this->filename;
  }

}
