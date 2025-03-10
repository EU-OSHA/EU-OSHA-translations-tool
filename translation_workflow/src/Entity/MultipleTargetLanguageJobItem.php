<?php

namespace Drupal\translation_workflow\Entity;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Render\Element;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\JobItemInterface;
use Drupal\translation_workflow\CounterManager;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class to extends functionalities of job items.
 */
class MultipleTargetLanguageJobItem extends JobItem {

  use MessengerTrait;

  /**
   * Define state validation required.
   */
  const STATE_TRANSLATION_VALIDATION_REQUIRED = 5;

  /**
   * Define state translation validated.
   */
  const STATE_TRANSLATION_VALIDATED = 6;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fieldsDefinitions = parent::baseFieldDefinitions($entity_type);
    $fieldsDefinitions['target_language'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Target language code'))
      ->setDescription(t('The target language.'));

    $fieldsDefinitions['retranslation_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Retranslation data'))
      ->setDescription(t('The source retranslation data'));
    return $fieldsDefinitions;

  }

  /**
   * Get State label.
   */
  public static function getStateLabel($state = NULL) {
    $states = static::getStates();
    return $state[$state] ?? NULL;

  }

  /**
   * {@inheritdoc}
   */
  public static function getStates() {
    return [
      static::STATE_ACTIVE => t('On Translation'),
      static::STATE_REVIEW => t('Translated'),
      static::STATE_TRANSLATION_VALIDATION_REQUIRED => t('Content Validation Required'),
      static::STATE_ABORTED => t('Translation Rejected'),
      static::STATE_TRANSLATION_VALIDATED => t('Translation Validated'),
      static::STATE_ACCEPTED => t('Ready to Publish'),
      static::STATE_INACTIVE => t('Inactive'),
    ];

  }

  /**
   * Get page count for items.
   *
   * @return string
   *   Page count.
   */
  public function getPageCount() {
    return number_format(($this->getCharactersCount() / MultipleTargetLanguageJob::CHARACTERS_PER_PAGE), 2, ',', '');

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
    $count = CounterManager::countJobItems([$this]);
    return $count;
  }

  /**
   * Returns the target language.
   *
   * @return \Drupal\Core\Language\LanguageInterface
   *   The target language.
   */
  public function getTargetLanguage() {
    return $this->get('target_language')->language;

  }

  /**
   * Assign a target language.
   *
   * @param string $langcode
   *   Target language to assign.
   */
  public function setTargetLanguage(string $langcode) {
    $this->set('target_language', $langcode);

  }

  /**
   * Returns the target language code.
   *
   * @return string
   *   The target language code
   */
  public function getTargetLangcode() {
    return $this->getTargetLanguage()->getId();

  }

  /**
   * {@inheritdoc}
   */
  public function getJob() {
    return MultipleTargetLanguageJob::load($this->getJobId());

  }

  /**
   * {@inheritdoc}
   */
  public function addTranslatedData(array $translation, $key = [], $status = NULL) {
    $job = $this->getJob();

    if ($this->isInactive()) {
      // The job item can not be inactive and receive translations.
      $this->setState(JobItemInterface::STATE_ACTIVE);
    }

    if ($this->hasRetranslationData()) {
      $itemId = $this->getItemId();
      $targetLanguage = $this->getTargetLangcode();
      $entity_type_manager = \Drupal::entityTypeManager();
      $entity = $entity_type_manager->getStorage($this->getItemType())->load($itemId);
      if ($entity) {
        if ($entity->hasTranslation($targetLanguage)) {
          $entity = $entity->getTranslation($targetLanguage);
        }
      }
      $originalData = $this->getData();
      foreach (Element::children($translation) as $fieldName) {
        foreach (Element::children($translation[$fieldName]) as $fieldIndex) {
          foreach (Element::children($translation[$fieldName][$fieldIndex]) as $fieldValueName) {
            $fieldValue = &$translation[$fieldName][$fieldIndex][$fieldValueName]['#text'];
            $fieldTranslatedValue = $entity->get($fieldName)->getString();
            $domModified = FALSE;
            if (isset($originalData[$fieldName]) && isset($originalData[$fieldName][$fieldIndex])
              && isset($originalData[$fieldName][$fieldIndex][$fieldValueName]) && isset($originalData[$fieldName][$fieldIndex][$fieldValueName]['#text'])
            ) {
              $crawler = new Crawler($originalData[$fieldName][$fieldIndex][$fieldValueName]['#text']);
              $newTranslatedValue = [];
              $crawler->filter('*[id*="tmgmt"]')->each(
                function (Crawler $node, $i) use (&$domModified, $fieldValue, &$newTranslatedValue, $fieldTranslatedValue) {
                  $idAttr = $node->attr('id');
                  if (!is_null($idAttr) && (strpos($fieldValue, '"' . $idAttr . '"') !== FALSE)) {
                    $subCrawler = new Crawler($fieldValue);
                    $subCrawler = $subCrawler->filter('#' . $idAttr);
                    if ($subCrawler->count()) {
                      $domModified = TRUE;
                      $translatedNode = $subCrawler->getNode(0);
                      $crawlerNode = $node->getNode(0);
                      $crawlerNode->nodeValue = NULL;
                      foreach ($crawlerNode->childNodes as $childNode) {
                        $crawlerNode->removeChild($childNode);
                      }
                      /**
                       * @var \DOMDocument $crawlerDocument
                       */
                      $crawlerDocument = $crawlerNode->ownerDocument;
                      foreach ($translatedNode->childNodes as $childNode) {
                        $copyNode = $crawlerDocument->importNode($childNode, TRUE);
                        $crawlerNode->appendChild($copyNode);
                      }
                      foreach ($crawlerNode->attributes as $attribute) {
                        $crawlerNode->removeAttribute($attribute->name);
                      }
                      foreach ($translatedNode->attributes as $attribute) {
                        $crawlerNode->setAttribute($attribute->name, $attribute->value);
                      }
                    }
                  }
                  else {
                    $domModified = TRUE;
                    $elementId = $node->attr('id');
                    $domCrawlerDocument = new Crawler($fieldTranslatedValue);
                    $domElementCrawler = $domCrawlerDocument->filter('#' . $elementId);
                    if ($domElementCrawler->count() > 0) {
                      $domElement = $domElementCrawler->getNode(0);
                      $crawlerNode = $node->getNode(0);
                      $crawlerNode->nodeValue = NULL;
                      foreach ($crawlerNode->childNodes as $childNode) {
                        $crawlerNode->removeChild($childNode);
                      }
                      /**
                       * @var \DOMDocument $crawlerDocument
                       */
                      $crawlerDocument = $crawlerNode->ownerDocument;
                      foreach ($domElement->childNodes as $childNode) {
                        $copyNode = $crawlerDocument->importNode($childNode, TRUE);
                        $crawlerNode->appendChild($copyNode);
                      }
                      foreach ($crawlerNode->attributes as $attribute) {
                        $crawlerNode->removeAttribute($attribute->name);
                      }
                      foreach ($domElement->attributes as $attribute) {
                        $crawlerNode->setAttribute($attribute->name, $attribute->value);
                      }
                    }
                  }
                }
              );
            }
            if ($domModified) {
              $fieldValue = $crawler->filter('body')->html();
            }
          }
        }
      }
    }

    $this->addTranslatedDataRecursive($translation, $key, $status);
    // Check if the job item has all the translated data that it needs now.
    // Only attempt to change the status to needs review if it is currently
    // active.
    if ($this->isActive()) {
      $data = \Drupal::service('tmgmt.data')
        ->filterTranslatable($this->getData());
      $finished = TRUE;
      foreach ($data as $item) {
        if (empty($item['#status']) || $item['#status'] == TMGMT_DATA_ITEM_STATE_PENDING || $item['#status'] == TMGMT_DATA_ITEM_STATE_PRELIMINARY) {
          $finished = FALSE;
          break;
        }
      }

      if ($finished && $job->hasTranslator()) {
        // There are no unfinished elements left.
        if ($job->getTranslator()->isAutoAccept()) {
          // If the job item is going to be auto-accepted, set to review without
          // a message.
          $this->needsReview(FALSE);
        }
        else {
          // Otherwise, create a message that contains source label, target
          // language and links to the review form.
          $job_url = $job->toUrl()->toString();
          $variables = [
            '@source' => $this->getSourceLabel(),
            '@language' => $this->getTargetLanguage()->getName(),
            ':review_url' => $this->toUrl('canonical', ['query' => ['destination' => $job_url]])
              ->toString(),
          ];
          (!$this->getSourceUrl()) ? $variables[':source_url'] = (string) $job_url : $variables[':source_url'] = $this->getSourceUrl()
            ->toString();
          $this->needsReview('The translation of <a href=":source_url">@source</a> to @language is finished and can now be <a href=":review_url">reviewed</a>.', $variables);
        }
      }
    }

    $this->save();

  }

  /**
   * Check if item referenced by job exists.
   *
   * @return bool
   *   If referred entity exists.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function currentItemExists() {
    return self::itemExists($this->getItemType(), $this->getItemId());

  }

  /**
   * Check if item referenced by job exists.
   *
   * @param string $entityType
   *   Entity type name to check.
   * @param string $itemId
   *   Entity id to load.
   *
   * @return bool
   *   If referred entity exists.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function itemExists(string $entityType, string $itemId) {
    return !is_null(
      \Drupal::entityTypeManager()->getStorage($entityType)->load($itemId)
    );

  }

  /**
   * Check if there are job items created for those conditions.
   *
   * @param array $conditions
   *   Conditions array.
   *
   * @return bool
   *   If there are job items created with those conditions.
   */
  public static function jobItemExists(array $conditions = []) {
    $query = \Drupal::entityQuery('tmgmt_job_item');
    $query->accessCheck(FALSE);
    foreach ($conditions as $field => $values) {
      if (is_array($values)) {
        if (!empty($values)) {
          $query->condition($field, $values, 'IN');
        }
      }
      else {
        $query->condition($field, $values);
      }
    }

    $query->condition(
      'state',
      [
        static::STATE_ACTIVE,
        static::STATE_REVIEW,
        static::STATE_TRANSLATION_VALIDATED,
        static::STATE_TRANSLATION_VALIDATION_REQUIRED,
      ],
      'IN'
    );
    $existingJobItems = $query->execute();
    return !empty($existingJobItems);

  }

  /**
   * Set retranslation data.
   *
   * @param array $retranslationData
   *   Retranslation data to add.
   */
  public function setRetranslationData(array $retranslationData = []) {
    $this->set('retranslation_data', Json::encode($retranslationData));

  }

  /**
   * Return TRUE if job item has retranslation data.
   *
   * @return bool
   *   TRUE if has retranslation data.
   */
  public function hasRetranslationData() {
    return !empty($this->get('retranslation_data')->value);

  }

  /**
   * Get retranslation dataadded to job.
   *
   * @return array|null
   *   Retranslation data.
   */
  public function getRetranslationData() {
    return Json::decode($this->get('retranslation_data')->value);

  }

  /**
   * {@inheritdoc}
   */
  public function getSourceData() {
    $retranslationData = $this->getRetranslationData();
    $ret = parent::getSourceData();
    if (!empty($retranslationData)) {
      $ret = array_filter(
        $ret,
        function ($key) use ($retranslationData) {
          return in_array($key, array_keys($retranslationData));
        },
        ARRAY_FILTER_USE_KEY
      );
    }

    return $ret;

  }

  /**
   * {@inheritdoc}
   */
  public function setState($state, $message = NULL, $variables = [], $type = 'debug') {
    // Return TRUE if the state could be set. Return FALSE otherwise.
    if (array_key_exists($state, static::getStates()) && $this->get('state')->value != $state) {
      $this->set('state', $state);
      // Changing the state resets the translator state.
      $this->setTranslatorState(NULL);
      $this->save();
      // If a message is attached to this state change add it now.
      if (!empty($message)) {
        $this->addMessage($message, $variables, $type);
      }
    }

    return $this->get('state')->value;

  }

  /**
   * Move the Job Item to On Translation state.
   *
   * When re-enabled the translation by t manager.
   */
  public function toOnTranslation($message = '') {
    $this->setState(
      static::STATE_ACTIVE,
      '@name re-enabled the translation' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now "@state". Message: @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_ACTIVE),
        '@message' => $message,
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    $this->messenger()->addMessage(t('You have Enabled the Translation'));

  }

  /**
   * Move the Job Item to Translated state.
   */
  public function toTranslated($message = '') {
    $this->setState(
      static::STATE_REVIEW,
      '@name approved the layout' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now "@state". Message: @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_REVIEW),
        '@message' => $message,
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    // @todo Validators
    // OshaWorkflowNotifications::notifyTranslationLayoutApproved($this);
    $this->messenger()->addMessage(t('You have approved the layout'));

  }

  /**
   * Move the Job Item to Translation Validated state.
   */
  public function toTranslationValidated($message = '') {
    $this->setState(
      static::STATE_TRANSLATION_VALIDATED,
      '@name validated translation content' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now "@state". Message: @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_TRANSLATION_VALIDATED),
        '@message' => $message,
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    // @todo Validators
    // OshaWorkflowNotifications::notifyTranslationValidated($this);
    $this->messenger()
      ->addMessage(t('All content validators have validated the translation!'));

  }

  /**
   * Move the Job Item to Translation Validation Required state.
   */
  public function toTranslationValidationRequired($message = '') {
    $this->setState(
      static::STATE_TRANSLATION_VALIDATION_REQUIRED,
      '@name: Content validation required' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now "@state". Message: @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_TRANSLATION_VALIDATION_REQUIRED),
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    // @todo Validators
    // OshaWorkflowNotifications::notifyTranslationValidators($this);
    $this->messenger()
      ->addMessage(t('You have Required Translation Validation for this translation'));

  }

  /**
   * Move the Job Item to Translation Validation Required state.
   */
  public function toTranslationRejected($message = '') {
    $this->setState(
      static::STATE_ABORTED,
      '@name: Translation rejected' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now "@state". Message: @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_ABORTED),
        '@message' => $message,
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    // @todo Validators
    // OshaWorkflowNotifications::notifyTranslationRejected($this);
    $this->messenger()->addMessage(t('You have Rejected this translation'));

  }

  /**
   * {@inheritdoc}
   */
  public function acceptTranslation(string $message = '') {
    $this->addMessage(
      '@name accepted the translation' . ' for job item @tjiid, item @item_id (@bundle), language @lang. ' . 'Status is now <strong>@state</strong>. @message',
      [
        '@name' => \Drupal::currentUser()->getAccountName(),
        '@state' => static::getStateLabel(static::STATE_ACCEPTED),
        '@message' => 'Message: ' . $message,
        '@tjiid' => $this->getJobId(),
        '@item_id' => $this->getItemId(),
        '@bundle' => $this->getItemType(),
        '@lang' => strtoupper($this->getTargetLangcode()),
      ]
    );
    if (!$plugin = $this->getSourcePlugin()) {
      return FALSE;
    }

    if (!$plugin->saveTranslation($this, $this->getTargetLangcode())) {
      return FALSE;
    }
    else{
      $pub_delete = $this->delete_media_file($this->values["item_id"]["x-default"]);
    }

    // If the plugin could save the translation, we will set it
    // to the 'accepted' state.
    $this->accepted();
    return TRUE;

  }

  /**
   * @param $nid
   * @return bool
   * @throws PluginNotFoundException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */

  public function delete_media_file($nid){
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    //If the CT is a publication We must delete the file media for all contest except the original.
    if ($node->values["type"]["x-default"]!='publication' && $node->values["type"]["x-default"]!='infographic'){
      return true;
    }
    if ($node->values["type"]["x-default"]=='publication'){
      // Skip Infographic File field
      if (isset($node->values["field_file_media"])) {
        $ncw_original_target_id = $node->values["field_file_media"]["x-default"][0]["target_id"];
        foreach ($node->values["field_file_media"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $ncw_original_target_id and $key != 'x-default') {
            unset($node->values["field_file_media"][$key]);
          }
        }
      }
      // Skip HWC Infographic File field
      if (isset($node->values["field_file"])) {
        $hwc_original_target_id = $node->values["field_file"]["x-default"][0]["target_id"];
        foreach ($node->values["field_file"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $hwc_original_target_id and $key != 'x-default') {
            unset($node->values["field_file"][$key]);
          }
        }
      }
    }
    // MDR-7087, MDR-7088: Translation Job shouldn't automatically add the File and Image fields (Infographics)
    if ($node->values["type"]["x-default"]=='infographic'){
      // Skip NCW Infographic File field
      if (isset($node->values["field_file_media"])) {
        $ncw_original_target_id = $node->values["field_file_media"]["x-default"][0]["target_id"];
        foreach ($node->values["field_file_media"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $ncw_original_target_id and $key != 'x-default') {
            unset($node->values["field_file_media"][$key]);
          }
        }
      }
      // Skip NCW Infographic Image field
      if (isset($node->values["field_image_media"])) {
        $ncw_field_image_media = $node->values["field_image_media"]["x-default"][0]["target_id"];
        foreach ($node->values["field_image_media"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $ncw_field_image_media and $key != 'x-default') {
            unset($node->values["field_image_media"][$key]);
          }
        }
      }
      // Skip HWC Infographic File field
      if (isset($node->values["field_file"])) {
        $hwc_original_file_target_id = $node->values["field_file"]["x-default"][0]["target_id"];
        foreach ($node->values["field_file"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $hwc_original_file_target_id and $key != 'x-default') {
            unset($node->values["field_file"][$key]);
          }
        }
      }
      // Skip HWC Infographic Image field
      if (isset($node->values["field_image"])) {
        $hwc_original_media_target_id = $node->values["field_image"]["x-default"][0]["target_id"];
        foreach ($node->values["field_image"] as $key => $file_media) {
          if ($file_media[0]["target_id"] == $hwc_original_media_target_id and $key != 'x-default') {
            unset($node->values["field_image"][$key]);
          }
        }
      }
    }

    $node->save();
    return true;
  }



  /**
   * {@inheritdoc}
   */
  public function accepted($message = NULL, $variables = [], $type = 'status') {
    if (!isset($message)) {
      $source_url = $this->getSourceUrl();
      try {
        // @todo Make sure we use the latest revision.
        // Fix in https://www.drupal.org/project/tmgmt/issues/2979126.
        $translation = \Drupal::entityTypeManager()
          ->getStorage($this->getItemType())
          ->load($this->getItemId());
      }
      catch (PluginNotFoundException $e) {
        $translation = NULL;
      }

      if (isset($translation) && $translation->hasTranslation($this->getTargetLangcode())) {
        $translation = $translation->getTranslation($this->getTargetLangcode());
        try {
          $translation_url = $translation->toUrl();
        }
        catch (UndefinedLinkTemplateException $e) {
          $translation_url = NULL;
        }

        $message = $source_url && $translation_url ? 'The translation for <a href=":source_url">@source</a> has been accepted as <a href=":target_url">@target</a>.' : 'The translation for @source has been accepted as @target.';
        $variables = $source_url && $translation_url ? [
          ':source_url' => $source_url->toString(),
          '@source' => ($this->getSourceLabel()),
          ':target_url' => $translation_url->toString(),
          '@target' => $translation ? $translation->label() : $this->getSourceLabel(),
        ] : [
          '@source' => ($this->getSourceLabel()),
          '@target' => ($translation ? $translation->label() : $this->getSourceLabel()),
        ];
      }
      else {
        $message = $source_url ? 'The translation for <a href=":source_url">@source</a> has been accepted.' : 'The translation for @source has been accepted.';
        $variables = $source_url ? [
          ':source_url' => $source_url->toString(),
          '@source' => ($this->getSourceLabel()),
        ] : ['@source' => ($this->getSourceLabel())];
      }
    }

    $return = $this->setState(static::STATE_ACCEPTED, $message, $variables, $type);
    // Check if this was the last unfinished job item in this job.
    $job = $this->getJob();
    if ($job && !$job->isContinuous() && tmgmt_job_check_finished($this->getJobId())) {
      // Mark the job as finished in case it is a normal job.
      $job->finished();
    }

    return $return;

  }

}
