# Vitrine

The display case for [Schmarrn](https://github.com/swisd-project/schmarrn) — a public collection of **templates** (themes under the theme contract) and **plugins** (p6 capability contract) that the Schmarrn CMS can browse and pull.

Schmarrn is the cake. Vitrine is the window it sits behind.

## Layout

```
templates/     themes per the theme contract (theme.json + page.html/slide.html + assets/)
plugins/       plugins per the p6 contract (plugin.json + plugin.php)
```

## How Schmarrn will find the wares (planned, not yet implemented)

The CMS gains (no code committed yet, ledger-tracked) a GUI that loads templates/plugins from two sources:

1. **Local scan** — whatever a user uploaded into `bridge/templates/` / `bridge/plugins/` directly (FTP reality).
2. **Vitrine check** — the CMS compares against this public repo (public/open-source wares) and can offer installs.

### The install contract (what the CMS will read from this repo)

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

- **Install packet:** the CMS downloads one archive of the whole branch
  (`codeload.github.com/swisd-project/vitrine/zip/heads/main` — stable public URL)
  with PHP and extracts **only `<path>/`** into the matching Schmarrn home
  (`bridge/plugins/<name>/` or `bridge/templates/<name>/`). ZipArchive does the
  unpacking on hosts that ship it, PharData covers the rest — no per-ware zips, no release machinery.
- **Install-time discernment rules (enforced by the CMS before enabling):**
  dir name matches `[a-z0-9-]+`; registry `name` matches the actual manifest `name`;
  `requiresSchmarrn` satisfied by the host version; upgrade = overwrite only if the
  newer `version` is greater, disable-first, and a broken install degrades to a log
  line — never a dead site (the core lid law, unchanged).
- The GUI merges the local scan with `wares.json`, keyed by `name` + `version`:
  **installed** · **installable** · **update available**.

## Contributing

Drop a template or plugin folder into the matching directory via pull request, then
add its entry to `wares.json`. Manifests follow the contracts documented in the
Schmarrn repo (`docs/theme-contract.md`, plugin manifest law).

---

SPDX-License-Identifier: MPL-2.0
