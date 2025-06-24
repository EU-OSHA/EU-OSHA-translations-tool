<?php

namespace Drupal\translation_workflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class NCWForm
 * @package Drupal\translation_workflow\Form
 */
class NCWForm extends FormBase {
  /**
   * @param $form
   * @param FormStateInterface $form_state
   * @param $form_id
   */
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Hide 'Request translation' button in a translation of node
    if($form_id == "tmgmt_content_translate_form"){
      unset($form['actions']['request']);
    }

    // Check if user is logged
    if (\Drupal::currentUser()->isAuthenticated()) {
      // Get the id of the current node
      $node = \Drupal::routeMatch()->getParameter('node');
      if ($node instanceof \Drupal\node\NodeInterface) {
        $nid = $node->id();

        // Get the state of the current node
        $queryUserSectionResult = \Drupal::database()
          ->query('SELECT t.item_id, t.state, t.target_language, t.tjid
            FROM tmgmt_job_item t
            WHERE t.item_id = :item_id', [":item_id" => $nid]);

        // Block node if it has been sent to translate.
        foreach ($queryUserSectionResult as $item) {
          if ($item->tjid == 0) {
            \Drupal::messenger()->addWarning(t('<div id="translation-job-message">This node is in the translation cart!</div>'));
          }
          elseif ($item->tjid > 0) {
            if ($item->state == 0 || $item->state == 1 || $item->state == 2 || $item->state == 5 || $item->state == 6) {
              // edition form
              if ( str_contains($form_id, '_edit_form')) {
                $form['#disabled'] = TRUE;

                // Get an id of the translation job
                $queryTranslationJobId = \Drupal::database()
                  ->query('SELECT MAX(DISTINCT(tjid)) tjid
                    FROM tmgmt_job_item t
                    WHERE t.item_id= :item_id', [":item_id" => $nid]);

                // Add a notification
                foreach ($queryTranslationJobId as $item) {
                  if ($item->tjid > 0) {
                    $job_number = $item->tjid;
                    $job_path = "/admin/translation_workflow/jobs/" . $job_number;
                    \Drupal::messenger()->addWarning(t('<div id="translation-job-message">This content is in an active translation job: <a href="@job_path">Translation job #@job_number</a></div>', array('@job_path' => $job_path, '@job_number' => $job_number)));
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  public function getFormId() {
    // TODO: Implement getFormId() method.
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // TODO: Implement buildForm() method.
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }
}
