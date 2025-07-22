<?php

namespace Drupal\translation_workflow\EventSubscriber;

use Drupal\translation_workflow\MailType;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\translation_workflow\UsersToNotify;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\translation_workflow\TmgmtElementsUtils;
use Drupal\translation_workflow\Event\TranslationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;

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
   * Language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Tmgmt elements utils service.
   *
   * @var \Drupal\translation_workflow\TmgmtElementsUtils
   */
  protected $tmtgmtElementsUtils;

  /**
   * Handler constructor.
   */
  public function __construct(MailManagerInterface $mailManager, UsersToNotify $usersToNotify, LanguageManagerInterface $languageManager, TmgmtElementsUtils $tmtgmtElementsUtils) {
    $this->mailManager = $mailManager;
    $this->usersToNotify = $usersToNotify;
    $this->languageManager = $languageManager;
    $this->tmtgmtElementsUtils = $tmtgmtElementsUtils;
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
    $langCode = $this->languageManager->getDefaultLanguage()->getId();
    $params = ['jobItem' => $translationJobItem];

    $notificationRoles = $this->tmtgmtElementsUtils->getNotificationRoles();
    $jobItemState = $translationJobItem->getState();
    $key = $this->getMailTypeFromJobItemState($jobItemState);
    $roles = $notificationRoles[$jobItemState] ?? [];
    $to = $this->usersToNotify->getByRole($roles);

    if ($jobItemState == MultipleTargetLanguageJobItem::STATE_ACCEPTED) {
      $itemId = $translationJobItem->getItemId();
      $itemType = $translationJobItem->getItemType();
      $jobItenLangCode = $translationJobItem->getTargetLanguage()->getId();
      $sourceEntity = \Drupal::entityTypeManager()->getStorage($itemType)->load($itemId);
      if ($sourceEntity->hasTranslation($jobItenLangCode)) {
        $translatedEntity = $sourceEntity->getTranslation($jobItenLangCode);
        $translatedEntity->setChangedTime(time());
        $translatedEntity->save();
      }
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
    $langCode = $this->languageManager->getDefaultLanguage()->getId();
    $params = ['job' => $event->getJob()];

    $notificationRoles = $this->tmtgmtElementsUtils->getNotificationRoles();
    $jobState = $translationJob->getState();
    $key = $this->getMailTypeFromJobState($jobState);
    $to = $this->usersToNotify->getByRole($notificationRoles[$jobState]);

    if (!empty($to) && is_array($to)) {
      $to = implode(',', $to);
    }
    if (!empty($to) && !empty($key)) {
      \Drupal::logger('translation_workflow')->debug('Sending email to @to with key @key', ['@to' => $to, '@key' => $key]);
      $this->mailManager->mail($module, $key, $to, $langCode, $params, NULL, TRUE);
    }
  }

    /**
   * Returns the mail type based on the job state.
   *
   * @param int $jobState
   *   The job state.
   *
   * @return string
   *   The mail type.
   */
  function getMailTypeFromJobState(int $jobState): string {
    switch ($jobState) {
      case MultipleTargetLanguageJob::STATE_ACTIVE:
        return MailType::JOB_ON_TRANSLATION;

      default:
        return '';
    }
  }

  /**
   * Returns the mail type based on the job item state.
   *
   * @param int $jobItemState
   *   The job item state.
   *
   * @return string
   *   The mail type.
   */
  function getMailTypeFromJobItemState(int $jobItemState): string {
    switch ($jobItemState) {
      case MultipleTargetLanguageJobItem::STATE_REVIEW:
        return MailType::JOB_ITEM_REVIEW;

      case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATION_REQUIRED:
        return MailType::JOB_ITEM_VALIDATION_REQUIRED;

      case MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATED:
        return MailType::JOB_ITEM_VALIDATED;

      case MultipleTargetLanguageJobItem::STATE_ABORTED:
        return MailType::JOB_ITEM_ABORTED;

      case MultipleTargetLanguageJobItem::STATE_ACCEPTED:
        return MailType::JOB_ITEM_ACCEPTED;

      default:
        return '';
    }
  }

}
