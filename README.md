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

## Contributing

Drop a template or plugin folder into the matching directory via pull request. Manifests follow the contracts documented in the Schmarrn repo (`docs/theme-contract.md`, plugin manifest law).

---

SPDX-License-Identifier: MPL-2.0
