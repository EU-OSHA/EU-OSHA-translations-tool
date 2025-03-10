<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\tmgmt\Form\JobItemForm;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class to override job item form.
 */
class MultipleTargetLanguageJobItemForm extends JobItemForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $parentForm = parent::form($form, $form_state);
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
