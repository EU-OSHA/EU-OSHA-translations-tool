<?php

namespace Drupal\translation_workflow\Entity\ViewsData;

use Drupal\tmgmt\Entity\ViewsData\JobItemViewsData;

/**
 * Class used to display information about job items.
 */
class MultipleTargetLanguageJobItemViewsData extends JobItemViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['tmgmt_job_item']['source_language'] = [
      'title' => 'Source language',
      'help' => 'Source language of job item.',
      'real field' => 'tjiid',
      'field' => [
        'id' => 'translation_workflow_source_language',
      ],
    ];

    $data['tmgmt_job_item']['page_count'] = [
      'title' => 'Page count',
      'help' => 'Displays the page count of a job.',
      'real field' => 'tjiid',
      'field' => [
        'id' => 'translation_workflow_pagecount',
      ],
      'sort' => [],
    ];

    $data['tmgmt_job_item']['characters_count'] = [
      'title' => 'Characters count',
      'help' => 'Displays the characters count of a job.',
      'real field' => 'tjiid',
      'field' => [
        'id' => 'translation_workflow_characters_count',
      ],
      'sort' => [],
    ];

    return $data;
  }

}
