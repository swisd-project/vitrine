# Mermaid

Version **0.1.0**. Diagrams from the md, the renderer rides with the ware. The
author places the plugin's tag `{{mermaid:<name>}}` into a page's md; the
diagram's data lives in its own md file in the page's `mermaid/` folder.
Installed and enabled, the serve-time content filter (`mermaid_page`) resolves
every tag and mermaid itself — bundled in the ware's assets, no CDN, no
internet needed at view time — renders the diagram in the browser.

## How a diagram is born

1. The author inserts the editor block **"Mermaid Diagram"** (`mermaid_diagram`
   in the canvas Add menu). It drops `{{mermaid:diagram}}` into the page's md;
   the author renames `diagram` to their own name.
2. The diagram's source — raw mermaid (`graph TD`, `sequenceDiagram`, …) — is
   stored in its own md file, one per diagram:
   `src/content/<type>/<slug>/mermaid/<name>.md`. The plugin creates the
   `mermaid/` folder on the first save.
3. The tag is plain text in the md (mdms renders it untouched, e.g. inside a
   `<p>`); the filter swaps it at serve time.

## Tag resolution (serve-time)

The `mermaid_page` content filter scans the rendered page for
`{{mermaid:<name>}}` (name matches `[a-z0-9-]+`):

- Data file present and non-empty → the tag becomes
  `<div class="mermaid" data-mermaid="<name>" role="img" aria-label="mermaid diagram <name>">`
  carrying the (HTML-escaped) source. mermaid reads it as text.
- Data file missing or empty → a visible notice —
  `mermaid diagram "<name>" has no data file yet` — never a dead page.
- No tag on the page → the page is untouched: no script, no bytes.

The renderer rides **once**, at the end of the body, only when at least one
diagram actually rendered:

```html
<script src="/bridge/plugins/mermaid/assets/mermaid.min.js"></script>
<script>mermaid.initialize({startOnLoad:false,securityLevel:"strict"});
mermaid.run({querySelector:".mermaid"});</script>
```

Rendering is entirely client-side, by mermaid.js; `securityLevel: "strict"`.

## The renderer ships with the ware

`assets/mermaid.min.js` — mermaid **v11.17.2**, self-contained, sets the global
`mermaid` — is vendored in the plugin's `assets/` folder and served from
`/bridge/plugins/mermaid/assets/mermaid.min.js` (the assets folder is the only
spot the plugin home serves; a direct hit on the plugin home never executes).
`mermaid.LICENSE` carries mermaid's MIT license text. No CDN, no internet at
view time.

## The tray door (route)

`POST /bridge/plugin/mermaid/diagram` — auth `user` (any logged-in editor),
CSRF enforced by the core dispatch lid. One route, three actions via
`body.action`:

| Action | Body | Answer |
|---|---|---|
| `save` | `type`, `slug`, `name`, `source` | Creates the `mermaid/` folder if needed, writes `<name>.md` (source, 1 byte..100 kB, non-empty), answers `{ok, name, tag: "{{mermaid:<name>}}"}` — the tag to paste. |
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
