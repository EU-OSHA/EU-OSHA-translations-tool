<?php

namespace Drupal\translation_workflow\Plugin\views\field;

use Drupal\tmgmt\Plugin\views\field\StatisticsBase;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\views\ResultRow;

/**
 * Handler to show word count for a multiple target language job or job item.
 *
 * @ViewsField("translation_workflow_pagecount")
 */
class PageCount extends StatisticsBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    $ret = '--';
    if ($entity instanceof MultipleTargetLanguageJob || $entity instanceof MultipleTargetLanguageJobItem) {
      $ret = $entity->getPageCount();
    }
    return $ret;
  }

}
