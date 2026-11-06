// Progressive enhancement only: every page works without this file.

// Show timestamps in the viewer's local time (the server renders UTC).
const dateTime = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
document.querySelectorAll('time[data-local]').forEach((el) => {
  const date = new Date(el.getAttribute('datetime'));
  if (!Number.isNaN(date.getTime())) el.textContent = dateTime.format(date);
});

// Clicking anywhere on a row opens that vendor (the name is a real link too).
document.querySelectorAll('tr[data-href]').forEach((row) => {
  row.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = row.dataset.href;
  });
});

// Filter checkboxes apply straight away.
document.querySelectorAll('[data-autosubmit]').forEach((input) => {
  input.addEventListener('change', () => input.form.requestSubmit());
});

// Stage form: a backward move needs a reason. The server enforces this too.
const stageForm = document.querySelector('[data-stage-form]');
if (stageForm) {
  const select = stageForm.querySelector('select[name="to_stage"]');
  const note = stageForm.querySelector('textarea[name="note"]');
  const label = stageForm.querySelector('[data-note-label]');
  const warning = stageForm.querySelector('[data-backward-warning]');
  const submit = stageForm.querySelector('button[type="submit"]');

  const sync = () => {
    const backward = select.selectedOptions[0]?.dataset.direction === 'backward';
    label.textContent = backward ? 'Reason (required)' : 'Note (optional)';
    note.required = backward;
    note.placeholder = backward ? note.dataset.placeholderBackward : note.dataset.placeholderForward;
    warning.hidden = !backward;
  };
  select.addEventListener('change', sync);
  sync();

  stageForm.addEventListener('submit', () => {
    submit.disabled = true;
    submit.textContent = 'Saving…';
  });
}
