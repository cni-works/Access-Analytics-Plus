(function () {
  'use strict';

  document.querySelectorAll('[data-aap-ai-report-form]').forEach(function (form) {
    var custom = form.querySelector('[data-aap-ai-custom-period]');
    var radios = form.querySelectorAll('input[name="report_range"]');
    function updatePeriod() {
      var selected = form.querySelector('input[name="report_range"]:checked');
      if (custom) custom.hidden = !selected || selected.value !== 'custom';
    }

    function checked(name, value) {
      var input = form.querySelector('input[name="' + name + '"][value="' + value + '"]');
      return Boolean(input && input.checked);
    }

    function setConditional(selector, visible) {
      var container = form.querySelector(selector);
      if (!container) return;
      container.hidden = !visible;
      container.querySelectorAll('input, textarea, select').forEach(function (field) {
        field.disabled = !visible;
      });
    }

    function updateBranches() {
      setConditional('[data-aap-inquiries]', checked('concern_topics[]', 'increase_inquiries'));
      setConditional('[data-aap-concern-other]', checked('concern_topics[]', 'other'));
      setConditional('[data-aap-advice-other]', checked('advice_types[]', 'other'));
      setConditional('[data-aap-all-post-titles]', checked('include_site_structure', '1'));
    }

    radios.forEach(function (radio) { radio.addEventListener('change', updatePeriod); });
    form.querySelectorAll('input[name="concern_topics[]"], input[name="advice_types[]"]').forEach(function (input) {
      input.addEventListener('change', updateBranches);
    });
    var structure = form.querySelector('input[name="include_site_structure"]');
    if (structure) structure.addEventListener('change', updateBranches);
    updatePeriod();
    updateBranches();
  });
})();
