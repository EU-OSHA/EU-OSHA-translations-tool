<?php

namespace Drupal\translation_workflow;

use Drupal\user\Entity\User;

/**
 * Service to find people to notify on translation workflow.
 */
class UsersToNotify {

  /**
   * Get a list of users by role.
   *
   * @param array $roleNames
   *   List of roles to search.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]|\Drupal\user\Entity\User[]
   *   User list.
   */
  public function getByRole(array $roleNames) {
    $ret = [];
    $userIds = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('roles', $roleNames, 'IN')
      ->execute();
    if (!empty($userIds)) {
      $ret = User::loadMultiple($userIds);
    }
    return array_map(function (User $user) {
      return $user->getEmail();
    }, $ret);
  }

}
