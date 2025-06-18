<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\node\NodeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\translation_workflow\TmgmtElementsUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;

/**
 * Class to implement retranslate functionality.
 */
class MultipleTargetLanguageRetranslateForm extends FormBase {

  /**
   * TmgmtElementUtils service.
   *
   * @var \Drupal\translation_workflow\TmgmtElementsUtils
   */
  protected $tmgmtElementsUtils;

  /**
   * Language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructor.
   */
  public function __construct(TmgmtElementsUtils $tmgmtElementsUtils, LanguageManagerInterface $languageManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->tmgmtElementsUtils = $tmgmtElementsUtils;
    $this->languageManager = $languageManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('translation_workflow.manage_tmgmt_elements'),
      $container->get('language_manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface $node
   *   Node object.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {

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
    $defaultLanguage = $this->languageManager->getDefaultLanguage();
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
    $fieldsDefinitions = $this->entityFieldManager->getFieldDefinitions($node->getEntityTypeId(), $node->bundle());
    foreach ($fieldsDefinitions as $fieldName => $fieldsDefinition) {
      $fieldType = $fieldsDefinition->getType();
      $isFieldTranslatable = $fieldsDefinition->isTranslatable();
      if ($isFieldTranslatable && in_array($fieldType, $this->tmgmtElementsUtils::COUNTABLE_FIELDS)) {
        $value = $node->get($fieldName)->value;
        if (!empty($value)) {
          $options = strpos($value, 'tmgmt') ? $this->tmgmtElementsUtils->getTmgmtOptions($value) : [$value];
          $form['fields'][$fieldName] = [
            '#type' => 'checkboxes',
            '#title' => $fieldsDefinition->getLabel(),
            '#options' => $options,
          ];
          $retranslateFields[] = $fieldName;
        }
        else {
          $form['fields'][$fieldName] = [
            '#type' => 'checkboxes',
            '#title' => $fieldsDefinition->getLabel(),
            '#options' => [$this->t('The field is empty.')],
            '#disabled' => TRUE,
          ];
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
