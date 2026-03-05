<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingPublishUpdateConfirmForm extends ConfirmFormBase implements ContainerInjectionInterface {

  private ?PrivateTempStoreFactory $tempStoreFactory = null;
  private ?EntityTypeManagerInterface $entityTypeManager = null;
  /** @var array<string,string>|null */
  private ?array $listingTypeLabels = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->tempStoreFactory = $container->get('tempstore.private');
    $form->entityTypeManager = $container->get('entity_type.manager');
    return $form;
  }

  public function getFormId(): string {
    return 'ai_book_listing_publish_update_confirm_form';
  }

  public function getQuestion(): string {
    $payload = $this->getPayload();
    $missingCount = (int) ($payload['missing_location_count'] ?? 0);
    $selectedCount = (int) ($payload['selected_count'] ?? 0);

    if ($missingCount === 0) {
      return (string) $this->t(
        'Publish/update @selected selected listings?',
        [
          '@selected' => $selectedCount,
        ]
      );
    }

    return (string) $this->t(
      'Publish/update @selected listings when @missing of them have no storage location?',
      [
        '@selected' => $selectedCount,
        '@missing' => $missingCount,
      ]
    );
  }

  public function getDescription(): string {
    $payload = $this->getPayload();
    $missingCount = (int) ($payload['missing_location_count'] ?? 0);

    if ($missingCount === 0) {
      return (string) $this->t('Review the selected listings, then continue the publish/update batch.');
    }

    return (string) $this->t(
      'These listings can be published, but the SKU will not include a storage location until you set one and republish. Continue only if that is intentional. (@count missing location)',
      ['@count' => $missingCount]
    );
  }

  public function getConfirmText(): string {
    return (string) $this->t('Continue publish/update');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('ai_listing.workbench');
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $listingRows = $this->buildSelectedListingRows();
    if ($listingRows !== []) {
      $form['selected_listings'] = [
        '#type' => 'details',
        '#title' => $this->t('Selected listings'),
        '#open' => TRUE,
      ];
      $form['selected_listings']['table'] = [
        '#theme' => 'table',
        '#header' => [
          $this->t('Type'),
          $this->t('Entity ID'),
          $this->t('Listing code'),
          $this->t('Title'),
          $this->t('Current location'),
        ],
        '#rows' => $listingRows,
        '#empty' => $this->t('No selected listings found.'),
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $payload = $this->getPayload();
    $listingIds = array_map('intval', $payload['listing_ids'] ?? []);
    $setLocation = (bool) ($payload['set_location'] ?? FALSE);
    $location = (string) ($payload['location'] ?? '');
    $operationMode = (string) ($payload['operation_mode'] ?? 'publish_update');

    $this->getConfirmTempStore()->delete(AiListingWorkbenchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);

    if ($listingIds === []) {
      $this->messenger()->addError($this->t('The publish/update confirmation expired. Please select the listings again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    batch_set(AiListingWorkbenchForm::buildListingBatchDefinition($listingIds, $setLocation, $location, $operationMode));
  }

  private function getPayload(): array {
    $payload = $this->getConfirmTempStore()->get(AiListingWorkbenchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);
    return is_array($payload) ? $payload : [];
  }

  /**
   * @return array<int,array<int,string|\Drupal\Component\Render\MarkupInterface>>
   */
  private function buildSelectedListingRows(): array {
    $payload = $this->getPayload();
    $listingIds = array_map('intval', $payload['listing_ids'] ?? []);
    if ($listingIds === []) {
      return [];
    }

    $listings = $this->getEntityTypeManager()->getStorage('bb_ai_listing')->loadMultiple($listingIds);
    $rows = [];

    foreach ($listingIds as $listingId) {
      $listing = $listings[$listingId] ?? null;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $rows[] = [
        $this->buildTypeLabel((string) $listing->bundle()),
        (string) $listingId,
        trim((string) ($listing->get('listing_code')->value ?? '')) ?: (string) $this->t('Unset'),
        Link::fromTextAndUrl(
          $this->buildListingLabel($listing),
          Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $listingId])
        )->toString(),
        trim((string) ($listing->get('storage_location')->value ?? '')) ?: (string) $this->t('Unset yet'),
      ];
    }

    return $rows;
  }

  private function buildListingLabel(BbAiListing $listing): string {
    if ($listing->bundle() === 'book_bundle') {
      return (string) ($listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled bundle'));
    }

    if ($listing->bundle() === 'book') {
      return (string) ($listing->get('field_full_title')->value ?: $listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled listing'));
    }

    return (string) ($listing->label() ?: $listing->get('ebay_title')->value ?: $this->t('Untitled listing'));
  }

  private function buildTypeLabel(string $listingType): string {
    $label = $this->getListingTypeLabels()[$listingType] ?? NULL;
    if (is_string($label) && $label !== '') {
      return $label;
    }

    return ucfirst(str_replace('_', ' ', $listingType));
  }

  /**
   * @return array<string,string>
   */
  private function getListingTypeLabels(): array {
    if ($this->listingTypeLabels !== null) {
      return $this->listingTypeLabels;
    }

    $labels = [];
    $types = $this->getEntityTypeManager()->getStorage('bb_ai_listing_type')->loadMultiple();
    foreach ($types as $type) {
      $id = (string) $type->id();
      $label = (string) $type->label();
      if ($id === '' || $label === '') {
        continue;
      }

      $labels[$id] = $label;
    }

    $this->listingTypeLabels = $labels;
    return $this->listingTypeLabels;
  }

  private function getConfirmTempStore(): \Drupal\Core\TempStore\PrivateTempStore {
    if ($this->tempStoreFactory === null) {
      $this->tempStoreFactory = \Drupal::service('tempstore.private');
    }

    return $this->tempStoreFactory->get(AiListingWorkbenchForm::WORKBENCH_TEMPSTORE_COLLECTION);
  }

  private function getEntityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === null) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }

    return $this->entityTypeManager;
  }

}
