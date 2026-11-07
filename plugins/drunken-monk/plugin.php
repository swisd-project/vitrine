<?php

/**
 * 1. Relative path: plugins/drunken-monk/plugin.php
 * 2. Description: The first real Schmarrn plugin. DRUNKEN MONK = machine-generated
 *                 payloads that live plainly in the md, wrapped in a monk banner
 *                 inside a ```drunken-monk fenced code block. mdms renders the
 *                 fence untouched (<pre><code class="language-drunken-monk">)
 *                 with the banner kept visible — an honest "not to be touched,
 *                 machine generated" notice in the editor. Installed AND enabled,
 *                 the serve-time p6 contentFilter (drunken_monk_page) decodes the
 *                 fence's final non-empty line (base64url JSON envelope) and
 *                 applies it by kind (style | script | html), swapping the fence
 *                 for a provenance comment — but ONLY SEALED ENVELOPES (the
 *                 integrity stamp, 2026-11-07, user law, closes B10): minting
 *                 seals the envelope with the host's HMAC (bridge/config/
 *                 monk.local.php, minted on first use); a hand-crafted fence
 *                 carries no valid seal and degrades to the untouched notice —
 *                 an editor cannot forge what only the admin route mints. Any
 *                 decode failure degrades to the untouched notice + a log
 *                 line — never a dead page. The minting
 *                 side: admin routes encode/decode (auth+CSRF enforced by the
 *                 core dispatch lid; handlers never re-check).
 * 3. Expects: Core-loaded only (never hit directly — the whitelist forbids it).
 * 4. Provides: plugin_drunken_monk_page(), plugin_drunken_monk_route_{encode,decode}().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** The envelope's injection wall: anything not on this list is refused at decode. */
function drunken_monk_kinds(): array
{
    return ['style', 'script', 'html'];
}

// ——— the integrity stamp (2026-11-07, user law — closes B10) ———
// A hand-crafted fence is just text in the md: the editor leash cannot see
// a base64 code block, so an editor could forge what only the admin route
// mints. The seal ends forgery: only envelopes stamped with THIS host's
// HMAC apply at serve time; anything else degrades to the visible notice.

/** The monk's seal secret — host-local, git-ignored, one file, fail-closed. */
function drunken_monk_secretFile(): string
{
    // bridge/plugins/drunken-monk → bridge/config/monk.local.php (host layout)
    return dirname(__DIR__, 2) . '/config/monk.local.php';
}

/** The seal secret as it stands on disk — null when absent (nothing verifies). */
function drunken_monk_secret(): ?string
{
    $file = drunken_monk_secretFile();
    if (!is_file($file)) {
        return null;
    }
    try {
        $cfg = (array)require $file;
    } catch (\Throwable) {
        return null;
    }
    $secret = $cfg['secret'] ?? null;
    return is_string($secret) && $secret !== '' ? $secret : null;
}

/** The seal secret, minted on first need — null when the config home refuses. */
function drunken_monk_ensureSecret(): ?string
{
    $existing = drunken_monk_secret();
    if ($existing !== null) {
        return $existing;
    }
    $file = drunken_monk_secretFile();
    if (!is_dir(dirname($file))) {
        return null; // no config home — fail-closed, no seal can exist
    }
    $secret = bin2hex(random_bytes(32));
    if (@file_put_contents($file, "<?php\n\nreturn ['secret' => '" . $secret . "'];\n") === false) {
        return null;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    return $secret;
}

/** The seal: HMAC over the EXACT bytes the filter would apply — kind, code, name. */
function drunken_monk_stamp(string $secret, string $kind, string $code, string $name): string
{
    $carrier = json_encode(['kind' => $kind, 'code' => $code, 'name' => $name],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash_hmac('sha256', (string)$carrier, $secret);
}

/** The banner the monk mints — the visible, human-facing notice. One voice, one face. */
function drunken_monk_banner(array $envelope): string
{
    $kind = $envelope['kind'];
    $name = (string)($envelope['name'] ?? 'payload');
    return <<<BANNER
                   ~~
        (o)        DRUNKEN MONK — DO NOT TOUCH
       <( )>       machine generated payload
        | |        kind: {$kind}   name: {$name}
            
BANNER;
}

/** Mint the full md fence text from an envelope (what the author pastes into the md).
 *  The envelope rides SEALED: mint normalizes the name, seals with the host's
 *  HMAC, and only sealed envelopes apply at serve time. */
function drunken_monk_mint(array $envelope): string
{
    $kind = (string)($envelope['kind'] ?? '');
    $code = (string)($envelope['code'] ?? '');
    $name = is_string($envelope['name'] ?? null) && $envelope['name'] !== '' ? $envelope['name'] : 'payload';
    $secret = drunken_monk_ensureSecret();
    if ($secret === null) {
        // unsealable config home — the honest refusal; the route pre-checks this
        @error_log('[schmarrn-plugins] drunken-monk: no seal could be minted — bridge/config must be writable.');
        return '';
    }
    $sealed = ['kind' => $kind, 'code' => $code, 'name' => $name,
        'stamp' => drunken_monk_stamp($secret, $kind, $code, $name)];
    $payload = rtrim(strtr(base64_encode(json_encode(
        $sealed,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )), '+/', '-_'), '=');
    return "```drunken-monk\n" . drunken_monk_banner($sealed) . "\n" . $payload . "\n```";
}

/**
 * The serve-time contentFilter (p6 slice 2): decode every ```drunken-monk block
 * and apply its envelope. Fence survives until decoded correctly — the notice
 * law. On success the block becomes provenance comment + the applied behaviour.
 *
 * @param array{site: array, type: string, slug: string, title: string} $ctx
 */
function plugin_drunken_monk_page(array $ctx, string $html): string
{
    $marker = '/<pre><code class="[^"]*language-drunken-monk[^"]*">(.*?)<\/code><\/pre>/s';
    return (string)preg_replace_callback($marker, function (array $m): string {
        $text = html_entity_decode($m[1], ENT_QUOTES);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        if ($lines === []) {
            return $m[0]; // empty monk — nothing to decode, notice stays
        }
        $payload = end($lines);
        if (preg_match('/^[A-Za-z0-9+\/_-]+$/', $payload) !== 1) {
            return $m[0]; // final line is not a payload — the monk keeps talking
        }
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($raw === false) {
            return $m[0];
        }
        $envelope = json_decode((string)$raw, true);
        if (!is_array($envelope) || !is_string($envelope['code'] ?? null)
            || !in_array($envelope['kind'] ?? '', drunken_monk_kinds(), true)) {
            return $m[0];
        }
        $kind = $envelope['kind'];
        $label = is_string($envelope['name'] ?? null) && $envelope['name'] !== '' ? $envelope['name'] : 'payload';
        // THE SEAL GATE (B10 closed, 2026-11-07, user law): only envelopes this
        // host's admin mint sealed with its HMAC apply. A hand-crafted fence —
        // an editor forging script — has no valid stamp and degrades to the
        // notice, byte-for-byte like any decode failure. Fail-closed: no
        // secret on disk, nothing applies.
        $stamp = (string)($envelope['stamp'] ?? '');
        $secret = drunken_monk_secret();
        if ($secret === null || $stamp === ''
            || hash_equals(drunken_monk_stamp($secret, (string)$kind, (string)$envelope['code'], (string)$label), $stamp) === false) {
            @error_log('[schmarrn-plugins] drunken-monk: unstamped envelope refused (integrity law) — the notice stands.');
            return $m[0];
        }
        $attr = htmlspecialchars($label, ENT_QUOTES);
        $tag = match ($kind) {
            'style' => '<style data-drunken-monk="' . $attr . '">' . $envelope['code'] . '</style>',
            'script' => '<script data-drunken-monk="' . $attr . '">' . $envelope['code'] . '</script>',
            'html' => '<div data-drunken-monk="' . $attr . '">' . $envelope['code'] . '</div>',
        };
        $stamp = str_replace('--', '-', "drunken-monk: {$label} ({$kind}) applied by the plugin");
        // comment inner label needs the escape: a label carrying '-->' would open
        // the comment — escaping it into &gt; keeps the comment closed and readable
        return $tag . "\n<!-- " . htmlspecialchars($stamp, ENT_QUOTES) . " -->";
    }, $html);
}

/** POST body → {envelope pieces} → the full md fence to paste. Admin+CSRF by the core lid. */
function plugin_drunken_monk_route_encode(string $method, array $body): array
{
    if ($method !== 'POST') {
        return ['_status' => 405, 'error' => 'The monk mints payloads by POST only, one knead.'];
    }
    $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
    $code = is_string($body['code'] ?? null) ? $body['code'] : '';
    $name = is_string($body['name'] ?? null) && $body['name'] !== '' ? $body['name'] : 'payload';
    if (!in_array($kind, drunken_monk_kinds(), true)) {
        return ['_status' => 400, 'error' => 'Unknown kind — the wall allows style | script | html only.'];
    }
    if (trim($code) === '') {
        return ['_status' => 400, 'error' => 'Empty code — the monk only carries substance.'];
    }
    $envelope = ['kind' => $kind, 'code' => $code, 'name' => $name];
    if (drunken_monk_ensureSecret() === null) {
        return ['_status' => 500, 'error' => 'The monk\'s seal could not be minted — bridge/config must be writable once.'];
    }
    return ['kind' => $kind, 'name' => $name, 'fence' => drunken_monk_mint($envelope)];
}

/** POST body {fence or payload} → the envelope for inspection. Admin+CSRF by the core lid. */
function plugin_drunken_monk_route_decode(string $method, array $body): array
{
    if ($method !== 'POST') {
        return ['_status' => 405, 'error' => 'The monk opens up by POST only.'];
    }
    $input = is_string($body['payload'] ?? null) ? $body['payload'] : '';
    if (preg_match('/^\s*```/', $input) === 1) {
        // whole fence pasted — the payload is the last non-empty line
        $lines = array_values(array_filter(array_map('trim', explode("\n", $input)), fn($l) => $l !== ''));
        array_pop($lines); // closing fence
        $input = $lines === [] ? '' : end($lines);
    }
    if (preg_match('/^[A-Za-z0-9+\/_-]+$/', $input) !== 1) {
        return ['_status' => 400, 'error' => 'That is no monk payload — base64url or the full fence, please.'];
    }
    $raw = base64_decode(strtr($input, '-_', '+/'), true);
    $envelope = $raw === false ? null : json_decode((string)$raw, true);
    if (!is_array($envelope)) {
        return ['_status' => 400, 'error' => 'The payload does not unravel — not a drunken monk envelope.'];
    }
    // the seal verdict: would this envelope apply at serve time on THIS host?
    $kind = (string)($envelope['kind'] ?? '');
    $label = is_string($envelope['name'] ?? null) && $envelope['name'] !== '' ? $envelope['name'] : 'payload';
    $stamp = (string)($envelope['stamp'] ?? '');
    $secret = drunken_monk_secret();
    $sealed = $secret !== null && $stamp !== ''
        && hash_equals(drunken_monk_stamp($secret, $kind, (string)($envelope['code'] ?? ''), $label), $stamp);
    return ['envelope' => $envelope, 'sealed' => $sealed];
}
