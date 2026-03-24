<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiListingLocationUpdateConfirmForm extends FormBase implements ContainerInjectionInterface {

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
    return 'ai_listing_location_update_confirm_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $payload = $this->getPayload();
    $selection = $this->getSelectionFromPayload($payload);
    $listingIds = $this->extractListingIdsFromSelection($selection, $payload);

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
      '#markup' => '<p>' . $this->t('Set the new storage location for the selected listings. This action updates local inventory metadata only and does not publish/update them.') . '</p>',
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

    $form['location_term'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Storage location'),
      '#description' => $this->t('Select an existing registered storage location to apply to the selected listings.'),
      '#target_type' => 'taxonomy_term',
      '#selection_handler' => 'default:taxonomy_term',
      '#selection_settings' => [
        'target_bundles' => ['storage_location' => 'storage_location'],
      ],
      '#tags' => FALSE,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update location'),
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
    if (!$this->resolveLocationTerm($form_state->getValue('location_term')) instanceof Term) {
      $form_state->setErrorByName('location_term', $this->t('Select an existing registered storage location before submitting.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $payload = $this->getPayload();
    $selection = $this->getSelectionFromPayload($payload);
    $locationTerm = $this->resolveLocationTerm($form_state->getValue('location_term'));
    if (!$locationTerm instanceof Term) {
      $this->messenger()->addError($this->t('Select an existing registered storage location before submitting.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $this->getConfirmTempStore()->delete(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);

    if ($selection === []) {
      $this->messenger()->addError($this->t('The location update selection expired. Please select the listings again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    batch_set(AiListingWorkbenchForm::buildListingBatchDefinition($selection, TRUE, $locationTerm->label(), 'location_only', (int) $locationTerm->id()));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  private function resolveLocationTerm(mixed $submittedValue): ?Term {
    if ($submittedValue instanceof Term) {
      return $submittedValue;
    }

    if (is_int($submittedValue)) {
      $term = $this->getEntityTypeManager()->getStorage('taxonomy_term')->load($submittedValue);
      return $term instanceof Term ? $term : null;
    }

    if (is_array($submittedValue) && isset($submittedValue['target_id'])) {
      return $this->resolveLocationTerm($submittedValue['target_id']);
    }

    if (is_array($submittedValue) && isset($submittedValue[0])) {
      return $this->resolveLocationTerm($submittedValue[0]);
    }

    if (is_string($submittedValue)) {
      $input = trim($submittedValue);
      if ($input === '') {
        return null;
      }

      if (ctype_digit($input)) {
        $term = $this->getEntityTypeManager()->getStorage('taxonomy_term')->load((int) $input);
        return $term instanceof Term ? $term : null;
      }

      $termId = EntityAutocomplete::extractEntityIdFromAutocompleteInput($input);
      if ($termId !== null) {
        $term = $this->getEntityTypeManager()->getStorage('taxonomy_term')->load((int) $termId);
        return $term instanceof Term ? $term : null;
      }

      $matches = $this->getEntityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'storage_location',
        'name' => $input,
      ]);
      $term = reset($matches);
      return $term instanceof Term ? $term : null;
    }

    return null;
  }

  private function getPayload(): array {
    $payload = $this->getConfirmTempStore()->get(AiListingWorkbenchForm::LOCATION_CONFIRM_TEMPSTORE_KEY);
    return is_array($payload) ? $payload : [];
  }

  /**
   * @param array<string,mixed> $payload
   *
   * @return array<int,array{listing_type:string,id:int}>
   */
  private function getSelectionFromPayload(array $payload): array {
    $selection = $payload['selection'] ?? [];
    if (!is_array($selection)) {
      return [];
    }

    $normalizedSelection = [];
    foreach ($selection as $item) {
      if (!is_array($item)) {
        continue;
      }
      if (!isset($item['listing_type'], $item['id'])) {
        continue;
      }

      $listingType = trim((string) $item['listing_type']);
      $listingId = (int) $item['id'];
      if ($listingType === '' || $listingId <= 0) {
        continue;
      }

      $normalizedSelection[] = [
        'listing_type' => $listingType,
        'id' => $listingId,
      ];
    }

    return $normalizedSelection;
  }

  /**
   * @param array<int,array{listing_type:string,id:int}> $selection
   * @param array<string,mixed> $payload
   *
   * @return int[]
   */
  private function extractListingIdsFromSelection(array $selection, array $payload): array {
    if ($selection !== []) {
      return array_map(
        static fn(array $item): int => (int) $item['id'],
        $selection
      );
    }

    return array_map('intval', $payload['listing_ids'] ?? []);
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

  private function buildListingLabel(\Drupal\ai_listing\Entity\BbAiListing $listing): string {
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
