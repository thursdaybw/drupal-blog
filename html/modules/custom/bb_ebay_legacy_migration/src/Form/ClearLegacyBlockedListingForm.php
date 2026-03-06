<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Form;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyImportBlocklistService;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClearLegacyBlockedListingForm extends ConfirmFormBase {

  private string $listingId = '';

  public function __construct(
    private readonly EbayLegacyImportBlocklistService $blocklistService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bb_ebay_legacy_migration.import_blocklist_service'),
    );
  }

  public function getFormId(): string {
    return 'bb_ebay_legacy_migration_clear_blocked_listing_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Clear blocked row for eBay item @listing_id?', [
      '@listing_id' => $this->listingId,
    ]);
  }

  public function getDescription(): string {
    return (string) $this->t('Use this after you fix required fields in eBay. The listing will be eligible for retry on the next import run.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('bb_ebay_legacy_migration.blocked_report');
  }

  public function getConfirmText(): string {
    return (string) $this->t('Clear');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $listing_id = NULL): array {
    $this->listingId = trim((string) $listing_id);
    if ($this->listingId === '') {
      throw new \InvalidArgumentException('A listing ID is required.');
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->blocklistService->markClearedForRetry($this->listingId);
    $this->messenger()->addStatus((string) $this->t('Marked blocked row as cleared for eBay item @listing_id.', [
      '@listing_id' => $this->listingId,
    ]));
    $form_state->setRedirect('bb_ebay_legacy_migration.blocked_report');
  }

}
