<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures Compute Orchestrator integration settings.
 */
final class ComputeOrchestratorSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'compute_orchestrator_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['compute_orchestrator.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('compute_orchestrator.settings');
    $hasApiKey = trim((string) $config->get('vast_api_key')) !== '';

    $form['help'] = [
      '#type' => 'item',
      '#markup' => '<p>These settings belong to this Drupal instance. Production and local development should override secrets in settings.php and keep this config object out of normal export/import with config_ignore.</p>',
    ];

    $form['vast_api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Vast API key status'),
      '#markup' => $hasApiKey ? $this->t('Configured.') : $this->t('Not configured.'),
    ];

    $form['vast_api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Vast API key'),
      '#description' => $this->t('Leave blank to keep the current value. In production, prefer a settings.php override from VAST_API_KEY instead of saving the key through this form.'),
    ];

    $form['clear_vast_api_key'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clear stored Vast API key'),
      '#description' => $this->t('This clears the editable config value. It cannot clear a settings.php config override.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('compute_orchestrator.settings');

    if ((bool) $form_state->getValue('clear_vast_api_key')) {
      $config->set('vast_api_key', '');
    }
    else {
      $apiKey = trim((string) $form_state->getValue('vast_api_key'));
      if ($apiKey !== '') {
        $config->set('vast_api_key', $apiKey);
      }
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
