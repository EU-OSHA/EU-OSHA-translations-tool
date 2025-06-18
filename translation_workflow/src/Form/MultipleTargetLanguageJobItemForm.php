<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\tmgmt\Form\JobItemForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DomCrawler\Crawler;
use Drupal\views\Entity\View;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;

/**
 * Class to override job item form.
 */
class MultipleTargetLanguageJobItemForm extends JobItemForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    // $parentForm = parent::form($form, $form_state);
    $parentForm = $this->fixTmgmtAntiPatternBug($form, $form_state);
    $item = $this->entity;
    if ($item instanceof MultipleTargetLanguageJobItem) {
      if (isset($parentForm['info']['translator'])) {
        unset($parentForm['info']['translator']);
      }

      // We need to alter target_language to show apropiate value and
      // we change name of it to avoid saving an empty value on entity.
      if (isset($parentForm['info']['target_language'])) {
        $parentForm['info']['target_language']['#markup'] = $item->getTargetLanguage()
          ->getName();
        $newInfo = [];
        array_walk($parentForm['info'], function ($item, $key) use (&$newInfo) {
          if ($key == 'target_language') {
            $key = 'target_language_item';
          }
          $newInfo[$key] = $item;
        });
        $parentForm['info'] = $newInfo;
      }
      if ($item->hasRetranslationData()) {
        $retranslationData = $item->getRetranslationData();
        if (isset($parentForm['review'])) {
          foreach ($parentForm['review'] as $field => $fieldInfo) {
            if (is_array($fieldInfo) && in_array($field, array_keys($retranslationData))) {
              foreach ($fieldInfo as $fieldKey => $fieldItem) {
                if (is_array($fieldItem)) {
                  foreach ($fieldItem as $fieldItemTitle => $fieldItemValue) {
                    if (is_array($fieldItemValue) && isset($fieldItemValue['source']) && isset($fieldItemValue['source']['#default_value'])) {
                      $defaultValue = $fieldItemValue['source']['#default_value'];
                      $crawler = new Crawler($defaultValue);
                      $newDefaultValue = [];
                      $subCrawler = new Crawler($fieldItemValue['translation']['#default_value']);
                      $newTranslationDefaltValue = [];
                      $crawler->filter('*[id*="tmgmt"]')
                        ->each(function (Crawler $node, $i) use ($retranslationData, $field, &$newDefaultValue, $subCrawler, &$newTranslationDefaltValue) {
                          $idAttr = $node->attr('id');
                          if (!is_null($idAttr) && is_array($retranslationData[$field]) && in_array($idAttr, $retranslationData[$field])) {
                            $newDefaultValue[] = $node->outerHtml();
                            if ($subCrawler->count()) {
                              $filteredElements = $subCrawler->filter('#' . $idAttr);
                              if ($filteredElements->count()) {
                                $newTranslationDefaltValue[] = $filteredElements->outerHtml();
                              }
                            }
                          }
                        });
                      $form_state->set($fieldItemTitle, $defaultValue);
                      if (!empty($newDefaultValue)) {
                        $parentForm['review'][$field][$fieldKey][$fieldItemTitle]['source']['#default_value'] = implode('', $newDefaultValue);
                      }
                      if (!empty($newTranslationDefaltValue)) {
                        $parentForm['review'][$field][$fieldKey][$fieldItemTitle]['translation']['#default_value'] = implode('', $newTranslationDefaltValue);
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    return $parentForm;
  }

  /**
   * This function fixes the tmgmt anti-pattern bug that is present in the
   * tmgmt module, in JobItemForm.
   *
   * As this custom entity (MultipleTargetLanguageJobItem) extends the
   * JobItem definded by the tmgmt module, this new entity has two new states,
   * which are not handled by the tmgmt module. We have override the
   * getStates() method to add the new states, but the tmgmt module when
   * build de JobItemForm calls the JobItem::getStates() method instead of
   * self::getStates() method, so the new states are not available in the
   * form and error is thrown.
   *
   * We decided to copy/paste the parent (contrib) form code and fix this line
   * instead of patching the contributted module, so we can avoid any potential
   * issues with the tmgmt module in the future.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  private function fixTmgmtAntiPatternBug(array &$form, FormStateInterface $form_state) {
    $item = $this->entity;
    $form['#title'] = $this->t('Job item @source_label', array('@source_label' => $item->getSourceLabel()));

    $form['info'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('tmgmt-ui-job-info', 'clearfix')),
      '#weight' => 0,
    );

    $url = $item->getSourceUrl();
    $form['info']['source'] = array(
      '#type' => 'item',
      '#title' => t('Source'),
      '#markup' => $url ? Link::fromTextAndUrl($item->getSourceLabel(), $url)->toString() : $item->getSourceLabel(),
      '#prefix' => '<div class="tmgmt-ui-source tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    $form['info']['sourcetype'] = array(
      '#type' => 'item',
      '#title' => t('Source type'),
      '#markup' => $item->getSourceType(),
      '#prefix' => '<div class="tmgmt-ui-source-type tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    $form['info']['source_language'] = array(
      '#type' => 'item',
      '#title' => t('Source language'),
      '#markup' => $item->getJob()->getSourceLanguage()->getName(),
      '#prefix' => '<div class="tmgmt-ui-source-language tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    $form['info']['target_language'] = array(
      '#type' => 'item',
      '#title' => t('Target language'),
      '#markup' => $item->getJob()->getTargetLanguage()->getName(),
      '#prefix' => '<div class="tmgmt-ui-target-language tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    $form['info']['changed'] = array(
      '#type' => 'item',
      '#title' => t('Last change'),
      '#value' => $item->getChangedTime(),
      '#markup' => $this->dateFormatter->format($item->getChangedTime()),
      '#prefix' => '<div class="tmgmt-ui-changed tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    // ======================================================================
    // This is a workaround for the tmgmt anti-pattern bug.
    // ======================================================================
    $states = $this->entity::getStates();
    // ======================================================================

    $form['info']['state'] = array(
      '#type' => 'item',
      '#title' => t('State'),
      '#markup' => $states[$item->getState()],
      '#prefix' => '<div class="tmgmt-ui-item-state tmgmt-ui-info-item">',
      '#suffix' => '</div>',
      '#value' => $item->getState(),
    );
    $job = $item->getJob();
    $form['info']['job'] = array(
      '#type' => 'item',
      '#title' => t('Job'),
      '#markup' => $job->toLink()->toString(),
      '#prefix' => '<div class="tmgmt-ui-job tmgmt-ui-info-item">',
      '#suffix' => '</div>',
    );

    // Display selected translator for already submitted jobs.
    if (!$item->getJob()->isSubmittable()) {
      $form['info']['translator'] = array(
        '#type' => 'item',
        '#title' => t('Provider'),
        '#markup' => $job->getTranslatorLabel(),
        '#prefix' => '<div class="tmgmt-ui-translator tmgmt-ui-info-item">',
        '#suffix' => '</div>',
      );
    }

    // Actually build the review form elements...
    $form['review'] = array(
      '#type' => 'container',
    );
    // Build the review form.
    $data = $item->getData();
    $this->trackChangedSource(\Drupal::service('tmgmt.data')->flatten($data), $form_state);
    $form_state->set('has_preliminary_items', FALSE);
    $form_state->set('all_preliminary', TRUE);
    // Need to keep the first hierarchy. So flatten must take place inside
    // of the foreach loop.
    foreach (Element::children($data) as $key) {
      $review_element = $this->reviewFormElement($form_state, \Drupal::service('tmgmt.data')->flatten($data[$key], $key), $key);
      if ($review_element) {
        $form['review'][$key] = $review_element;
      }
    }

    if ($form_state->get('has_preliminary_items')) {
      $form['translation_changes'] = array(
        '#type' => 'container',
        '#markup' => $this->t('The translations below are in preliminary state and can not be changed.'),
        '#attributes' => array(
          'class' => array('messages', 'messages--warning'),
        ),
        '#weight' => -50,
      );
    }

    if ($view = View::load('tmgmt_job_item_messages')) {
      $form['messages'] = array(
        '#type' => 'details',
        '#title' => $view->label(),
        '#open' => FALSE,
        '#weight' => 50,
      );
      $form['messages']['view'] = $view->getExecutable()->preview('block', array($item->id()));
    }

    $form['#attached']['library'][] = 'tmgmt/admin';
    // The reject functionality has to be implement by the translator plugin as
    // that process is completely unique and custom for each translation service.

    // Give the source ui controller a chance to affect the review form.
    $source = $this->sourceManager->createUIInstance($item->getPlugin());
    $form = $source->reviewForm($form, $form_state, $item);
    // Give the translator ui controller a chance to affect the review form.
    if ($item->getTranslator()) {
      $plugin_ui = $this->translatorManager->createUIInstance($item->getTranslator()->getPluginId());
      $form = $plugin_ui->reviewForm($form, $form_state, $item);
    }
    $form['footer'] = tmgmt_color_review_legend();
    return $form;
  }

  /**
   * Save information about retranslation.
   *
   * @param array $form
   *   Form elements.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state information.
   */
  public function submitRetranslation(array &$form, FormStateInterface $form_state) {
    if (!empty($form['actions']['accept']) && $form_state->getTriggeringElement()['#value'] == $form['actions']['accept']['#value']) {
      $formStateValues = $form_state->getValues();
      $retranslationData = $this->getEntity()->getRetranslationData();
      foreach (Element::children($form['review']) as $field) {
        foreach (Element::children($form['review'][$field]) as $fieldInside) {
          foreach (Element::children($form['review'][$field][$fieldInside]) as $fieldInsideValue) {
            $element = &$form['review'][$field][$fieldInside][$fieldInsideValue];
            if (isset($element['source']) && isset($element['translation']) && $form_state->has($fieldInsideValue)) {
              $defaultValue = $form_state->get($fieldInsideValue);

              // Change default value for elements to be original text.
              $element['source']['#default_value'] = $defaultValue;
              if (is_array($formStateValues[$fieldInsideValue]['source'])) {
                $formStateValues[$fieldInsideValue]['source']['value'] = $defaultValue;
              }
              else {
                $formStateValues[$fieldInsideValue]['source'] = $defaultValue;
              }
              $domModified = FALSE;

              // Change translation value to include the rest of the text.
              $crawler = new Crawler($defaultValue);
              $newValue = is_array($formStateValues[$fieldInsideValue]["translation"]) ? $formStateValues[$fieldInsideValue]["translation"]["value"] : $formStateValues[$fieldInsideValue]["translation"];
              $subCrawler = new Crawler($newValue);
              $crawler->filter('*[id*="tmgmt"]')->each(function (Crawler $node, $i) use (&$domModified, $retranslationData, $field, $subCrawler) {
                $idAttr = $node->attr('id');
                if (!is_null($idAttr) && in_array($idAttr, $retranslationData[$field])) {
                  $filteredCrawler = $subCrawler->filter('#' . $idAttr);
                  if ($filteredCrawler->count()) {
                    $domModified = TRUE;
                    $translatedNode = $filteredCrawler->getNode(0);
                    $crawlerNode = $node->getNode(0);
                    $crawlerNode->nodeValue = NULL;
                    foreach ($crawlerNode->childNodes as $childNode) {
                      $crawlerNode->removeChild($childNode);
                    }
                    /**
                     * @var \DOMDocument $crawlerDocument
                     */
                    $crawlerDocument = $crawlerNode->ownerDocument;
                    foreach ($translatedNode->childNodes as $childNode) {
                      $copyNode = $crawlerDocument->importNode($childNode, TRUE);
                      $crawlerNode->appendChild($copyNode);
                    }
                    foreach ($crawlerNode->attributes as $attribute) {
                      $crawlerNode->removeAttribute($attribute->name);
                    }
                    foreach ($translatedNode->attributes as $attribute) {
                      $crawlerNode->setAttribute($attribute->name, $attribute->value);
                    }
                  }
                }
              });
              if ($domModified) {
                if (is_array($formStateValues[$fieldInsideValue]["translation"])) {
                  $formStateValues[$fieldInsideValue]["translation"]["value"] = $crawler->filter('body')->html();
                }
                else {
                  $formStateValues[$fieldInsideValue]["translation"] = $crawler->filter('body')->html();
                }
                $form_state->setValues($formStateValues);
              }
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /**
     * @var \Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem $entity
     */
    $entity = $this->getEntity();
    if ($entity->hasRetranslationData()) {
      $this->submitRetranslation($form, $form_state);
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /**
     * @var \Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem $entity
     */
    $entity = $this->getEntity();
    if ($entity->hasRetranslationData()) {
      $this->submitRetranslation($form, $form_state);
    }
    parent::save($form, $form_state);
  }

}
