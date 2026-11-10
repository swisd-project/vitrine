<?php

/**
 * 1. Relative path: plugins/hello/plugin.php
 * 2. Description: Demo plugin — the smallest possible proof of the p6 contract.
 *                 One manifest-declared placeholder (hello_badge) and one
 *                 manifest-declared public route (status). Function naming is the
 *                 contract: plugin_<prefix>_placeholders / plugin_<prefix>_route_<route>.
 *                 The same ware ships INSIDE Schmarrn as the preinstalled default
 *                 plugin (bridge/plugins/hello/) — this folder is the shelf copy:
 *                 installable again after a deletion, and the copy-me blueprint.
 * 3. Expects: Core-loaded only (never hit this file directly — the whitelist forbids it).
 * 4. Provides: plugin_hello_placeholders(), plugin_hello_route_status().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/**
 * @param array{site: array, type: string, slug: string, title: string} $ctx
 * @return array<string, string> token => HTML (only manifest-declared keys are honored)
 */
function plugin_hello_placeholders(array $ctx): array
{
    $where = $ctx['slug'] !== '' ? (string)$ctx['slug'] : 'the front door';
    return [
        'hello_badge' => '<p class="hello-badge">👋 hello from a plugin — serving '
            . htmlspecialchars($where, ENT_QUOTES) . '</p>',
    ];
}

/** Public route: GET /bridge/plugin/hello/status — the core handled auth already. */
function plugin_hello_route_status(string $method, array $body): array
{
    return [
        'ok' => true,
        'plugin' => 'hello',
        'method' => $method,
        'time' => date('c'),
    ];
}

/** Session route: any method — the core enforced auth + CSRF (non-GET) before this runs. */
function plugin_hello_route_echo(string $method, array $body): array
{
    return ['ok' => true, 'echo' => $body];
}
