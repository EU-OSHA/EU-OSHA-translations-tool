/**
 * @file
 * Code for select all functionality on translation cart.
 */

(function (Drupal) {
  Drupal.behaviors.jobSelectAll = {
    attach: function (context, settings) {
      document.querySelector('[name="select_all_lng"]').addEventListener('change', (elem, ev) => {
        let targetlanguageSelect = document.querySelector('[name="target_language[]"]');
        targetlanguageSelect.querySelectorAll('option').forEach((value, index) => {
          value.selected = true;
        });
      });
    }
  };
})(Drupal);
