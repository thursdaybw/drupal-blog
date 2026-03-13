<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\marketplace_orders\Service\SyncMarketplaceOrdersSinceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Embedded operator form for pulling current marketplace orders.
 */
final class FetchMarketplaceOrdersForm extends FormBase {

  public function __construct(
    private readonly SyncMarketplaceOrdersSinceService $syncMarketplaceOrdersSinceService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(SyncMarketplaceOrdersSinceService::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'marketplace_orders_fetch_marketplace_orders_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $marketplace
   * @param string|null $destination
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $marketplace = NULL, ?string $destination = NULL): array {
    $normalizedMarketplace = trim((string) $marketplace);
    if ($normalizedMarketplace === '') {
      $normalizedMarketplace = 'ebay';
    }

    $form['marketplace'] = [
      '#type' => 'hidden',
      '#value' => $normalizedMarketplace,
    ];

    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => trim((string) ($destination ?? '')),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch orders'),
      '#attributes' => [
        'class' => [
          'button',
          'button--primary',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $marketplace = trim((string) $form_state->getValue('marketplace'));
    $destination = trim((string) $form_state->getValue('destination'));

    try {
      $summary = $this->syncMarketplaceOrdersSinceService->sync($marketplace);
      $this->messenger()->addStatus((string) $this->t(
        'Fetched @count orders from @marketplace. Next sync boundary: @timestamp.',
        [
          '@count' => $summary->getFetchedOrders(),
          '@marketplace' => $summary->getMarketplace(),
          '@timestamp' => gmdate('Y-m-d H:i:s', $summary->getNextSinceTimestamp()),
        ]
      ));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError((string) $this->t(
        'Order fetch failed: @message',
        ['@message' => $exception->getMessage()]
      ));
    }

    if ($destination !== '') {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
      return;
    }

    $form_state->setRedirect('marketplace_orders.pick_pack_queue');
  }

}
