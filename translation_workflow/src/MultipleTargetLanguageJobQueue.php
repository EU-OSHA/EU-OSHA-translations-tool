<?php

namespace Drupal\translation_workflow;

use Drupal\tmgmt\JobQueue;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;

/**
 * Class to decorate tmgmt service.
 */
class MultipleTargetLanguageJobQueue extends JobQueue {

  /**
   * Original service implementation.
   *
   * @var \Drupal\tmgmt\JobQueue
   */
  protected $queueService;

  /**
   * {@inheritdoc}
   */
  public function __construct(JobQueue $queueService) {
    $this->queueService = $queueService;
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function getNextJob() {
    while ($id = reset($this->queue)) {
      // Done.
      if ($job = MultipleTargetLanguageJob::load($id)) {
        return $job;
      }
      else {
        // Stale job ID that can't be loaded, remove it from the queue.
        array_shift($this->queue);
      }
    }
  }

}
