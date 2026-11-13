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

/** The ware's KEY STORE — the house VAULT (the core's own, bridge/vault.php):
 *  the master and the access-key rows rest SEALED under one name, encrypted
 *  at rest (libsodium), the vault's key in a 0600 keyfile. Two laws make this
 *  the only sane home: the plugin folder is RENAMED AWAY on every upgrade
 *  (the install's swap), so a store inside it dies with the next shelf ride —
 *  and a secret in a plaintext file is a secret waiting for a backup. Shape:
 *  {master, epoch, keys:[rows]}. The master is the admin's own root secret:
 *  never rendered, never accepted as a request key, only ever COMPARED (as
 *  the HMAC input that derives the access keys). */
function bienenstich_vault_name(): string
{
    return 'bienenstich.keys';
}

/** The legacy plaintext store (0.2.6 and older) — read once, then wiped. */
function bienenstich_legacy_file(): string
{
    return __DIR__ . '/keys.local.json';
}

/** Does the old plaintext store still stand? */
function bienenstich_legacy_exists(): bool
{
    return is_file(bienenstich_legacy_file());
}

/** The old plaintext store, read once on the migration road. */
function bienenstich_legacy_read(): ?array
{
    try {
        $raw = json_decode((string)file_get_contents(bienenstich_legacy_file()), true);
    } catch (\Throwable) {
        return null;
    }
    return is_array($raw) ? $raw : null;
}

/** The stored keys, lid-cleaned. */
function bienenstich_store(): array
{
    $raw = function_exists('\Vault\open') ? \Vault\open(bienenstich_vault_name()) : null;
    $all = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $all = is_array($decoded) ? $decoded : [];
    } elseif (bienenstich_legacy_exists()) {
        // One-way migration: the old plaintext store is sealed into the vault
        // and then GONE — a secret must not lie around after the fix.
        $legacy = bienenstich_legacy_read();
        if ($legacy !== null) {
            \Vault\seal(bienenstich_vault_name(), (string)json_encode($legacy, JSON_UNESCAPED_SLASHES));
            @unlink(bienenstich_legacy_file());
            $all = $legacy;
        }
    }
    $keys = [];
    foreach ((array)($all['keys'] ?? []) as $row) {
        if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['nonce'] ?? null)) {
            $keys[] = [
                'label' => mb_substr((string)$row['label'], 0, 60),
                'nonce' => (string)$row['nonce'],
                'epoch' => (int)($row['epoch'] ?? 0),
                'createdAt' => (int)($row['createdAt'] ?? 0),
                'revoked' => ($row['revoked'] ?? false) === true,
            ];
        }
    }
    return [
        'master' => is_string($all['master'] ?? null) ? (string)$all['master'] : '',
        'epoch' => (int)($all['epoch'] ?? 0),
        'keys' => $keys,
    ];
}



/** Write the key store — sealed into the house vault, never plain on disk. */
function bienenstich_store_save(array $store): void
{
    if (!function_exists('\Vault\seal')) {
        // An older core (pre-vault) carries no vault: refuse honestly instead
        // of fataling the route — a ware must never kill a host's day.
        \Router\refuse(500, 'This core carries no vault — update Schmarrn before minting keys.');
    }
    $json = json_encode($store, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        \Router\refuse(500, 'The key store would not bake — its shape is broken.');
    }
    \Vault\seal(bienenstich_vault_name(), $json);
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

/** The derivation: an access key = bs_<version>_<32 alphanumerics>, HMAC'd
 *  from the master and the row's nonce. The master never appears in it. */
function bienenstich_derive(string $master, string $label, string $nonce): string
{
    $alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len = mb_strlen($alnum);
    $hash = hash_hmac('sha512', $master, $label . '|' . $nonce, true);
    $out = '';
    $i = 0;
    // Rejection sampling: bytes past 62*4 are dropped, so every character is
    // equally likely — a biased key is a weaker key.
    while (mb_strlen($out) < 32) {
        if ($i >= strlen($hash)) {
            $hash = hash_hmac('sha512', $master, $label . '|' . $nonce . '|' . $i, true);
            $i = 0;
        }
        $byte = ord($hash[$i]);
        $i++;
        if ($byte >= $len * 4) {
            continue;
        }
        $out .= $alnum[$byte % $len];
    }
    return 'bs_' . bienenstich_version() . '_' . $out;
}

/** The door: every route passes here first. The key is checked against the
 *  ACCESS KEYS the admin issued from their own master secret — the master
 *  itself never opens this door. Without a valid access key the answer is
 *  one plain line and nothing else. Exits before the core's JSON writer: the
 *  bots get text, not a contract. */
function bienenstich_gate(): void
{
    $given = is_string($_GET['key'] ?? null) ? (string)$_GET['key'] : '';
    $store = bienenstich_store();
    $ok = false;
    if ($given !== '' && $store['master'] !== '') {
        foreach ($store['keys'] as $row) {
            if ($row['revoked'] || $row['epoch'] !== $store['epoch']) {
                continue; // revoked, or minted under a master that has been renewed since
            }
            if (hash_equals(bienenstich_derive($store['master'], $row['label'], $row['nonce']), $given)) {
                $ok = true;
                break;
            }
        }
    }
    if (!$ok) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Bakery closed.';
        exit;
    }
}

/** The panel descriptor — the ware's own words for its own state. */
function bienenstich_panel(): array
{
    $store = bienenstich_store();
    // An older core (pre-vault) has no vault at all: say so plainly instead
    // of showing an empty shelf that reads like "no master set".
    $hasVault = function_exists('\Vault\open');
    // The vault holds the envelope but will not open it: the vault key is
    // gone or the envelope is damaged. The house says so out loud — a
    // silently dark shelf would look like "no master set".
    $sealed = $hasVault && in_array(bienenstich_vault_name(), \Vault\holds(), true);
    $dark = $sealed && $store['master'] === '' && $store['keys'] === [];
    $rows = [];
    foreach ($store['keys'] as $row) {
        $state = $row['revoked'] ? 'revoked' : ($row['epoch'] === $store['epoch'] ? 'active' : 'dead');
        $rows[] = [
            'id' => $row['label'],
            'cells' => [
                'label' => $row['label'],
                'state' => $state,
                'created' => $row['createdAt'] > 0
                    ? gmdate('Y-m-d H:i', $row['createdAt']) . ' UTC'
                    : '—',
            ],
            'actions' => [
                ['id' => 'renew', 'label' => 'renew'],
                $row['revoked']
                    ? ['id' => 'restore', 'label' => 'restore']
                    : ['id' => 'revoke', 'label' => 'revoke'],
                ['id' => 'delete', 'label' => 'delete'],
            ],
        ];
    }
    return [
        'title' => 'Access Keys',
        'note' => 'Your own master secret is never shown and never works as a ?key= — only access keys do. '
            . 'Renewing the master invalidates every access key at once. The store rests sealed in the house vault.'
            . ($dark ? ' WARNING: the sealed store will not open — the vault key is missing or the envelope is damaged. Set a master again.' : ''),
        'status' => [
            'label' => 'Master key',
            'value' => $hasVault
                ? ($store['master'] !== '' ? 'set' : 'not set')
                : 'core has no vault',
        ],
        'actions' => [
            ['id' => 'generate', 'label' => 'Generate'],
            [
                'id' => 'input',
                'label' => 'input',
                'modal' => [
                    'label' => 'Master key',
                    'type' => 'password',
                    'placeholder' => '12 characters or more',
                ],
            ],
            ['id' => 'issue', 'label' => '+ Access key', 'modal' => [
                'label' => 'Label',
                'type' => 'text',
                'placeholder' => 'e.g. App A, Staging',
            ]],
        ],
        'list' => [
            'label' => 'Access Keys',
            'columns' => [
                ['key' => 'label', 'label' => 'Label'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'created', 'label' => 'Created'],
            ],
            'rows' => $rows,
        ],
    ];
}

/** The ware's PANEL (admin route, the plugin's own admin surface): the
 *  descriptor the dashboard renders — status, actions, the access-key list —
 *  plus the actions themselves. The core knows nothing of keys; it only
 *  carries what this ware says. */
function plugin_bienenstich_route_keys(string $method, array $body): array
{
    if ($method === 'POST') {
        $action = is_string($body['action'] ?? null) ? (string)$body['action'] : '';
        $id = is_string($body['id'] ?? null) ? trim((string)$body['id']) : '';
        $value = is_string($body['value'] ?? null) ? (string)$body['value'] : '';
        $show = null;
        $store = bienenstich_store();
        if ($action === 'master' || $action === 'input') {
            // A new master invalidates EVERY access key: the derivation
            // changes under them. The master is never echoed back.
            $master = trim($value);
            if (mb_strlen($master) < 12) {
                return ['_status' => 400, 'error' => 'That master key is too short — 12 characters or more.'];
            }
            $store['master'] = $master;
            $store['epoch']++;
            bienenstich_store_save($store);
        } elseif ($action === 'generate') {
            $alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $master = '';
            for ($i = 0; $i < 40; $i++) {
                $master .= $alnum[random_int(0, mb_strlen($alnum) - 1)];
            }
            $store['master'] = $master;
            $store['epoch']++;
            bienenstich_store_save($store);
        } elseif ($action === 'issue') {
            $label = trim($value);
            if ($label === '' || mb_strlen($label) > 60) {
                return ['_status' => 400, 'error' => 'That access key needs a label — one line, no secrets.'];
            }
            if ($store['master'] === '') {
                return ['_status' => 409, 'error' => 'No master key yet — set one before access keys.'];
            }
            foreach ($store['keys'] as $row) {
                if (strcasecmp($row['label'], $label) === 0) {
                    return ['_status' => 409, 'error' => "That label already carries a key — renew it instead: {$label}"];
                }
            }
            $nonce = bin2hex(random_bytes(8));
            $store['keys'][] = [
                'label' => $label,
                'nonce' => $nonce,
                'epoch' => $store['epoch'],
                'createdAt' => time(),
                'revoked' => false,
            ];
            bienenstich_store_save($store);
            $show = [
                'label' => "New access key for “{$label}” — copy it now, it is never shown again.",
                'value' => bienenstich_derive($store['master'], $label, $nonce),
            ];
        } elseif ($action === 'renew' || $action === 'restore') {
            if ($store['master'] === '') {
                return ['_status' => 409, 'error' => 'No master key yet — set one before access keys.'];
            }
            $found = false;
            foreach ($store['keys'] as $i => $row) {
                if (strcasecmp($row['label'], $id) === 0) {
                    $nonce = bin2hex(random_bytes(8));
                    $store['keys'][$i]['nonce'] = $nonce;
                    $store['keys'][$i]['epoch'] = $store['epoch'];
                    $store['keys'][$i]['createdAt'] = time();
                    $store['keys'][$i]['revoked'] = false;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['_status' => 404, 'error' => 'No access key wears that label.'];
            }
            bienenstich_store_save($store);
            $show = [
                'label' => "Renewed access key for “{$id}” — copy it now, it is never shown again.",
                'value' => bienenstich_derive($store['master'], $id, $nonce),
            ];
        } elseif ($action === 'revoke' || $action === 'delete') {
            $kept = [];
            $found = false;
            foreach ($store['keys'] as $row) {
                if (strcasecmp($row['label'], $id) === 0) {
                    $found = true;
                    if ($action === 'revoke') {
                        $row['revoked'] = true; // the label stays, the key stops working
                        $kept[] = $row;
                    }
                    continue; // delete: the label goes with it
                }
                $kept[] = $row;
            }
            if (!$found) {
                return ['_status' => 404, 'error' => 'No access key wears that label.'];
            }
            $store['keys'] = $kept;
            bienenstich_store_save($store);
        } else {
            return ['_status' => 400, 'error' => 'That move is not on the menu.'];
        }
        $panel = bienenstich_panel();
        if ($show !== null) {
            $panel['show'] = $show;
        }
        return $panel;
    }
    if ($method !== 'GET') {
        return ['_status' => 405, 'error' => 'The panel answers GET and POST only.'];
    }
    return bienenstich_panel();
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
