/* Direct booking page: the card never gets SHORTER while the visitor moves through the steps (calendar, times, form), so it does not jump up and
   down when the height changes. It may grow (the form is taller); a change of the window WIDTH starts again. */
(function () {
  var c = document.querySelector('.bk-card');
  if (!c || !window.ResizeObserver) { return; }
  var max = 0, w = window.innerWidth;
  new ResizeObserver(function () {
    var h = c.offsetHeight;
    if (h > max) { max = h; c.style.minHeight = h + 'px'; }
  }).observe(c);
  window.addEventListener('resize', function () {
    if (window.innerWidth !== w) { w = window.innerWidth; max = 0; c.style.minHeight = ''; }
  });
})();
