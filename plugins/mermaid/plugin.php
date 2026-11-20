<?php

/**
 * 1. Relative path: plugins/mermaid/plugin.php
 * 2. Description: MERMAID — diagrams from the md, the renderer rides with
 *                 the ware. The author places the plugin's tag,
 *                 {{mermaid_<name>}}, into a page's md (the underscore is the
 *                 law: Schmarrn's md dialect reads a bare `:name` as a
 *                 directive and eats it whole, so the colon form never
 *                 survives to this filter — the tag must stay token-shaped);
 *                 the diagram's DATA
 *                 lives in its own md file in the page's mermaid/ folder
 *                 (mermaid/<name>.md beside the page's index.md — the
 *                 folder is the plugin's tray, created on the first save).
 *                 Installed AND enabled, the serve-time p6 contentFilter
 *                 (mermaid_page) resolves every tag: it reads the diagram
 *                 md, swaps the tag for a <div class="mermaid"> carrying
 *                 the source, and mermaid itself — bundled in the ware's
 *                 assets/ folder, served from /bridge/plugins/mermaid/
 *                 assets/mermaid.min.js, no CDN, no internet at view time
 *                 — renders it in the browser. Until it does, the source
 *                 never shows: the div is born pending (the source hidden
 *                 behind a spinner, the slot collapsed) and the dress dies
 *                 the instant the SVG lands — a DOM-state law (:not(:has(svg)),
 *                 not the renderer's promise, so a hung run() can never pin a
 *                 spinner over a finished diagram). Failure drops the class
 *                 too — a notice, never a blank. A preload link rides first
 *                 in the body so the bundle's fetch starts while the page
 *                 still parses. Each rendered diagram is dressed in a box
 *                 with its own −/+ zoom pill — the scale is the diagram's,
 *                 never the page's. The renderer rides once, at the end of
 *                 the body, only when the page carries a
 *                 diagram. A tag whose data file is missing degrades to a
 *                 visible notice — never a dead page. The admin route
 *                 (diagram) is the tray's door: save/list/delete the md
 *                 files (auth + CSRF enforced by the core dispatch lid).
 * 3. Expects: Core-loaded only (never hit this file directly — the whitelist forbids it).
 * 4. Provides: mermaid_ident(), mermaid_content_root(), plugin_mermaid_page(),
 *              plugin_mermaid_route_diagram().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** The tag as it rides the md: {{mermaid_<name>}} — name is the data file's stem.
 *  The underscore is the law: Schmarrn's md dialect reads a bare ":name" as a
 *  directive and swallows it whole, so the colon form never survives the
 *  render to reach this filter. */
const MERMAID_TAG = '/\{\{mermaid_([a-z0-9-]+)\}\}/';

/** Identifiers: type, slug and diagram name share the house slug alphabet. */
function mermaid_ident(string $value): bool
{
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
}

/** The host's content tree (bridge/plugins/mermaid → the repo root). */
function mermaid_content_root(): string
{
    return dirname(__DIR__, 3) . '/src/content';
}

/**
 * The serve-time contentFilter (p6 slice 2): resolve every {{mermaid_<name>}}
 * tag against the page's mermaid/ tray and swap it for the diagram container;
 * the renderer rides once, at the end of the body, when the page carries one.
 *
 * @param array{site: array, type: string, slug: string, title: string} $ctx
 */
function plugin_mermaid_page(array $ctx, string $html): string
{
    $type = (string)$ctx['type'];
    $slug = (string)$ctx['slug'];
    $matched = false; // a tag stood on the page (diagram or notice)
    $found = false;   // a diagram actually rendered (the renderer's trigger)
    $out = preg_replace_callback(MERMAID_TAG, function (array $m) use ($type, $slug, &$matched, &$found): string {
        $name = $m[1];
        $file = mermaid_content_root() . "/{$type}/{$slug}/mermaid/{$name}.md";
        $source = is_file($file) ? trim((string)file_get_contents($file)) : '';
        if ($source === '') {
            // No data, no diagram — the page says so, visibly. Never a dead page.
            $matched = true;
            return '<span class="mermaid-missing" data-mermaid="' . htmlspecialchars($name, ENT_QUOTES) . '">'
                . 'mermaid diagram “' . htmlspecialchars($name, ENT_QUOTES) . '” has no data file yet</span>';
        }
        $matched = true;
        $found = true;
        return '<div class="mermaid sm-mermaid-pending" data-mermaid="' . htmlspecialchars($name, ENT_QUOTES) . '"'
            . ' role="img" aria-label="mermaid diagram ' . htmlspecialchars($name, ENT_QUOTES) . '">'
            . htmlspecialchars($source, ENT_QUOTES) . '</div>';
    }, $html);
    if (!$matched) {
        return $html; // no tag on the page — untouched
    }
    if (!$found) {
        return $out; // only notices — no renderer, no bytes
    }
    // The renderer is the ware's own asset: a direct hit on the plugin home
    // never executes (the whitelist forbids it), but assets/ is served. The
    // guard is the error-tolerant law — if the vendored bundle fails to load
    // (404, corrupt, a path that drifted) mermaid is undefined, so every
    // diagram degrades to a visible notice instead of a silent wall of raw
    // source; and each diagram renders in its own run() so one malformed
    // source (run rejects unless suppressErrors) can't abort the rest of the
    // page.
    $renderer = <<<'JS'
<style>
/* the mermaid ware: the raw source never shows. The div is born pending — the
   source is transparent AND collapsed (font-size:0) so it takes no room — and
   a spinner holds the slot. THE UN-HIDE IS A DOM-STATE LAW, NOT A PROMISE:
   :not(:has(svg)) means the pending dress dies the instant the SVG lands in
   the div, whatever the renderer's promise does — a hung run() (the bundle
   waits on animation frames) can no longer pin a spinner over a finished
   diagram. The plugin owns the class, NOT mermaid's data-processed (mermaid
   sets that BEFORE it renders). The fail path drops the class too — a notice,
   never a blank, never a dead page. The spinner wears the theme's own accent
   through var(--jam). */
.mermaid.sm-mermaid-pending:not(:has(svg)) { color:transparent; font-size:0; line-height:0; }
.mermaid.sm-mermaid-pending:not(:has(svg))::before {
  content:''; display:block; width:2.2rem; height:2.2rem; margin:1.5rem auto;
  border:3px solid rgba(95,86,74,.18); border-top-color:var(--jam,#d6006f);
  border-radius:50%; animation:sm-mermaid-spin .8s linear infinite;
}
@keyframes sm-mermaid-spin { to { transform:rotate(360deg); } }
/* the DIAGRAM zoom (user order 2026-11-16: the page-wide handle goes — this
   one scopes to the diagram, only the diagram): the guard dresses each
   rendered diagram in a box with its own −/+ pill; the scale rides a var ON
   THE BOX (per diagram, never per page) and the canvas' width compensates,
   so the drawing grows into the column instead of over it. The page's zoom
   handle is the core's business; this one is the ware's. */
.sm-mermaid-box { position:relative; overflow-x:auto; }
/* THE ZOOM LAW (user order 2026-11-20): + zooms IN (100, 105, …), − zooms
   OUT (95, 90, …) — the drawing's rendered width is the column × the level.
   NO width compensation on the container (the old calc(100% / zoom) narrowed
   the box while the responsive svg shrank to fit it — + visually zoomed
   OUT). The svg scales directly; a zoom past the column overflows the BOX
   (scoped horizontal scroll), never the page. */
.sm-mermaid-box .mermaid svg { width:calc(100% * var(--sm-mermaid-zoom,1)) !important; max-width:none !important; height:auto; }
.sm-mermaid-zoom {
  position:absolute; top:.35rem; right:.35rem; z-index:2; display:flex; align-items:center; gap:.05rem;
  padding:.15rem; background:var(--batter,#fff); border:1px solid var(--line,#e4e8ec); border-radius:4px;
  box-shadow:0 2px 8px rgba(95,86,74,.14);
  font-family:'Noto Sans',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; font-size:11px;
}
.sm-mermaid-zoom button { font:inherit; font-weight:700; color:var(--ink-soft,#59636e); background:none; border:none; padding:.15rem .4rem; cursor:pointer; border-radius:3px; }
.sm-mermaid-zoom button:hover:not(:disabled) { background:var(--crust,#f6f7f9); color:var(--ink,#1f2328); }
.sm-mermaid-zoom button:disabled { opacity:.35; cursor:not-allowed; }
.sm-mermaid-zoom .sm-mermaid-zoom-val { min-width:2.9rem; text-align:center; color:var(--ink,#1f2328); font-variant-numeric:tabular-nums; }
</style>
<script src="/bridge/plugins/mermaid/assets/mermaid.min.js"></script>
<script src="/bridge/plugins/mermaid/assets/guard.js"></script>
JS;
    // The preload rides FIRST in the body — a link the browser meets at the top
    // starts the multi-MB fetch while it still parses the page; the script at
    // the end then finds the bytes already there. (The renderer block above
    // rides last; only its <style> and <script> belong there.)
    return '<link rel="preload" as="script" href="/bridge/plugins/mermaid/assets/mermaid.min.js">' . "\n"
        . $out . "\n" . $renderer;
}

/** POST body -> the tray's answer. Editor+CSRF by the core lid; handlers never re-check. */
function plugin_mermaid_route_diagram(string $method, array $body): array
{
    if ($method !== 'POST') {
        return ['_status' => 405, 'error' => 'The mermaid tray answers POST only.'];
    }
    $action = is_string($body['action'] ?? null) ? $body['action'] : '';
    $type = is_string($body['type'] ?? null) ? $body['type'] : '';
    $slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
    if (!mermaid_ident($type) || !mermaid_ident($slug)) {
        return ['_status' => 400, 'error' => 'No page named — type and slug, please.'];
    }
    $dir = mermaid_content_root() . "/{$type}/{$slug}/mermaid";
    if ($action === 'list') {
        $names = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (is_file($dir . '/' . $file) && str_ends_with($file, '.md')) {
                $names[] = substr($file, 0, -3);
            }
        }
        sort($names);
        return ['diagrams' => $names];
    }
    $name = is_string($body['name'] ?? null) ? $body['name'] : '';
    if (!mermaid_ident($name)) {
        return ['_status' => 400, 'error' => 'The diagram name is unbaked dough — [a-z0-9-]+, please.'];
    }
    if ($action === 'delete') {
        if (!is_file($dir . '/' . $name . '.md') || !@unlink($dir . '/' . $name . '.md')) {
            return ['_status' => 404, 'error' => 'No such diagram in the tray.'];
        }
        return ['ok' => true];
    }
    if ($action !== 'save') {
        return ['_status' => 400, 'error' => 'The tray speaks save, list and delete.'];
    }
    $source = is_string($body['source'] ?? null) ? $body['source'] : '';
    if (trim($source) === '') {
        return ['_status' => 400, 'error' => 'An empty diagram is no diagram — give the source.'];
    }
    if (strlen($source) > 100_000) {
        return ['_status' => 400, 'error' => 'That diagram is a novel — 100 kB is the roof.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['_status' => 500, 'error' => 'The mermaid folder would not rise — check the host writable paths.'];
    }
    if (@file_put_contents($dir . '/' . $name . '.md', rtrim($source) . "\n") === false) {
        return ['_status' => 500, 'error' => 'The diagram md would not write.'];
    }
    return ['ok' => true, 'name' => $name, 'tag' => '{{mermaid_' . $name . '}}'];
}
