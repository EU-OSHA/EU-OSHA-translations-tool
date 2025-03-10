<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class to implement retranslate functionality.
 */
class MultipleTargetLanguageRetranslateForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    $form['info'] = [
      '#type' => 'container',
    ];
    $form['info']['content_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Re-translation for %title', ['%title' => $node->label()]),
    ];
    $form['info']['help_text'] = [
      '#type' => 'html_tag',
      '#tag' => 'h4',
      '#value' => $this->t('Choose the fields and text that you want to re-translate'),
    ];

    $form['fields'] = [
      '#type' => 'container',
    ];
    $retranslateFields = [];
    $translationLanguages = $node->getTranslationLanguages();
    $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
    if (isset($translationLanguages[$defaultLanguage->getId()])) {
      unset($translationLanguages[$defaultLanguage->getId()]);
    }
    if (empty($translationLanguages)) {
      $this->messenger()
        ->addWarning($this->t('The node is not translated so you cannot re-translate.'));
      $redirectResponse = new TrustedRedirectResponse($node->toUrl('drupal:content-translation-overview')
        ->toString());
      $redirectResponse->send();
    }
    $fieldsDefinitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($node->getEntityTypeId(), $node->bundle());
    foreach ($fieldsDefinitions as $fieldName => $fieldsDefinition) {
      if ($fieldsDefinition->isTranslatable() && in_array($fieldsDefinition->getType(), [
        'string',
        'text_with_summary',
        'text_long',
      ])) {
        $value = $node->get($fieldName)->value;
        switch ($fieldsDefinition->getType()) {
          case 'string':
          case 'text_long':
            $form['fields'][$fieldName] = [
              '#type' => 'checkbox',
              '#prefix' => '<label>' . $fieldsDefinition->getLabel() . '</label>',
              '#title' => $value,
            ];
            $retranslateFields[] = $fieldName;
            break;

          case 'text_with_summary':
            if (strpos($value, 'tmgmt')) {
              $options = [];
              $domCrawler = new Crawler($value);
              $domCrawler->filter('*')->each(function (Crawler $node, int $i) use (&$options) {
                if ($node->attr('id')) {
                  $id = $node->attr('id');
                  if (preg_match('/tmgmt-\d/', $id)) {
                    $options[$id] = $node->outerHtml();
                  }
                }
              });
              $form['fields'][$fieldName] = [
                '#type' => 'checkboxes',
                '#title' => $fieldsDefinition->getLabel(),
                '#options' => $options,
              ];
            }
            else {
              $form['fields'][$fieldName] = [
                '#type' => 'checkbox',
                '#prefix' => '<label>' . $fieldsDefinition->getLabel() . '</label>',
                '#title' => $value,
              ];
            }
            $retranslateFields[] = $fieldName;
            break;
        }
      }
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    $form_state->set('node', $node);
    $form_state->set('retranslate_fields', $retranslateFields);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->has('node')) {
      /**
       * @var \Drupal\node\NodeInterface $node
       */
      $node = $form_state->get('node');
      $existingJobItems = MultipleTargetLanguageJobItem::jobItemExists([
        'item_id' => $node->id(),
      ]);
      if ($existingJobItems) {
        $form_state->setErrorByName('', $this->t('Content is already added for translation.'));
      }
      if (empty($this->getSelectedValues($form, $form_state))) {
        $form_state->setErrorByName('', $this->t('Almost one field must to be selected for retranslation.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Get values that are enabled.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object with form information.
   *
   * @return array
   *   Values that are enabled.
   */
  protected function getSelectedValues(array $form, FormStateInterface $form_state) {
    $retranslateFields = $form_state->get('retranslate_fields');
    $values = [];
    if ($retranslateFields) {
      $values = array_filter($form_state->getValues(), function (&$value, $key) use ($retranslateFields) {
        $ret = FALSE;
        if (in_array($key, $retranslateFields)) {
          if (is_array($value)) {
            foreach ($value as $tmgmtId => $tmgmtSelected) {
              if ($tmgmtSelected === 0) {
                unset($value[$tmgmtId]);
              }
            }
            $ret = !empty($value);
          }
          else {
            $ret = $value != 0;
          }
        }
        return $ret;
      }, ARRAY_FILTER_USE_BOTH);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'translation_workflow_retranslate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->has('node') && $form_state->has('retranslate_fields')) {
      /**
       * @var \Drupal\node\NodeInterface $node
       */
      $node = $form_state->get('node');
      $values = $this->getSelectedValues($form, $form_state);
      $jobItem = tmgmt_cart_get()->addJobItem('content', $node->getEntityTypeId(), $node->id());
      if ($jobItem instanceof MultipleTargetLanguageJobItem) {
        $jobItem->setRetranslationData($values);
      }
      $jobItem->save();
      $this->messenger()
        ->addStatus($this->t('One content source was added into the <a href="@url">cart</a>.', [
          '@url' => Url::fromRoute('tmgmt.cart')
            ->toString(),
        ]));
      $form_state->setRedirectUrl($node->toUrl('drupal:content-translation-overview'));
    }
  }

}
