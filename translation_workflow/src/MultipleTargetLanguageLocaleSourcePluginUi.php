<?php

namespace Drupal\translation_workflow;

use Drupal\tmgmt_locale\LocaleSourcePluginUi;

/**
 * Class to override a method using trait.
 *
 * @see \Drupal\translation_workflow\MultipleTargetLanguageOverviewFormSubmitTrait
 */
class MultipleTargetLanguageLocaleSourcePluginUi extends LocaleSourcePluginUi {
  use MultipleTargetLanguageOverviewFormSubmitTrait;
}
