<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\tmgmt\Form\TmgmtFormBase;
use Drupal\tmgmt\JobInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\views\Views;

/**
 * Class to implement job form.
 */
class MultipleTargetLanguageJobForm extends TmgmtFormBase {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /**
     * @var \Drupal\translation_workflow\Entity\MultipleTargetLanguageJob $job
     */
    $job = $this->entity;
    $currentUser = $this->currentUser();
    $form['info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tmgmt-ui-job-info', 'clearfix']],
      '#weight' => 0,
    ];

    $form['info']['created'] = [
      '#type' => 'item',
      '#title' => $this->t('Created'),
      '#markup' => \Drupal::service('date.formatter')
        ->format($job->getCreatedTime(), 'short'),
      '#prefix' => '<div class="tmgmt-ui-created tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    ];
    $form['info']['file_sent'] = [
      '#type' => 'item',
      '#title' => t('File sent'),
      '#markup' => $job->isSentToCdt() ? t('Yes') : t('No'),
      '#prefix' => '<div class="tmgmt-file-sent tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    ];

    $form['language_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tmgmt-ui-job-info', 'clearfix']],
      '#weight' => 1,
    ];

    $targetLanguagesCodes = $job->getTargetLangcodes();

    $form['language_info']['show_target_languages'] = [
      '#title' => t('Target languages') . ' (' . count($targetLanguagesCodes) . ')',
      '#type' => 'checkboxes',
      '#default_value' => $targetLanguagesCodes,
      '#options' => array_combine($targetLanguagesCodes, $targetLanguagesCodes),
      '#disabled' => TRUE,
      '#weight' => 1,
      '#attributes' => ['class' => ['container-inline']],
    ];

    $untranslated = array_map(function ($language) {
      return $language->getId();
    }, \Drupal::languageManager()->getLanguages());

    $form['language_info']['show_target_languages_not'] = [
      '#title' => '',
      '#type' => 'checkboxes',
      '#options' => array_combine($untranslated, $untranslated),
      '#disabled' => TRUE,
      '#weight' => 2,
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['info']['character_count'] = [
      '#type' => 'item',
      '#title' => $this->t('Total character count'),
      '#markup' => number_format($job->getCharactersCount()),
      '#prefix' => '<div class="tmgmt-ui-characters-count tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    ];

    $form['info']['page_count'] = [
      '#type' => 'item',
      '#title' => $this->t('Page count'),
      '#markup' => $job->getPageCount(),
      '#prefix' => '<div class="tmgmt-ui-page-count tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    ];

    $form['info']['priority'] = [
      '#type' => 'item',
      '#title' => $this->t('Priority'),
      '#markup' => $job->getPriority(),
      '#prefix' => '<div class="tmgmt-ui-created tmgmt-ui-info-item">',
      '#suffix' => '</div>',
      '#value' => $job->getPriority(),
    ];

    $form['job_items_wrapper'] = [
      '#type' => 'container',
      '#weight' => 10,
      '#prefix' => '<div id="tmgmt-ui-job-checkout-details">',
      '#suffix' => '</div>',
    ];
    $form['footer'] = tmgmt_color_job_item_legend();
    $form['footer']['#weight'] = 100;

    // Translation jobs.
    $form['job_items_wrapper']['items'] = [
      '#type' => 'details',
      '#title' => t('Job items'),
      '#open' => in_array($job->getState(), [JobInterface::STATE_ACTIVE]),
      '#prefix' => '<div class="' . 'tmgmt-ui-job-items ' . ($job->isSubmittable() ? 'tmgmt-ui-job-submit' : 'tmgmt-ui-job-manage') . '">',
      'view' => [
        '#type' => 'view',
        '#name' => 'tmgmt_job_items',
        '#display_id' => $job->isSubmittable() ? 'checkout' : 'submitted',
        '#arguments' => [$job->id()],
        '#weight' => 30,
      ],
      '#attributes' => [
        'class' => [
          'tmgmt-ui-job-items',
          $job->isSubmittable() ? 'tmgmt-ui-job-submit' : 'tmgmt-ui-job-manage',
        ],
      ],
      '#suffix' => '</div>',
    ];

    if ($job->isFileUploaded()) {
      $form['nodes'] = [
        '#description' => $this->t('Manage multiple nodes at a time. All job items from this translation job - associated to a node - will be set ready to publish.'),
        '#type' => 'details',
        '#title' => $this->t('Content'),
        '#open' => TRUE,
        '#weight' => 12,
      ];
      $rows = [];

      foreach ($job->getItems() as $jobItem) {
        if (!$jobItem->isAccepted()) {
          if ($jobItem->getItemType() == 'node') {
            $node = Node::load($jobItem->getItemId());
            $nid = $node->id();
            $statistics = !empty($rows[$nid][1]) ? $rows[$nid][1] : [];
            foreach (MultipleTargetLanguageJobItem::getStates() as $jobState => $label) {
              if ($jobItem->isState($jobState)) {
                if (isset($statistics[(string) $label])) {
                  $statistics[(string) $label] += 1;
                }
                else {
                  $statistics[(string) $label] = 1;
                }
              }
            }
            $rows[$jobItem->getItemType() . '-' . $jobItem->getItemId()] = [
              $node->toLink(),
              $jobItem->getSourceType(),
              $statistics,
            ];
          }
          else {
            if ($jobItem->getItemType() == 'default') {
              $type = 'String literal';
            }
            else {
              $type = ucfirst(str_replace('_', ' ', $jobItem->getItemType()));
            }
            $rows[$jobItem->getItemType() . '-' . $jobItem->getItemId()] = [
              $jobItem->label(),
              $type,
              '-',
            ];
          }
        }
      }

      foreach ($rows as &$row) {
        if (is_array($row[2])) {
          $title = [];
          foreach ($row[2] as $k => $v) {
            $title[] = sprintf('%s:%s', $k, $v);
          }
          $row[2] = Markup::create(sprintf('<span title="%s">%s</span>', implode(', ', $title), implode('/', $row[2])));
        }
      }

      $form['nodes']['table'] = [
        '#type' => 'tableselect',
        '#header' => ['Title', 'Type', 'Progress'],
        '#options' => $rows,
      ];

      $all_options = MultipleTargetLanguageJobItem::getStates();
      $options = ['' => t('-- Please select --')];
      if ($currentUser->hasPermission('edit translation content validators')) {
        $options = $all_options;
      }
      else {
        if ($currentUser->hasPermission('set job item to translated state')) {
          $options[MultipleTargetLanguageJobItem::STATE_REVIEW] = $all_options[MultipleTargetLanguageJobItem::STATE_REVIEW];
        }
      }
      if (isset($options[MultipleTargetLanguageJobItem::STATE_INACTIVE])) {
        unset($options[MultipleTargetLanguageJobItem::STATE_INACTIVE]);
      }
      if (count($options) > 1) {
        $form['nodes']['new_state'] = [
          '#type' => 'select',
          '#options' => $options,
          '#title' => t('Select new state'),
        ];

        $form['nodes']['new_state_apply'] = [
          '#type' => 'submit',
          '#value' => t('Apply'),
          '#validate' => ['::jobItemsMassChangeValidate'],
          '#submit' => ['::jobItemsMassChangeSubmit'],
        ];
      }
    }

    $form['translator_wrapper'] = [
      '#type' => 'details',
      '#title' => t('Translator information'),
      '#open' => TRUE,
      '#weight' => 20,
    ];

    $form['translator_wrapper']['checkout_info'] = $this->checkoutInfo($job);

    if ($messagesView = Views::getView('tmgmt_job_messages')) {
      $output = $messagesView->preview('embed', [$job->id()]);
      if ($messagesView->result) {
        $form['messages'] = [
          '#type' => 'details',
          '#title' => $messagesView->storage->label(),
          '#open' => TRUE,
          '#weight' => 50,
        ];
        $form['messages']['view'] = $output;
      }
    }

    $form['#attached']['library'][] = 'tmgmt/admin';
    return $form;
  }

  /**
   * Validate mass job item update.
   */
  public function jobItemsMassChangeValidate(array &$form, FormStateInterface $form_state) {
    $currentUser = $this->currentUser();
    $values = $form_state->getValues();
    // Only translation manager or layout validator can access this screen.
    if (!$currentUser->hasPermission('set job item to translated state') && !$currentUser->hasPermission('edit translation content validators')) {
      $form_state->setErrorByName('nodes][table', $this->t('Insufficient privileges'));
    }

    $states = MultipleTargetLanguageJobItem::getStates();
    if (!array_key_exists($values['new_state'], $states)) {
      $form_state->setErrorByName('new_state', $this->t('Please select valid new state'));
    }
    else {
      $new_state = $values['new_state'];
      if ($new_state != MultipleTargetLanguageJobItem::STATE_REVIEW && !$currentUser->hasPermission('edit translation content validators')) {
        $form_state->setErrorByName('new_state', $this->t('You are not allowed to assign this state'));
      }
      if ($new_state == MultipleTargetLanguageJobItem::STATE_REVIEW
        && (!$currentUser->hasPermission('set job item to translated state') && !$currentUser->hasPermission('edit translation content validators'))) {
        $form_state->setErrorByName('new_state', $this->t('You are not allowed to assign this state'));
      }
    }

    if (!empty($values['table'])) {
      $values = array_filter(array_values($values['table']));
      if (empty($values)) {
        $form_state->setErrorByName('nodes][table', $this->t('Please select at least one row to mark it ready for publish'));
      }
    }
  }

  /**
   * Submit handler for publish multiple job items at once form.
   */
  public function jobItemsMassChangeSubmit(array &$form, FormStateInterface $form_state) {
    $user = $this->currentUser();
    $values = $form_state->getValues();
    /**
     * @var \Drupal\translation_workflow\Entity\MultipleTargetLanguageJob $job
     */
    $job = $this->getEntity();
    $publish = [];
    foreach ($values['table'] as $nid) {
      if (!empty($nid)) {
        $publish[] = $nid;
      }
    }
    $items = $job->getItems();
    $new_state = $values['new_state'];
    /**
     * @var \Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem $jobItem
     */
    foreach ($items as $jobItem) {
      $id = $jobItem->getItemType() . '-' . $jobItem->getItemId();
      // && $jobItem->getState() != $new_state) {
      if (in_array($id, $publish)) {
        // Change the state.
        if ($jobItem->getState() == MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATION_REQUIRED) {
          // @todo Validators and notifications.
          /*$removed_validators = osha_tmgmt_load_validators_by_job_item($jobItem);
          $old_current_validator = osha_tmgmt_load_validators_next($removed_validators);
          OshaWorkflowNotifications::notifyTranslationValidatorsRemoved($old_current_validator, $jobItem);*/
        }
        switch ($new_state) {
          case MultipleTargetLanguageJobItem::STATE_ACTIVE:
            $jobItem->toOnTranslation('Mass updated by ' . $user->getAccountName());
            break;

          case MultipleTargetLanguageJobItem::STATE_REVIEW:
            $jobItem->toTranslated('Mass updated by ' . $user->getAccountName());
            break;

          case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATION_REQUIRED:
            $jobItem->toTranslationValidationRequired('Mass updated by ' . $user->getAccountName());
            break;

          case MultipleTargetLanguageJobItem::STATE_ABORTED:
            $jobItem->toTranslationRejected('Mass updated by ' . $user->getAccountName());
            break;

          case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATED:
            $jobItem->toTranslationValidated('Mass updated by ' . $user->getAccountName());
            break;

          case MultipleTargetLanguageJobItem::STATE_ACCEPTED:
            $jobItem->acceptTranslation('Mass updated by ' . $user->getAccountName());
            break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function actionsElement(array $form, FormStateInterface $form_state) {
    $parentActions = parent::actionsElement($form, $form_state);
    $job = $this->getEntity();

    if (!$job->isSentToCdt()) {
      // Add sent to cdt submit button.
      $parentActions['sent_to_cdt'] = [
        '#type' => 'submit',
        '#value' => $this->t('File sent to CDT'),
        '#weight' => 14,
        '#submit' => ['::submitSentToCdt'],
      ];
    }
    $parentActions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.translation_workflow_jobs_overview.page_1'),
      '#weight' => 15,
    ];

    $parentActions['abort_job'] = [
      '#type' => 'link',
      '#title' => t('Abort job'),
      '#url' => Url::fromRoute('entity.tmgmt_job_multiple_target.abort_form', [
        'tmgmt_job_multiple_target' => $job->id(),
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
      '#access' => $job->isAbortable(),
      '#weight' => 7,
    ];

    $parentActions['resubmit_job'] = [
      '#type' => 'link',
      '#title' => t('Resubmit'),
      '#url' => Url::fromRoute('entity.tmgmt_job_multiple_target.resubmit_form', [
        'tmgmt_job_multiple_target' => $job->id(),
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
      '#access' => $job->isAborted(),
      '#weight' => 7,
    ];

    $parentActions['checkout'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit to translator'),
      '#access' => $job->isSubmittable(),
      '#submit' => ['::submitCheckout'],
      '#weight' => 7,
    ];

    if (isset($parentActions['delete'])) {
      $parentActions['delete']['#attributes'] = [
        'class' => ['button'],
      ];
    }
    return $parentActions;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Translation job saved.'));
  }

  /**
   * Set job as file sent to CDT.
   */
  public function submitSentToCdt() {
    $job = $this->getEntity();
    $job->set('file_sent', TRUE)->save();
  }

  /**
   * Request translation for job.
   */
  public function submitCheckout() {
    $job = $this->getEntity();
    $job->requestTranslation();
  }

  /**
   * Helper function for retrieving the rendered job checkout information.
   */
  public function checkoutInfo(JobInterface $job) {
    // The translator might have been disabled or removed.
    if (!$job->hasTranslator()) {
      return ['#markup' => t('The job has no provider assigned.')];
    }
    $translator = $job->getTranslator();
    $plugin_ui = $this->translatorManager->createUIInstance($translator->getPluginId());
    return $plugin_ui->checkoutInfo($job);
  }

}
