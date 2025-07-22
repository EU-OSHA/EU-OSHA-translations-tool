<?php

namespace Drupal\translation_workflow\Plugin\views\field;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\tmgmt\Plugin\views\field\Progress as Tmgmt_Progress;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\views\ResultRow;

/**
 * Field handler which shows the progress of a job or job item.
 *
 * @ViewsField("translation_workflow_progress")
 */
class Progress extends Tmgmt_Progress {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity;
    if ($entity instanceof MultipleTargetLanguageJob) {
      $jobItemStates = MultipleTargetLanguageJobItem::getStates();
      $jobStatesCount = array_fill_keys(array_keys($jobItemStates), 0);
      foreach ($entity->getItems() as $item) {
        $state = $item->getState();
        $jobStatesCount[$state] += 1;
      }
      $humanReadableStatesCount = $jobStatesCount;
      array_walk($humanReadableStatesCount, function (&$stateCount, $stateKey) use ($jobItemStates) {
        $stateCount = new FormattableMarkup('@state: @count', [
          '@state' => $jobItemStates[$stateKey],
          '@count' => $stateCount,
        ]);
      });

      return new FormattableMarkup('<span title="@title">@text</span>', [
        '@title' => implode(', ', $humanReadableStatesCount),
        '@text' => implode('/', $jobStatesCount),
      ]);
    }
    else {
      return parent::render($values);
    }
  }

}
