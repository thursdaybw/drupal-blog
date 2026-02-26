<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingPublishUpdateConfirmForm extends ConfirmFormBase implements ContainerInjectionInterface {

  private ?PrivateTempStoreFactory $tempStoreFactory = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->tempStoreFactory = $container->get('tempstore.private');
    return $form;
  }

  public function getFormId(): string {
    return 'ai_book_listing_publish_update_confirm_form';
  }

  public function getQuestion(): string {
    $payload = $this->getPayload();
    $missingCount = (int) ($payload['missing_location_count'] ?? 0);
    $selectedCount = (int) ($payload['selected_count'] ?? 0);

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

    return (string) $this->t(
      'These listings can be published, but the SKU will not include a storage location until you set one and republish. Continue only if that is intentional. (@count missing location)',
      ['@count' => $missingCount]
    );
  }

  public function getConfirmText(): string {
    return (string) $this->t('Continue publish/update');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('ai_listing.location_batch');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $payload = $this->getPayload();
    $listingIds = array_map('intval', $payload['listing_ids'] ?? []);
    $setLocation = (bool) ($payload['set_location'] ?? FALSE);
    $location = (string) ($payload['location'] ?? '');

    $this->getConfirmTempStore()->delete(AiBookListingLocationBatchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);

    if ($listingIds === []) {
      $this->messenger()->addError($this->t('The publish/update confirmation expired. Please select the listings again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    batch_set(AiBookListingLocationBatchForm::buildListingBatchDefinition($listingIds, $setLocation, $location));
  }

  private function getPayload(): array {
    $payload = $this->getConfirmTempStore()->get(AiBookListingLocationBatchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY);
    return is_array($payload) ? $payload : [];
  }

  private function getConfirmTempStore(): \Drupal\Core\TempStore\PrivateTempStore {
    if ($this->tempStoreFactory === null) {
      $this->tempStoreFactory = \Drupal::service('tempstore.private');
    }

    return $this->tempStoreFactory->get(AiBookListingLocationBatchForm::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_COLLECTION);
  }

}
