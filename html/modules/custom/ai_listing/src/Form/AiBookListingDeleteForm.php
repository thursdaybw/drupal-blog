<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

final class AiBookListingDeleteForm extends ContentEntityDeleteForm {

  public function getQuestion(): string {
    $label = $this->getEntity()->label();
    return (string) $this->t('Are you sure you want to delete the AI book listing for %title?', ['%title' => $label ?: $this->t('untitled book')]);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $listingId = (int) $this->getEntity()->id();
    if (!$this->listingHasInventoryAndMarketplaceData($listingId)) {
      return;
    }

    $form_state->setErrorByName('', (string) $this->t('This listing cannot be deleted because Drupal has inventory and marketplace publication records for it. Remove marketplace records first.'));
  }

  private function listingHasInventoryAndMarketplaceData(int $listingId): bool {
    $entityTypeManager = \Drupal::entityTypeManager();

    $inventoryIds = $entityTypeManager->getStorage('ai_listing_inventory_sku')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('ai_book_listing', $listingId)
      ->range(0, 1)
      ->execute();

    if ($inventoryIds === []) {
      return FALSE;
    }

    $publicationIds = $entityTypeManager->getStorage('ai_marketplace_publication')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('ai_book_listing', $listingId)
      ->range(0, 1)
      ->execute();

    return $publicationIds !== [];
  }

}
