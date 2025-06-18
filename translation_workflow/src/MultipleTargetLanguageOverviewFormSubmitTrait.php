<?php

namespace Drupal\translation_workflow;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\Form\SourceOverviewForm;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;

/**
 * Override method on some clases using this trait to load correct entity.
 */
trait MultipleTargetLanguageOverviewFormSubmitTrait {
  use MessengerTrait, StringTranslationTrait, LoggerChannelTrait;

  /**
   * Submit handler for the source entities overview form.
   *
   * @param array $form
   *   Drupal form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $type
   *   Entity type.
   */
  public function overviewFormSubmit(array $form, FormStateInterface $form_state, $type) {
    // Handle search redirect.
    if ($this->overviewSearchFormRedirect($form, $form_state, $type)) {
      return;
    }

    $target_language = $form_state->getValue('target_language');
    if ($target_language == SourceOverviewForm::ALL) {
      $target_languages = array_keys(tmgmt_available_languages());
    }
    elseif ($target_language == SourceOverviewForm::MULTIPLE) {
      $target_languages = array_filter($form_state->getValue('target_languages'));
    }
    else {
      $target_languages = [$target_language];
    }

    $enforced_source_language = NULL;
    if ($form_state->getValue('source_language') != SourceOverviewForm::SOURCE) {
      $enforced_source_language = $form_state->getValue('source_language');
    }

    $skipped_count = 0;
    $jobItems = [];
    // Group the selected items by source language.
    foreach (array_filter($form_state->getValue('items')) as $item_id) {
      foreach ($target_languages as $target_language) {
        $job_item = tmgmt_job_item_create($this->pluginId, $type, $item_id);
        $job_item->set('target_language', $target_language);
        $job_item->save();
        $source_language = $enforced_source_language ? $enforced_source_language : $job_item->getSourceLangCode();
        if (in_array($source_language, $job_item->getExistingLangCodes())) {
          $jobItems[] = $job_item;
        }
        else {
          $skipped_count++;
        }
      }
    }
    $languageManager = \Drupal::languageManager();
    $targetLanguageObjects = [];
    foreach ($target_languages as $language) {
      $targetLanguageObjects[] = $languageManager->getLanguage($language);
    }
    $translators = Translator::loadMultiple();

    $job = MultipleTargetLanguageJob::create([
      'source_language' => $source_language,
      'target_languages' => $targetLanguageObjects,
      'uid' => \Drupal::currentUser()->id(),
      'job_items' => $jobItems,
      'translator' => reset($translators),
    ]);

    try {
      $job->save();
      $job->set('label', (string) $job->label())->save();
      foreach ($jobItems as $jobItem) {
        $jobItem->set('tjid', $job->id())->save();
      }
    }
    catch (EntityStorageException $e) {
      $this->messenger()->addError($this->t('Error creating Multiple target language job'));
      $this->getLogger('translation_workflow')->error('Error creating entity: %message', [
        '%message' => $e->getMessage(),
      ]);
    }

    // Start the checkout process if any jobs were created.
    if ($job) {
      if ($enforced_source_language) {

        $this->messenger()
          ->addWarning($this->t('You have enforced the job source language which most likely resulted in having a translation of your original content as the job source text. You should review the job translation received from the translator carefully to prevent the content quality loss.'));
        if ($skipped_count) {
          $languages = \Drupal::languageManager()->getLanguages();
          $this->messenger()->addStatus(
            \Drupal::translation()->formatPlural(
              $skipped_count, 'One item skipped as for the language @language it was not possible to retrieve a translation.',
              '@count items skipped as for the language @language it was not possible to retrieve a translations.', ['@language' => $languages[$enforced_source_language]->getName()]
            )
          );
        }
      }
      \Drupal::service('tmgmt.job_checkout_manager')
        ->checkoutAndRedirect($form_state, [$job]);
    }
    else {
      $this->messenger()
        ->addError($this->t('From the selection you made it was not possible to create any translation job.'));
    }
  }

}
