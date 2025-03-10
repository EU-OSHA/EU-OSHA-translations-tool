<?php

namespace Drupal\translation_workflow\Entity\ListBuilder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Class to define default opdations for the jobs page.
 */
class MultipleTargetLanguageJobListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    $operations['manage'] = [
      'url' => $entity->toUrl()->setOption('query', ['destination' => Url::fromRoute('<current>')->getInternalPath()]),
      'title' => t('Manage'),
      'weight' => -10,
    ];
    return $operations;
  }

}
