<?php

namespace Drupal\translation_workflow\Entity;

use Drupal\tmgmt\JobInterface;

/**
 * Defines priority structure for an entity.
 */
interface PriorityJobInterface extends JobInterface {

  const PRIORITY_LOW = 'low';
  const PRIORITY_NORMAL = 'normal';
  const PRIORITY_HIGH = 'high';

  /**
   * Get priority value for the entity.
   *
   * @return string
   *   Priority of entity.
   */
  public function getPriority();

  /**
   * Set priority value for entity.
   *
   * @param string $priority
   *   New priority value for entity.
   */
  public function setPriority(string $priority = self::PRIORITY_NORMAL);

  /**
   * Get priority posible values.
   *
   * @return string[]
   *   Priority values for entity.
   */
  public function getPriorityValues();

}
