# Mermaid

Version **0.2.5**. Diagrams from the md, the renderer rides with the ware. The
author places the plugin's tag `{{mermaid_<name>}}` into a page's md; the
diagram's data lives in its own md file in the page's `mermaid/` folder.
Installed and enabled, the serve-time content filter (`mermaid_page`) resolves
every tag and mermaid itself — bundled in the ware's assets, no CDN, no
internet needed at view time — renders the diagram in the browser.

The underscore is the law: Schmarrn's md dialect (mdms + remark-directive) reads
a bare `:name` as a directive and eats it, so the colon form `mermaid:foo` loses
its name before the filter ever sees it. The underscore form stays whole and
resolves. The colon form is dead as of 0.2.0 — a page still carrying
`mermaid:foo` must change it to `{{mermaid_foo}}`.

## How a diagram is born

1. The author inserts the editor block **"Mermaid Diagram"** (`mermaid_diagram`
   in the canvas Add menu). It drops `{{mermaid_diagram}}` into the page's md;
   the author renames `diagram` to their own name.
2. The diagram's source — raw mermaid (`graph TD`, `sequenceDiagram`, …) — is
   stored in its own md file, one per diagram:
   `src/content/<type>/<slug>/mermaid/<name>.md`. The plugin creates the
   `mermaid/` folder on the first save. No subfolder per diagram — the flat
   layout is deliberate and most-reliable.
3. The tag is plain text in the md (mdms renders it untouched, e.g. inside a
   `<p>`); the filter swaps it at serve time.

## Tag resolution (serve-time)

The `mermaid_page` content filter scans the rendered page for
`{{mermaid_<name>}}` (name matches `[a-z0-9-]+`):

- Data file present and non-empty → the tag becomes
  `<div class="mermaid sm-mermaid-pending" data-mermaid="<name>" role="img" aria-label="mermaid diagram <name>">`
  carrying the (HTML-escaped) source. mermaid reads it as text.
- While `sm-mermaid-pending` holds: the source is transparent AND collapsed
  (`font-size:0` — takes no room); a small spinner holds the slot. The raw
  source never shows while the page loads. Since 0.2.2 the un-hide is a
  DOM-state law, not a promise: `sm-mermaid-pending` carries `:not(:has(svg))`,
  so it dies the instant the SVG lands in the div — a hung `run()` (the vendored
  bundle waits on animation frames) can no longer pin a spinner over a finished
  diagram. The fail path still drops the class (a notice, never a blank).
- Why a plugin-owned class, not mermaid's `data-processed`: the vendored
  mermaid sets `data-processed="true"` BEFORE it renders — a hide hooked there
  would un-hide the source mid-render. The plugin controls its own class.
- Data file missing or empty → a visible notice —
  `mermaid diagram "<name>" has no data file yet` — never a dead page.
- No tag on the page → the page is untouched: no script, no bytes.

A `<link rel="preload" as="script">` for the vendored bundle rides FIRST in the
body — the multi-MB fetch starts while the browser still parses the page,
instead of when it reaches the script at the end. The renderer itself rides
**once**, at the end of the body, only when at least one diagram actually
rendered:

```html
<script src="/bridge/plugins/mermaid/assets/mermaid.min.js"></script>
<script>
(function () {
  function fail(d, msg) { d.classList.add('mermaid-missing'); d.textContent = msg; }
  if (typeof mermaid === 'undefined') {
    document.querySelectorAll('.mermaid').forEach(function (d) {
      fail(d, 'mermaid diagram could not be rendered (the renderer failed to load)');
    });
    return;
  }
  mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
  document.querySelectorAll('.mermaid').forEach(function (d) {
    mermaid.run({ nodes: [d] }).catch(function () {
      fail(d, 'mermaid diagram could not be rendered');
    });
  });
})();
</script>
```

Rendering is entirely client-side, by mermaid.js; `securityLevel: "strict"`.

Once the renderer is up, the guard dresses each rendered diagram in a box
(`sm-mermaid-box`) with its own `− / 100% / +` pill at the diagram's top-right.
Scale steps: 100 / 125 / 150 / 200 / 250 / 300 %; clicking the percentage
resets to 100 %. The scale rides a CSS var on the box — per diagram, never per
page — and the diagram's width compensates, so the drawing grows into the
column instead of overflowing it. The pill is guard-generated (no change to
the tag or the div); works on the public page and in the editor's
token-preview iframe alike; not persisted — a reading aid for the moment
(exists because the core's page-wide zoom handle zooms everything; the ware
owns a zoom that touches only the diagram).

Since 0.2.4, when the preview is FRAMED (the editor's token-preview iframe),
the guard also reports the content's height to the parent by
`postMessage({ t: 'sm-preview-height', h: <scrollHeight> }, '*')` — a listening
parent can size the frame to the diagram instead of clipping it into a
scrolling box. It fires after a diagram lands (show), after a failure (the
notice is sized too), and on every zoom turn (a zoom grows the drawing, the
frame grows with it). Unframed pages do nothing — a plain page owns its own
height. The report is fire-and-forget: if the parent never listens, the frame
keeps its fallback height and nothing breaks. The why: the editor's preview
iframe is a fixed-height sandboxed frame (`allow-scripts`, no same-origin), so
postMessage is the only channel out — the ware reports, the parent sizes.

Since 0.2.5 the guard is an external asset, not inline script. The editor's
token-preview iframe is served under a CSP allowing origin scripts only
(`script-src <origin>`, no `unsafe-inline`) — the 0.2.4 inline `<script>` block
was silently blocked there, mermaid's `startOnLoad: true` default auto-rendered
without the guard, and the preview showed the diagram but no pill, no pending
dress, no height report. The public page (no CSP) worked — the bug read as
"works in public, dead in /_". The guard now lives in
`assets/guard.js`, beside the mermaid bundle, and the renderer block emits
`<script src="/bridge/plugins/mermaid/assets/guard.js"></script>` — external
origin scripts pass the cage's CSP, so the guard runs everywhere (public page,
preview cage, any framed surface). No contract change: same class names, same
steps, same height report.

## The renderer ships with the ware

`assets/mermaid.min.js` — mermaid **v11.17.2**, self-contained, sets the global
`mermaid` — is vendored in the plugin's `assets/` folder and served from
`/bridge/plugins/mermaid/assets/mermaid.min.js` (the assets folder is the only
spot the plugin home serves; a direct hit on the plugin home never executes).
The guard ships as a second vendored asset, `assets/guard.js`, served beside it.
`mermaid.LICENSE` carries mermaid's MIT license text. No CDN, no internet at
view time.

## Error-tolerant

Two fails degrade to a visible notice, never a blank, never a dead page —
additive beside the existing missing-data notice (kept):

- Renderer failed to load — the vendored bundle 404s, corrupts, or its path
  drifts, so `mermaid` is undefined → the pending class is dropped, every
  `.mermaid` div shows
  `mermaid diagram could not be rendered (the renderer failed to load)` instead
  of a silent wall of raw source.
- One bad diagram, not the batch — each diagram renders in its own
  `mermaid.run({ nodes: [d] })`, wrapped in `.catch`; a malformed source
  (`run()` rejects unless `suppressErrors` is set) no longer aborts the rest —
  the bad one shows `mermaid diagram could not be rendered`, the others render.
  Verified on a battery (two diagrams, one malformed → both divs present).

## The tray door (route)

`POST /bridge/plugin/mermaid/diagram` — auth `user` (any logged-in editor),
CSRF enforced by the core dispatch lid. One route, three actions via
`body.action`:

| Action | Body | Answer |
|---|---|---|
| `save` | `type`, `slug`, `name`, `source` | Creates the `mermaid/` folder if needed, writes `<name>.md` (source, 1 byte..100 kB, non-empty), answers `{ok, name, tag: "{{mermaid_<name>}}"}` — the tag to paste. |
| `list` | `type`, `slug` | `{diagrams: [names]}` — the diagram names in the page's tray, sorted. |
| `delete` | `type`, `slug`, `name` | Removes `<name>.md`; 404 when absent. |

Guards: `type`, `slug` and `name` are house slugs (`[a-z0-9-]+`); `source` is
at most 100 kB. Wrong shape → 400.

There is **no dedicated diagram-editor UI** in the dashboard yet — the tray's
door is the route itself, driven by an editor with API access.

## Setup (site owner)

1. Install the plugin (arrives disabled — flip the enable switch).
2. Requires Schmarrn >= 1.2.0 (content filters + editor blocks). No other
   plugin required. No panel capability.

---

SPDX-License-Identifier: MPL-2.0
