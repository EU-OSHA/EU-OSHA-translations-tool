<?php

namespace Drupal\translation_workflow\Entity\ViewsData;

use Drupal\views\EntityViewsData;

/**
 * Class used to show information about job.
 */
class MultipleTargetLanguageJobViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['tmgmt_multiple_target_job']['progress'] = [
      'title' => 'Progress',
      'help' => 'Displays the progress of a job.',
      'real field' => 'id',
      'field' => [
        'id' => 'translation_workflow_progress',
      ],
    ];

    $data['tmgmt_multiple_target_job']['word_count'] = [
      'title' => 'Word count',
      'help' => 'Displays the word count of a job.',
      'real field' => 'id',
      'field' => [
        'id' => 'tmgmt_wordcount',
      ],
      'sort' => [],
    ];

    $data['tmgmt_multiple_target_job']['characters_count'] = [
      'title' => 'Characters count',
      'help' => 'Displays the characters count of a job.',
      'real field' => 'id',
      'field' => [
        'id' => 'translation_workflow_characters_count',
      ],
      'sort' => [],
    ];

    $data['tmgmt_multiple_target_job']['page_count'] = [
      'title' => 'Page count',
      'help' => 'Displays the page count of a job.',
      'real field' => 'id',
      'field' => [
        'id' => 'translation_workflow_pagecount',
      ],
      'sort' => [],
    ];
    return $data;
  }

}
