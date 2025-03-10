<?php

namespace Drupal\translation_workflow\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\Message;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\TranslatableResult;
use Drupal\translation_workflow\CounterManager;
use Drupal\translation_workflow\Event\TranslationEvent;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Entity class for the tmgmt_job entity.
 *
 * @ContentEntityType(
 *   id = "tmgmt_job_multiple_target",
 *   label = @Translation("Multiple Target Language Translation Job"),
 *   module = "translation_workflow",
 *   handlers = {
 *     "access" = "Drupal\tmgmt\Entity\Controller\JobAccessControlHandler",
 *   "form" = {
 *       "edit" = "Drupal\translation_workflow\Form\MultipleTargetLanguageJobForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "abort" = "Drupal\tmgmt\Form\JobAbortForm",
 *       "resubmit_form" = "Drupal\tmgmt\Form\JobResubmitForm"
 *     },
 *     "list_builder" = "Drupal\translation_workflow\Entity\ListBuilder\MultipleTargetLanguageJobListBuilder",
 *     "views_data" = "Drupal\translation_workflow\Entity\ViewsData\MultipleTargetLanguageJobViewsData"
 *   },
 *   base_table = "tmgmt_multiple_target_job",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/translation_workflow/jobs/{tmgmt_job_multiple_target}",
 *     "delete-form" = "/admin/translation_workflow/jobs/{tmgmt_job_multiple_target}/delete",
 *     "abort-form" = "/admin/translation_workflow/jobs/{tmgmt_job_multiple_target}/abort",
 *     "resubmit-form" = "/admin/translation_workflow/jobs/{tmgmt_job_multiple_target}/resubmit"
 *   }
 * )
 *
 * @ingroup tmgmt_job
 */
class MultipleTargetLanguageJob extends ContentEntityBase implements EntityOwnerInterface, PriorityJobInterface {

  /**
   * Character allowed per page to calculate pages number.
   */
  const CHARACTERS_PER_PAGE = 1500;

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('The label of this job.'))
      ->setDefaultValue('')
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => -5,
      ]);
    $fields['source_language'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Source language code'))
      ->setDescription(t('The source language.'));

    $fields['target_languages'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Target language code'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDescription(t('The target language.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t('The user that is the job owner.'))
      ->setSettings([
        'target_type' => 'user',
      ])
      ->setDefaultValue(0);

    $fields['translator'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Provider'))
      ->setDescription(t('The selected provider'))
      ->setSettings([
        'target_type' => 'tmgmt_translator',
      ]);
    $fields['job_items'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Job items'))
      ->setDescription(t('Job items to be processed'))
      ->setSettings([
        'target_type' => 'tmgmt_job_item',
      ]);

    $fields['state'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('Job state'))
      ->setDescription(t('The job state.'))
      ->setSetting('allowed_values', self::getStates())
      ->setDefaultValue(static::STATE_UNPROCESSED);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the job was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the job was last edited.'));
    $fields['priority'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Job priority'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDefaultValue('normal')
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setDisplayOptions('form', [
        'region' => 'hidden',
      ])
      ->setSetting('allowed_values', self::getPriorities())
      ->setDescription('Set job priority.');

    $fields['file_uploaded'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('File uploaded'))
      ->setDescription(t('A boolean indicating if job has a file uploaded or not.'))
      ->setDefaultValue(FALSE);

    $fields['file_sent'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('File Sent to CDT'))
      ->setDescription(t('A boolean indicating if the translation file was sent to CDT.'))
      ->setDefaultValue(FALSE);

    return $fields;
  }

  /**
   * Return priorities values.
   *
   * @return array
   *   Priorities values.
   */
  public static function getPriorities() {
    return [
      static::PRIORITY_LOW => t('Low'),
      static::PRIORITY_NORMAL => t('Normal'),
      static::PRIORITY_HIGH => t('High'),
    ];
  }

  /**
   * Get if file has been uploaded to job.
   *
   * @return bool
   *   Return if file has been uploaded to job.
   */
  public function isFileUploaded() {
    return $this->get('file_uploaded')->getString() == 1;
  }

  /**
   * Get if job is sent to CDT.
   *
   * @return bool
   *   Return if job is sent to CDT.
   */
  public function isSentToCdt() {
    return $this->get('file_sent')->getString() == 1;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->setOwnerId($account->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->getOwner()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetLanguage() {
    /* As we have multiple target languages returns only first one to maintain
    default behaviour of module.*/
    return $this->get('target_languages')->language;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetLangcode() {
    return $this->getTargetLanguage()->getId();
  }

  /**
   * Returns the target languages.
   *
   * @return \Drupal\Core\Language\LanguageInterface[]
   *   The target languages.
   */
  public function getTargetLanguages() {
    $targetLangcodes = [];
    foreach ($this->get('target_languages') as $language) {
      $targetLangcodes[] = $language->language;
    }
    return $targetLangcodes;
  }

  /**
   * Returns the target language codes.
   *
   * @return string[]
   *   The target language codes
   */
  public function getTargetLangcodes() {
    $langcodes = [];
    $values = $this->get('target_languages')->getValue();
    foreach ($values as $value) {
      $langcodes[] = $value['value'];
    }
    return $langcodes;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceLanguage() {
    return $this->get('source_language')->language;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceLangcode() {
    return $this->getSourceLanguage()->getId();
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function addItem($plugin, $item_type, $item_id) {
    $transaction = \Drupal::database()->startTransaction();
    $is_new = FALSE;

    if ($this->isNew()) {
      $this->save();
      $is_new = TRUE;
    }

    $item = tmgmt_job_item_create($plugin, $item_type, $item_id, ['tjid' => $this->id()]);
    $item->save();

    if ($item->getWordCount() == 0) {
      $transaction->rollback();

      // In case we got word count 0 for the first job item, NULL tjid so that
      // if there is another addItem() call the rolled back job object will get
      // persisted.
      if ($is_new) {
        $this->tjid = NULL;
      }

      throw new TMGMTException('Job item @label (@type) has no translatable content.',
        ['@label' => $item->label(), '@type' => $item->getSourceType()]);
    }

    else {
      $this->addExistingItem($item);
    }

    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function addExistingItem(JobItemInterface $item) {
    $item->set('tjid', $this->id());
    $item->save();
    $this->get('job_items')->appendItem($item);
  }

  /**
   * {@inheritdoc}
   */
  public function addMessage($message, $variables = [], $type = 'status') {
    // Save the job if it hasn't yet been saved.
    if (!$this->isNew() || $this->save()) {
      $message = tmgmt_message_create($message, $variables, [
        'tjid' => $this->id(),
        'type' => $type,
      ]);
      if ($message->save()) {
        return $message;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getItems($conditions = []) {
    $items = [];
    $query = \Drupal::entityQuery('tmgmt_job_item')
      ->accessCheck(FALSE)
      ->condition('tjid', $this->id());
    foreach ($conditions as $key => $condition) {
      if (is_array($condition)) {
        $operator = isset($condition['operator']) ? $condition['operator'] : 'IN';
        $query->condition($key, $condition['value'], $operator);
      }
      else {
        $query->condition($key, $condition);
      }
    }
    $query->sort('tjiid', 'ASC');
    $results = $query->execute();
    if (!empty($results)) {
      $items = JobItem::loadMultiple($results);
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getMostRecentItem($plugin, $item_type, $item_id) {
    $jobItem = NULL;
    $query = \Drupal::entityQuery('tmgmt_job_item')
      ->accessCheck(FALSE)
      ->condition('tjid', $this->id())
      ->condition('plugin', $plugin)
      ->condition('item_type', $item_type)
      ->condition('item_id', $item_id)
      ->sort('tjiid', 'DESC')
      ->range(0, 1);
    $result = $query->execute();
    if (!empty($result)) {
      $jobItem = JobItem::load(reset($result));
    }
    return $jobItem;
  }

  /**
   * {@inheritdoc}
   */
  public function getMessages($conditions = []) {
    $query = \Drupal::entityQuery('tmgmt_message')
      ->accessCheck(FALSE)
      ->condition('tjid', $this->id());
    foreach ($conditions as $key => $condition) {
      if (is_array($condition)) {
        $operator = isset($condition['operator']) ? $condition['operator'] : '=';
        $query->condition($key, $condition['value'], $operator);
      }
      else {
        $query->condition($key, $condition);
      }
    }
    $query->sort('created', 'ASC');
    $query->sort('mid', 'ASC');
    $results = $query->execute();
    if (!empty($results)) {
      return Message::loadMultiple($results);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getMessagesSince($time = NULL) {
    $time = isset($time) ? $time : \Drupal::time()->getRequestTime();
    $conditions = ['created' => ['value' => $time, 'operator' => '>=']];
    return $this->getMessages($conditions);
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslatorId() {
    return $this->getTranslator()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslatorLabel() {
    return $this->getTranslator()->label();
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslator() {
    if ($this->hasTranslator()) {
      return $this->get('translator')->entity;
    }
    elseif (!$this->translator->entity) {
      throw new TMGMTException('The job has no provider assigned.');
    }
    elseif (!$this->translator->entity->hasPlugin()) {
      throw new TMGMTException('The translator assigned to this job is missing the plugin.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasTranslator() {
    return $this->get('translator')->count() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getState() {
    return $this->get('state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setState($state, $message = NULL, $variables = [], $type = 'debug') {
    // Return TRUE if the state could be set. Return FALSE otherwise.
    if (array_key_exists($state, static::getStates())) {
      $this->set('state', $state);
      $this->save();
      // If a message is attached to this state change add it now.
      if (!empty($message)) {
        $this->addMessage($message, $variables, $type);
      }
    }
    \Drupal::service('event_dispatcher')->dispatch(new TranslationEvent($this, NULL), TranslationEvent::TRANSLATION_JOB_STATE_CHANGED);
    return $this->getState();
  }

  /**
   * {@inheritdoc}
   */
  public function isState($state) {
    return $this->getState() == $state;
  }

  /**
   * {@inheritdoc}
   */
  public function isAuthor(AccountInterface $account = NULL) {
    $account = $account ?? \Drupal::currentUser();
    return $this->getOwnerId() == $account->id();
  }

  /**
   * {@inheritdoc}
   */
  public function isUnprocessed() {
    return $this->isState(static::STATE_UNPROCESSED);
  }

  /**
   * {@inheritdoc}
   */
  public function isAborted() {
    return $this->isState(static::STATE_ABORTED);
  }

  /**
   * {@inheritdoc}
   */
  public function isActive() {
    return $this->isState(static::STATE_ACTIVE);
  }

  /**
   * {@inheritdoc}
   */
  public function isRejected() {
    return $this->isState(static::STATE_REJECTED);
  }

  /**
   * {@inheritdoc}
   */
  public function isFinished() {
    return $this->isState(static::STATE_FINISHED);
  }

  /**
   * {@inheritdoc}
   */
  public function isContinuousActive() {
    return $this->isState(static::STATE_CONTINUOUS);
  }

  /**
   * {@inheritdoc}
   */
  public function isContinuousInactive() {
    return $this->isState(static::STATE_CONTINUOUS_INACTIVE);
  }

  /**
   * {@inheritdoc}
   */
  public function canRequestTranslation() {
    if ($translator = $this->getTranslator()) {
      return $translator->checkTranslatable($this);
    }
    return TranslatableResult::no(t('Translation cant be requested.'));
  }

  /**
   * {@inheritdoc}
   */
  public function isAbortable() {
    // Only non-submitted translation jobs can be aborted.
    if ($this->isContinuous()) {
      return FALSE;
    }
    else {
      return $this->isActive();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isSubmittable() {
    if ($this->isContinuous()) {
      return FALSE;
    }
    else {
      return $this->isUnprocessed() || $this->isRejected();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDeletable() {
    return !$this->isActive();
  }

  /**
   * {@inheritdoc}
   */
  public function isContinuous() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitted($message = NULL, $variables = [], $type = 'status') {
    if (!isset($message)) {
      $message = 'The translation job has been submitted.';
    }
    $this->setState(static::STATE_ACTIVE, $message, $variables, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function finished($message = NULL, $variables = [], $type = 'status') {
    if (!isset($message)) {
      $message = 'The translation job has been finished.';
    }
    return $this->setState(static::STATE_FINISHED, $message, $variables, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function aborted($message = NULL, $variables = [], $type = 'status') {
    if (!isset($message)) {
      $message = 'The translation job has been aborted.';
    }
    /** @var \Drupal\tmgmt\JobItemInterface $item */
    foreach ($this->getItems() as $item) {
      $item->setState(JobItem::STATE_ABORTED);
    }
    return $this->setState(static::STATE_ABORTED, $message, $variables, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function rejected($message = NULL, $variables = [], $type = 'error') {
    if (!isset($message)) {
      $message = 'The translation job has been rejected by the translation provider.';
    }
    return $this->setState(static::STATE_REJECTED, $message, $variables, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation() {
    if (!$this->canRequestTranslation()->getSuccess()) {
      return FALSE;
    }
    if (!$this->isContinuous()) {
      $this->setOwnerId(\Drupal::currentUser()->id());
    }

    $moduleHandler = \Drupal::moduleHandler();

    // Call the hook before requesting the translation.
    $moduleHandler->invokeAll('tmgmt_job_before_request_translation', [$this->getItems()]);

    // We do not want to translate the items that are already translated.
    $this->filterTranslatedItems = TRUE;

    // We don't know if the translator plugin already processed our
    // translation request after this point. That means that the plugin has to
    // set the 'submitted', 'needs review', etc. states on its own.
    if (!empty($this->getItems())) {
      $this->getTranslatorPlugin()->requestTranslation($this);
    }
    else {
      $this->submitted();
    }

    // Reset it again so getData returns again all the values.
    $this->filterTranslatedItems = FALSE;

    // Call the hook after requesting the translation.
    $moduleHandler->invokeAll('tmgmt_job_after_request_translation', [$this->getItems()]);
  }

  /**
   * {@inheritdoc}
   */
  public function abortTranslation() {
    if (!$this->isAbortable() || !$plugin = $this->getTranslatorPlugin()) {
      return FALSE;
    }
    // We don't know if the translator plugin was able to abort the translation
    // job after this point. That means that the plugin has to set the
    // 'aborted' state on its own.
    return $plugin->abortTranslation($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getTranslatorPlugin() {
    return $this->getTranslator()->getPlugin();
  }

  /**
   * {@inheritdoc}
   */
  public function getData($key = [], $index = NULL) {
    $data = [];
    if (!empty($key)) {
      $tjiid = array_shift($key);
      $item = JobItem::load($tjiid);
      if ($item) {
        $data[$tjiid] = $item->getData($key, $index);
        // If not set, use the job item label as the data label.
        if (!isset($data[$tjiid]['#label'])) {
          $data[$tjiid]['#label'] = $item->getSourceLabel();
        }
      }
    }
    else {
      foreach ($this->getItems() as $tjiid => $item) {
        $data[$tjiid] = $item->getData();
        // If not set, use the job item label as the data label.
        if (!isset($data[$tjiid]['#label'])) {
          $data[$tjiid]['#label'] = $item->getSourceLabel();
        }
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getCountPending() {
    return tmgmt_job_statistic($this, 'count_pending');
  }

  /**
   * {@inheritdoc}
   */
  public function getCountTranslated() {
    return tmgmt_job_statistic($this, 'count_translated');
  }

  /**
   * {@inheritdoc}
   */
  public function getCountAccepted() {
    return tmgmt_job_statistic($this, 'count_accepted');
  }

  /**
   * {@inheritdoc}
   */
  public function getCountReviewed() {
    return tmgmt_job_statistic($this, 'count_reviewed');
  }

  /**
   * {@inheritdoc}
   */
  public function getWordCount() {
    return tmgmt_job_statistic($this, 'word_count');
  }

  /**
   * Returns characters count for items.
   *
   * 27/05/2022 (jorge.perez) - Remove function code to use CounterManager
   * class, that compute successfully the translation or retranslations of
   * JobItems.
   *
   * @return int
   *   Characters count.
   */
  public function getCharactersCount() {
    $items = $this->getItems();
    $count = CounterManager::countJobItems($items);
    return $count;
  }

  /**
   * Get page count for items.
   *
   * @return string
   *   Page count.
   */
  public function getPageCount() {
    return number_format($this->getCharactersCount() / self::CHARACTERS_PER_PAGE, 2, ',', '');
  }

  /**
   * {@inheritdoc}
   */
  public function getTagsCount() {
    return tmgmt_job_statistic($this, 'tags_count');
  }

  /**
   * {@inheritdoc}
   */
  public function addTranslatedData(array $data, $key = NULL, $status = NULL) {
    $itemsSearch = [];
    if (isset($data['target_languages'])) {
      $itemsSearch = ['target_language' => ['value' => $data['target_languages']]];
      unset($data['target_languages']);
    }
    $key = \Drupal::service('tmgmt.data')->ensureArrayKey($key);
    $items = $this->getItems($itemsSearch);
    // If there is a key, get the specific item and forward the call.
    if (!empty($key)) {
      $item_id = array_shift($key);
      if (isset($items[$item_id])) {
        $items[$item_id]->addTranslatedData($data, $key, $status);
      }
    }
    else {
      foreach ($data as $key => $value) {
        if (isset($items[$key])) {
          $items[$key]->addTranslatedData($value, [], $status);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function acceptTranslation() {
    foreach ($this->getItems() as $item) {
      $item->acceptTranslation();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteMappings() {
    $trids = \Drupal::entityQuery('tmgmt_remote')
      ->condition('tjid', $this->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($trids)) {
      return RemoteMapping::loadMultiple($trids);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSuggestions(array $conditions = []) {
    $suggestions = \Drupal::moduleHandler()->invokeAll('tmgmt_source_suggestions', [
      $this->getItems($conditions),
      $this,
    ]);

    // EachJob needs a job id to be able to count the words, because the
    // source-language is stored in the job and not the item.
    foreach ($suggestions as &$suggestion) {
      $jobItem = $suggestion['job_item'];
      $jobItem->tjid = $this->id();
      $jobItem->recalculateStatistics();
    }
    return $suggestions;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanSuggestionsList(array &$suggestions) {
    foreach ($suggestions as $k => $suggestion) {
      if (is_array($suggestion) && isset($suggestion['job_item']) && ($suggestion['job_item'] instanceof JobItem)) {
        $jobItem = $suggestion['job_item'];

        // Items with no words to translate should not be presented.
        if ($jobItem->getWordCount() <= 0) {
          unset($suggestions[$k]);
          continue;
        }

        // Check if there already exists a translation job for this item in the
        // current language.
        $items = tmgmt_job_item_load_all_latest($jobItem->getPlugin(), $jobItem->getItemType(), $jobItem->getItemId(), $this->getSourceLangcode());
        if (isset($items[$this->getTargetLangcode()])) {
          unset($suggestions[$k]);
          continue;
        }

        // If the item is part of the current job, no matter which language,
        // remove it.
        foreach ($items as $item) {
          if ($item->getJobId() == $this->id()) {
            unset($suggestions[$k]);
            continue;
          }
        }
      }
      else {
        unset($suggestions[$k]);
        continue;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isTranslatable() {
    // Translation jobs themself can not be translated.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function language() {
    return \Drupal::languageManager()->getLanguage(LanguageInterface::LANGCODE_NOT_SPECIFIED);
  }

  /**
   * {@inheritdoc}
   */
  public static function getStates() {
    return [
      static::STATE_UNPROCESSED => t('Unprocessed'),
      static::STATE_ACTIVE => t('Active'),
      static::STATE_REJECTED => t('Rejected'),
      static::STATE_ABORTED => t('Aborted'),
      static::STATE_FINISHED => t('Finished'),
      static::STATE_CONTINUOUS => t('Continuous'),
      static::STATE_CONTINUOUS_INACTIVE => t('Continuous Inactive'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getReference() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getJobType() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getContinuousSettings() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function cloneAsUnprocessed() {
    $clone = $this->createDuplicate();
    $clone->uid->value = 0;
    $clone->created->value = \Drupal::time()->getRequestTime();
    $clone->state->value = static::STATE_UNPROCESSED;
    return $clone;
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteSourceLanguage() {
    return $this->getTranslator()->mapToRemoteLanguage($this->getSourceLangcode());
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteTargetLanguage() {
    return $this->getTranslator()->mapToRemoteLanguage($this->getTargetLangcode());
  }

  /**
   * {@inheritdoc}
   */
  public function getSetting($name) {
    $ret = NULL;
    if (isset($this->settings->$name)) {
      $ret = $this->settings->$name;
    }
    // The translator might provide default settings.
    if ($this->hasTranslator()) {
      if (($setting = $this->getTranslator()->getSetting($name)) !== NULL) {
        $ret = $setting;
      }
      if (!is_string($ret)) {
        $defaults = $this->getTranslatorPlugin()->defaultSettings();
        if (isset($defaults[$name])) {
          $ret = $defaults[$name];
        }
      }
    }
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function getConflictingItemIds() {
    $conflicting_item_ids = [];
    foreach ($this->getItems() as $item) {
      // Count existing job items that are have the same languages, same source,
      // are active or in review and are not the job item that we are checking.
      $existing_items_count = \Drupal::entityQuery('tmgmt_job_item')
        ->accessCheck(FALSE)
        ->condition('state', [
          JobItemInterface::STATE_ACTIVE,
          JobItemInterface::STATE_REVIEW,
        ], 'IN')
        ->condition('plugin', $item->getPlugin())
        ->condition('item_type', $item->getItemType())
        ->condition('item_id', $item->getItemId())
        ->condition('tjiid', $item->id(), '<>')
        ->condition('tjid.entity.source_language', $this->getSourceLangcode())
        ->condition('tjid.entity.target_language', $this->getTargetLangcode())
        ->count()
        ->execute();

      // If there are any, this is a conflicting job item.
      if ($existing_items_count) {
        $conflicting_item_ids[] = $item->id();
      }
    }
    return $conflicting_item_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority() {
    return $this->get('priority')->value;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   Throws exception if an invalid value is added.
   */
  public function setPriority(string $priority = self::PRIORITY_NORMAL) {
    if (in_array($priority, $this->getPriorityValues())) {
      $this->set('priority', $priority);
    }
    else {
      throw new TMGMTException($this->t('Invalid argument value supplied for priority'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getPriorityValues() {
    return static::getPriorities();
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    $label = parent::label();
    if (empty($label)) {
      $label = $this->t('Translation job #@id', [
        '@id' => $this->id(),
      ]);
    }
    return $label;
  }

  public function getConflictingItems()
  {
    // TODO: Implement getConflictingItems() method.
  }
}
