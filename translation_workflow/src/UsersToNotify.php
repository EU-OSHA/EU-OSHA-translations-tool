<?php

namespace Drupal\translation_workflow;

use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to find people to notify on translation workflow.
 */
class UsersToNotify {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get a list of users by role.
   *
   * @param array $roleNames
   *   List of roles to search.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]|\Drupal\user\Entity\User[]
   *   User list.
   */
  public function getByRole(array $roleNames): array {
    $ret = [];
    if (empty($roleNames)) {
      return $ret;
    }
    $userStorage = $this->entityTypeManager->getStorage('user');
    $userIds = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', $roleNames, 'IN')
      ->execute();
    if (!empty($userIds)) {
      $ret = $userStorage->loadMultiple($userIds);
    }
    return array_map(function (User $user) {
      return $user->getEmail();
    }, $ret);
  }

}
