<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\Form\CartForm;
use Drupal\translation_workflow\CounterManager;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\translation_workflow\Entity\PriorityJobInterface;

/**
 * Class to override cart form.
 */
class MultipleTargetLanguageCartForm extends CartForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plugin = NULL, $item_type = NULL) {
    $options = array();
    $selected = [];
    $form = parent::buildForm($form, $form_state, $plugin, $item_type);
    $characterCount = 0;
    $pageCount = "0,00";

    foreach (tmgmt_cart_get()->getJobItemsFromCart() as $item) {

      /*
       * 27/05/2022 (jorge.perez) - Remove function code to use CounterManager
       * class, that compute successfully the translation or retranslations of
       * JobItems.
       */
      $characterItem = 0;
      $url = $item->getSourceUrl();
      $selected[$item->id()] = TRUE;
      $characterItem += CounterManager::countJobItem($item);
      $characterCount += $characterItem;
      $pageItem = number_format(($characterItem / MultipleTargetLanguageJob::CHARACTERS_PER_PAGE), 2, ',', '');
      $pageCount = number_format(($characterCount / MultipleTargetLanguageJob::CHARACTERS_PER_PAGE), 2, ',', '');

      $options[$item->id()] = array(
        $item->getSourceType(),
        $url ? Link::fromTextAndUrl($item->label(), $url)->toString() : $item->label(),
         'English',  $characterItem ,  $pageItem ,
      );
    }


    $form['items'] = array(
      '#type' => 'tableselect',
      '#header' => array(t('Type'), t('Content'), t('Language'),t('Character count').' ('.$characterCount.')',t('Page count') .' ('. $pageCount .')'),
      '#empty' => t('There are no items in your cart.'),
      '#options' => $options,
      '#default_value' => $selected,
    );

    if (isset($form['request_translation'])) {
      $form['request_translation']['#validate'][] = '::validateNewItems';
    }
    if (isset($form['source_language'])) {
      $form['source_language']['#size'] = 24;
      if (isset($form['source_language']['#options']['nol'])) {
        unset($form['source_language']['#options']['nol']);
      }
    }

    if (isset($form['target_language'])) {
      $allowedLanguages = osha_enabled_language_list();
      $form['target_language']['#size'] = 24;
      $default = \Drupal::languageManager()->getDefaultLanguage()->getId();
      if (isset($form['target_language']['#options']['nol'])) {
        unset($form['target_language']['#options']['nol']);
      }
      if (isset($form['target_language']['#options'][$default])) {
        unset($form['target_language']['#options'][$default]);
      }
      $form['target_language']['#options'] = array_filter($form['target_language']['#options'], function ($key) use ($allowedLanguages) {
        return in_array($key, $allowedLanguages);
      }, ARRAY_FILTER_USE_KEY);
    }

    $jobFieldDefinitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('tmgmt_job_multiple_target', 'default');
    if (isset($jobFieldDefinitions['priority'])) {
      $priorityField = $jobFieldDefinitions['priority'];
      $form['priority'] = [
        '#type' => 'select',
        '#options' => $priorityField->getSetting('allowed_values'),
        '#title' => $priorityField->getLabel(),
        '#default_value' => PriorityJobInterface::PRIORITY_NORMAL,
        '#weight' => -1,
      ];
    }

    // Select all languages.
    $form['select_all_lng'] = [
      '#type' => 'checkbox',
      '#title' => t('Select all languages'),
    ];
    $form['empty_cart']['#weight'] = 7;
    $form['remove_selected']['#weight'] = 6;
    $form['request_translation']['#weight'] = 4;

    $form['#attached']['library'][] = 'translation_workflow/select_all';
    return $form;
  }

  /**
   * Validate new added items to the cart.
   */
  public function validateNewItems(array &$form, FormStateInterface $form_state) {
    $targetLanguages = array_filter($form_state->getValue('target_language'));
    if (empty($targetLanguages)) {
      $form_state->setErrorByName('target_language', t("You didn't select any target language."));
    }
    $jobItems = array_filter($form_state->getValue('items'));
    $itemIds = array_map(function ($jobItemId) {
      $ret = NULL;
      $jobItem = MultipleTargetLanguageJobItem::load($jobItemId);
      if ($jobItem) {
        $ret = $jobItem->getItemId();
      }
      return $ret;

    }, $jobItems);

    $existingJobItems = MultipleTargetLanguageJobItem::jobItemExists([
      'item_id' => $itemIds,
      'target_language' => $targetLanguages,
    ]);
    if ($existingJobItems) {
      $form_state->setErrorByName('', $this->t('There are active translation jobs with this items.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitRequestTranslation(array $form, FormStateInterface $form_state) {
    $targetLanguages = array_filter($form_state->getValue('target_language'));
    $enforced_source_language = NULL;
    if ($form_state->getValue('enforced_source_language')) {
      $enforced_source_language = $form_state->getValue('source_language');
    }

    $skipped_count = 0;
    $jobItems = [];
    // Group the selected items by source language.
    foreach (JobItem::loadMultiple(array_filter($form_state->getValue('items'))) as $job_item) {
      $targetLanguagesTemp = $targetLanguages;
      $firstLanguage = array_shift($targetLanguagesTemp);
      $job_item->setTargetLanguage($firstLanguage);
      $job_item->save();
      $source_language = $enforced_source_language ? $enforced_source_language : $job_item->getSourceLangCode();
      if (in_array($source_language, $job_item->getExistingLangCodes())) {
        $jobItems[$job_item->id()] = $job_item;
        foreach ($targetLanguagesTemp as $langCode) {
          if ($langCode !== $source_language) {
            $newJobItem = tmgmt_job_item_create($job_item->getPlugin(), $job_item->getItemType(), $job_item->getItemId(), ['target_language' => $langCode]);
            if (($job_item instanceof MultipleTargetLanguageJobItem) && ($newJobItem instanceof MultipleTargetLanguageJobItem)) {
              if ($job_item->hasRetranslationData()) {
                $newJobItem->setRetranslationData($job_item->getRetranslationData());
              }
            }
            $newJobItem->save();
            $jobItems[$newJobItem->id()] = $newJobItem;
          }
        }
      }
      else {
        $skipped_count++;
      }
    }

    $languageManager = \Drupal::languageManager();
    $targetLanguageObjects = [];
    foreach ($targetLanguages as $language) {
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
      tmgmt_cart_get()->removeJobItems(array_keys($jobItems));
    }
    catch (EntityStorageException $e) {
      $this->messenger()
        ->addError($this->t('Error creating Multiple target language job'));
      $this->getLogger('translation_workflow')
        ->error('Error creating entity: %message', [
          '%message' => $e->getMessage(),
        ]);
    }

    // Start the checkout process if any jobs were created.
    if ($job) {
      if ($enforced_source_language) {

        $this->messenger()
          ->addWarning(t('You have enforced the job source language which most likely resulted in having a translation of your original content as the job source text. You should review the job translation received from the translator carefully to prevent the content quality loss.'));

        if ($skipped_count) {
          $languages = \Drupal::languageManager()->getLanguages();
          $this->messenger()->addStatus(\Drupal::translation()
            ->formatPlural($skipped_count, 'One item skipped as for the language @language it was not possible to retrieve a translation.',
              '@count items skipped as for the language @language it was not possible to retrieve a translations.', ['@language' => $languages[$enforced_source_language]->getName()]));
        }
      }

      \Drupal::service('tmgmt.job_checkout_manager')
        ->checkoutAndRedirect($form_state, [$job]);
    }
    else {
      $this->messenger()
        ->addError(t('From the selection you made it was not possible to create any translation job.'));
    }
  }

}
