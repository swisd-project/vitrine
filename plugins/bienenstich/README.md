# Bienenstich (API)

Version **0.2.7**. The CMS's API surface as a ware. The core answers one page at
a time (`/bridge/public/<type>/<slug>`); a headless consumer wants the whole
honey jar in one dip. Read-only by law: it never writes, never renders Markdown
(the cache speaks), never leaks a draft — not even to an editor's own session,
so the public face answers the same to every hand. CORS opens wide for plain
GETs (`Access-Control-Allow-Origin: *`); the core's OPTIONS exit happens before
a plugin route is reached — no preflight, no headers.

## Routes

Public routes are GET only. Everything under `/bridge/plugin/bienenstich/`.

| Route | Auth | What it hands over |
|---|---|---|
| `content` | public + gate | Whole published site as JSON: site face (name, claim, logo, home), every published page — title, slug, url, tags, updatedAt, rendered body HTML. `?body=0` for the light index (no HTML). `?type=<type>` narrows to one content type. A stale cache means `bodyHtml: null` — an honest null beats a wrong page. |
| `search?q=needle` | public + gate | Byte search over **published markdown only** — the core's own needle reads the drafts, this door never does. Hits carry title and the matching line as excerpt (trimmed to ~160 chars). Empty `q` → 400. |
| `status` | public + gate | Heartbeat: site face, published page count, ware version, time. Cheap enough for a monitor to knock on. |
| `keys` | **admin** (core lid, CSRF) | The plugin's own panel — see below. GET = descriptor, POST = one action. |

The drawer handle **"Bienenstich API"** opens `content` — gated, so without a
key it answers `Bakery closed.`

## The key gate (2026-11-12)

Every public route passes `bienenstich_gate()` first.

- No valid access key, missing `?key`, wrong key → **HTTP 403**,
  `text/plain; charset=utf-8`, body exactly `Bakery closed.` — nothing else, ever.
- The gate exits with raw plain text **before** the core's JSON writer, on
  purpose — bots get text, not parseable JSON.

The ware owns every key word. The core stores no plugin secret and knows no key
concept: generation, labels, renew/revoke and the comparison at the gate all
live in the plugin.

## The key store

The master and the access-key rows rest **SEALED in the house vault** — the
core's own service (`bridge/vault.php`) — under the name `bienenstich.keys`,
encrypted at rest (libsodium secretbox), the vault's key in a 0600 keyfile
beside it. Nothing secret lies in the plugin folder anymore.

Why the vault, plainly: the install **swaps a plugin home on every upgrade** —
the whole folder is renamed away, the new ware renamed in, the old one deleted.
A store inside the plugin folder would die with each upgrade: every access key
would vanish and the API would go silent. The vault lives in `bridge/config/`,
which no swap touches.

- A 0.2.6-and-older plaintext store (`keys.local.json`) is migrated **once, on
  first read**: sealed into the vault, the plaintext file wiped immediately. A
  legacy install keeps working after the migration — the keys do not reset.
- Lost vault key (`bridge/config/vault.key` lost, or the envelope damaged): the
  store will not open — the panel says so out loud, and the admin sets a master
  again (which invalidates the old keys).
- On a core that carries no vault yet: the panel says "core has no vault", the
  gate fails closed (`Bakery closed.`), and minting refuses with an
  update-the-core message.

Shape `{master, epoch, keys: [rows]}`; row `{label, nonce, epoch, createdAt,
revoked}`.

## The master secret

The admin's own root secret. It is **never shown**, **never usable as
`?key=`** — only ever compared, as the HMAC input that derives the access keys.

- Type your own into the panel's `input` modal (12 characters or more) or let
  `Generate` mint one server-side (40 random alphanumerics).
- Setting or generating a master **invalidates every access key at once** — the
  derivation changes under them. Rows carry an epoch; the list tells the truth,
  dead keys show as dead.

## Access keys

`bs_<plugin-version>_<32 alphanumerics>` — HMAC-SHA512 over the master and the
row's nonce, rejection-sampled so every character is equally likely. The master
never appears in the key.

- A key wears a label (e.g. "App A") and is shown **exactly once**, right after
  it is minted — copy it then, it never appears again.
- Per label: **renew** (same label, new key, the old key dies at once),
  **revoke** (the label stays, the key stops working), **restore**, **delete**
  (the label goes with it).
- No master yet → issuing is refused (409).

## The panel (2026-11-12)

The ware's own admin surface: `GET|POST /bridge/plugin/bienenstich/keys` (admin
+ CSRF). The manifest declares `capabilities.panel = {route: "keys"}`; the core
renders the descriptor generically and understands none of the words.

It shows: "Master key" status (set / not set) · the buttons `Generate`,
`input` (one-field modal, password type), `+ Access key` (one-field modal for
the label) · the access-key list (label, state, created) with each row's own
actions · a one-time reveal for a freshly minted key.

## Setup (site owner)

1. Install the plugin (arrives disabled — flip the enable switch).
2. Plugins tab → Bienenstich → the **settings** tab (the Access Keys panel) →
   `Generate` a master, or type your own into `input`.
3. `+ Access key` → type the label → copy the key when it appears — once.
4. Hand the URLs to your consumers:
   `/bridge/plugin/bienenstich/content?key=<access key>`

Until an access key exists every public route answers 403 — **the bakery is
closed by default, and that is the safe default.**

## Contract break (0.2.5)

The 0.2.6 key model **replaces** the old single-key model (0.2.5): one shared
key setting and its minting route are gone — an old key no longer opens the
API. Set a master and issue a labelled access key instead.

---

## Test-battery contract note (2026-11-13)

The Schmarrn base's battery (tests/battery-bienenstich.php) seeds the rig with
the OLD static api_key setting and is now 16/30 RED (403 "Bakery closed").
This ware's key model moved to the derived key store (master + nonce keys,
epochs, revocation) on 2026-11-13 — the base's rig must be updated to mint a
key through this ware's own functions before the battery answers green again.
Parked by user order until the ware settles.

---

SPDX-License-Identifier: MPL-2.0
