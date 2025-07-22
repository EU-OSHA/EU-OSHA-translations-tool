<?php

namespace Drupal\translation_workflow;

use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\JobItemInterface;
use Symfony\Component\DomCrawler\Crawler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;

/**
 * Service for utils and manage tmgmt elements on html.
 */
class TmgmtElementsUtils {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Define which fields are countable.
   *
   * @var array
   */
  const COUNTABLE_FIELDS = [
    'string',
    'text_with_summary',
    'text_long',
  ];

  /**
   * Define JobItem states labels.
   */
  const STATE_LABELS = [
    JobItemInterface::STATE_INACTIVE => 'Inactive',
    JobItemInterface::STATE_ACTIVE   => 'Active',
    JobItemInterface::STATE_REVIEW   => 'Review',
    JobItemInterface::STATE_ACCEPTED => 'Accepted',
    JobItemInterface::STATE_ABORTED  => 'Aborted',
    MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATION_REQUIRED => 'Translation validation required',
    MultipleTargetLanguageJobItem::STATE_TRANSLATION_VALIDATED => 'Translation validated',
  ];

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Count characters of full or selected retranslations.
   *
   * @param array $items
   *   Array of JobItemInterface objects.
   *
   * @return int
   *   The total count of characters.
   */
  public function countJobItems(array $items): int {
    $countedItems = [];
    $count = 0;
    foreach ($items as $item) {
      $itemId = $item->getItemId();
      if (!isset($countedItems[$itemId])) {
        $countedItems[$itemId] = TRUE;
        $count += self::countJobItem($item);
      }
    }
    return $count;
  }

  /**
   * Count characters of full or selected retranslations.
   *
   * @param \Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem $item
   *   JobItem to count.
   *
   * @return int
   *   The total count of characters.
   */
  public function countJobItem(MultipleTargetLanguageJobItem $item): int {
    $count = 0;
    $itemId = $item->id();
    $itemType = JobItem::load($itemId)->getItemType();
    $nodeId = $item->getItemId();

    if ($itemType == 'node') {

      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
      if (!$item->hasRetranslationData()) {
        if ($node) {
          /** @var \Drupal\Core\Field\FieldItemListInterface $fields */
          $fields = $node->getFields();
          foreach ($fields as $fieldName => $fieldsDefinition) {
            $fieldType = $fieldsDefinition->getFieldDefinition()->getType();
            $isFieldTranslatable = $fieldsDefinition->getFieldDefinition()->isTranslatable();
            if ($fieldName != 'moderation_state' && $isFieldTranslatable && in_array($fieldType, self::COUNTABLE_FIELDS)) {
              // Check if for example field is like a Body field, with
              // value and summary text.
              // This code, counts the summary text also.
              if (is_array($node->get($fieldName)->getValue())) {
                foreach ($node->get($fieldName)->getValue() as $v) {
                  if (is_array($v)) {
                    foreach ($v as $key => $content) {
                      if (in_array($key, ['value', 'summary'])) {
                        if (!empty($content)) {
                          $count += $this->countText($content);
                        }
                      }
                    }
                  }
                }
              }
              // Text field...yes.
              else {
                $text = $node->get($fieldName)->value;
                $count += $this->countText($text);
              }
            }
          }
        }
      }
      else {
        $retranslationData = $item->getRetranslationData();
        $fieldsDefinitions = $node->getFieldDefinitions();
        foreach ($fieldsDefinitions as $fieldName => $fieldsDefinition) {
          if (!in_array($fieldName, array_keys($retranslationData))) {
            continue;
          }
          $fieldType = $fieldsDefinition->getType();
          $isFieldTranslatable = $fieldsDefinition->isTranslatable();

          if ($isFieldTranslatable && in_array($fieldType, self::COUNTABLE_FIELDS)) {
            // Check if for example field is like a Body field, with
            // value and summary text.
            // This code, counts the summary text also.
            $extracted = [];
            if (is_array($node->get($fieldName)->getValue())) {
              foreach ($node->get($fieldName)->getValue() as $v) {
                if (is_array($v)) {
                  foreach ($v as $key => $content) {
                    if (in_array($key, ['value', 'summary'])) {
                      if (is_string($content) && !$this->isHtml($content)) {
                        $extracted[] = $content;
                      }
                      else {
                        $extracted = array_merge($extracted, $this->extractValues([$content], $fieldName, $retranslationData));
                      }
                    }
                  }
                }
              }
            }
            else {
              $text = $node->get($fieldName)->value;
              if (is_string($text) && !$this->isHtml($text)) {
                $extracted = [$text];
              }
              else {
                $extracted = $this->extractValues(is_array($text) ? $text : [$text], $fieldName, $retranslationData);
              }
            }

            foreach ($extracted as $text) {
              $count += $this->countText($text);
            }
          }
        }
      }
    }
    else {
      $count = $this->countText($item->label());
    }
    return $count;
  }

  /**
   * Count text characters.
   *
   * This function call cleanText function before count characters to ignore
   * HTML tags, spaces and new line special chars.
   *
   * @param string|null $text
   *   The text to count.
   *
   * @return int
   *   The length of text ignoring html tags, html entities and special chars.
   */
  public function countText(?string $text): int {
    return empty($text) ? 0 : mb_strlen($this->cleanText($text), 'utf-8');
  }

  /**
   * Build the tmgmt-x options array.
   *
   * @param string $value
   *   The HTML string to parse.
   *
   * @return array
   *   The array of tmgmt-x options.
   */
  public function getTmgmtOptions(string $value): array {
    $options = [];
    $domCrawler = new Crawler($value);
    $domCrawler->filter('*')->each(function (Crawler $node, int $i) use (&$options) {
      if ($node->attr('id')) {
        $id = $node->attr('id');
        if (preg_match('/tmgmt-\d/', $id)) {
          $options[$id] = $node->outerHtml();
        }
      }
    });
    return $options;
  }

  /**
   * Helper function thats check if string is HTML.
   *
   * @param string $string
   *   The string to check.
   *
   * @return bool
   *   TRUE or FALSE if the string is HTML or not.
   */
  private function isHtml(string $string) {
    return $string != strip_tags($string);
  }

  /**
   * Extract tmtgmt-x values.
   *
   * @param \DOMNode $node
   *   The dom node to parse.
   * @param string $field
   *   The field name to check.
   * @param array $retranslationData
   *   The retranslation data array.
   *
   * @return array
   *   The extracted values.
   */
  private function extractValues($node, string $field, array $retranslationData): array {
    $ret = [];
    $crawler = new Crawler($node);
    $crawler->filter('*[id*="tmgmt"]')->each(
      function (Crawler $node, $i) use (&$ret, $retranslationData, $field) {
        $idAttr = $node->attr('id');
        if (!is_null($idAttr) && is_array($retranslationData[$field]) && in_array($idAttr, $retranslationData[$field])) {
          $ret[] = $node->outerHtml();
        }
      });
    return $ret;
  }

  /**
   * Clean the specified text.
   *
   * This function remove html tags, entities, spaces and special chars, like
   * new line.
   *
   * @param string $text
   *   The text to clean.
   *
   * @return string
   *   The cleaned text.
   */
  private function cleanText(string $text): string {
    $text = strip_tags(html_entity_decode($text));
    // C2A0 is unicode nbsp.
    $text = preg_replace("/\x{00A0}|&nbsp;|\s/", '', $text);
    // Clean UTF-8 encoding for spaces (char code 194)
    return str_replace(chr(194), "", $text);
  }

  /**
   * Add tmgmt Elements to html value of fields.
   *
   * @param string $fieldValue
   *   Field value.
   *
   * @return string
   *   Text with tmgmt elements added.
   *
   * @throws \DOMException
   */
  public function addTmgmtElements(string $fieldValue) {
    $fieldValue = $this->removeTmgmtDuplicatedIds($fieldValue);
    $crawler = new Crawler($fieldValue);
    $tmgmtCounter = 1;
    $crawler->filter('body > *')->each(function (Crawler $node, $i) use (&$tmgmtCounter, &$finalValue) {
      $domNode = $node->getNode(0);
      $domNode->setAttribute('id', 'tmgmt-' . $tmgmtCounter);
      $tmgmtCounter++;
    });
    return $crawler->filter('body')->html();
  }

  /**
   * Update tmgmt Elements to html value of fields.
   *
   * @param string $fieldValue
   *   Field value.
   *
   * @return string
   *   Text with tmgmt elements added.
   *
   * @throws \DOMException
   */
  public function updateTmgmtElements(string $fieldValue) {
    $fieldValue = $this->removeTmgmtDuplicatedIds($fieldValue);
    $maxTmgmtId = $this->getMaxTmgmtId($fieldValue);
    $crawler = new Crawler($fieldValue);
    $crawler->filter('body > *')->each(function (Crawler $node, $i) use (&$finalValue, &$maxTmgmtId) {
      if (empty($node->attr('id'))) {
        $maxTmgmtId++;
        $domNode = $node->getNode(0);
        $domNode->setAttribute('id', 'tmgmt-' . $maxTmgmtId);
      }
    });
    return $crawler->filter('body')->html();
  }

  /**
   * Remove duplicated tmgmt ids.
   *
   * @param string $html
   *   HTML string.
   *
   * @return string
   *   The HTML string with duplicated tmgmt ids removed.
   */
  private function removeTmgmtDuplicatedIds(string $html): string {
    $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
    $seen_ids = [];
    // Select all dom elements with attribute id="tmgmt-".
    $crawler->filter('[id^="tmgmt-"]')->each(function (Crawler $node) use (&$seen_ids) {
      $id = $node->attr('id');
      if (isset($seen_ids[$id])) {
        // Clone dom node and remove attribute id.
        $element = $node->getNode(0);
        $element->removeAttribute('id');
      }
      else {
        $seen_ids[$id] = TRUE;
      }
    });
    // Prepare the html output.
    $output = '';
    foreach ($crawler as $node) {
      $output .= $node->ownerDocument->saveHTML($node);
    }
    return $output;
  }

  /**
   * Get max tmgmt id value.
   *
   * @param string $html
   *   HTML string.
   *
   * @return int
   *   Max tmgmt id value.
   */
  private function getMaxTmgmtId(string $html): int {
    // Search all matches.
    preg_match_all('/id="tmgmt-(\d+)"/', $html, $matches);
    // If there is no mathes, return 0.
    if (empty($matches[1])) {
      return 0;
    }
    // Cast matches.
    $ids = array_map('intval', $matches[1]);
    // Return the max id found.
    return max($ids);
  }

  /**
   * Get the notification roles.
   *
   * @return array
   *  The notification roles.
   */
  public function getNotificationRoles(): array {
    $config = \Drupal::config('translation_workflow.notifications_settings');
    $roles = [];
    foreach (TmgmtElementsUtils::STATE_LABELS as $state_value => $state_label) {
      $roles[$state_value] = $config->get("notification_roles.$state_value");
    }
    return $roles;
  }

}
