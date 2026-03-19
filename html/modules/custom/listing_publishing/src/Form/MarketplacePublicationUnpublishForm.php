<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\listing_publishing\Service\MarketplaceUnpublishService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Inline form for marketplace publication takedown.
 */
final class MarketplacePublicationUnpublishForm extends FormBase {

  public function __construct(
    private readonly MarketplaceUnpublishService $marketplaceUnpublishService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(MarketplaceUnpublishService::class),
    );
  }

  public function getFormId(): string {
    return 'listing_publishing_marketplace_publication_unpublish_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $publication_id = NULL, ?string $destination = NULL): array {
    $form['publication_id'] = [
      '#type' => 'hidden',
      '#value' => is_numeric($publication_id) ? (int) $publication_id : 0,
    ];
    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => trim((string) ($destination ?? '')),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Unpublish'),
      '#attributes' => [
        'class' => [
          'button',
          'button--danger',
          'button--small',
        ],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $publicationId = (int) $form_state->getValue('publication_id');
    $destination = trim((string) $form_state->getValue('destination'));

    try {
      $result = $this->marketplaceUnpublishService->unpublishPublication($publicationId);
      if ($result->alreadyUnpublished) {
        $this->messenger()->addStatus((string) $this->t(
          '@marketplace SKU @sku was already unpublished on the marketplace. Removed the local publication record.',
          [
            '@marketplace' => $result->marketplaceKey,
            '@sku' => $result->sku,
          ]
        ));
      }
      else {
        $this->messenger()->addStatus((string) $this->t(
          'Unpublished @marketplace SKU @sku and removed the local publication record.',
          [
            '@marketplace' => $result->marketplaceKey,
            '@sku' => $result->sku,
          ]
        ));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError((string) $this->t(
        'Unable to unpublish marketplace record: @message',
        ['@message' => $exception->getMessage()]
      ));
    }

    if ($destination !== '') {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
      return;
    }

    $form_state->setRedirect('<front>');
  }

}
