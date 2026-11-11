# Bienenstich (API)

The CMS's API surface as a ware. The core answers one page at a time
(`/bridge/public/<type>/<slug>`); a headless consumer wants the whole honey jar
in one dip. Read-only by law: it never writes, never renders Markdown (the
cache speaks), never leaks a draft — not even to an editor's own session, so
the public face answers the same to every hand. CORS opens wide for plain GETs.

## Routes

All routes are GET only. Everything under `/bridge/plugin/bienenstich/`.

| Route | Auth | What it hands over |
|---|---|---|
| `content` | public + gate | Whole published site as JSON: site face (name, claim, logo, home), every published page — title, slug, url, tags, updatedAt, rendered body HTML. `?body=0` for the light index (no HTML). `?type=<type>` narrows to one content type. A stale cache means `bodyHtml: null` — an honest null beats a wrong page. |
| `search?q=needle` | public + gate | Byte search over **published markdown only** — the core's own needle reads the drafts, this door never does. Returns hits with title and the matching line as excerpt (trimmed to ~160 chars). Empty `q` → 400. |
| `status` | public + gate | Heartbeat: site face, published page count, ware version, time. Cheap enough for a monitor. |
| `keygen` | **admin** (core lid) | The key mint — see below. |

## The key gate (2026-11-11)

Every public route passes `bienenstich_gate()` first. The secret lives in a
`bienenstich.key` file beside `plugin.php` (site-local, **never committed**).
The client presents it as `?key=<secret>` on any route.

- No key file, empty file, missing `?key`, or wrong key → **HTTP 403**,
  `text/plain`, body exactly `Bakery closed.` — nothing else, ever.
- Valid key → the route answers as normal.

Deliberate contract break: the gate exits with raw plain text **before** the
core's JSON writer, on purpose — bots get text, not parseable JSON.

## The key mint (2026-11-11)

`GET /bridge/plugin/bienenstich/keygen` — manifest-declared `auth: admin`; the
core's lid gates it. The API key never guards its own mint.

- No key file yet → mints `bs_<plugin-version>_<32 random alphanumeric chars>`
  (`random_int`-based, not `rand`) and writes it beside `plugin.php`.
- Key already on disk → returns the existing key, never clobbers.
- `?rotate=1` → mints fresh and overwrites. **The old key dies immediately —
  every existing consumer breaks. Loudly: rotate only when you mean it.**

Returns JSON: `ok`, `key`, `rotated`, `usage` (a ready-made example URL).
Unwritable file → **500** with instruction to drop the secret in by hand.

The drawer entry **"Bienenstich key"** in the admin band opens it.

## Setup quickstart (site owner)

1. Install the plugin (arrives disabled — flip the enable switch).
2. Open the **Bienenstich key** drawer entry in the admin band → the mint
   hands you the key.
3. Copy the key, hand `?key=<secret>` URLs to your consumers:
   `/bridge/plugin/bienenstich/content?key=<secret>`

A fresh install has no key file yet, so the public routes answer 403 out of
the box — **the bakery is closed by default, and that is the safe default.**

---

SPDX-License-Identifier: MPL-2.0
