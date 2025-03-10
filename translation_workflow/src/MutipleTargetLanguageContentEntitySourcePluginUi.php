<?php

namespace Drupal\translation_workflow;

use Drupal\tmgmt_content\ContentEntitySourcePluginUi;

/**
 * Class to override a method using trait.
 *
 * @see \Drupal\translation_workflow\MultipleTargetLanguageOverviewFormSubmitTrait
 */
class MutipleTargetLanguageContentEntitySourcePluginUi extends ContentEntitySourcePluginUi {
  use MultipleTargetLanguageOverviewFormSubmitTrait;
}
