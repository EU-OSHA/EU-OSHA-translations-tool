<?php

namespace Drupal\translation_workflow;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\JobCheckoutManager;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobQueue;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class to decorate tmgmt service.
 */
class MultipleTargetLanguageJobCheckoutManager extends JobCheckoutManager {

  /**
   * Original service implementation.
   *
   * @var \Drupal\tmgmt\JobCheckoutManager
   */
  protected $originalService;

  /**
   * {@inheritdoc}
   */
  public function __construct(JobCheckoutManager $manager, RequestStack $request_stack, JobQueue $job_queue, ModuleHandler $module_handler, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->originalService = $manager;
    parent::__construct($request_stack, $job_queue, $module_handler, $config_factory, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutAndRedirect(FormStateInterface $form_state, array $jobs) {
    $checkout_jobs = $this->checkoutMultiple($jobs);

    $jobs_ready_for_checkout = array_udiff($jobs, $checkout_jobs, function (JobInterface $a, JobInterface $b) {
      if ($a->id() < $b->id()) {
        return -1;
      }
      elseif ($a->id() > $b->id()) {
        return 1;
      }
      else {
        return 0;
      }
    });

    // If necessary, do a redirect.
    if ($checkout_jobs || $jobs_ready_for_checkout) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request->query->has('destination')) {
        // Remove existing destination, as that will prevent us from being
        // redirect to the job checkout page. Set the destination as the final
        // redirect instead.
        $redirect = $request->query->get('destination');
        $request->query->remove('destination');
      }
      else {
        $redirect = Url::fromRoute('<current>')->getInternalPath();
      }
      $this->jobQueue->startQueue(array_merge($checkout_jobs, $jobs_ready_for_checkout), $redirect);

      // Prepare a batch job for the jobs that can be submitted already.
      if ($checkout_jobs) {
        $batch = [
          'title' => t('Submitting jobs'),
          'operations' => [],
          'finished' => [JobCheckoutManager::class, 'batchSubmitFinished'],
        ];

        foreach ($checkout_jobs as $job) {
          $batch['operations'][] = [
            [JobCheckoutManager::class, 'batchSubmit'],
            [$job->id(), NULL],
          ];

        }
        batch_set($batch);
      }
      else {
        $form_state->setRedirectUrl($this->jobQueue->getNextUrl());
      }

      // Count of the job messages is one less due to the final redirect.
      $this->messenger()->addStatus($this->getStringTranslation()
        ->formatPlural(count($checkout_jobs), t('One job needs to be checked out.'), t('@count jobs need to be checked out.')));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function doBatchSubmit($job_id, $template_job_id = NULL) {
    $jobStorage = $this->entityTypeManager->getStorage('tmgmt_job_multiple_target');
    /** @var \Drupal\tmgmt\JobInterface $job */
    $job = $jobStorage->load($job_id);
    if (!$job) {
      return;
    }

    // Delete duplicates.
    if ($existing_items_ids = $job->getConflictingItemIds()) {
      $item_storage = $this->entityTypeManager->getStorage('tmgmt_job_item');
      if (count($existing_items_ids) == $job->getItems()) {
        $this->messenger()
          ->addStatus($this->t('All job items for job @label are conflicting, the job can not be submitted.', ['@label' => $job->label()]));
        return;
      }
      $item_storage->delete($item_storage->loadMultiple($existing_items_ids));
      $num_of_items = count($existing_items_ids);
      $this->messenger()->addWarning($this->getStringTranslation()
        ->formatPlural($num_of_items, '1 conflicting item has been dropped for job @label.', '@count conflicting items have been dropped for job @label.', ['@label' => $job->label()]));
    }

    if ($template_job_id && $job_id != $template_job_id) {
      /** @var \Drupal\tmgmt\JobInterface $template_job */
      $template_job = $jobStorage->load($template_job_id);
      if ($template_job) {
        $job->set('translator', $template_job->getTranslatorId());
        $job->set('settings', $template_job->get('settings')->getValue());

        // If there is a custom label on the template job, copy that as well.
        if ($template_job->get('label')->value) {
          $job->set('label', $template_job->get('label')->value);
        }
      }
    }

    $translator = $job->getTranslator();
    // Check translator availability.
    $translatable_status = $translator->checkTranslatable($job);
    if (!$translatable_status->getSuccess()) {
      $this->messenger()
        ->addError($this->t('Job @label is not translatable with the chosen settings: @reason', [
          '@label' => $job->label(),
          '@reason' => $translatable_status->getReason(),
        ]));
      return;
    }

    if ($this->requestTranslation($job)) {
      $this->jobQueue->markJobAsProcessed($job);
    }
  }

}
