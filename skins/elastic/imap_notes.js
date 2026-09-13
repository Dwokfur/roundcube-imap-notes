(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var confirmedField;
    var message;

    if (!form || !form.classList || !form.classList.contains('note-delete-form')) {
      return;
    }

    message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }

    confirmedField = form.querySelector('input[name="confirm_delete"]');
    if (!confirmedField) {
      confirmedField = document.createElement('input');
      confirmedField.type = 'hidden';
      confirmedField.name = 'confirm_delete';
      form.appendChild(confirmedField);
    }
    confirmedField.value = '1';
  }, true);
})();
