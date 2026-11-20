(function () {
  function reportHeight() {
    if (window.parent === window) return; // not framed — a plain page owns its own height
    try {
      parent.postMessage({ t: 'sm-preview-height', h: document.documentElement.scrollHeight }, '*');
    } catch (e) {
      /* framed but sealed — the parent's fallback height stands */
    }
  }
  // THE REACTIVE LAW: a reflow (the parent resizes the frame, the user
  // turns the zoom) changes the drawing's height — report again, debounced.
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    if (resizeTimer !== null) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(reportHeight, 120);
  });
  function show(d) {
    d.classList.remove('sm-mermaid-pending');
    reportHeight();
  }
  function fail(d, msg) {
    d.classList.remove('sm-mermaid-pending');
    d.classList.add('mermaid-missing');
    d.textContent = msg;
    reportHeight();
  }
  if (typeof mermaid === 'undefined') {
    document.querySelectorAll('.mermaid').forEach(function (d) {
      fail(d, 'mermaid diagram could not be rendered (the renderer failed to load)');
    });
    return;
  }
  // THE ZOOM LAW (user order 2026-11-20, same as the canvas zoom): fine 5%
  // steps — + zooms IN (100, 105, 110, …), − zooms OUT (95, 90, 85, …) —
  // BOTH directions open at 100%, bounded 50–300%. The level rides as a
  // plain percent; no step-index ladder to drift.
  var ZOOM_MIN = 50, ZOOM_MAX = 300, ZOOM_STEP = 5;
  function dressZoom(d) {
    var box = document.createElement('div');
    box.className = 'sm-mermaid-box';
    var bar = document.createElement('div');
    bar.className = 'sm-mermaid-zoom';
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', 'Diagram zoom');
    var out = document.createElement('button');
    out.type = 'button'; out.textContent = '\u2212'; out.setAttribute('aria-label', 'Zoom out');
    var val = document.createElement('button');
    val.type = 'button'; val.className = 'sm-mermaid-zoom-val'; val.textContent = '100%'; val.title = 'Reset to 100%';
    var inn = document.createElement('button');
    inn.type = 'button'; inn.textContent = '+'; inn.setAttribute('aria-label', 'Zoom in');
    var at = 100;
    function apply() {
      box.style.setProperty('--sm-mermaid-zoom', String(at / 100));
      val.textContent = at + '%';
      out.disabled = at <= ZOOM_MIN;
      inn.disabled = at >= ZOOM_MAX;
      // NO reportHeight on a zoom turn (user order 2026-11-20): the frame's
      // size is fixed by the 100% render — zooming scales the CONTENTS
      // inside the canvas, the canvas scrolls internally, the PAGE never
      // moves. Re-reporting here would grow the frame and re-flow the page.
    }
    out.addEventListener('click', function (e) { e.stopPropagation(); if (at > ZOOM_MIN) { at -= ZOOM_STEP; apply(); } });
    inn.addEventListener('click', function (e) { e.stopPropagation(); if (at < ZOOM_MAX) { at += ZOOM_STEP; apply(); } });
    val.addEventListener('click', function (e) { e.stopPropagation(); at = 100; apply(); });
    bar.append(out, val, inn);
    d.replaceWith(box);
    box.append(bar, d);
    apply();
  }
  mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
  document.querySelectorAll('.mermaid').forEach(function (d) {
    dressZoom(d);
    mermaid.run({ nodes: [d] }).then(function () {
      show(d);
    }, function () {
      fail(d, 'mermaid diagram could not be rendered');
    });
  });
})();
