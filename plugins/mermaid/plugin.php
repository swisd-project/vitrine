<?php

/**
 * 1. Relative path: plugins/mermaid/plugin.php
 * 2. Description: MERMAID — diagrams from the md, the renderer rides with
 *                 the ware. The author places the plugin's tag,
 *                 {{mermaid:<name>}}, into a page's md; the diagram's DATA
 *                 lives in its own md file in the page's mermaid/ folder
 *                 (mermaid/<name>.md beside the page's index.md — the
 *                 folder is the plugin's tray, created on the first save).
 *                 Installed AND enabled, the serve-time p6 contentFilter
 *                 (mermaid_page) resolves every tag: it reads the diagram
 *                 md, swaps the tag for a <div class="mermaid"> carrying
 *                 the source, and mermaid itself — bundled in the ware's
 *                 assets/ folder, served from /bridge/plugins/mermaid/
 *                 assets/mermaid.min.js, no CDN, no internet at view time
 *                 — renders it in the browser. The renderer rides once,
 *                 at the end of the body, only when the page carries a
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

/** The tag as it rides the md: {{mermaid:<name>}} — name is the data file's stem. */
const MERMAID_TAG = '/\{\{mermaid:([a-z0-9-]+)\}\}/';

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
 * The serve-time contentFilter (p6 slice 2): resolve every {{mermaid:<name>}}
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
        return '<div class="mermaid" data-mermaid="' . htmlspecialchars($name, ENT_QUOTES) . '"'
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
    // never executes (the whitelist forbids it), but assets/ is served.
    return $out . "\n"
        . '<script src="/bridge/plugins/mermaid/assets/mermaid.min.js"></script>'
        . '<script>mermaid.initialize({startOnLoad:false,securityLevel:"strict"});'
        . 'mermaid.run({querySelector:".mermaid"});</script>';
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
    return ['ok' => true, 'name' => $name, 'tag' => '{{mermaid:' . $name . '}}'];
}
