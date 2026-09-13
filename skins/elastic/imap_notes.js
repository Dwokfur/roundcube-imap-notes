(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var deleteStepField;
    var message;

    if (!form || !form.classList || !form.classList.contains('note-delete-form')) {
      return;
    }

    deleteStepField = form.querySelector('input[name="delete_step"]');
    if (deleteStepField && deleteStepField.value === 'confirm') {
      return;
    }

    message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }

    if (deleteStepField) {
      deleteStepField.value = 'confirm';
    }
  }, true);
})();
