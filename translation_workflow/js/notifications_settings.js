/**
 * @file
 * Code for the Notification Settings Form.
 */

(function (Drupal) {
  Drupal.behaviors.notificationSettinsForm = {
    attach: function (context, settings) {
      document.querySelectorAll('select.form-select').forEach(function (select) {
        select.addEventListener('change', function () {
          const selectedOptions = Array.from(select.selectedOptions);
          const noneOption = Array.from(select.options).find(opt => opt.value === '');
          const isNoneSelected = selectedOptions.some(opt => opt.value === '');
          if (isNoneSelected) {
            Array.from(select.options).forEach(opt => {
              if (opt.value !== '') {
                opt.selected = false;
              }
            });
          }
          else {
            if (noneOption && noneOption.selected) {
              noneOption.selected = false;
            }
          }
        });
      });
    }
  };
})(Drupal);
