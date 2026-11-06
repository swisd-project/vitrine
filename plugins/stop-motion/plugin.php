<?php

/**
 * 1. Relative path: plugins/stop-motion/plugin.php
 * 2. Description: STOP MOTION — a numbered image series (0..n) plays as a
 *                 stop-motion animation. The author drops a ```stop-motion
 *                 fence into the md; it carries a single-line JSON config
 *                 ({files:[...]} or {prefix,count,ext,start} — counting from
 *                 start, default 0 — plus fps and an optional caption). mdms
 *                 renders the fence untouched (<pre><code>) with the config
 *                 visible — an honest "machine-readable, plugin-decodes"
 *                 notice in the editor. Installed AND enabled, the serve-time
 *                 p6 contentFilter (stop_motion_page) decodes the fence and
 *                 swaps it for stacked <img> frames: frame i gets an
 *                 animation-delay of -i/fps s and a shared keyframe that is
 *                 visible for exactly one frame-slice (duplicate keyframe
 *                 offsets, later-wins — no interpolation, no seconds of fade).
 *                 Pure CSS steps-by-delay — no JS, so all three serving doors
 *                 behave, prefers-reduced-motion freezes on frame 0. Frame
 *                 files resolve page-locally against /bridge/media/<type>/<slug>.
 *                 The speed handles: fence fps (editable in the md) + admin
 *                 routes mint (config -> fence) and speed (fence + fps ->
 *                 re-timed fence), auth+CSRF enforced by the core dispatch
 *                 lid; handlers never re-check. Any decode failure degrades to
 *                 the untouched fence + a log line — never a dead page.
 * 3. Expects: Core-loaded only (never hit this file directly — the whitelist forbids it).
 * 4. Provides: stop_motion_safe_file(), stop_motion_config(), stop_motion_fence(),
 *              plugin_stop_motion_page(), plugin_stop_motion_route_{mint,speed}().
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** The shutter range: frames per second the plugin will honour. */
const STOP_MOTION_FPS_MIN = 0.5;
const STOP_MOTION_FPS_MAX = 30.0;
const STOP_MOTION_FPS_DEFAULT = 8.0;
const STOP_MOTION_FRAMES_MAX = 300;

/** One frame file name: plain path chars, no climb, sane length. */
function stop_motion_safe_file(string $file): bool
{
    return $file !== ''
        && strlen($file) <= 200
        && !str_contains($file, '..')
        && preg_match('/^[\w][\w .\/-]*[\w.-]?$/', $file) === 1
        && !str_starts_with($file, '/');
}

/** Config (array from JSON) -> cleanShutter ['files'=>string[], 'fps'=>float, 'caption'=>string, 'dir'=>string] | null. */
function stop_motion_config(array $cfg): ?array
{
    // The frames' folder relative to the page's media home (the dedicated
    // tray of the editor's stop-motion modal). Invalid -> ignore (root).
    $dirGuard = ['/^[a-z0-9][a-z0-9-]*$/', ''];
    $dir = is_string($cfg['dir'] ?? null) ? $cfg['dir'] : '';
    $dir = preg_match($dirGuard[0], $dir) === 1 ? $dir : $dirGuard[1];

    // Frame list: either an explicit files array or prefix+count (+ext, start).
    $files = [];
    if (is_array($cfg['files'] ?? null)) {
        foreach ($cfg['files'] as $file) {
            if (!is_string($file) || !stop_motion_safe_file($file)) {
                return null;
            }
            $files[] = $file;
        }
    } elseif (is_string($cfg['prefix'] ?? null) && is_numeric($cfg['count'] ?? null)) {
        $count = (int)$cfg['count'];
        $start = max(0, (int)($cfg['start'] ?? 0));
        $ext = is_string($cfg['ext'] ?? null) && preg_match('/^[\w]{1,5}$/', $cfg['ext']) === 1 ? $cfg['ext'] : 'png';
        $prefix = $cfg['prefix'];
        if ($prefix !== '' && !stop_motion_safe_file($prefix . $start . '.' . $ext)) {
            return null;
        }
        if ($count < 2 || $count > STOP_MOTION_FRAMES_MAX || $start > 10_000_000) {
            return null;
        }
        for ($i = $start; $i < $start + $count; $i++) {
            $files[] = $prefix . $i . '.' . $ext;
        }
    } else {
        return null;
    }
    if (count($files) < 2 || count($files) > STOP_MOTION_FRAMES_MAX) {
        return null; // two frames minimum — a still image is the image module's day job
    }
    $fps = is_numeric($cfg['fps'] ?? null) ? (float)$cfg['fps'] : STOP_MOTION_FPS_DEFAULT;
    if ($fps < STOP_MOTION_FPS_MIN || $fps > STOP_MOTION_FPS_MAX) {
        return null;
    }
    $caption = is_string($cfg['caption'] ?? null) ? trim($cfg['caption']) : '';
    return ['files' => $files, 'fps' => $fps, 'caption' => $caption, 'dir' => $dir];
}

/** Mint the full md fence text from a clean shutter config (what the author pastes into the md). */
function stop_motion_fence(array $cfg): string
{
    $clean = [
        'files' => $cfg['files'],
        'fps' => $cfg['fps'],
    ];
    if (($cfg['dir'] ?? '') !== '') {
        $clean['dir'] = $cfg['dir'];
    }
    if (($cfg['caption'] ?? '') !== '') {
        $clean['caption'] = $cfg['caption'];
    }
    $payload = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "```stop-motion\n{$payload}\n```";
}

/**
 * The serve-time contentFilter (p6 slice 2): decode every ```stop-motion block
 * and swap it for the animated markup. Fence survives until decoded correctly —
 * the notice law. The plugin's stage CSS is injected once, on the first
 * conversion, at the end of the body.
 *
 * @param array{site: array, type: string, slug: string, title: string} $ctx
 */
function plugin_stop_motion_page(array $ctx, string $html): string
{
    $marker = '/<pre><code class="[^"]*language-stop-motion[^"]*">(.*?)<\/code><\/pre>/s';
    $seq = 0;
    $out = (string)preg_replace_callback($marker, function (array $m) use ($ctx, &$seq): string {
        $text = html_entity_decode($m[1], ENT_QUOTES);
        // The fence may hold the config in one line, pretty-printed, with stray
        // fence marks clinging — the marks leave, the rest is the JSON config.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $lines = array_values(array_filter($lines, fn($l) => !str_starts_with($l, '```')));
        if ($lines === []) {
            return $m[0]; // empty shutter — nothing to decode, notice stays
        }
        $cfg = json_decode(implode("\n", $lines), true);
        if (!is_array($cfg) || ($clean = stop_motion_config($cfg)) === null) {
            return $m[0];
        }
        $fps = $clean['fps'];
        $base = '/bridge/media/' . rawurlencode((string)$ctx['type']) . '/' . rawurlencode((string)$ctx['slug']) . '/'
            . (($clean['dir'] ?? '') !== '' ? rawurlencode((string)$clean['dir']) . '/' : '');
        $caption = $clean['caption'];

        // Survivors first: a fence may still name frames the folder has lost
        // (deleted after the save). The dead stay out of the march, the live
        // re-rank with no gaps and no freezes — the folder IS the truth.
        $rootDir = dirname(__DIR__, 3); // bridge/plugins/stop-motion → the repo root
        $tray = $rootDir . '/src/content/' . $ctx['type'] . '/' . $ctx['slug'] . '/'
            . (($clean['dir'] ?? '') !== '' ? $clean['dir'] . '/' : '');
        $surviving = [];
        $dropped = [];
        foreach ($clean['files'] as $file) {
            if (is_file($tray . $file)) {
                $surviving[] = $file;
            } else {
                $dropped[] = $file;
            }
        }
        // A stage of nothing (or a single lonely frame) is not a stage — the
        // fence survives and says so, the notice law keeps the md honest.
        if (count($surviving) < 2) {
            return $m[0];
        }

        $n = count($surviving);
        $dur = number_format($n / $fps, 4, '.', '');
        $slice = round(100 / $n, 6);
        $alt = $caption !== '' ? $caption : 'stop-motion sequence, ' . $n . ' frames';

        $frames = [];
        foreach ($surviving as $i => $file) {
            $src = $base . rawurlencode($file);
            $delay = number_format($i / $fps, 4, '.', '');
            $first = $i === 0;
            // Frame 0 carries the stage's layout (in flow) and the alt text;
            // the rest stack absolutely on top, walking one frame-slice each.
            $frames[] = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '"'
                . ' class="stop-motion-frame"' . ($first ? ' data-stop-motion-lead' : '')
                . ' alt="' . htmlspecialchars($first ? $alt : '', ENT_QUOTES) . '"'
                . ' loading="' . ($first ? 'eager' : 'lazy') . '" decoding="async"'
                . ' style="animation: stop-motion-march-' . $seq . ' ' . $dur . 's step-end infinite;'
                . ' animation-delay: -' . $delay . 's"'
                . ($first && $caption !== '' ? ' data-stop-motion-caption="' . htmlspecialchars($caption, ENT_QUOTES) . '"' : '')
                . '>';
        }

        $seq++;
        $provenance = $dropped !== []
            ? '<!-- stop-motion: ' . count($dropped) . ' frame(s) in the fence no longer exist and were skipped: '
                . htmlspecialchars(implode(', ', array_map(fn($d) => str_replace('--', '-', $d), $dropped)), ENT_QUOTES) . ' -->'
                . "\n"
            : '';
        return $provenance
            . '<span class="stop-motion-stage" data-stop-motion="' . $n . ' frames @ ' . htmlspecialchars((string)$fps, ENT_QUOTES) . ' fps" data-stop-motion-fps="' . htmlspecialchars((string)$fps, ENT_QUOTES) . '" role="img" aria-label="' . htmlspecialchars($alt, ENT_QUOTES) . '">'
            . implode('', $frames)
            . ($caption !== '' ? '<span class="stop-motion-caption">' . htmlspecialchars($caption, ENT_QUOTES) . '</span>' : '')
            . '</span>'
            . '<style data-stop-motion="stage">'
            . '@keyframes stop-motion-march-' . ($seq - 1) . '{0%{opacity:1}'
            . $slice . '%{opacity:1}' . $slice . '%{opacity:0}100%{opacity:0}}'
            . '.stop-motion-stage{position:relative;display:block;max-width:100%;margin:1rem 0}'
            . '.stop-motion-frame{position:absolute;inset:0;width:100%;height:auto;display:block;margin:0}'
            . '.stop-motion-frame[data-stop-motion-lead]{position:static}'
            . '.stop-motion-caption{display:block;margin-top:.25rem;font-size:.85em}'
            . '@media (prefers-reduced-motion: reduce){.stop-motion-frame{animation:none!important}}'
            . '</style>';
    }, $html);
    return $out;
}

/** POST body -> {config pieces} -> the full md fence to paste. Admin+CSRF by the core lid. */
function plugin_stop_motion_route_mint(string $method, array $body): array
{
    if ($method !== 'POST') {
        return ['_status' => 405, 'error' => 'The shutter winds by POST only, one wind.'];
    }
    $cfg = [];
    foreach (['prefix', 'ext', 'caption', 'dir'] as $key) {
        if (is_string($body[$key] ?? null)) {
            $cfg[$key] = $body[$key];
        }
    }
    foreach (['count', 'start'] as $key) {
        if (is_numeric($body[$key] ?? null)) {
            $cfg[$key] = $body[$key];
        }
    }
    if (is_numeric($body['fps'] ?? null)) {
        $cfg['fps'] = $body['fps'];
    }
    if (is_array($body['files'] ?? null)) {
        $cfg['files'] = $body['files'];
    }
    $clean = stop_motion_config($cfg);
    if ($clean === null) {
        return ['_status' => 400, 'error' => 'No shutter in that config — pass files[] (2..' . STOP_MOTION_FRAMES_MAX
            . ' names) or prefix+count (2..' . STOP_MOTION_FRAMES_MAX . '), fps ' . STOP_MOTION_FPS_MIN
            . '..' . STOP_MOTION_FPS_MAX . '. Each name: plain path chars, no climbing.'];
    }
    return ['fence' => stop_motion_fence($clean), 'frames' => count($clean['files']), 'fps' => $clean['fps']];
}

/** POST body {fence or payload, fps} -> the re-timed fence to paste back. Admin+CSRF by the core lid. */
function plugin_stop_motion_route_speed(string $method, array $body): array
{
    if ($method !== 'POST') {
        return ['_status' => 405, 'error' => 'The shutter re-times by POST only.'];
    }
    if (!is_numeric($body['fps'] ?? null)) {
        return ['_status' => 400, 'error' => 'No fps on the handle — give the crank a number.'];
    }
    $fps = (float)$body['fps'];
    if ($fps < STOP_MOTION_FPS_MIN || $fps > STOP_MOTION_FPS_MAX) {
        return ['_status' => 400, 'error' => 'The crank only turns ' . STOP_MOTION_FPS_MIN . '..' . STOP_MOTION_FPS_MAX . ' fps.'];
    }
    $input = is_string($body['fence'] ?? null) ? $body['fence'] : (is_string($body['payload'] ?? null) ? $body['payload'] : '');
    // Normalize the paste: full fence, bare payload, pretty-printed config,
    // or fence delimiters clinging anywhere — the marks leave, the rest is JSON.
    $lines = array_values(array_filter(array_map('trim', explode("\n", $input)), fn($l) => $l !== ''));
    $lines = array_values(array_filter($lines, fn($l) => !str_starts_with($l, '```')));
    $input = implode("\n", $lines);
    $cfg = json_decode($input, true);
    if (!is_array($cfg) || ($clean = stop_motion_config($cfg)) === null) {
        return ['_status' => 400, 'error' => 'That is no stop-motion config — the fence or its JSON line, please.'];
    }
    $clean['fps'] = $fps;
    return ['fence' => stop_motion_fence($clean), 'frames' => count($clean['files']), 'fps' => $fps];
}
