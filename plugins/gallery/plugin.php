<?php

/**
 * 1. Relative path: plugins/gallery/plugin.php
 * 2. Description: Gallery plugin — scaffold. The smallest valid proof of the
 *                 p6 contract: one manifest-declared public route (status).
 *                 Function naming is the contract:
 *                 plugin_<prefix>_route_<route>.
 * 3. Expects: Core-loaded only (never hit this file directly — the whitelist forbids it).
 * 4. Provides: plugin_gallery_route_status().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** Public route: GET /bridge/plugin/gallery/status — the core handled auth already. */
function plugin_gallery_route_status(string $method, array $body): array
{
    return [
        'ok' => true,
        'plugin' => 'gallery',
        'method' => $method,
        'time' => date('c'),
    ];
}
