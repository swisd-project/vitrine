(function () {
  function reportHeight() {
    if (window.parent === window) return; // not framed — a plain page owns its own height
    try {
      parent.postMessage({ t: 'sm-preview-height', h: document.documentElement.scrollHeight }, '*');
    } catch (e) {
      /* framed but sealed — the parent's fallback height stands */
    }
  }
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
  var STEPS = [1, 1.25, 1.5, 2, 2.5, 3];
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
    var at = 0;
    function apply() {
      box.style.setProperty('--sm-mermaid-zoom', String(STEPS[at]));
      val.textContent = Math.round(STEPS[at] * 100) + '%';
      out.disabled = at === 0;
      inn.disabled = at === STEPS.length - 1;
      reportHeight(); // a zoom turn grows the drawing — a framing parent must grow with it
    }
    out.addEventListener('click', function (e) { e.stopPropagation(); if (at > 0) { at--; apply(); } });
    inn.addEventListener('click', function (e) { e.stopPropagation(); if (at < STEPS.length - 1) { at++; apply(); } });
    val.addEventListener('click', function (e) { e.stopPropagation(); at = 0; apply(); });
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
