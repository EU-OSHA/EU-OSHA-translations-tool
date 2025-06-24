<?php

namespace Drupal\translation_workflow\Form;

use Drupal\user\Entity\Role;
use Drupal\tmgmt\JobItemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\translation_workflow\TmgmtElementsUtils;
use Drupal\translation_workflow\TranslationWorkflowLanguages;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;

/**
 * Contains all global settings form Translation Workflow module.
 */
class NotificationsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['translation_workflow.notifications_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'translation_workflow_notifications_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('translation_workflow.notifications_settings');

    // Get roles
    $excluded_roles = ['anonymous', 'authenticated'];
    $roles = Role::loadMultiple();
    $role_options = ['' => $this->t('None')];
    foreach ($roles as $role_id => $role) {
      if (in_array($role_id, $excluded_roles)) {
        continue;
      }
      $role_options[$role_id] = $role->label();
    }

    $form['notification_roles'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Job / Job Item States'),
        $this->t('Roles allowed for notifications'),
      ],
    ];

    foreach (TmgmtElementsUtils::STATE_LABELS as $state_value => $state_label) {
      $form['notification_roles'][$state_value]['state'] = [
        '#plain_text' => $state_label,
      ];
      $form['notification_roles'][$state_value]['roles'] = [
        '#type' => 'select',
        '#options' => $role_options,
        '#multiple' => TRUE,
        '#default_value' => $config->get("notification_roles.$state_value") ?? [],
      ];
    }

    $form['#attached']['library'][] = 'translation_workflow/notifications_settings';

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('notification_roles');
    foreach (TmgmtElementsUtils::STATE_LABELS as $state_value => $state_label) {
      $this->config('translation_workflow.notifications_settings')
        ->set("notification_roles.$state_value", $values[$state_value]['roles'])
        ->save();
    }
    parent::submitForm($form, $form_state);
  }

}
