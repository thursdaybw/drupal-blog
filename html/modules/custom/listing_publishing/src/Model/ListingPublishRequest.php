<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

use Drupal\listing_publishing\Model\ListingImageSource;
final class ListingPublishRequest {

  public function __construct(
    private readonly string $sku,
    private readonly string $title,
    private readonly string $description,
    private readonly string $author,
    private readonly string $price,
    private readonly array $imageSources,
    private readonly array $imageUrls,
    private readonly int $quantity,
    private readonly string $condition,
    private readonly array $attributes,
  ) {}

  public function getSku(): string {
    return $this->sku;
  }

  public function getTitle(): string {
    return $this->title;
  }

  public function getDescription(): string {
    return $this->description;
  }

  public function getAuthor(): string {
    return $this->author;
  }

  public function getPrice(): string {
    return $this->price;
  }

  public function getImageUrls(): array {
    return $this->imageUrls;
  }

  /**
   * @return ListingImageSource[]
   */
  public function getImageSources(): array {
    return $this->imageSources;
  }

  public function withImageUrls(array $imageUrls): self {
    return new self(
      $this->sku,
      $this->title,
      $this->description,
      $this->author,
      $this->price,
      $this->imageSources,
      $imageUrls,
      $this->quantity,
      $this->condition,
      $this->attributes
    );
  }

  public function getQuantity(): int {
    return $this->quantity;
  }

  public function getCondition(): string {
    return $this->condition;
  }

  public function getAttributes(): array {
    return $this->attributes;
  }

  public function toArray(): array {
    return [
      'sku' => $this->sku,
      'title' => $this->title,
      'description' => $this->description,
      'author' => $this->author,
      'price' => $this->price,
      'imageSources' => $this->imageSources,
      'imageUrls' => $this->imageUrls,
      'quantity' => $this->quantity,
      'condition' => $this->condition,
      'attributes' => $this->attributes,
    ];
  }

}
