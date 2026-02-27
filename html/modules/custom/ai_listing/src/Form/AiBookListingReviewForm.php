<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AiBookListingReviewForm extends AiListingReviewFormBase {

  public function getFormId(): string {
    return 'ai_book_listing_review_form';
  }

  protected function resolveListing(?BbAiListing $listing): BbAiListing {
    if ($listing instanceof BbAiListing && $listing->bundle() === 'book') {
      return $listing;
    }

    $routeValue = $this->getRouteMatch()->getParameter('bb_ai_listing');
    if ($routeValue instanceof BbAiListing && $routeValue->bundle() === 'book') {
      return $routeValue;
    }

    $routeId = (int) $routeValue;
    if ($routeId > 0) {
      $loaded = $this->entityTypeManager->getStorage('bb_ai_listing')->load($routeId);
      if ($loaded instanceof BbAiListing && $loaded->bundle() === 'book') {
        return $loaded;
      }
    }

    throw new NotFoundHttpException('Book listing not found.');
  }

  protected function buildPhotoItems(BbAiListing $listing): array {
    $items = [
      '#type' => 'container',
    ];

    $listingImageItems = $this->buildListingImagePhotoItems($listing);
    $items['listing_image_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h4',
      '#value' => 'Listing images',
    ];
    $items['listing_image_items'] = $listingImageItems !== []
      ? $listingImageItems
      : ['#markup' => '<p><em>No listing images found.</em></p>'];

    return $items;
  }

  protected function savePhotoSelections(BbAiListing $listing, FormStateInterface $form_state): void {
    $listingImageItems = (array) $form_state->getValue(['photos', 'items', 'listing_image_items'], []);
    if ($listingImageItems === []) {
      return;
    }

    foreach ($this->loadListingImagesForReview($listing) as $listingImage) {
      $listingImageId = (int) $listingImage->id();
      if ($listingImageId === 0) {
        continue;
      }

      $itemKey = 'listing_image_' . $listingImageId;
      $postedItem = $listingImageItems[$itemKey] ?? null;
      if (!is_array($postedItem)) {
        continue;
      }

      $listingImage->set('is_metadata_source', !empty($postedItem['is_metadata_source']));
      $listingImage->save();
    }
  }

  protected function getAddRouteName(): string {
    return 'ai_listing.add';
  }

  /**
   * @return array<string,mixed>
   */
  private function buildListingImagePhotoItems(BbAiListing $listing): array {
    $listingImages = $this->loadListingImagesForReview($listing);
    if ($listingImages === []) {
      return [];
    }

    $items = [
      '#type' => 'container',
    ];

    foreach ($listingImages as $listingImage) {
      $listingImageId = (int) $listingImage->id();
      $file = $listingImage->get('file')->entity;
      if ($listingImageId === 0 || $file === null) {
        continue;
      }

      $itemKey = 'listing_image_' . $listingImageId;
      $items[$itemKey] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'display:inline-block; margin:0 8px 12px 0; vertical-align:top;',
        ],
      ];
      $items[$itemKey]['thumbnail'] = [
        '#theme' => 'image_style',
        '#style_name' => 'thumbnail',
        '#uri' => $file->getFileUri(),
      ];
      $items[$itemKey]['is_metadata_source'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use for metadata'),
        '#default_value' => (bool) $listingImage->get('is_metadata_source')->value,
      ];
    }

    return $items;
  }

  /**
   * @return EntityInterface[]
   */
  private function loadListingImagesForReview(BbAiListing $listing): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $listingImageStorage = $this->entityTypeManager->getStorage('listing_image');
    $ids = $listingImageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    return $listingImageStorage->loadMultiple($ids);
  }

}

