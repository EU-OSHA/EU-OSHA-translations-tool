<?php

namespace Drupal\translation_workflow;

/**
 * Emulates enum for mail types.
 */
final class MailType {

  const JOB_ON_TRANSLATION = "job-on-translation";

  const JOB_ITEM_ACCEPTED = "job-item-accepted";
  const JOB_ITEM_VALIDATION_REQUIRED = "job-item-validation-required";
  const JOB_ITEM_VALIDATED = "job-item-validated";
  const JOB_ITEM_ABORTED = "job-item-aborted";
  const JOB_ITEM_REVIEW = "job-item-review";

  /**
   * Not allow creating instances of this class.
   */
  private function __construct() {
  }

}
