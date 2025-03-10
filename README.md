This document covers:
* _Technical pre-requirements:_ Detailing the technical prerequisites and constraints needed to integrate the EU-OSHA translation tool in an existing Drupal CMS installation.
* _Modules required:_ Detailing the modules needed for the EU-OSHA translation tool.
* _Functionalities of the EU-OSHA translation tool:_ Describing the main functionalities of the EUOSHA translation tool as user stories.

## Technical pre-requirements

This section describes the requirements that must be accomplished to use the EU-OSHA translation tool:
* _Drupal version:_ The EU-OSHA translation tool has been implemented in Drupal 10; therefore, it must be considered that no other major version is supported.
* _Translation level:_ The EU-OSHA translation tool uses field-level translation.
* _EU-OSHA translation tool source code:_ The translation tool's translation workflow module source code will be available on EU-OSHA GitHub.

## Modules required
This section describes the list of modules that the EU-OSHA translation tool needs to be installed to ensure that this tool can be used. The list below presents the list of these required modules:
* tmgmt module https://www.drupal.org/project/tmgmt/releases/8.x-1.15
    * Translation Management Core 8.x-1.15. (_tmgmt_)
    * Locales Source. (_tmgmt_locale_)
    * Export / Import File. (_tmgmt_file_)
    * Drupal user. (_tmgmt_local_)
    * Content Entity Source. (_tmgmt_content_)
* Interface Translation. (_locale_)
* Content Translation https://www.drupal.org/docs/8/core/modules/content-translation . (_content_translation_)
* Configuration Translation. (_config_translation_)
* Translation workflow (custom module with the improvements in the tmgmt module). (_translation_workflow_)
* Translation workflow node block (custom module with the block node if it has been sent to translate). (_translation_workflow_node_block_)

Moreover, since the EU-OSHA translation tool has been developed based on the Translation Management Tool module, the following list of modules must be installed:
* Entity API. (_entity_)
* Views. (_views_)
* Chaos Tools https://www.drupal.org/project/ctools .(_ctools_) 
* Views Bulk Operations. (_views_bulk_operations_)
* Content Translation https://www.drupal.org/docs/8/core/modules/content-translation . (_content_translation_)
* Locale. (_locale_)
* Internationalization/i18n (for string translation).
* Entity translation (for the entity source).
* Rules (for node translation) https://www.drupal.org/project/rules . (_rules_)

## How to install and how to use the translation workflow
For more information about how to install and use, please, check the documentation with the name "EU-OSHA_ translation tool guideline_v1.0.pdf"
