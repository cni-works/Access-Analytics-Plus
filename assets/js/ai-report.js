(function () {
  'use strict';

  document.querySelectorAll('[data-aap-ai-report-form]').forEach(function (form) {
    var custom = form.querySelector('[data-aap-ai-custom-period]');
    var radios = form.querySelectorAll('input[name="report_range"]');
    function updatePeriod() {
      var selected = form.querySelector('input[name="report_range"]:checked');
      if (custom) custom.hidden = !selected || selected.value !== 'custom';
    }
    radios.forEach(function (radio) { radio.addEventListener('change', updatePeriod); });
    updatePeriod();
  });
})();
