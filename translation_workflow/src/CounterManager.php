<?php

namespace Drupal\translation_workflow;

use Drupal\node\Entity\Node;
use Drupal\tmgmt\JobItemInterface;
use Drupal\translation_workflow\Entity\MultipleTargetLanguageJobItem;
use Symfony\Component\DomCrawler\Crawler;

/**
 * CounterManager
 * web/modules/custom/translation_workflow/src/CounterManager.php
 *
 * This class is the result of refactorization, clean duplicated code and other
 * improvements.
 *
 * @author jorge.perez@bilbomatica.es
 * @version 1.0
 * @date 27-5-2022
 */

class CounterManager {

  /**
   * @param JobItemInterface array $items
   *
   * @return int
   */
  public static function countJobItems(array $items): int {
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
   * @param \Drupal\tmgmt\JobItemInterface $item JobItem to count
   *
   * @return int
   */
  public static function countJobItem(JobItemInterface $item): int {
    $count = 0;
    $itemId = $item->id();
    $itemType = MultipleTargetLanguageJobItem::load($itemId)->getItemType();
    $nodeId = $item->getItemId();

    if ($itemType == 'node') {
      $node = Node::load($nodeId);
      if (!$item->hasRetranslationData()) {
        $fields = $node->getFields();
        foreach ($fields as $fieldName => $fieldsDefinition) {
          if ($fieldName != 'moderation_state' && $fieldsDefinition->getFieldDefinition()
              ->isTranslatable() && in_array($fieldsDefinition->getFieldDefinition()
              ->getType(), [
              'string',
              'text_with_summary',
              'text_long',
            ])) {

            //@ Check if for example field is like a Body field, with
            //@ value and summary text.
            //@ This code, counts the summary text also.
            if (is_array($node->get($fieldName)->getValue())) {
              foreach ($node->get($fieldName)->getValue() as $v) {
                if (is_array($v)) {
                  foreach ($v as $key => $content) {
                    if (in_array($key, ['value', 'summary'])) {
                      $count += self::countText($content);
                    }
                  }
                }
              }
            }
            //@ Text field...yes
            else {
              $text = $node->get($fieldName)->value;
              $count += self::countText($text);
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
          if ($fieldsDefinition->isTranslatable() && in_array($fieldsDefinition->getType(), [
              'string',
              'text_with_summary',
              'text_long',
            ])) {

            //@ Check if for example field is like a Body field, with
            //@ value and summary text.
            //@ This code, counts the summary text also.
            $extracted = [];
            if (is_array($node->get($fieldName)->getValue())) {
              foreach ($node->get($fieldName)->getValue() as $v) {
                if (is_array($v)) {
                  foreach ($v as $key => $content) {
                    if (in_array($key, ['value', 'summary'])) {
                      if (is_string($content) && !self::isHtml($content)) {
                        $extracted[] = $content;
                      }
                      else {
                        $extracted = array_merge($extracted, self::extractValues([$content], $fieldName, $retranslationData));
                      }
                    }
                  }
                }
              }
            }
            else {
              $text = $node->get($fieldName)->value;
              if (is_string($text) && !self::isHtml($text)) {
                $extracted = [$text];
              }
              else {
                $extracted = self::extractValues(is_array($text) ? $text : [$text], $fieldName, $retranslationData);
              }
            }

            foreach ($extracted as $text) {
              $count += self::countText($text);
            }
          }
        }
      }
    }
    else {
      $count = CounterManager::countText($item->label());
    }
    return $count;
  }

  /**
   * Count text characters. This function call cleanText function before count
   * characters to ignore HTML tags, spaces and new line special chars.  *
   *
   * @param string|null $text
   *
   * @return int Length of text ignoring html tags, html entities and special
   * chars.
   */
  public static function countText(?string $text): int {
    return empty($text) ? 0 : mb_strlen(self::cleanText($text), 'utf-8');
  }

  /**
   * Helper function thats check if string is HTML.
   * @param string $string
   *
   * @return bool
   */
  private static function isHtml(string $string) {
    return $string != strip_tags($string);
  }

  /**
   * @param string $key
   * @param \DOMNodeList|\DOMNode|\DOMNode[]|string|null $node A Node to use as the base for the crawling
   * @return string   */
  private static function extractValues($node, string $field, array $retranslationData): array {
    $ret = [];
    $crawler = new Crawler($node);
    $crawler->filter('*[id*="tmgmt"]')->each(function (Crawler $node, $i) use (&$ret, $retranslationData, $field/*, &$newDefaultValue, $subCrawler, &$newTranslationDefaltValue*/) {
        $idAttr = $node->attr('id');
      if (!is_null($idAttr) && is_array($retranslationData[$field]) && in_array($idAttr, $retranslationData[$field])) {
        $ret[] = $node->outerHtml();
      }
    });
    return $ret;
  }

  /**
   * This function remove html tags, entities, spaces and special chars, like
   * new line.
   * @param string $text
   *
   * @return array|string|string[]
   */
  private static function cleanText(string $text) {
    $text = strip_tags(html_entity_decode($text));
    // C2A0 is unicode nbsp.
    $text = preg_replace("/\x{00A0}|&nbsp;|\s/", '', $text);
    //@ Clean UTF-8 encoding for spaces (char code 194)
    return str_replace(chr(194), "", $text);
  }

}
