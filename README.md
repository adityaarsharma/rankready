# RankReady

**Internal/private dev repo for RankReady — the WordPress plugin for LLM SEO, EEAT, and AI Optimization.**

> This repo is **private**. It is not the public distribution. Do not link to it from blog posts, plugin listings, or marketing pages until it goes public.

Current version: **0.5.0**

---

## What's in this repo

The whole `rankready/` plugin folder, including every feature shipped under the old `posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization` repo, repackaged as v0.5 for a controlled internal rollout. See [CHANGELOG.md](CHANGELOG.md) for the full feature list.

Bundled: [Plugin Update Checker (PUC) v5.6](https://github.com/YahnisElsts/plugin-update-checker) under `vendor/plugin-update-checker/`. PUC pulls update metadata from this repo's GitHub releases and surfaces them in WP-Admin → Plugins like a normal update.

---

## Auto-update flow (the whole point)

```
                               git push --tags
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────┐
   │ GitHub Action (.github/workflows/release.yml)               │
   │  1. Validate plugin header / RR_VERSION / readme.txt match  │
   │  2. Stage rankready/ folder (rsync, exclude .git/.github)   │
   │  3. Build rankready-X.Y.Z.zip with correct folder structure │
   │  4. Extract changelog section for X.Y.Z                     │
   │  5. Create GitHub release, attach the zip                   │
   └─────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────┐
   │ PUC on every install (daily check)                          │
   │  1. Authenticate with RANKREADY_GITHUB_TOKEN (private repo) │
   │  2. GET /repos/adityaarsharma/rankready/releases/latest     │
   │  3. Compare release tag vs RR_VERSION                       │
   │  4. If newer, surface "Update available" in Plugins screen  │
   │  5. On click → download release asset → install via WP      │
   └─────────────────────────────────────────────────────────────┘
```

You ship a new version with three commands:

```bash
# 1. bump the three version refs in rankready.php + readme.txt + CHANGELOG.md
# 2. commit
git commit -am "vX.Y.Z: <one-line summary>"
# 3. tag and push
git tag vX.Y.Z && git push origin main --tags
```

The Action takes ~60 seconds. After that, every install with the token sees the update on its next daily PUC poll.

---

## Installing on a test/production site

Because this is a private repo, every install needs a GitHub Personal Access Token to receive updates. Without the token, the plugin still **works**, it just won't see new releases.

### One-time setup per site

1. **Create a Personal Access Token (PAT)** at https://github.com/settings/tokens
   - Type: Classic (or Fine-grained scoped to `adityaarsharma/rankready`)
   - Scopes: `repo` (full)
   - Expiration: whatever fits your security posture (1 year is typical for internal)
2. **Add it to `wp-config.php`** (above the `/* That's all, stop editing! */` line):
   ```php
   define( 'RANKREADY_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
   ```
3. **Install the plugin** — download `rankready-0.5.0.zip` from the [latest release](https://github.com/adityaarsharma/rankready/releases/latest) and upload via WP-Admin → Plugins → Add New → Upload Plugin.

From this point forward, the site auto-checks once a day and shows updates inline like any normal plugin.

### Checking that PUC is alive

WP-Admin → Plugins → RankReady row → "View details" should fetch metadata from GitHub. If the token is wrong or missing, you'll see "An unexpected error occurred" — that's PUC failing silently, not a fatal.

You can also force a check via the URL:
```
/wp-admin/plugins.php?puc_check_for_updates=1&puc_slug=rankready
```

---

## Releasing a new version (full checklist)

1. Bump the **three places**:
   - `rankready.php` plugin header `Version: X.Y.Z`
   - `rankready.php` `define( 'RR_VERSION', 'X.Y.Z' )`
   - `readme.txt` `Stable tag: X.Y.Z`
2. Add a section to `CHANGELOG.md`:
   ```markdown
   ## [X.Y.Z] - YYYY-MM-DD

   ### Added / Fixed / Changed
   - ...
   ```
   The release.yml workflow extracts this section verbatim into the GitHub release body.
3. Commit:
   ```bash
   git commit -am "vX.Y.Z: <one-line summary>"
   ```
4. Tag (matching `vX.Y.Z` or `X.Y.Z` — both work):
   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```
5. Watch the Action: https://github.com/adityaarsharma/rankready/actions
6. Verify the release: https://github.com/adityaarsharma/rankready/releases
   - Tag matches
   - `rankready-X.Y.Z.zip` is attached
   - Release body has the changelog section
7. On a test site, force a PUC check via the URL above and confirm the update notice appears.

If the Action fails on the version-mismatch step, one of the three version refs is wrong. Fix it, retag (delete the bad tag first: `git tag -d vX.Y.Z && git push origin :refs/tags/vX.Y.Z`), repush.

---

## Repo layout

```
rankready/                            ← repo root IS the plugin folder
├── .github/workflows/release.yml     ← tag → build zip → release
├── .gitignore
├── CHANGELOG.md                      ← Keep-a-Changelog format, drives release notes
├── README.md                         ← this file (internal dev README)
├── readme.txt                        ← WordPress.org readme (Stable tag drives version validation)
├── rankready.php                     ← plugin bootstrap, constants, PUC wiring
├── uninstall.php                     ← option + meta cleanup on uninstall
├── index.php                         ← security blank
├── assets/                           ← admin.js, block.js, faq-block.js, style.css, admin-style.css
├── includes/                         ← all PHP classes (RR_Admin, RR_Faq, RR_Generator, RR_Rest, etc.)
├── languages/                        ← i18n
└── vendor/
    └── plugin-update-checker/        ← bundled PUC v5.6 (do not edit, replaceable on upgrade)
```

When `release.yml` builds the zip, it stages the entire repo into a `rankready/` directory, excludes `.git`, `.github`, `.gitignore`, `*.zip`, `node_modules`, etc., and zips it. The resulting zip has `rankready/rankready.php` at its root — the layout WordPress requires for a clean upload.

---

## Future: license-gated updates

Right now PUC does **not** check a license. Anyone with the repo zip can install and use the plugin. This is intentional for v0.5 — the gate is **repo access** (the PAT).

When monetization kicks in:

1. Set up EDD Software Licensing on store.posimyth.com.
2. Create a RankReady EDD product, enable Software Licensing.
3. Replace PUC with [EDD-SL Plugin Updater](https://github.com/easydigitaldownloads/EDD-Sample-Plugin) in `rankready.php`. Same five-line pattern — point at `store.posimyth.com` instead of GitHub, pass the customer's license key.
4. Add `includes/class-rr-license.php` for the license tab UI (key field + activate button).
5. Gate paid features with `if ( ! rr_is_license_valid() ) return;` checks in the relevant feature classes.
6. Switch the GitHub repo from private to public for community trust + wp.org submission.

The PUC integration in `rankready.php` is already structured so swapping it for EDD-SL is a single block replacement — no surrounding code changes.

---

## Old repo

The legacy `posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization` repo is no longer the source of truth. All version branches there have been deleted. The `main` branch is being left alone until a posimyth org admin archives or deletes the entire repo.

Do not push to it. Do not branch from it. Everything happens here.
