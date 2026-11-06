/*
 * sections.js — the one-pager's companion: an anchor nav built from the page's
 * own sections, plus the scrollspy that marks where you are.
 *
 * The one-pager has no deck: sections flow, the document scrolls natively,
 * anchor clicks glide via CSS `scroll-behavior: smooth`. What the script adds:
 *  - a section nav in the header, one link per `.sm-slide`, labeled from the
 *    slide titles (`aria-label`), clicking an anchor glides;
 *  - the live `.active` class on the link of the section in view;
 *  - nothing else. No snap, no keyboard takeover — the page reads like a page.
 *
 * READINESS LAW: a page reaches this script through three serving doors —
 * the authed editor view (content renders client-side, asynchronously), the
 * fresh HTML cache (content is static), and the fallback shell (client
 * render, asynchronously). The script therefore never assumes the sections
 * exist yet: it waits on a MutationObserver, wires itself when they appear,
 * and rewires when the set is replaced — the editor swaps nodes on every
 * block save, and stale references would leave links and spy firing at
 * ghosts.
 *
 * No dependencies. SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */
(() => {
  // The currently wired section set — null once nothing is.
  let live = null;

  // Section roots: top-level .sm-slide nodes only (a slide never nests in one).
  const scan = () => {
    const slides = Array.from(document.querySelectorAll('.sm-slide'))
      .filter((s) => !s.parentElement.closest('.sm-slide'));
    return slides.length === 0 ? null : slides;
  };

  // Identity of the section set: node references catch the editor's node
  // swaps, the attribute fingerprint catches retitled sections in place.
  const fingerprintOf = (slides) =>
    slides
      .map((s) => `${s.id}~${s.getAttribute('aria-label') ?? s.getAttribute('data-slide-title') ?? ''}`)
      .join('|');

  const sameSet = (slides) =>
    live !== null &&
    live.slides.length === slides.length &&
    live.slides.every((s, i) => s === slides[i]) &&
    live.fingerprint === fingerprintOf(slides);

  const unwire = () => {
    if (live === null) return;
    live.ac.abort(); // every listener and the spy die with the signal
    document.querySelector('.cf-sections')?.remove();
    live = null;
  };

  const wire = () => {
    const slides = scan();
    if (slides === null) {
      unwire();
      return;
    }
    if (sameSet(slides)) return;
    unwire();

    const ac = new AbortController();
    live = { ac, slides, fingerprint: fingerprintOf(slides) };

    // ——— the anchor nav: built from the sections themselves ———
    const nav = document.createElement('nav');
    nav.className = 'cf-sections';
    nav.setAttribute('aria-label', 'Page sections');
    slides.forEach((slide, i) => {
      const title = slide.getAttribute('aria-label') || slide.getAttribute('data-slide-title');
      if (slide.id) {
        const a = document.createElement('a');
        a.href = `#${slide.id}`;
        a.textContent = title || `Section ${i + 1}`;
        nav.append(a);
      } else {
        const span = document.createElement('span');
        span.textContent = title || `Section ${i + 1}`;
        nav.append(span);
      }
    });
    const host = document.querySelector('.site-head');
    if (host) host.append(nav); else document.body.prepend(nav);
    const links = Array.from(nav.children);

    // ——— scrollspy: the section crossing the viewport's middle band marks the link ———
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const current = slides.indexOf(entry.target);
        links.forEach((link, i) => link.classList.toggle('active', i === current));
      });
    }, { rootMargin: '-45% 0px -45% 0px' });
    slides.forEach((s) => spy.observe(s));
    ac.signal.addEventListener('abort', () => spy.disconnect());
  };

  // ——— readiness: watch for the sections, then for their replacements ———
  // Mutations coalesce: the editor renders block by block, so a burst must
  // cost ONE scan, not one per node.
  let pending = 0;
  const observer = new MutationObserver(() => {
    clearTimeout(pending);
    pending = setTimeout(wire, 80);
  });
  observer.observe(document.body, { childList: true, subtree: true });
  wire();
})();
