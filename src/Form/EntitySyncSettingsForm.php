<?php

namespace Drupal\entity_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Entity Sync settings.
 */
class EntitySyncSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'entity_sync_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['entity_sync.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('entity_sync.settings');

    $form['data_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Data path'),
      '#default_value' => $config->get('data_path') ?? '',
      '#description' => $this->t('Absolute path or path relative to the Drupal project root. Leave empty to use the default "<drupal>/data" folder.'),
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $this->config('entity_sync.settings')
      ->set('data_path', (string) $form_state->getValue('data_path'))
      ->save();
  }

}

