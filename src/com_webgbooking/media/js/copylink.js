/**
 * WebG Booking, admin: the "Copy link" buttons of the event types. A button carries the address in data-wgb-copy and the
 * text to show for a moment after copying in data-wgb-copied. Uses the clipboard API where the page is allowed to,
 * otherwise a selected text field and execCommand, so it also works on an http address.
 */
(function () {
  'use strict';

  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  function done(btn) {
    var old = btn.innerHTML;
    btn.textContent = btn.getAttribute('data-wgb-copied') || '';
    btn.classList.add('wgb-copied');
    setTimeout(function () { btn.innerHTML = old; btn.classList.remove('wgb-copied'); }, 1800);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-wgb-copy]') : null;
    if (!btn) { return; }
    ev.preventDefault();
    var text = btn.getAttribute('data-wgb-copy') || '';
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(btn); }, function () { if (fallback(text)) { done(btn); } });
    } else if (fallback(text)) {
      done(btn);
    }
  });
})();
