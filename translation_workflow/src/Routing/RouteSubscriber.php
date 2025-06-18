<?php

namespace Drupal\translation_workflow\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\translation_workflow\Form\MultipleTargetLanguageCartForm;
use Symfony\Component\Routing\RouteCollection;

/**
 * Service to alter a route.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('tmgmt.cart')) {
      $route->setDefault('_form', MultipleTargetLanguageCartForm::class);
    }
  }

}
