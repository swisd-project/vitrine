<?php

/**
 * 1. Relative path: plugins/bienenstich/battery.php
 * 2. Description: The Bienenstich ware's OWN battery — its boundary: the key
 *                 gate, the draft blindness, the read-only law, the JSON hat,
 *                 the CORS door. It rides the CORE's ware-battery hook
 *                 (`php tests/ware-battery.php plugins/bienenstich` from the
 *                 Schmarrn checkout, 2026-11-13): the core builds the rig,
 *                 boots the server and hands over $base, $cookie/$csrf (a
 *                 live admin session), wreq() and jdec(). The cases live
 *                 HERE, in the ware's repo — the core understands none of
 *                 the ware's words.
 * 3. Expects: Included by the core's tests/ware-battery.php (the contract
 *                 in its header). The key is MINTED here through the ware's
 *                 own panel flow (master → issue → shown once) — no
 *                 settings shortcut, the real lifecycle.
 * 4. Provides: PASS/FAIL per case; the runner owns the teardown + verdict.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

$KEY = '';

/** One request through the ware's own door law: ?key= appended unless the
 *  caller passes '' (the shut-door cases do). Rides the runner's wreq(). */
function req(string $method, string $path, ?string $body = null, ?string $cookie = null, ?string $key = null): array
{
    global $KEY;
    $key = $key === null ? $KEY : $key;
    $headers = [];
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($key !== '') {
        $sep = str_contains($path, '?') ? '&' : '?';
        $path .= $sep . 'key=' . rawurlencode($key);
    }
    return wreq($method, $path, $body, $headers);
}

/** The plugin's own routes, as the core mounts them. */
function route(string $name, string $query = ''): string
{
    return '/bridge/plugin/bienenstich/' . $name . $query;
}

// ——— A. the key gate: no key, no contract ———
[$s, $h, $b] = req('GET', route('status'), null, null, '');
Harness::check('A1 without a key the door is shut (403)', $s === 403, "got {$s}");
Harness::check('A2 the shut door says one plain line and nothing else',
    trim($b) === 'Bakery closed.', substr($b, 0, 60));
Harness::check('A3 the shut door speaks text, not JSON',
    !str_contains(strtolower($h['content-type'] ?? ''), 'application/json'), $h['content-type'] ?? 'none');

[$s, , $b] = req('GET', route('status'), null, null, 'wrong-key');
Harness::check('A4 a wrong key is shut too', $s === 403, "got {$s}");

// ——— B0. the key's real lifecycle: the admin mints through the ware's own ———
// ——— panel flow (master set → access key issued, shown exactly once). The ———
// ——— A cases ran BEFORE any key existed — the safe default proven. ———
if ($cookie === '' || $csrf === '') {
    Harness::check('B0 the admin session is available for the mint', false, 'no session');
    return; // bail — the runner tears down and verdicts
}

$mint = function (string $body) use ($base, $cookie, $csrf): array {
    $opts = ['http' => [
        'method' => 'POST',
        'ignore_errors' => true,
        'timeout' => 20,
        'header' => ["Cookie: {$cookie}", "X-CSRF-Token: {$csrf}", 'Content-Type: application/json'],
        'content' => $body,
    ]];
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($base . '/bridge/plugin/bienenstich/keys', false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int)$m[1];
        }
    }
    return [$status, (string)$raw];
};

[$s, $b] = $mint((string)json_encode(['action' => 'master', 'value' => 'rig-master-secret-2026']));
Harness::check('B0.1 the master secret is set (the ware answers its panel)', $s === 200, "got {$s}: " . substr($b, 0, 80));
[$s, $b] = $mint((string)json_encode(['action' => 'issue', 'value' => 'battery']));
$panel = jdec($b);
$KEY = is_array($panel) && is_array($panel['show'] ?? null) && is_string($panel['show']['value'] ?? null)
    ? $panel['show']['value'] : '';
Harness::check('B0.2 the access key is issued and shown exactly once', $s === 200 && str_starts_with($KEY, 'bs_'), "got {$s}: " . substr($b, 0, 80));

// ——— B. with the key, the ware answers ———
[$s, , $b] = req('GET', route('status'));
$j = jdec($b);
Harness::check('B1 the right key opens the door (200)', $s === 200, "got {$s}");
Harness::check('B2 the status is JSON', $j !== null, substr($b, 0, 80));

[$s, , $b] = req('GET', route('content'));
$j = jdec($b);
$slugs = array_column((array)($j['pages'] ?? []), 'slug');
Harness::check('B3 the content answers every published page', $s === 200 && $j !== null, "got {$s}");
Harness::check('B4 the published page is in the jar', in_array('hello', $slugs, true), implode(',', $slugs));

// ——— C. drafts never ride along, not even for an admin ———
Harness::check('C1 the draft is not in the jar', !in_array('secret', $slugs, true), implode(',', $slugs));
Harness::check('C2 the draft title does not leak', !str_contains($b, 'Secret Draft'), 'draft title leaked');
Harness::check('C3 the draft body does not leak', !str_contains($b, 'Honeyed secret'), 'draft body leaked');

[$s, , $b] = req('GET', route('search', '?q=Honeyed'));
$j = jdec($b);
Harness::check('C4 the needle cannot find the draft',
    $s === 200 && ($j['count'] ?? -1) === 0, 'found it');

// the admin session: the core lists drafts, the plugin must not
[$ls, $lh, $lb] = req('POST', '/bridge/login', (string)json_encode([
    'user' => 'admin', 'password' => 'rig-test-pw',
]), null, '');
$login = jdec($lb);
$cookie = '';
foreach ($lh as $name => $value) {
    if ($name === 'set-cookie') {
        $cookie = explode(';', $value)[0];
    }
}
if (!is_array($login) || ($login['ok'] ?? false) !== true || $cookie === '') {
    Harness::check('C5 the admin session is available for the draft comparison', false, 'no login');
} else {
    [$s, , $b] = req('GET', route('content'), null, $cookie);
    $j = jdec($b);
    $slugs = array_column((array)($j['pages'] ?? []), 'slug');
    Harness::check('C5 the draft stays hidden from an admin too',
        $s === 200 && !in_array('secret', $slugs, true), implode(',', $slugs));
    [$s, , $b] = req('GET', route('search', '?q=Honeyed'), null, $cookie);
    $j = jdec($b);
    Harness::check('C6 the needle stays blind for an admin too',
        $s === 200 && ($j['count'] ?? -1) === 0, 'found it');
    // the core DOES list drafts for editors — the difference is the plugin's law
    [$s, , $b] = req('GET', '/bridge/pages', null, $cookie);
    $j = jdec($b);
    $coreSlugs = array_column((array)($j['pages'] ?? []), 'slug');
    Harness::check('C7 the core lists the draft for editors (the plugin is stricter)',
        in_array('secret', $coreSlugs, true), 'the core did not list it');
}

// ——— D. the files themselves are not served ———
[$s] = req('GET', '/bridge/plugins/bienenstich/plugin.php', null, null, '');
Harness::check('D1 the code file is not served', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugins/bienenstich/plugin.json', null, null, '');
Harness::check('D2 the manifest is not served', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugin/bienenstich', null, null, '');
Harness::check('D3 the plugin without a route is a 404', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugin/bienenstich/nope', null, null, '');
Harness::check('D4 an undeclared route is a 404', $s === 404, "got {$s}");

// ——— E. the read-only law ———
[$s, , $before] = req('GET', route('content'));
[$s, , $b] = req('GET', route('content', '?type=pages'));
$j = jdec($b);
Harness::check('E1 a type filter narrows the jar', $s === 200 && is_array($j), "got {$s}");
[$s, , $b] = req('GET', route('content', '?type=../../etc/passwd'));
$j = jdec($b);
Harness::check('E2 a path climb yields no pages', $s === 200 && is_array($j) && $j['pages'] === [], "got {$s}");
[$s, , $b] = req('GET', route('search', '?q=*'));
$j = jdec($b);
Harness::check('E3 the needle is literal, not a regex',
    $s === 200 && is_array($j) && ($j['count'] ?? -1) === 0, 'wildcard matched');

// ——— F. the answer is always JSON, always with the right hat ———
foreach (['content', 'status'] as $r) {
    [$s, $h, $b] = req('GET', route($r));
    Harness::check("F1 {$r} answers application/json",
        str_contains(strtolower($h['content-type'] ?? ''), 'application/json'), $h['content-type'] ?? 'none');
    Harness::check("F2 {$r} answers valid JSON", jdec($b) !== null, substr($b, 0, 80));
}
[$s, $h] = req('GET', route('content'));
Harness::check('F3 the CORS door is open for plain GETs',
    ($h['access-control-allow-origin'] ?? '') === '*', $h['access-control-allow-origin'] ?? 'none');

// ——— G. the site still stands with the ware switched on ———
[$s, , $b] = req('GET', '/');
Harness::check('G1 the public face still serves', $s === 200 && str_contains($b, '<html'), "got {$s}");
[$s, , $b] = req('GET', '/bridge/pages');
Harness::check('G2 the core page index still answers', $s === 200 && jdec($b) !== null, "got {$s}");
[$s, , $b] = req('GET', route('status'), null, 'PHPSESSID=not-a-real-session');
Harness::check('G3 a bogus session does not confuse the public door', $s === 200, "got {$s}");
