<?php

namespace Drupal\translation_workflow\Plugin\views\field;

use Drupal\tmgmt\Plugin\views\field\StatisticsBase;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\views\ResultRow;

/**
 * Handler to show source language for multiple target language job item.
 *
 * @ViewsField("translation_workflow_source_language")
 */
class JobItemSourceLanguage extends StatisticsBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    $ret = '--';
    if ($entity instanceof MultipleTargetLanguageJobItem) {
      $ret = $entity->getJob()->getSourceLanguage()->getName();
    }
    return $ret;
  }

}
