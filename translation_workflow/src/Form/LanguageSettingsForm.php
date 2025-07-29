<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\translation_workflow\TranslationWorkflowLanguages;

/**
 * Contains all global settings form Translation Workflow module.
 */
class LanguageSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['translation_workflow.language_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'translation_workflow_language_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('translation_workflow.language_settings');

    $form['translation_workflow_languages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed languages'),
      '#description' => $this->t('Enter the language codes separated by comma (e.g. en,es,fr).'),
      '#default_value' => implode(',', $config->get('translation_workflow_languages') ?? TranslationWorkflowLanguages::DEFAULT_LANGUAGES),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $languages_raw = $form_state->getValue('translation_workflow_languages');
    $languages = array_map('trim', explode(',', $languages_raw));

    $this->config('translation_workflow.language_settings')
      ->set('translation_workflow_languages', $languages)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
