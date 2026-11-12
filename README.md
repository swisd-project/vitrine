# Vitrine

The display case for [Schmarrn](https://github.com/swisd-project/schmarrn) — a public collection of **templates** (themes under the theme contract) and **plugins** (p6 capability contract) that the Schmarrn CMS can browse and pull.

Schmarrn is the cake. Vitrine is the window it sits behind.

## Layout

```
templates/     themes per the theme contract (theme.json + page.html/slide.html + assets/)
plugins/       plugins per the p6 contract (plugin.json + plugin.php)
```

Each plugin folder may carry its own `README.md` — the ware's own documentation
beside its manifest and code, e.g. [`plugins/bienenstich/README.md`](plugins/bienenstich/README.md).

## How Schmarrn finds the wares (shipped)

The CMS's Templates and Plugins tabs carry a "From the Vitrine" shelf that loads
templates/plugins from two sources:

1. **Local scan** — whatever a user uploaded into `bridge/templates/` / `bridge/plugins/` directly (FTP reality).
2. **Vitrine check** — the CMS compares against this public repo (public/open-source wares) and can offer installs.

### The install contract (what the CMS reads from this repo)

- **Registry:** [`wares.json`](wares.json) at the repo root — one JSON, one GET, no API, no token,
  no rate drama. Schema:

  ```json
  {
    "schema": 1,
    "wares": [
      {
        "kind": "plugin",            // "plugin" | "template"
        "name": "hello",             // dir name AND manifest name, [a-z0-9-]+
        "title": "Hello",
        "version": "0.1.0",
        "description": "…",
        "requiresSchmarrn": ">=1.0.0", // optional; also an optional manifest field
        "requires": ["drunken-monk"],  // optional — plugin wares this one needs ON THE HOST
                                       // before its own install clears the CMS lid
        "path": "plugins/hello"      // repo-relative folder to extract
      }
    ]
  }
  ```

- **Install packet:** the CMS rides the ware's OWN FOLDER first — the trees API lists
  `<path>/` once, the raw files carry the rest — and falls back to one archive of the
  whole branch (`codeload.github.com/swisd-project/vitrine/zip/heads/main` — stable public URL)
  where only `<path>/` is extracted into the matching Schmarrn home
  (`bridge/plugins/<name>/` or `bridge/templates/<name>/`). ZipArchive does the
  unpacking on hosts that ship it, PharData covers the rest — no per-ware zips, no release machinery.
- **Install-time discernment rules (enforced by the CMS at the install lid):**
  dir name matches `[a-z0-9-]+`; registry `name` matches the actual manifest `name`;
  `requiresSchmarrn` satisfied by the host version; upgrade = overwrite only if the
  newer `version` is greater, disable-first, and a broken install degrades to a log
  line — never a dead site (the core lid law, unchanged). The arrival comes DISABLED —
  the enable switch stays the user's hand.
- The GUI merges the local scan with `wares.json`, keyed by `name` + `version`:
  **installed** · **installable** · **update available**.

### The shipped example — `hello`

`plugins/hello/` is the smallest possible proof of the plugin contract, and it wears
two hats: the Schmarrn core keeps its OWN tracked copy as the preinstalled default
plugin (a fresh FTP install has it without touching the network), and this folder is
the shelf copy — installable again after a deletion, and the copy-me blueprint for
plugin authors. Its registry entry sits in [`wares.json`](wares.json) — the schema
above shows its shape.

## Contributing

Drop a template or plugin folder into the matching directory via pull request, then
add its entry to `wares.json`. Manifests follow the contracts documented in the
Schmarrn repo (`docs/theme-contract.md`, plugin manifest law).

---

SPDX-License-Identifier: MPL-2.0
