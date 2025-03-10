<?php

namespace Drupal\translation_workflow;

use Drupal\tmgmt_config\ConfigSourcePluginUi;

/**
 * Class to override a method using trait.
 *
 * @see \Drupal\translation_workflow\MultipleTargetLanguageOverviewFormSubmitTrait
 */
class MultipleTargetLanguageConfigSourcePluginUi extends ConfigSourcePluginUi {
  use MultipleTargetLanguageOverviewFormSubmitTrait;
}
