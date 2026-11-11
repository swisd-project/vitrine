<?php

/**
 * 1. Relative path: plugins/bienenstich/plugin.php
 * 2. Description: BIENENSTICH (API) — the CMS's API surface as a ware. The core
 *                 answers ONE page at a time (/bridge/public/<type>/<slug>);
 *                 a headless consumer wants the whole honey jar in one dip.
 *                 Three public routes: content (every published page — title,
 *                 slug, url, tags, updatedAt and the rendered body HTML unless
 *                 body=0), search (a byte needle over PUBLISHED markdown only
 *                 — the core's own needle reads the drafts, this door never
 *                 does) and status (the heartbeat: site face, page count,
 *                 version). READ-ONLY BY LAW: it never writes, never renders
 *                 Markdown (PHP does not parse — the cache speaks), never
 *                 leaks a draft — not even to an editor's own session, so the
 *                 public face answers the same to every hand. CORS opens wide
 *                 for plain GETs; the core's OPTIONS exit happens before a
 *                 plugin route is ever reached, so no preflight, no headers.
 * 3. Expects: Core-loaded only (never hit this file directly — the whitelist forbids it).
 * 4. Provides: plugin_bienenstich_route_{content,search,status}().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** The key — the API's secret. ONE source of truth: the plugin's own
 *  `api_key` setting (settings tab or the keygen mint write it). The old
 *  `bienenstich.key` file beside this file is a read-only fallback for
 *  pre-settings installs — never written anymore. No setting and no file:
 *  the door is welded shut. The client presents it as ?key=<secret>. */
function bienenstich_key(): string
{
    static $key = null;
    if ($key === null) {
        $key = function_exists('\Plugins\setting')
            ? (string)(\Plugins\setting('bienenstich', 'api_key') ?? '')
            : '';
        if ($key === '') {
            $file = __DIR__ . '/bienenstich.key';
            $key = is_file($file) ? trim((string)file_get_contents($file)) : '';
        }
    }
    return $key;
}

/** The door: every route passes here first. No key on disk, no key given,
 *  or a wrong key — the answer is one plain line and nothing else. Exits
 *  before the core's JSON writer: the bots get text, not a contract. */
function bienenstich_gate(): void
{
    $key = bienenstich_key();
    $given = is_string($_GET['key'] ?? null) ? (string)$_GET['key'] : '';
    if ($key === '' || !hash_equals($key, $given)) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Bakery closed.';
        exit;
    }
}

/** The CORS door: the API answers any origin — a plain GET carries no custom
 *  headers and needs no preflight, and the content it serves is public
 *  content. One line, one law. */
function bienenstich_cors(): void
{
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
    }
}

/** The ware's own version — read from the manifest beside this file, so the
 *  code can never contradict the shelf (one source of truth, no twin). */
function bienenstich_version(): string
{
    static $version = null;
    if ($version === null) {
        $manifest = is_file(__DIR__ . '/plugin.json')
            ? json_decode((string)file_get_contents(__DIR__ . '/plugin.json'), true)
            : null;
        $version = is_array($manifest) && is_string($manifest['version'] ?? null)
            ? $manifest['version']
            : '0.0.0';
    }
    return $version;
}

/** The published pages — type, slug, updatedAt, tags. No title (a title is a
 *  read of the md) and NO drafts: the API is a public face, so a draft never
 *  rides along — not even for the editor whose session would have listed it. */
function bienenstich_published(): array
{
    $out = [];
    foreach (\Store\listAll() as $type => $pages) {
        foreach ($pages as $page) {
            if (($page['draft'] ?? false) === true) {
                continue; // a draft is not published — the door stays shut
            }
            $out[] = [
                'type' => (string)$type,
                'slug' => (string)$page['slug'],
                'updatedAt' => (int)($page['updatedAt'] ?? 0),
                'tags' => array_values((array)($page['tags'] ?? [])),
            ];
        }
    }
    return $out;
}

/** The public URL of a page — the clean-URL law of Serve\dispatch(): one
 *  segment is a page, two are a type and a slug, the home is the root. */
function bienenstich_url(string $type, string $slug, string $home): string
{
    if ($home !== '' && $type === 'pages' && $slug === $home) {
        return '/';
    }
    return $type === 'pages' ? '/' . $slug : '/' . $type . '/' . $slug;
}

/** The rendered body — the cache, and only while it is fresh. A stale cache
 *  is NO body at all: an honest null beats a wrong page. */
function bienenstich_body(string $type, string $slug): ?string
{
    return \Cache\htmlStale($type, $slug) ? null : \Cache\html($type, $slug);
}

/** The body of a page without its frontmatter — the needle asks about
 *  CONTENT, not about the metadata above it. */
function bienenstich_bodyText(string $raw): string
{
    return (string)preg_replace('/\A---\s*\R.*?\R---\s*\R/s', '', $raw, 1);
}

/** The matching line, trimmed to a readable length — the needle's answer. */
function bienenstich_excerpt(string $raw, string $needle): string
{
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && stripos($line, $needle) !== false) {
            return mb_strlen($line) > 160 ? mb_substr($line, 0, 157) . '…' : $line;
        }
    }
    return '';
}

/** The mint: a random alphanumeric body for a key — random_int, not rand:
 *  a secret forged from a predictable source is not a secret. */
function bienenstich_mint(): string
{
    $alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < 32; $i++) {
        $out .= $alnum[random_int(0, mb_strlen($alnum) - 1)];
    }
    return $out;
}

/** Admin route: GET /bridge/plugin/bienenstich/keygen — the key mint. No key
 *  set: one is minted (bs_<version>_<random>) and stored in the plugin's own
 *  api_key SETTING — the same slot the settings tab edits. A key already set
 *  (setting or legacy key file): it is shown, never clobbered — rotating is
 *  ?rotate=1 and kills every consumer of the old key on purpose. Admin lid
 *  only: the core gates this door, the API key never guards its own mint. */
function plugin_bienenstich_route_keygen(string $method, array $body): array
{
    if ($method !== 'GET') {
        return ['_status' => 405, 'error' => 'The mint answers GET only.'];
    }
    $rotate = ($_GET['rotate'] ?? '') === '1';
    $key = bienenstich_key();
    if ($key === '' || $rotate) {
        $key = 'bs_' . bienenstich_version() . '_' . bienenstich_mint();
        \Plugins\setSetting('bienenstich', 'api_key', $key);
    }
    return [
        'ok' => true,
        'key' => $key,
        'rotated' => $rotate,
        'usage' => '/bridge/plugin/bienenstich/content?key=' . $key,
    ];
}

/** Public route: GET /bridge/plugin/bienenstich/content — the whole published
 *  site in one JSON document: the site face plus every published page (title,
 *  slug, url, tags, updatedAt) and — unless body=0 — the rendered body HTML.
 *  ?type=<type> narrows the dip to one content type. */
function plugin_bienenstich_route_content(string $method, array $body): array
{
    if ($method !== 'GET') {
        return ['_status' => 405, 'error' => 'The API reads — GET only, one way in.'];
    }
    bienenstich_gate();
    bienenstich_cors();
    $site = \Site\load();
    $home = \Serve\homeSlug($site);
    $type = is_string($_GET['type'] ?? null) ? trim((string)$_GET['type']) : '';
    $withBody = ($_GET['body'] ?? '1') !== '0';
    $pages = [];
    foreach (bienenstich_published() as $page) {
        if ($type !== '' && $page['type'] !== $type) {
            continue;
        }
        $page['title'] = \Serve\titleOf($page['type'], $page['slug'], $page['slug']);
        $page['url'] = bienenstich_url($page['type'], $page['slug'], $home);
        $page['bodyHtml'] = $withBody ? bienenstich_body($page['type'], $page['slug']) : null;
        $pages[] = $page;
    }
    return [
        'plugin' => 'bienenstich',
        'version' => bienenstich_version(),
        'site' => [
            'name' => (string)($site['name'] ?? ''),
            'claim' => (string)($site['claim'] ?? ''),
            'logo' => \Serve\logoUrl((string)($site['logo'] ?? '')),
            'home' => $home,
        ],
        'generatedAt' => date('c'),
        'pages' => $pages,
    ];
}

/** Public route: GET /bridge/plugin/bienenstich/search?q=needle — a byte
 *  search over PUBLISHED markdown only (the core's own needle reads the
 *  drafts; this door never does), with the matching line as the excerpt. */
function plugin_bienenstich_route_search(string $method, array $body): array
{
    if ($method !== 'GET') {
        return ['_status' => 405, 'error' => 'The needle reads — GET only.'];
    }
    bienenstich_gate();
    bienenstich_cors();
    $needle = is_string($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '';
    if ($needle === '') {
        return ['_status' => 400, 'error' => 'The search needs a needle — q cannot be empty.'];
    }
    $hits = [];
    foreach (bienenstich_published() as $page) {
        $raw = \Store\read($page['type'], $page['slug']);
        if ($raw === null) {
            continue;
        }
        $text = bienenstich_bodyText($raw);
        if (stripos($text, $needle) === false) {
            continue;
        }
        $page['title'] = \Serve\titleOf($page['type'], $page['slug'], $page['slug']);
        $page['excerpt'] = bienenstich_excerpt($text, $needle);
        $hits[] = $page;
    }
    return [
        'plugin' => 'bienenstich',
        'q' => $needle,
        'count' => count($hits),
        'hits' => $hits,
    ];
}

/** Public route: GET /bridge/plugin/bienenstich/status — the heartbeat: the
 *  site face, the page count, the ware's version. Cheap enough for a monitor
 *  to knock on. */
function plugin_bienenstich_route_status(string $method, array $body): array
{
    if ($method !== 'GET') {
        return ['_status' => 405, 'error' => 'The heartbeat beats on GET.'];
    }
    bienenstich_gate();
    bienenstich_cors();
    $site = \Site\load();
    return [
        'ok' => true,
        'plugin' => 'bienenstich',
        'version' => bienenstich_version(),
        'site' => [
            'name' => (string)($site['name'] ?? ''),
            'claim' => (string)($site['claim'] ?? ''),
            'home' => \Serve\homeSlug($site),
        ],
        'pages' => count(bienenstich_published()),
        'time' => date('c'),
    ];
}
