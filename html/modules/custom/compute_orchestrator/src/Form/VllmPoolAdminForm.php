<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Form;

use Drupal\compute_orchestrator\Batch\VllmPoolBatch;
use Drupal\compute_orchestrator\Service\VastCredentialProviderInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmPoolManager;
use Drupal\compute_orchestrator\Service\VllmPoolRepositoryInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin UI for inspecting and controlling the generic vLLM pool.
 */
final class VllmPoolAdminForm extends FormBase {

  private const STATE_IDLE_REAP_ENABLED = 'compute_orchestrator.vllm_pool.idle_reap_enabled';

  public function __construct(
    private readonly VllmPoolManager $poolManager,
    private readonly VastRestClientInterface $vastClient,
    private readonly VllmPoolRepositoryInterface $poolRepository,
    private readonly VastCredentialProviderInterface $credentialProvider,
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('compute_orchestrator.vllm_pool_manager'),
      $container->get('compute_orchestrator.vast_rest_client'),
      $container->get('compute_orchestrator.vllm_pool_repository'),
      $container->get('compute_orchestrator.vast_credential_provider'),
      $container->get('state'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'compute_orchestrator_vllm_pool_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>View and control the pooled Vast instances used for vLLM workloads. Client applications should call acquire/release and should not mutate pool timestamps directly.</p>',
    ];

    $hasApiKey = $this->resolveHasVastApiKey();
    $sshKeyPath = $this->resolveSshKeyPath();
    $hasReadableSshKey = ($sshKeyPath !== '' && is_readable($sshKeyPath));

    $form['diagnostics'] = [
      '#type' => 'details',
      '#title' => $this->t('Diagnostics'),
      '#open' => TRUE,
    ];

    $form['diagnostics']['env'] = [
      '#type' => 'table',
      '#header' => [$this->t('Key'), $this->t('Value')],
      '#rows' => $this->buildDiagnosticsRows(),
      '#empty' => $this->t('No diagnostics available.'),
    ];

    if (!$hasApiKey) {
      $settingsLink = Link::fromTextAndUrl(
        $this->t('Compute Orchestrator settings'),
        Url::fromRoute('compute_orchestrator.settings'),
      )->toString();
      $this->messenger()->addWarning($this->t('Vast API key is not configured. Pool actions that call Vast will fail until it is set in @settings_link or overridden in settings.php.', [
        '@settings_link' => $settingsLink,
      ]));
    }

    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Pool settings'),
      '#open' => TRUE,
    ];

    $form['settings']['idle_reap_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable post-lease reap on Drupal cron'),
      '#default_value' => (bool) $this->state->get(self::STATE_IDLE_REAP_ENABLED, TRUE),
      '#description' => $this->t('When enabled, Drupal cron will stop available running instances after the post-lease grace period.'),
    ];

    $form['settings']['idle_shutdown_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Post-lease grace period (seconds)'),
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $this->poolManager->getIdleShutdownSeconds(),
      '#description' => $this->t('Default 600 seconds. Set 0 to disable post-lease warm hold.'),
    ];

    $form['settings']['lease_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Lease TTL (seconds)'),
      '#min' => 60,
      '#step' => 1,
      '#default_value' => $this->poolManager->getLeaseTtlSeconds(),
      '#description' => $this->t('Default 600 seconds. Leased jobs should renew before expiry.'),
    ];

    $form['settings']['save_settings'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#submit' => ['::submitSaveSettings'],
    ];

    $form['settings']['configure_credentials'] = [
      '#type' => 'link',
      '#title' => $this->t('Configure Compute Orchestrator credentials'),
      '#url' => Url::fromRoute('compute_orchestrator.settings'),
    ];

    $form['pool'] = [
      '#type' => 'details',
      '#title' => $this->t('Pool inventory'),
      '#open' => TRUE,
    ];

    $form['pool']['action_help'] = [
      '#type' => 'item',
      '#title' => $this->t('Action help'),
      '#markup' => implode('', [
        '<ul>',
        '<li><strong>Acquire Qwen / Acquire Whisper</strong>: reuse a tracked instance first; if none are usable, provision a fresh Vast instance.</li>',
        '<li><strong>Renew lease</strong>: extend lease expiry for the selected leased instance.</li>',
        '<li><strong>Release lease</strong>: mark a tracked instance as available for reuse (does not stop or destroy).</li>',
        '<li><strong>Remove from pool</strong>: stop tracking only (does not stop or destroy).</li>',
        '<li><strong>Reap available (stop)</strong>: stop running instances that are available past the post-lease grace period.</li>',
        '<li><strong>Destroy instance</strong>: permanently destroy the Vast instance and remove it from pool tracking.</li>',
        '</ul>',
      ]),
    ];

    $instances = $this->poolManager->listInstances();

    $form['pool']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['pool']['actions']['acquire_qwen'] = [
      '#type' => 'submit',
      '#value' => $this->t('Acquire Qwen (qwen-vl)'),
      '#submit' => ['::submitAcquireQwen'],
      '#disabled' => !$hasReadableSshKey || !$hasApiKey,
      '#description' => !$hasReadableSshKey
        ? $this->t('Set VAST_SSH_KEY_PATH to a readable private key to enable acquire (SSH is used to start the model server).')
        : '',
    ];

    $form['pool']['actions']['acquire_whisper'] = [
      '#type' => 'submit',
      '#value' => $this->t('Acquire Whisper'),
      '#submit' => ['::submitAcquireWhisper'],
      '#disabled' => !$hasReadableSshKey || !$hasApiKey,
    ];

    $form['pool']['actions']['reap_dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reap available (dry run)'),
      '#submit' => ['::submitReapDryRun'],
      '#disabled' => !$hasApiKey,
    ];

    $form['pool']['actions']['reap_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reap available (stop)'),
      '#submit' => ['::submitReapNow'],
      '#disabled' => !$hasApiKey,
    ];

    $form['pool']['actions']['refresh_status'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh Vast status'),
      '#submit' => ['::submitRefreshStatus'],
      '#disabled' => !$hasApiKey,
    ];

    $form['pool']['selected'] = [
      '#type' => 'details',
      '#title' => $this->t('Selected instance actions'),
      '#open' => TRUE,
    ];

    $form['pool']['selected']['instance_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Instance'),
      '#options' => $this->buildInstanceOptions($instances),
      '#empty_option' => $this->t('- Select -'),
    ];

    $form['pool']['selected']['release'] = [
      '#type' => 'submit',
      '#value' => $this->t('Release lease'),
      '#submit' => ['::submitReleaseSelected'],
      '#states' => [
        'enabled' => [
          ':input[name="pool[selected][instance_id]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    $form['pool']['selected']['renew_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Renew TTL (seconds)'),
      '#min' => 60,
      '#step' => 1,
      '#default_value' => $this->poolManager->getLeaseTtlSeconds(),
    ];

    $form['pool']['selected']['renew'] = [
      '#type' => 'submit',
      '#value' => $this->t('Renew lease'),
      '#submit' => ['::submitRenewSelected'],
      '#states' => [
        'enabled' => [
          ':input[name="pool[selected][instance_id]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    $form['pool']['selected']['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove from pool'),
      '#submit' => ['::submitRemoveSelected'],
      '#states' => [
        'enabled' => [
          ':input[name="pool[selected][instance_id]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    $form['pool']['selected']['confirm_destroy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand destroy is permanent and will delete the Vast instance.'),
    ];

    $form['pool']['selected']['destroy'] = [
      '#type' => 'submit',
      '#value' => $this->t('Destroy instance'),
      '#submit' => ['::submitDestroySelected'],
      '#states' => [
        'enabled' => [
          ':input[name="pool[selected][instance_id]"]' => ['filled' => TRUE],
          ':input[name="pool[selected][confirm_destroy]"]' => ['checked' => TRUE],
        ],
      ],
      '#button_type' => 'danger',
    ];

    $form['pool']['table'] = [
      '#type' => 'table',
      '#header' => $this->buildPoolTableHeader(),
      '#rows' => $this->buildPoolTableRows($instances),
      '#empty' => $this->t('No pooled instances are registered.'),
    ];

    $form['active_runtime'] = [
      '#type' => 'details',
      '#title' => $this->t('Active runtime state'),
      '#open' => TRUE,
    ];
    $form['active_runtime']['state'] = [
      '#type' => 'table',
      '#header' => [$this->t('Key'), $this->t('Value')],
      '#rows' => $this->buildActiveRuntimeRows(),
      '#empty' => $this->t('No active runtime state present.'),
    ];

    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * Form API no-op submit; handlers are explicitly bound per button.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Saves pool-related settings into state.
   */
  public function submitSaveSettings(array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue('settings');
    $this->state->set(self::STATE_IDLE_REAP_ENABLED, (bool) ($values['idle_reap_enabled'] ?? TRUE));

    $idle = (int) ($values['idle_shutdown_seconds'] ?? $this->poolManager->getIdleShutdownSeconds());
    $this->state->set('compute_orchestrator.vllm_pool.idle_shutdown_seconds', max(0, $idle));
    $leaseTtl = (int) ($values['lease_ttl_seconds'] ?? $this->poolManager->getLeaseTtlSeconds());
    $this->state->set('compute_orchestrator.vllm_pool.lease_ttl_seconds', max(60, $leaseTtl));

    $this->messenger()->addStatus($this->t('Settings saved.'));
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Batch-acquires a pooled runtime for the Qwen image inference workload.
   */
  public function submitAcquireQwen(array &$form, FormStateInterface $form_state): void {
    $this->startAcquireBatch('qwen-vl');
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Batch-acquires a pooled runtime for the Whisper transcription workload.
   */
  public function submitAcquireWhisper(array &$form, FormStateInterface $form_state): void {
    $this->startAcquireBatch('whisper');
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Starts a Drupal batch that acquires a runtime for the requested workload.
   */
  private function startAcquireBatch(string $workload): void {
    $label = $workload === 'whisper' ? 'Whisper' : 'Qwen';
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Acquiring pooled runtime for @label', ['@label' => $label]))
      ->setInitMessage($this->t('Starting acquisition for @label. Phases: check pool, wake/provision instance, start workload, verify ready. This can take several minutes.', ['@label' => $label]))
      ->setProgressMessage($this->t('Running acquire flow for @label. Waiting for phase completion...', ['@label' => $label]))
      ->setErrorMessage($this->t('Acquisition failed for @label. Check the error details shown after the batch completes.', ['@label' => $label]))
      ->setFinishCallback([VllmPoolBatch::class, 'batchFinishedAcquire']);

    $builder->addOperation([VllmPoolBatch::class, 'batchAcquireOperation'], [
      $workload,
    ]);

    batch_set($builder->toArray());
  }

  /**
   * Runs the post-lease reaper in dry-run mode and reports matching instances.
   */
  public function submitReapDryRun(array &$form, FormStateInterface $form_state): void {
    $results = $this->poolManager->reapIdleAvailableInstances(NULL, TRUE);
    if ($results === []) {
      $this->messenger()->addStatus($this->t('No available pooled instances exceeded the post-lease grace period.'));
    }
    else {
      foreach ($results as $result) {
        $this->messenger()->addStatus($this->t('@id @action: @message', [
          '@id' => (string) ($result['contract_id'] ?? ''),
          '@action' => (string) ($result['action'] ?? ''),
          '@message' => (string) ($result['message'] ?? ''),
        ]));
      }
    }
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Runs the post-lease reaper and stops eligible available instances.
   */
  public function submitReapNow(array &$form, FormStateInterface $form_state): void {
    $results = $this->poolManager->reapIdleAvailableInstances(NULL, FALSE);
    if ($results === []) {
      $this->messenger()->addStatus($this->t('No available pooled instances exceeded the post-lease grace period.'));
    }
    else {
      foreach ($results as $result) {
        $this->messenger()->addStatus($this->t('@id @action: @message', [
          '@id' => (string) ($result['contract_id'] ?? ''),
          '@action' => (string) ($result['action'] ?? ''),
          '@message' => (string) ($result['message'] ?? ''),
        ]));
      }
    }
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Refreshes Vast status fields for known pool records.
   */
  public function submitRefreshStatus(array &$form, FormStateInterface $form_state): void {
    $instances = $this->poolManager->listInstances();
    $refreshed = 0;
    $removed = 0;
    foreach ($instances as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }
      try {
        $info = $this->vastClient->showInstance((string) $contractId);
        $record['vast_cur_state'] = (string) ($info['cur_state'] ?? '');
        $record['vast_actual_status'] = (string) ($info['actual_status'] ?? '');
        $record['last_seen_at'] = time();
        $record['last_error'] = '';
        $this->poolRepository->save($record);
        $refreshed++;
      }
      catch (\Throwable $exception) {
        if ($this->shouldRemoveRecordAfterRefreshFailure($exception)) {
          try {
            $this->poolManager->remove((string) $contractId);
            $removed++;
            $this->messenger()->addWarning($this->t('Removed @id from pool: Vast instance no longer exists.', [
              '@id' => (string) $contractId,
            ]));
            continue;
          }
          catch (\Throwable) {
            // Fall through to default error reporting below.
          }
        }
        $this->messenger()->addError($this->t('Failed to refresh @id: @message', [
          '@id' => (string) $contractId,
          '@message' => $exception->getMessage(),
        ]));
      }
    }

    if ($refreshed > 0) {
      $this->messenger()->addStatus($this->t('Refreshed Vast status for @count instance(s).', ['@count' => $refreshed]));
    }
    if ($removed > 0) {
      $this->messenger()->addStatus($this->t('Removed @count stale pool record(s) for externally destroyed instances.', ['@count' => $removed]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Returns TRUE when a refresh failure indicates stale/deleted Vast contract.
   */
  private function shouldRemoveRecordAfterRefreshFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    return str_contains($message, '"instances":null')
      || str_contains($message, 'not found')
      || str_contains($message, 'no such')
      || str_contains($message, 'unknown instance');
  }

  /**
   * Releases the selected pooled instance lease.
   */
  public function submitReleaseSelected(array &$form, FormStateInterface $form_state): void {
    $instanceId = (string) ($form_state->getValue(['pool', 'selected', 'instance_id']) ?? '');
    if ($instanceId === '') {
      $this->messenger()->addError($this->t('Select an instance first.'));
      $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
      return;
    }

    $this->poolManager->release($instanceId);
    $this->messenger()->addStatus($this->t('Released pooled instance @id.', ['@id' => $instanceId]));
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Renews the selected pooled instance lease.
   */
  public function submitRenewSelected(array &$form, FormStateInterface $form_state): void {
    $instanceId = (string) ($form_state->getValue(['pool', 'selected', 'instance_id']) ?? '');
    if ($instanceId === '') {
      $this->messenger()->addError($this->t('Select an instance first.'));
      $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
      return;
    }

    $ttl = (int) ($form_state->getValue(['pool', 'selected', 'renew_ttl_seconds']) ?? $this->poolManager->getLeaseTtlSeconds());
    try {
      $record = $this->poolManager->renewLease($instanceId, NULL, $ttl);
      $this->messenger()->addStatus($this->t('Renewed lease for @id until @expires.', [
        '@id' => $instanceId,
        '@expires' => $this->formatTimestamp((int) ($record['lease_expires_at'] ?? 0)),
      ]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Renew lease failed for @id: @message', [
        '@id' => $instanceId,
        '@message' => $exception->getMessage(),
      ]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Removes the selected pooled instance from pool tracking.
   */
  public function submitRemoveSelected(array &$form, FormStateInterface $form_state): void {
    $instanceId = (string) ($form_state->getValue(['pool', 'selected', 'instance_id']) ?? '');
    if ($instanceId === '') {
      $this->messenger()->addError($this->t('Select an instance first.'));
      $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
      return;
    }

    $this->poolManager->remove($instanceId);
    $this->messenger()->addStatus($this->t('Removed pooled instance @id.', ['@id' => $instanceId]));
    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Destroys the selected Vast instance and removes it from pool tracking.
   */
  public function submitDestroySelected(array &$form, FormStateInterface $form_state): void {
    $instanceId = (string) ($form_state->getValue(['pool', 'selected', 'instance_id']) ?? '');
    if ($instanceId === '') {
      $this->messenger()->addError($this->t('Select an instance first.'));
      $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
      return;
    }

    if (!(bool) $form_state->getValue(['pool', 'selected', 'confirm_destroy'])) {
      $this->messenger()->addError($this->t('Confirm destroy before running this action.'));
      $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
      return;
    }

    try {
      $result = $this->poolManager->destroyAndRemove($instanceId);
      $this->messenger()->addStatus($this->t('@id @action: @message', [
        '@id' => (string) ($result['contract_id'] ?? $instanceId),
        '@action' => (string) ($result['action'] ?? 'destroyed'),
        '@message' => (string) ($result['message'] ?? ''),
      ]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Destroy failed for @id: @message', [
        '@id' => $instanceId,
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl(Url::fromRoute('compute_orchestrator.vllm_pool_admin'));
  }

  /**
   * Builds a diagnostic table for required runtime prerequisites.
   *
   * @return array<int, array<int, string>>
   *   Table rows.
   */
  private function buildDiagnosticsRows(): array {
    $rows = [];

    $sshKeyPath = $this->resolveSshKeyPath();
    $rows[] = ['Vast API key configured', $this->resolveHasVastApiKey() ? 'yes' : 'no'];
    $rows[] = ['Vast API key source', 'Drupal config (settings.php may override config from VAST_API_KEY)'];
    $rows[] = ['VAST_SSH_KEY_PATH', $sshKeyPath !== '' ? $sshKeyPath : '(unset)'];
    $rows[] = ['SSH key readable', ($sshKeyPath !== '' && is_readable($sshKeyPath)) ? 'yes' : 'no'];

    $rows[] = ['Post-lease grace period seconds', (string) $this->poolManager->getIdleShutdownSeconds()];
    $rows[] = ['Lease TTL seconds', (string) $this->poolManager->getLeaseTtlSeconds()];
    $rows[] = [
      'Post-lease reap enabled (cron)',
      (bool) $this->state->get(self::STATE_IDLE_REAP_ENABLED, TRUE) ? 'yes' : 'no',
    ];

    return $rows;
  }

  /**
   * Returns TRUE if Vast API key is configured through Drupal config.
   */
  private function resolveHasVastApiKey(): bool {
    return $this->credentialProvider->getApiKey() !== NULL;
  }

  /**
   * Resolves SSH key path from environment.
   */
  private function resolveSshKeyPath(): string {
    $sshKeyPath = (string) ($_ENV['VAST_SSH_KEY_PATH'] ?? getenv('VAST_SSH_KEY_PATH') ?: '');
    return trim($sshKeyPath);
  }

  /**
   * Builds rows for the active pooled runtime.
   *
   * @return array<int, array<int, string>>
   *   Table rows.
   */
  private function buildActiveRuntimeRows(): array {
    $rows = [];
    $activeContract = trim((string) $this->state->get('compute_orchestrator.vllm_pool.active_contract_id', ''));
    if ($activeContract === '') {
      return $rows;
    }

    $rows[] = ['compute_orchestrator.vllm_pool.active_contract_id', $activeContract];
    $record = $this->poolRepository->get($activeContract);
    if ($record === NULL) {
      $rows[] = ['active_contract_record', 'missing from pool inventory'];
      return $rows;
    }

    $rows[] = ['lease_status', (string) ($record['lease_status'] ?? '')];
    $rows[] = ['lease_token', (string) ($record['lease_token'] ?? '')];
    $rows[] = ['leased_at', $this->formatTimestamp((int) ($record['leased_at'] ?? 0))];
    $rows[] = ['last_heartbeat_at', $this->formatTimestamp((int) ($record['last_heartbeat_at'] ?? 0))];
    $rows[] = ['lease_expires_at', $this->formatTimestamp((int) ($record['lease_expires_at'] ?? 0))];
    $rows[] = ['current_workload_mode', (string) ($record['current_workload_mode'] ?? '')];
    $rows[] = ['current_model', (string) ($record['current_model'] ?? '')];
    $rows[] = ['url', (string) ($record['url'] ?? '')];
    $rows[] = ['host', (string) ($record['host'] ?? '')];
    $rows[] = ['port', (string) ($record['port'] ?? '')];

    return $rows;
  }

  /**
   * Builds select options for known pool records.
   *
   * @param array<string, array<string,mixed>> $instances
   *   Pool records keyed by contract ID.
   *
   * @return array<string, string>
   *   Select options.
   */
  private function buildInstanceOptions(array $instances): array {
    $options = [];
    foreach ($instances as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }
      $options[(string) $contractId] = sprintf(
        '%s (%s %s)',
        (string) $contractId,
        (string) ($record['lease_status'] ?? ''),
        (string) ($record['current_workload_mode'] ?? '')
      );
    }
    return $options;
  }

  /**
   * Header for the pool inventory table.
   *
   * @return array<int, string>
   *   Header cells.
   */
  private function buildPoolTableHeader(): array {
    return [
      (string) $this->t('Contract'),
      (string) $this->t('Lease'),
      (string) $this->t('Lease expires'),
      (string) $this->t('Last heartbeat'),
      (string) $this->t('Workload'),
      (string) $this->t('Model'),
      (string) $this->t('URL'),
      (string) $this->t('Vast cur_state'),
      (string) $this->t('Vast status'),
      (string) $this->t('Last used'),
      (string) $this->t('Last seen'),
      (string) $this->t('Last stopped'),
      (string) $this->t('Last error'),
    ];
  }

  /**
   * Builds rows for the pool inventory table.
   *
   * @param array<string, array<string,mixed>> $instances
   *   Pool records keyed by contract ID.
   *
   * @return array<int, array<int, string>>
   *   Table rows.
   */
  private function buildPoolTableRows(array $instances): array {
    $rows = [];
    foreach ($instances as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }

      $rows[] = [
        (string) $contractId,
        (string) ($record['lease_status'] ?? ''),
        $this->formatTimestamp((int) ($record['lease_expires_at'] ?? 0)),
        $this->formatTimestamp((int) ($record['last_heartbeat_at'] ?? 0)),
        (string) ($record['current_workload_mode'] ?? ''),
        (string) ($record['current_model'] ?? ''),
        (string) ($record['url'] ?? ''),
        (string) ($record['vast_cur_state'] ?? ''),
        (string) ($record['vast_actual_status'] ?? ''),
        $this->formatTimestamp((int) ($record['last_used_at'] ?? 0)),
        $this->formatTimestamp((int) ($record['last_seen_at'] ?? 0)),
        $this->formatTimestamp((int) ($record['last_stopped_at'] ?? 0)),
        (string) ($record['last_error'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * Formats a unix timestamp for operator display.
   */
  private function formatTimestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    return $this->dateFormatter->format($timestamp, 'short');
  }

}
