<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\marketplace_orders\Service\TransitionOrderLineWorkflowService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Inline POST form that applies one workflow transition to an order line.
 */
final class OrderLineWorkflowTransitionForm extends FormBase {

  public function __construct(
    private readonly TransitionOrderLineWorkflowService $transitionService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(TransitionOrderLineWorkflowService::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'marketplace_orders_order_line_workflow_transition_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $order_line_id
   * @param string|null $action
   * @param string|null $destination
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $order_line_id = NULL, ?string $action = NULL, ?string $destination = NULL): array {
    $normalizedOrderLineId = is_numeric($order_line_id) ? (int) $order_line_id : 0;
    $normalizedAction = trim((string) $action);
    $normalizedDestination = trim((string) ($destination ?? ''));

    $form['order_line_id'] = [
      '#type' => 'hidden',
      '#value' => $normalizedOrderLineId,
    ];

    $form['action_name'] = [
      '#type' => 'hidden',
      '#value' => $normalizedAction,
    ];

    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => $normalizedDestination,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->buildLabel($normalizedAction),
      '#attributes' => [
        'class' => [
          'button',
          'button--small',
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
    $orderLineId = (int) $form_state->getValue('order_line_id');
    $action = (string) $form_state->getValue('action_name');
    $destination = trim((string) $form_state->getValue('destination'));
    $actorUid = (int) $this->currentUser()->id();

    try {
      $state = $this->transitionService->transition($orderLineId, $action, $actorUid);
      $this->messenger()->addStatus((string) $this->t(
        'Order line @id updated to @status.',
        [
          '@id' => $state->getOrderLineId(),
          '@status' => $state->getWarehouseStatus(),
        ]
      ));
    }
    catch (\InvalidArgumentException $exception) {
      $this->messenger()->addError((string) $this->t(
        'Unable to apply transition: @message',
        ['@message' => $exception->getMessage()]
      ));
    }

    if ($destination !== '') {
      $form_state->setRedirectUrl(\Drupal\Core\Url::fromUserInput($destination));
      return;
    }

    $form_state->setRedirect('marketplace_orders.pick_pack_queue');
  }

  private function buildLabel(string $action): string {
    return match ($action) {
      'picked' => 'Picked',
      'packed' => 'Packed',
      'label_purchased' => 'Label Purchased',
      'dispatched' => 'Dispatched',
      default => 'Apply',
    };
  }

}
