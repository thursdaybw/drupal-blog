<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AiBookBundleListingReviewForm extends AiListingReviewFormBase {

  public function getFormId(): string {
    return 'ai_book_bundle_listing_review_form';
  }

  protected function resolveListing(?BbAiListing $listing): BbAiListing {
    if ($listing instanceof BbAiListing && $listing->bundle() === 'book_bundle') {
      return $listing;
    }

    $routeValue = $this->getRouteMatch()->getParameter('bb_ai_listing');
    if ($routeValue instanceof BbAiListing && $routeValue->bundle() === 'book_bundle') {
      return $routeValue;
    }

    $routeId = (int) $routeValue;
    if ($routeId > 0) {
      $loaded = $this->entityTypeManager->getStorage('bb_ai_listing')->load($routeId);
      if ($loaded instanceof BbAiListing && $loaded->bundle() === 'book_bundle') {
        return $loaded;
      }
    }

    throw new NotFoundHttpException('Book bundle listing not found.');
  }

  protected function buildPhotoItems(BbAiListing $listing): array {
    return [
      'bundle_listing_images' => $this->buildBundleListingImages($listing),
      'bundle_item_images' => $this->buildBundleItemImages($listing),
    ];
  }

  protected function savePhotoSelections(BbAiListing $listing, FormStateInterface $form_state): void {
    $bundleListingPostedItems = (array) $form_state->getValue(['photos', 'items', 'bundle_listing_images', 'items'], []);
    $this->saveMetadataSourceSelections(
      $this->loadListingImages('bb_ai_listing', (int) $listing->id()),
      $bundleListingPostedItems
    );

    $bundleItemPostedItems = (array) $form_state->getValue(['photos', 'items', 'bundle_item_images', 'items'], []);
    $bundleItems = $this->entityTypeManager
      ->getStorage('ai_book_bundle_item')
      ->loadByProperties(['bundle_listing' => (int) $listing->id()]);

    foreach ($bundleItems as $bundleItem) {
      $bundleItemId = (int) $bundleItem->id();
      if ($bundleItemId <= 0) {
        continue;
      }

      $bundleItemKey = 'bundle_item_' . $bundleItemId;
      $postedImages = (array) ($bundleItemPostedItems[$bundleItemKey]['images'] ?? []);
      $images = $this->loadListingImages('ai_book_bundle_item', $bundleItemId);
      $this->saveMetadataSourceSelections($images, $postedImages);
    }
  }

  protected function validatePhotoSelections(FormStateInterface $form_state): void {
    $bundleItemPostedItems = (array) $form_state->getValue(['photos', 'items', 'bundle_item_images', 'items'], []);
    if ($bundleItemPostedItems === []) {
      $form_state->setErrorByName('photos][items][bundle_item_images][items', 'Each bundle item must have at least one image selected for metadata.');
      return;
    }

    foreach ($bundleItemPostedItems as $bundleItemKey => $bundleItem) {
      if (!is_array($bundleItem)) {
        continue;
      }

      $postedImages = (array) ($bundleItem['images'] ?? []);
      $hasMetadataSource = FALSE;

      foreach ($postedImages as $postedImage) {
        if (is_array($postedImage) && !empty($postedImage['is_metadata_source'])) {
          $hasMetadataSource = TRUE;
          break;
        }
      }

      if ($hasMetadataSource) {
        continue;
      }

      $form_state->setErrorByName(
        'photos][items][bundle_item_images][items][' . $bundleItemKey . '][images',
        'Each bundle item must have at least one image selected for metadata.'
      );
    }
  }

  protected function getAddRouteName(): string {
    return 'ai_listing.bundle_add';
  }

  /**
   * @return array<string,mixed>
   */
  private function buildBundleListingImages(BbAiListing $listing): array {
    $images = $this->loadListingImages('bb_ai_listing', (int) $listing->id());
    $container = [
      '#type' => 'details',
      '#title' => $this->t('Bundle-level images'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    if ($images === []) {
      $container['empty'] = ['#markup' => '<p><em>' . $this->t('No bundle-level images found.') . '</em></p>'];
      return $container;
    }

    $container['items'] = ['#type' => 'container'];
    foreach ($images as $listingImage) {
      $listingImageId = (int) $listingImage->id();
      $file = $listingImage->get('file')->entity;
      if ($listingImageId === 0 || $file === null) {
        continue;
      }

      $imageKey = 'listing_image_' . $listingImageId;
      $container['items'][$imageKey] = $this->buildImageItemElement(
        $file->getFileUri(),
        (bool) $listingImage->get('is_metadata_source')->value
      );
    }

    return $container;
  }

  /**
   * @return array<string,mixed>
   */
  private function buildBundleItemImages(BbAiListing $listing): array {
    $container = [
      '#type' => 'details',
      '#title' => $this->t('Bundle item images'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $bundleItems = $this->entityTypeManager
      ->getStorage('ai_book_bundle_item')
      ->loadByProperties(['bundle_listing' => (int) $listing->id()]);
    if ($bundleItems === []) {
      $container['empty'] = ['#markup' => '<p><em>' . $this->t('No bundle item images found.') . '</em></p>'];
      return $container;
    }

    $container['items'] = ['#type' => 'container'];
    foreach ($bundleItems as $bundleItem) {
      $bundleItemId = (int) $bundleItem->id();
      if ($bundleItemId <= 0) {
        continue;
      }

      $bundleItemKey = 'bundle_item_' . $bundleItemId;
      $bundleItemTitle = trim((string) ($bundleItem->get('title')->value ?? ''));
      if ($bundleItemTitle === '') {
        $bundleItemTitle = $this->t('Book item @id', ['@id' => $bundleItemId])->render();
      }

      $container['items'][$bundleItemKey] = [
        '#type' => 'details',
        '#title' => $bundleItemTitle,
        '#open' => TRUE,
      ];

      $bundleItemAuthor = trim((string) ($bundleItem->get('author')->value ?? ''));
      $ebaySearchLink = $this->buildBundleItemEbaySearchLink($bundleItemTitle, $bundleItemAuthor);
      if ($ebaySearchLink !== '') {
        $container['items'][$bundleItemKey]['search_link'] = [
          '#type' => 'markup',
          '#markup' => $ebaySearchLink,
          '#prefix' => '<div class="ai-help">',
          '#suffix' => '</div>',
        ];
      }

      $itemImages = $this->loadListingImages('ai_book_bundle_item', $bundleItemId);
      if ($itemImages === []) {
        $container['items'][$bundleItemKey]['empty'] = [
          '#markup' => '<p><em>' . $this->t('No images found for this bundle item.') . '</em></p>',
        ];
        continue;
      }

      $container['items'][$bundleItemKey]['images'] = ['#type' => 'container'];
      foreach ($itemImages as $listingImage) {
        $listingImageId = (int) $listingImage->id();
        $file = $listingImage->get('file')->entity;
        if ($listingImageId === 0 || $file === null) {
          continue;
        }

        $imageKey = 'listing_image_' . $listingImageId;
        $container['items'][$bundleItemKey]['images'][$imageKey] = $this->buildImageItemElement(
          $file->getFileUri(),
          (bool) $listingImage->get('is_metadata_source')->value
        );
      }
    }

    return $container;
  }

  /**
   * @return array<string,mixed>
   */
  private function buildImageItemElement(string $uri, bool $isMetadataSource): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display:inline-block; margin:0 8px 12px 0; vertical-align:top;',
      ],
      'thumbnail' => [
        '#theme' => 'image_style',
        '#style_name' => 'thumbnail',
        '#uri' => $uri,
        '#attributes' => [
          'data-full-src' => $this->fileUrlGenerator->generateString($uri),
        ],
      ],
      'is_metadata_source' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Use for metadata'),
        '#default_value' => $isMetadataSource,
      ],
    ];
  }

  private function buildBundleItemEbaySearchLink(string $title, string $author): string {
    $query = trim($title . ' ' . $author);
    if ($query === '') {
      return '';
    }

    $url = 'https://www.ebay.com.au/sch/i.html?_nkw=' . UrlHelper::encodePath($query);
    $titleAttr = Html::escape($this->t('Search eBay for ~%query%~', ['%query%' => $query]));

    return sprintf(
      '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">Search eBay for %s</a>',
      $url,
      $titleAttr,
      Html::escape($query)
    );
  }

  /**
   * @return EntityInterface[]
   */
  private function loadListingImages(string $ownerTargetType, int $ownerTargetId): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('listing_image');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', $ownerTargetType)
      ->condition('owner.target_id', $ownerTargetId)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * @param EntityInterface[] $listingImages
   * @param array<string,mixed> $postedItems
   */
  private function saveMetadataSourceSelections(array $listingImages, array $postedItems): void {
    foreach ($listingImages as $listingImage) {
      $listingImageId = (int) $listingImage->id();
      if ($listingImageId <= 0) {
        continue;
      }

      $itemKey = 'listing_image_' . $listingImageId;
      $postedItem = $postedItems[$itemKey] ?? null;
      if (!is_array($postedItem)) {
        continue;
      }

      $listingImage->set('is_metadata_source', !empty($postedItem['is_metadata_source']));
      $listingImage->save();
    }
  }

}
