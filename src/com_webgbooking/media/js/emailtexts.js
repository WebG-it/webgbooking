/* WebG Booking: the email text editor (insert a placeholder, preview, send me a test, restore). No dependency besides what Joomla loads. */
(function () {
  'use strict';
  var form = document.getElementById('adminForm');
  if (!form || !form.hasAttribute('data-preview-url')) { return; }

  var lastInput = null;                       // the plain field the cursor was in last (null = the visual editor)
  var editorId = 'jform_text';

  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t && t.tagName === 'INPUT' && form.contains(t) && t.id && t.id.indexOf('jform_') === 0) { lastInput = t; }
  });
  document.addEventListener('mousedown', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('.js-editor-tinymce, .tox, .editor, [id^="jform_text"]')) { lastInput = null; }
  });

  function editor() {
    return window.Joomla && Joomla.editors && Joomla.editors.instances ? Joomla.editors.instances[editorId] : null;
  }

  function bodyValue() {
    var ed = editor();
    if (ed && typeof ed.getValue === 'function') { return ed.getValue(); }
    var ta = document.getElementById(editorId);
    return ta ? ta.value : '';
  }

  function data() {
    var fd = new FormData(form);
    fd.set('jform[text]', bodyValue());
    fd.delete('task');
    return fd;
  }

  function insertAtCursor(el, text) {
    var s = el.selectionStart, e = el.selectionEnd, v = el.value;
    if (typeof s !== 'number') { el.value = v + text; return; }
    el.value = v.slice(0, s) + text + v.slice(e);
    el.selectionStart = el.selectionEnd = s + text.length;
    el.focus();
  }

  var sel = form.querySelector('[data-wgb-insert]');
  if (sel) {
    sel.addEventListener('change', function () {
      var token = sel.value;
      if (!token) { return; }
      if (lastInput) {
        insertAtCursor(lastInput, token);
      } else {
        var ed = editor();
        if (ed && typeof ed.replaceSelection === 'function') { ed.replaceSelection(token); }
        else { var ta = document.getElementById(editorId); if (ta) { insertAtCursor(ta, token); } }
      }
      sel.selectedIndex = 0;
    });
  }

  function post(url, done) {
    var fd = data();
    var token = form.getAttribute('data-token');
    if (token) { fd.set(token, '1'); }
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(done)
      .catch(function () { done({ ok: false, message: Joomla.Text._('COM_WEBGBOOKING_EM_PREVIEW_FAILED') }); });
  }

  function say(message, type) {
    if (window.Joomla && Joomla.renderMessages) {
      var o = {}; o[type || 'message'] = [message]; Joomla.renderMessages(o);
    } else { window.alert(message); }
  }

  var pv = form.querySelector('[data-wgb-preview]');
  if (pv) {
    pv.addEventListener('click', function () {
      post(form.getAttribute('data-preview-url'), function (res) {
        if (!res || !res.ok) { say((res && res.message) || Joomla.Text._('COM_WEBGBOOKING_EM_PREVIEW_FAILED'), 'error'); return; }
        var box = document.getElementById('wgb-preview');
        document.getElementById('wgb-preview-subject').textContent = res.subject || '';
        document.getElementById('wgb-preview-frame').srcdoc = res.html || '';
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  var tb = form.querySelector('[data-wgb-test]');
  if (tb) {
    tb.addEventListener('click', function () {
      tb.disabled = true;
      post(form.getAttribute('data-test-url'), function (res) {
        tb.disabled = false;
        say((res && res.message) || '', res && res.ok ? 'message' : 'error');
      });
    });
  }

  var conv = form.querySelector('[data-task="email.convert"]');
  if (conv) {
    conv.addEventListener('click', function (e) { e.preventDefault(); Joomla.submitform('email.convert', form); });
  }

  // the toolbar: "Restore the default text" asks first
  var original = Joomla.submitbutton;
  Joomla.submitbutton = function (task) {
    if (task === 'email.restore' && !window.confirm(Joomla.Text._('COM_WEBGBOOKING_EM_RESTORE_CONFIRM'))) { return; }
    if (typeof original === 'function') { return original.apply(this, arguments); }
    Joomla.submitform(task, form);
  };
})();
