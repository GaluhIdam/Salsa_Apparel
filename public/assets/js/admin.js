/* Admin: konfirmasi sebelum aksi berisiko (form dengan atribut data-confirm). */
document.addEventListener('submit', function (e) {
  var form = e.target;
  if (form && form.matches && form.matches('[data-confirm]') && !window.confirm(form.dataset.confirm)) {
    e.preventDefault();
  }
});
