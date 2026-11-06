<?php

/**
 * 1. Relative path: bridge/plugins/drunken-monk/plugin.php
 * 2. Description: The first real Schmarrn plugin. DRUNKEN MONK = machine-generated
 *                 payloads that live plainly in the md, wrapped in a monk banner
 *                 inside a ```drunken-monk fenced code block. mdms renders the
 *                 fence untouched (<pre><code class="language-drunken-monk">)
 *                 with the banner kept visible — an honest "not to be touched,
 *                 machine generated" notice in the editor. Installed AND enabled,
 *                 the serve-time p6 contentFilter (drunken_monk_page) decodes the
 *                 fence's final non-empty line (base64url JSON envelope) and
 *                 applies it by kind (style | script | html), swapping the fence
 *                 for a provenance comment. Any decode failure degrades to the
 *                 untouched notice + a log line — never a dead page. The minting
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

/** Mint the full md fence text from an envelope (what the author pastes into the md). */
function drunken_monk_mint(array $envelope): string
{
    $payload = rtrim(strtr(base64_encode(json_encode(
        $envelope,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )), '+/', '-_'), '=');
    return "```drunken-monk\n" . drunken_monk_banner($envelope) . "\n" . $payload . "\n```";
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
    return ['envelope' => $envelope];
}
