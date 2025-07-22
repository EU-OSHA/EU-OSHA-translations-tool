<?php

namespace Drupal\translation_workflow;

/**
 * Translation Workflow available languages.
 */
class TranslationWorkflowLanguages {

  /**
   * Define which languages are supported by the translation workflow.
   */
  const DEFAULT_LANGUAGES = [
    'en', 'bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'hr',
    'fr', 'hu', 'is', 'it', 'lv', 'lt', 'nl', 'mt', 'no', 'pl',
    'pt-pt', 'ro', 'sk', 'sl', 'sv',
  ];

  /**
   * Get the list of supported languages.
   *
   * @return array
   *   An array of language codes.
   */
  public static function getLanguages(): array {
    $config = \Drupal::config('translation_workflow.language_settings');
    $languages = $config->get('translation_workflow_languages') ?? self::DEFAULT_LANGUAGES;
    return array_map('trim', is_array($languages) ? $languages : explode(',', $languages));
  }

}
