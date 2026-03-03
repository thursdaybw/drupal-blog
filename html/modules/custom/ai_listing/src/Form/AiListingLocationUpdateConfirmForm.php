<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiListingLocationUpdateConfirmForm extends FormBase implements ContainerInjectionInterface {

  private ?PrivateTempStoreFactory $tempStoreFactory = null;
  private ?EntityTypeManagerInterface $entityTypeManager = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->tempStoreFactory = $container->get('tempstore.private');
    $form->entityTypeManager = $container->get('entity_type.manager');
    return $form;
  }

  public function getFormId(): string {
    return 'ai_listing_location_update_confirm_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $payload = $this->getPayload();
    $listingIds = array_map('intval', $payload['listing_ids'] ?? []);

    if ($listingIds === []) {
      $this->messenger()->addError($this->t('The location update selection expired. Please select the listings again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return $form;
    }

    $form['summary'] = [
      '#type' => 'container',
    ];
    $form['summary']['selected_count'] = [
      '#markup' => '<p><strong>' . $this->t('Selected listings:') . '</strong> ' . count($listingIds) . '</p>',
    ];
    $form['summary']['description'] = [
      '#markup' => '<p>' . $this->t('Set the new storage location, then run the normal publish/update flow for the selected listings.') . '</p>',
    ];
    $form['summary']['selected_listings'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Type'),
        $this->t('Entity ID'),
        $this->t('Listing code'),
        $this->t('Title'),
        $this->t('Current location'),
      ],
      '#rows' => $this->buildSelectedListingRows($listingIds),
      '#empty' => $this->t('No selected listings found.'),
    ];

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage location'),
      '#description' => $this->t('Set the shelf or bin code to apply to the selected listings.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update location and publish/update'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $location = trim((string) $form_state->getValue('location'));
    if ($location === '') {
      $form_state->setErrorByName('location', $this->t('Provide a storage location before submitting.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $payload = $this->getPayload();
    $listingIds = array_map('intval', $payload['listing_ids'] ?? []);
    $location = trim((string) $form_state->getValue('location'));

    $this->getConfirmTempStore()->delete(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);

    if ($listingIds === []) {
      $this->messenger()->addError($this->t('The location update selection expired. Please select the listings again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    batch_set(AiListingWorkbenchForm::buildListingBatchDefinition($listingIds, TRUE, $location, 'publish_update'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  private function getPayload(): array {
    $payload = $this->getConfirmTempStore()->get(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);
    return is_array($payload) ? $payload : [];
  }

  private function getConfirmTempStore(): \Drupal\Core\TempStore\PrivateTempStore {
    if ($this->tempStoreFactory === null) {
      $this->tempStoreFactory = \Drupal::service('tempstore.private');
    }

    return $this->tempStoreFactory->get(AiListingWorkbenchForm::WORKBENCH_TEMPSTORE_COLLECTION);
  }

  /**
   * @param int[] $listingIds
   * @return array<int,array<int,string|\Drupal\Component\Render\MarkupInterface>>
   */
  private function buildSelectedListingRows(array $listingIds): array {
    if ($listingIds === []) {
      return [];
    }

    $listings = $this->getEntityTypeManager()->getStorage('bb_ai_listing')->loadMultiple($listingIds);
    $rows = [];

    foreach ($listingIds as $listingId) {
      $listing = $listings[$listingId] ?? null;
      if (!$listing instanceof \Drupal\ai_listing\Entity\BbAiListing) {
        continue;
      }

      $rows[] = [
        $listing->bundle() === 'book_bundle' ? (string) $this->t('Book bundle') : (string) $this->t('Book'),
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

  private function buildListingLabel(\Drupal\ai_listing\Entity\BbAiListing $listing): string {
    if ($listing->bundle() === 'book_bundle') {
      return (string) ($listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled bundle'));
    }

    return (string) ($listing->get('field_full_title')->value ?: $listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled listing'));
  }

  private function getEntityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === null) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }

    return $this->entityTypeManager;
  }

  private function getCancelUrl(): Url {
    return Url::fromRoute('ai_listing.workbench');
  }

}
