<?php

namespace Drupal\translation_workflow\EventSubscriber;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\translation_workflow\Event\TranslationEvent;
use Drupal\translation_workflow\MailType;
use Drupal\translation_workflow\UsersToNotify;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for translation notifications.
 */
class TranslationEventSubscriber implements EventSubscriberInterface {

  /**
   * Mail manager to send emails.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  private $mailManager;

  /**
   * Search users service.
   *
   * @var \Drupal\translation_workflow\UsersToNotify
   */
  private $usersToNotify;

  /**
   * Handler constructor.
   */
  public function __construct(MailManagerInterface $mailManager, UsersToNotify $usersToNotify) {
    $this->mailManager = $mailManager;
    $this->usersToNotify = $usersToNotify;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      TranslationEvent::TRANSLATION_JOB_STATE_CHANGED => ['onJobStateChanged'],
      TranslationEvent::TRANSLATION_JOB_ITEM_STATE_CHANGED => ['onJobItemStateChanged'],
    ];
  }

  /**
   * Event handler for a job item state change.
   *
   * @param \Drupal\translation_workflow\Event\TranslationEvent $event
   *   Translation event.
   */
  public function onJobItemStateChanged(TranslationEvent $event) {
    $translationJobItem = $event->getJobItem();
    $module = 'translation_workflow';
    $langCode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $params = [
      'jobItem' => $event->getJobItem(),
    ];
    $key = '';
    $to = '';
    switch ($translationJobItem->getState()) {
      case MultipleTargetLanguageJobItem::STATE_REVIEW:
        $to = $this->usersToNotify->getByRole(['translation_manager']);
        $key = MailType::JOB_ITEM_REVIEW;
        break;

      case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATION_REQUIRED:
        $to = $this->usersToNotify->getByRole(['translation_manager']);
        $key = MailType::JOB_ITEM_VALIDATION_REQUIRED;
        break;

      case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATED:
        $to = $this->usersToNotify->getByRole([
          'content_validator',
          'review_manager',
        ]);
        $key = MailType::JOB_ITEM_VALIDATED;
        break;

      case MultipleTargetLanguageJobItem::STATE_ABORTED:
        $to = $this->usersToNotify->getByRole(['translation_manager']);
        $key = MailType::JOB_ITEM_ABORTED;
        break;

      case MultipleTargetLanguageJobItem::STATE_ACCEPTED:
        $to = $this->usersToNotify->getByRole([
          'translation_manager',
          'translation_liaison',
        ]);
        $key = MailType::JOB_ITEM_ACCEPTED;
        break;
    }
    if (!empty($to) && is_array($to)) {
      $to = implode(',', $to);
    }
    if (!empty($to) && !empty($key)) {
      $this->mailManager->mail($module, $key, $to, $langCode, $params, NULL, TRUE);
    }
  }

  /**
   * Event handler for a job state change.
   *
   * @param \Drupal\translation_workflow\Event\TranslationEvent $event
   *   Translation event.
   */
  public function onJobStateChanged(TranslationEvent $event) {
    $translationJob = $event->getJob();
    $module = 'translation_workflow';
    $langCode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $params = [
      'job' => $event->getJob(),
    ];
    $to = '';
    $key = '';
    switch ($translationJob->getState()) {
      case MultipleTargetLanguageJob::STATE_ACTIVE:
        $to = $this->usersToNotify->getByRole(['translation_liaison']);
        $key = MailType::JOB_ON_TRANSLATION;
        break;
    }
    if (!empty($to) && is_array($to)) {
      $to = implode(',', $to);
    }
    if (!empty($to) && !empty($key)) {
      $this->mailManager->mail($module, $key, $to, $langCode, $params, NULL, TRUE);
    }
  }

}
