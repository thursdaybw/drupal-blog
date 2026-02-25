<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\listing_publishing\Service\ListingPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingLocationBatchForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingPublisher $listingPublisher,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('drupal.listing_publishing.publisher'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'ai_book_listing_location_batch_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $statusFilter = $form_state->getValue('status_filter') ?? 'ready_to_shelve';

    $form['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status filter'),
      '#options' => AiBookListing::statusAllowedValues(),
      '#default_value' => $statusFilter,
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['listings_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-batch-listings'],
    ];

    $form['listings_container']['listings'] = [
      '#type' => 'tableselect',
      '#header' => [
        'title' => $this->t('Title'),
        'author' => $this->t('Author'),
        'price' => $this->t('Price'),
        'location' => $this->t('Current location'),
        'created' => $this->t('Created'),
      ],
      '#options' => $this->buildReadyToShelveOptions($statusFilter),
      '#empty' => $this->t('No listings are ready for shelving at the moment.'),
      '#multiple' => TRUE,
      '#default_value' => [],
    ];

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage location'),
      '#description' => $this->t('Set the shelf or bin code to apply to the selected listings and publish them.'),
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Set location and publish'),
      '#button_type' => 'primary',
    ];

    $form['#attached']['library'][] = 'ai_listing/location_table';

    return $form;
  }

  public function updateListingsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['listings_container'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('listings') ?? []);
    if (empty($selected)) {
      $this->messenger()->addError($this->t('Select at least one listing to update.'));
      $form_state->setRebuild();
      return;
    }

    $location = trim((string) $form_state->getValue('location'));
    if ($location === '') {
      $this->messenger()->addError($this->t('Provide a storage location before submitting.'));
      $form_state->setRebuild();
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_book_listing');
    $success = 0;
    $errors = [];

    foreach ($selected as $id) {
      /** @var \Drupal\ai_listing\Entity\AiBookListing|null $listing */
      $listing = $storage->load($id);
      if (!$listing) {
        continue;
      }

      $listing->set('storage_location', $location);
      $listing->save();

      try {
        $result = $this->listingPublisher->publish($listing);
      }
      catch (\Throwable $e) {
        $listing->set('status', 'failed');
        $listing->save();
        $errors[] = $this->t('Listing %title failed: %reason', [
          '%title' => $listing->label(),
          '%reason' => $e->getMessage(),
        ]);
        continue;
      }

      if (!$result->isSuccess()) {
        $listing->set('status', 'failed');
        $listing->save();
        $errors[] = $this->t('Listing %title failed: %reason', [
          '%title' => $listing->label(),
          '%reason' => $result->getMessage(),
        ]);
        continue;
      }

      $listing->set('ebay_item_id', $result->getMarketplaceId());
      $listing->set('status', 'published');
      $listing->save();
      $success++;
    }

    if ($success > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $success,
        'Published one listing.',
        'Published @count listings.'
      ));
    }

    foreach ($errors as $message) {
      $this->messenger()->addError($message);
    }

    $form_state->setRebuild();
  }

  private function buildReadyToShelveOptions(string $status): array {
    $storage = $this->entityTypeManager->getStorage('ai_book_listing');
    $items = $storage->loadByProperties(['status' => $status]);
    uasort($items, fn($a, $b) => $a->get('created')->value <=> $b->get('created')->value);

    $options = [];
    foreach ($items as $listing) {
      $link = Link::fromTextAndUrl(
        $listing->label() ?: $this->t('Untitled listing'),
        Url::fromRoute('entity.ai_book_listing.canonical', ['ai_book_listing' => $listing->id()])
      );

      $options[$listing->id()] = [
        'title' => $link->toString(),
        'author' => $listing->get('author')->value ?: $this->t('Unknown'),
        'price' => $listing->get('price')->value ?? $this->t('—'),
        'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
        'created' => $this->dateFormatter->format((int) $listing->get('created')->value),
      ];
    }

    return $options;
  }
}
