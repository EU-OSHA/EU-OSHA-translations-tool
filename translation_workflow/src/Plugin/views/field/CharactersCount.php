<?php

namespace Drupal\translation_workflow\Plugin\views\field;

use Drupal\tmgmt\Plugin\views\field\StatisticsBase;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\views\ResultRow;

/**
 * Characters count field handler.
 *
 * Handler to show characters count for a multiple target language
 * job or job item.
 *
 * @ViewsField("translation_workflow_characters_count")
 */
class CharactersCount extends StatisticsBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    $ret = '--';
    if ($entity instanceof MultipleTargetLanguageJob || $entity instanceof MultipleTargetLanguageJobItem) {
      $ret = $entity->getCharactersCount();
    }
    return $ret;
  }

}
