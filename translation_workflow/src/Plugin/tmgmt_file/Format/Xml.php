<?php

namespace Drupal\translation_workflow\Plugin\tmgmt_file\Format;

use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt_file\Format\FormatInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJob;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Drupal\translation_workflow\Entity\PriorityJobInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Export into XML translation format.
 *
 * @FormatPlugin(
 *   id = "xml",
 *   label = @Translation("XML")
 * )
 */
class Xml extends \XMLWriter implements FormatInterface {

  use MessengerTrait, StringTranslationTrait;

  /**
   * Store whole xml element processed.
   *
   * @var \SimpleXMLElement
   */
  protected $importedXML;

  /**
   * Store translation information.
   *
   * @var array
   */
  protected $importedTransUnits = [];

  /**
   * Store mapped Job Item Ids [lang][entity_type][entity_id].
   *
   * @var array
   */
  protected $mappedItemsIDs = [];

  /**
   * Data service for flattern and unflattern.
   *
   * @var \Drupal\tmgmt\Data
   */
  protected $dataService;

  /**
   * Xml constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $this->dataService = \Drupal::service('tmgmt.data');
  }

  /**
   * {@inheritdoc}
   */
  public function export(JobInterface $job, $conditions = []) {

    $this->openMemory();
    $this->setIndent(TRUE);
    $this->setIndentString(' ');
    $this->startDocument('1.0', 'UTF-8');

    // <Transaction> Root element.
    $this->startElement('Transaction');
    $this->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $this->writeAttribute('xsi:noNamespaceSchemaLocation', 'http://oami.europa.eu/schemas/common/EM-Translation-V0-3.xsd');

    $this->startElement('TransactionHeader');
    $this->startElement('SenderDetails');
    $this->writeElement('ProducerDateTime', date('c', $job->getCreatedTime()));
    // </SenderDetails>
    $this->endElement();
    // </TransactionHeader>
    $this->endElement();

    // <TransactionIdentifier>777</TransactionIdentifier>
    $this->writeElement('TransactionIdentifier', $job->id());

    $this->writeElement('TransactionCode', 'EM-OWS Translation Request');

    // <TransactionData>
    $this->startElement('TransactionData');
    $this->startElement('TranslationDetails');
    if ($job instanceof MultipleTargetLanguageJob) {
      $this->writeElement('TotalCharacterLength', $job->getCharactersCount());
    }
    else {
      $this->writeElement('TotalCharacterLength', $job->getWordCount());
    }

    $addedItems = [];
    $job_items = self::getUniqueItems($job);
    foreach ($job_items as $item_type => $item_ids) {
      foreach ($item_ids as $item_id => $item_list) {
        foreach ($item_list as $item) {
          if (!in_array($item->getItemId(), $addedItems) && ($item->currentItemExists())) {
            $this->addItem($item, $job);
            $addedItems[] = $item->getItemId();
          }
        }
      }
    }

    // </TransactionDetails>
    $this->endElement();
    // </TransactionData>
    $this->endElement();

    $this->endDocument();
    return $this->outputMemory();
  }

  /**
   * {@inheritdoc}
   */
  public function validateImport($imported_file, $is_file = TRUE) {
    $xml = $this->getImportedXml($imported_file);
    if (empty($xml)) {
      $this->messenger()
        ->addError($this->t('Cannot parse XML file, check XML integrity and syntax'));
      return FALSE;
    }
    $phase = $xml->xpath('//TransactionIdentifier');
    if ($phase) {
      $phase = reset($phase);
    }
    else {
      $this->messenger()
        ->addError($this->t('TransactionIdentifier tag is missing. XML is not valid'));
      return FALSE;
    }
    // Check if the job can be loaded.
    $tjid = (string) $phase;
    $job = MultipleTargetLanguageJob::load($tjid);
    if (empty($job)) {
      $this->messenger()
        ->addError($this->t('Cannot find inside the system a matching job for ID: %s', ['%s' => $tjid]));
      return FALSE;
    }
    // Check if Job Items IDs from file belongs to this Job.
    $items_ids = array_keys($job->getItems());
    $identifiers = $xml->xpath('//ContentIdentifier');
    foreach ($identifiers as $identifier) {
      $tjiid = (string) $identifier;
      if (!in_array($tjiid, $items_ids)) {
        $this->messenger()
          ->addError($this->t('The job inside the system does not have a corresponding job item with ID: %s', ['%s' => $tjiid]));
        return FALSE;
      }
    }
    // Check if file contains other languages than those from the job.
    $this->getMappedItemIds($job);
    $languages = $xml->xpath("//TranslationTargetLanguage");
    foreach ($languages as $language) {
      $lang = (string) $language;
      // @todo Do it in a better way.
      if ($lang == 'pt') {
        $lang = 'pt-pt';
      }
      if ($lang != $job->getSourceLangcode() && !isset($this->mappedItemsIDs[$lang])) {
        $this->messenger()
          ->addError($this->t('Invalid target language. This job does not accept translations in: %s', ['%s' => $lang]));
        return FALSE;
      }
    }
    $targets = $this->getImportedTargets($job);
    if (empty($targets)) {
      $this->messenger()
        ->addError($this->t('No translations processed (missing Translation or TranslationTargetLanguage elements?)'));
      return FALSE;
    }
    return $job;
  }

  /**
   * {@inheritdoc}
   */
  public function import($imported_file, $is_file = TRUE) {
    $this->getImportedXML($imported_file);
    $tjid = $this->importedXML->xpath("//TransactionIdentifier");
    $tjid = reset($tjid);
    $job = MultipleTargetLanguageJob::load((string) $tjid);
    $job->set('file_uploaded', TRUE)->save();

    $flat_data = $this->getImportedTargets($job);
    $flat_data['target_languages'] = $this->extractTargetLanguages();
    return $this->dataService->unflatten($flat_data);
  }

  /**
   * Extract translation language.
   *
   * @return array
   *   Translation language of xml.
   */
  protected function extractTargetLanguages() {
    $targetLanguages = [];
    $translationTargetLanguages = $this->importedXML->xpath('//TranslationTargetLanguage');
    if ($translationTargetLanguages) {
      foreach ($translationTargetLanguages as $translationTargetLanguage) {
        $targetLanguage = (string) $translationTargetLanguage;
        // @todo Do this in a better way.
        if ($targetLanguage == 'pt') {
          $targetLanguage = 'pt-pt';
        }
        $targetLanguages[] = $targetLanguage;
      }
    }
    return $targetLanguages;
  }

  /**
   * Gets translation units.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   Translation job.
   *
   * @return array|false
   *   Get an array of translation units.
   */
  protected function getImportedTargets(JobInterface $job) {
    if (empty($this->importedXML)) {
      return FALSE;
    }
    // Populates Job Item Ids array.
    $this->getMappedItemIds($job);
    if (empty($this->importedTransUnits)) {
      $reader = new \XMLReader();
      foreach ($this->importedXML->xpath('//Translation') as $translation) {
        $target_language = (string) $translation->TranslationTargetLanguage;
        if (!$target_language) {
          $this->messenger()
            ->addWarning($this->t('Invalid translation detected on imported file.'));
          continue;
        }
        if ($target_language == 'pt') {
          $target_language = 'pt-pt';
        }
        if ($job->getSourceLangcode() != $target_language) {
          foreach ($translation->xpath('DynamicElement') as $unit) {
            // Get the Job Item ID that handles the language translation
            // of the current entity id and type.
            $entity_id = (string) $unit['instanceId'];
            $entity_type = (string) $unit['indexType'];
            if (!empty($this->mappedItemsIDs[$target_language][$entity_type][$entity_id])) {
              $item_id = $this->mappedItemsIDs[$target_language][$entity_type][$entity_id];
              $reader->XML($unit->DynamicContent->asXML());
              $reader->read();
              $key = $item_id . (string) $unit['name'];
              $this->importedTransUnits[$key]['#text'] = $this->processForImport($reader->readInnerXML(), $job);
            }
          }
        }
      }
    }
    return $this->importedTransUnits;
  }

  /**
   * Processes trans-unit/target to rebuild back the HTML.
   *
   * @param string $translation
   *   Job data array.
   * @param \Drupal\tmgmt\JobInterface $job
   *   Translation job.
   *
   * @return string
   *   Rebuilt html.
   */
  protected function processForImport($translation, JobInterface $job) {
    $reader = new \XMLReader();
    $reader->XML('<translation>' . $translation . '</translation>');
    $text = '';

    while ($reader->read()) {
      // If the current element is text append it to the result text.
      if ($reader->name == '#text' || $reader->name == '#cdata-section') {
        $text .= $reader->value;
      }
      elseif ($reader->name == 'x') {
        if ($reader->getAttribute('ctype') == 'lb') {
          $text .= '<br />';
        }
      }
      elseif ($reader->name == 'ph') {
        if ($reader->getAttribute('ctype') == 'image') {
          $text .= '<img';
          while ($reader->moveToNextAttribute()) {
            // @todo we have to use x-html: prefixes for attributes.
            if ($reader->name != 'ctype' && $reader->name != 'id') {
              $text .= " {$reader->name}=\"{$reader->value}\"";
            }
          }
          $text .= ' />';
        }
      }
    }
    return $text;
  }

  /**
   * Return an array of mappedItemsIDs.
   *
   * Job Item ids are stored in [lang][entity_type][entity_id].
   */
  public function getMappedItemIds(JobInterface $job) {
    $items = $job->getItems();
    foreach ($items as $item_id => $item) {
      if ($job instanceof MultipleTargetLanguageJob && $item instanceof MultipleTargetLanguageJobItem) {
        $this->mappedItemsIDs[$item->getTargetLangcode()][$item->getItemType()][$item->getItemId()] = $item_id;
      }
      else {
        $this->mappedItemsIDs[$job->getRemoteTargetLanguage()][$item->getItemType()][$item->getItemId()] = $item_id;
      }
    }
  }

  /**
   * Gets imported XML document.
   *
   * @param string $imported_file
   *   Xml file path.
   *
   * @return false|\SimpleXMLElement|string|null
   *   Xml document processed.
   */
  protected function getImportedXml($imported_file) {
    if (empty($this->importedXML)) {
      // It is not possible to load the file directly with simplexml as it gets
      // url encoded due to the temporary://. This is a PHP bug, see
      // https://bugs.php.net/bug.php?id=61469
      $xml_string = file_get_contents($imported_file);
      if ($this->importedXML = simplexml_load_string($xml_string)) {
        // Register the XLIFF namespace, required for xpath.
        $this->importedXML->registerXPathNamespace('cdt', 'urn:oasis:names:tc:cdt:document:1.2');
      }
    }
    return $this->importedXML;
  }

  /**
   * Generates an unique array of items, based on item content.
   *
   * Also sets $item->target_languages array, used in addItem.
   *
   * @return array
   *   Items -> Items[item_type][item_id] contains an array of items
   */
  public static function getUniqueItems(JobInterface $job) {
    $job_items = [];
    foreach ($job->getItems() as $item) {
      // Search if node already exists in job_items.
      if (isset($job_items[$item->getItemType()][$item->id()])) {
        // $is_new will be FALSE when $item is added to an existing
        // element of $job_items.
        $is_new = TRUE;
        foreach ($job_items[$item->getItemType()][$item->id()] as $new_item) {
          // Now check if the two items have the same content.
          if (serialize($new_item->getData()) == serialize($item->getData())) {
            // Same content, simply add another language.
            $new_item->target_languages[] = $job->getRemoteTargetLanguage();
            $is_new = FALSE;
            break;
          }
        };
        if ($is_new) {
          // If not found until now, add it.
          $item->target_languages = [$job->getRemoteTargetLanguage()];
          $job_items[$item->getItemType()][$item->id()][] = $item;
        }
      }
      else {
        // This is the first job item for the node, add it.
        $item->target_languages = [$job->getRemoteTargetLanguage()];
        $job_items[$item->getItemType()][$item->id()][] = $item;
      }
    }
    return $job_items;
  }

  /**
   * Adds a job item to the xml export.
   */
  protected function addItem(JobItemInterface $item, JobInterface $job) {
    $retranslationData = [];
    // <Translation>
    $this->startElement('Translation');
    $this->writeElement('TranslationSourceLanguage', $item->getSourceLangCode());
    if ($job instanceof MultipleTargetLanguageJob) {
      foreach ($job->getTargetLanguages() as $targetLanguage) {

        // @todo Make this in a better way.
        $langCode = $targetLanguage->getId();
        if ($langCode == 'pt-pt') {
          $langCode = 'pt';
        }
        $this->writeElement('TranslationTargetLanguage', $langCode);
      }
      if ($item->hasRetranslationData()) {
        $retranslationData = $item->getRetranslationData();
      }
    }
    else {
      $this->writeElement('TranslationTargetLanguage', $job->getRemoteTargetLanguage());
    }

    // <ContentIdentifier>
    $this->startElement('ContentIdentifier');
    $this->writeAttribute('transactionId', $job->id());
    $this->text($item->id());
    // </ContentIdentifier>
    $this->endElement();

    // @todo implement priority levels
    if ($job instanceof MultipleTargetLanguageJob) {
      $this->writeElement('Priority', $job->getPriority());
    }
    else {
      // Default jobs does not have priority so put normal for all.
      $this->writeElement('Priority', PriorityJobInterface::PRIORITY_NORMAL);
    }
    $this->writeElement('CharacterLength', $item->getWordCount());
    $this->writeElement('PreviewLink', 'NO PREVIEW LINK');
    $this->writeElement('ContentTitle', 'NO TITLE');
    $this->writeElement('FriendlyUrlName', 'NO FRIENDLY URL');
    $this->writeElement('Keywords', 'NO KEYWORDS');

    $data = array_filter($this->dataService->flatten($item->getData()), function ($value) {
      return !(empty($value['#text']) || (isset($value['#translate']) && $value['#translate'] === FALSE));
    });
    foreach ($data as $key => $field) {
      $this->startElement('DynamicElement');
      $this->writeAttribute('dynamicElementKind', 'TextArea');
      $this->writeAttribute('indexType', $item->getItemType());
      $this->writeAttribute('instanceId', $item->getItemId());
      $this->writeAttribute('name', '][' . $key);
      $this->writeAttribute('repeatable', 'false');
      $this->startElement('DynamicContent');
      $textContent = $field['#text'];
      if (!empty($retranslationData)) {
        $fieldNameParts = explode(']', $key);
        $fieldName = reset($fieldNameParts);
        $newTextContent = [];
        if (isset($retranslationData[$fieldName])) {
          $crawler = new Crawler($textContent);
          $crawler->filter('*[id*="tmgmt"]')->each(function (Crawler $node, $i) use ($retranslationData, &$newTextContent, $fieldName) {
              $idAttr = $node->attr('id');
              if (!is_null($idAttr) && is_array($retranslationData[$fieldName]) && in_array($idAttr, $retranslationData[$fieldName])) {
                $newTextContent[] = $node->outerHtml();
              }
            });
          if (!empty($newTextContent)) {
            $textContent = implode('', $newTextContent);
          }
        }
      }
      $formats = array_keys(FilterFormat::loadMultiple());
      if (isset($field['#format']) && in_array($field['#format'], $formats)) {
        if (strpos($field['#text'], 'tmgmt-') === FALSE) {
          $textContent = \Drupal::service('translation_workflow.manage_tmgmt_elements')
            ->addTmgmtElements($textContent);
        }
      }
      $this->writeCdata($textContent);
      // </DynamicContent>
      $this->endElement();
      // </DynamicElement>
      $this->endElement();
    }
    $this->endElement();
  }

}
