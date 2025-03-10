<?php

namespace Drupal\translation_workflow;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for utils and manage tmgmt elements on html.
 */
class TmgmtElementsUtils {

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
    $crawler = new Crawler($fieldValue);
    $tmgmtCounter = 1;
    $crawler->filter('body > *')->each(function (Crawler $node, $i) use (&$tmgmtCounter, &$finalValue) {
      $domNode = $node->getNode(0);
      $domNode->setAttribute('id', 'tmgmt-' . $tmgmtCounter);
      $tmgmtCounter++;
    });
    return $crawler->filter('body')->html();
  }

}
