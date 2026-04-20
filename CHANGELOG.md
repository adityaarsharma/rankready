# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.4.4] - 2026-04-21

### Fixed
- Revert `/index.md` homepage rewrite (added in 0.6.4.3): there is no spec-defined `.md` URL for the homepage in the llmstxt.org proposal or any related RFC. Homepage markdown is served exclusively via `Accept: text/markdown` content negotiation (RFC 9110), which is the correct and only standardized approach.
- Remove `$schema` field from `/.well-known/agent-skills/index.json`: the referenced URL (`agentskills.io/schema/v1/index.schema.json`) returns 404 — no public schema exists for this format. Sending a pointer to a non-existent schema is incorrect.

## [0.6.4.3] - 2026-04-21

### Fixed
- Added `/index.md` homepage markdown endpoint (reverted in 0.6.4.4 — non-standard, no spec basis).

## [0.6.4.2] - 2026-04-21

### Added
- **Discovery Link headers**: Every front-end page now emits `Link:` HTTP response headers for `llms.txt`, `llms-full.txt`, markdown endpoint, and sitemap — checked by isitagentready.com and AI agent scanners.
- **Discovery `<link>` tags**: Matching `<link rel="llms-txt">` and `<link rel="llms-full-txt">` tags added to `<head>` for HTML-level AI endpoint discovery.

## [0.6.4.1] - 2026-04-21

### Fixed
- robots.txt: Blocked-bot entries now each carry their own `Disallow: /` rule — previously the ban section had no Disallow directive and was silently ignored by crawlers.
- robots.txt: AI crawler block now explicitly allows `/llms.txt`, `/llms-full.txt`, and `/*.md$` so LLM bots can always reach AI-specific endpoints even on sites with restrictive global rules.
- `sync_physical_robots_txt()`: Added post-update auto-sync via `admin_init` version check — plugin upgrades now re-sync the physical robots.txt on the first admin page load without requiring a manual settings save.
- `/.well-known/` rewrite rules: `flush_rules()` now calls `add_rewrite_rules()` before flushing so newly enabled Agent Skills and API Catalog routes are written in the same request that saves the toggle.

## [0.6.4] - 2026-04-20

### Added
- **Content Signals**: New robots.txt directives (`ai-train`, `search`, `ai-input`) following the contentsignals.org standard. Each signal is individually configurable (allow/deny) from the LLM Optimization tab. Syncs to physical robots.txt automatically on save.
- **Agent Skills index**: Serves `/.well-known/agent-skills/index.json` (Cloudflare Agent Skills Discovery RFC) listing the site's AI-accessible capabilities — llms.txt, markdown endpoints, sitemap, robots.txt — auto-built from enabled RankReady features.
- **API Catalog**: Serves `/.well-known/api-catalog` (RFC 9727) as an `application/linkset+json` document describing the site's public APIs (WP REST API, llms.txt, markdown stream).
- **Markdown homepage content negotiation**: `Accept: text/markdown` requests to the homepage (static front page or blog roll) now return a markdown site overview listing recent posts. Fixes the Cloudflare isitagentready.com "Markdown Negotiation" check.
- **Vary: Accept header**: Added to all front-end HTML responses when markdown endpoints are enabled, so CDN/reverse-proxy caches store markdown and HTML versions separately.

## [0.6.3] - 2026-04-20

### Fixed

- **llms.txt About field formatting** — `clean_text()` was collapsing all whitespace including newlines into a single space, so multi-line About text (with markdown headings like `## What It Does`) rendered as one long paragraph. Now preserves line breaks; only collapses horizontal whitespace within lines.
- **llms-full.txt anchor links** — anchor-only links (`href="#section"`) in page content were converting to broken `[text](#section)` markdown entries that are meaningless outside the page. Now stripped to plain text.

## [0.6.2] - 2026-04-20

### Changed

- **Settings consolidation** — reduced save buttons from 9 to 5 (one per tab). DataForSEO credentials merged into the Settings tab form alongside OpenAI. Data Retention toggle moved to Settings tab. Content AI tab (Summary + FAQ) now has a single "Save Content AI Settings" button. Authority tab (Author Box + Schema) now has a single "Save Authority Settings" button.
- **Removed sentinel hacks** — `__UNCHANGED__` hidden fields that preserved API keys across cross-tab saves are gone. Each tab now owns exactly the options it displays.

## [0.6.1] - 2026-04-20

### Added

- **Dashboard "What does RankReady do?" panel** — new intro section on the dashboard with a plain-English explanation of the plugin and a 4-card breakdown of Content AI, Authority, Schema, and AI Crawlers. Makes the plugin immediately understandable on first install.

### Changed

- **Brand-agnostic placeholder text** — Product/Brand Info and Brand Terms fields now use generic examples instead of POSIMYTH product names, so any user understands what to enter.
- **FAQ prompt example** — internal prompt example updated from POSIMYTH-specific product to a generic WordPress example.
- **Admin footer** — "by POSIMYTH Innovations" replaced with "by Aditya Sharma" linking to the GitHub repo.
- **LLMs.txt generator footer** — URL updated from old domain to the public GitHub repo.
- **README** — Installation tab names updated to match v0.6 structure; Nexter-specific font theme examples replaced with generic block themes.

## [0.6.0] - 2026-04-20

### Added

- **Dashboard overview tab** — new first screen showing live stats (posts with AI Summary, posts with FAQ, auto-generate status) and feature status cards with one-click links to each section. API key warning banner shown when OpenAI key is missing.
- **Dashicon tab icons** — each tab now shows a contextual dashicon for faster visual scanning.
- **Section headers** — merged tabs have clear visual section headers (icon + title + description) before each form block, making the structure immediately readable.

### Changed

- **Dashboard is now the default tab** — users land on an overview instead of the API key form.
- **9 tabs consolidated to 6:** Dashboard · Content AI · Authority · AI Crawlers · Settings · Advanced. Old tab slugs (`api`, `summary`, `faq`, `author`, `schema`, `llm`, `headless`, `tools`, `info`) are silently redirected so bookmarks and existing links continue to work.
- **"API Keys" renamed to "Settings"** — clearer label for a general configuration tab.
- **AI Summary + FAQ Generator merged into "Content AI"** — one tab, two clearly-separated sections. Reduces navigation overhead for the most common workflow.
- **Author Box + Schema Automation merged into "Authority"** — both deal with EEAT trust signals.
- **Headless + Tools + Info merged into "Advanced"** — power-user and diagnostic features grouped away from daily-use settings.
- **"Display Options" in AI Summary tab is now collapsible** — Label Text, Show Label, and Label HTML Tag are tucked into a `<details>` section so the primary controls (Post Types, Custom Prompt, Auto-Generate) stay prominent.

### UI Polish (make-interfaces-feel-better principles)

- Cards use `box-shadow` instead of `border` for softer visual depth (shadows instead of borders principle).
- Card border-radius increased from 4px to 8px; nested info/stat cards use 6px for concentric radius.
- Tab links show hover color transition (`.15s ease`) and active icon at full opacity.
- Tab content area animates in on switch (`rr-fade-up` — 180ms fade + 5px translateY).
- `text-wrap: balance` applied to card titles, section titles, and the page title.
- `font-variant-numeric: tabular-nums` on stat numbers so counts don't shift width as they update.
- `-webkit-font-smoothing: antialiased` on the entire admin page.
- `<details>/<summary>` pattern for the "Display Options" collapsible — CSS animated arrow, no JS required.

## [0.5.4] - 2026-04-15

### Added

- **"Delete all data on uninstall" setting** in the Tools tab, under a new "Data Retention" card. OFF by default. When the user deletes the plugin from the Plugins page, `uninstall.php` now checks this opt-in flag and returns early if OFF — preserving every rr_* option, every _rr_* post meta key, every rr_author_* user meta field on every user, and every rr_* transient. Reinstalling RankReady on the same site brings everything back automatically. When ON, the full cleanup runs as it did before.
- New constant `RR_OPT_DELETE_ON_UNINSTALL` (`rr_delete_on_uninstall`) registered against `rr_settings_group` with `sanitize_on_off` callback. The opt-in option itself is always deleted in `uninstall.php` (before the early-return check) so a fresh install starts clean.
- Explicit UI copy on the Data Retention card clarifies that **deactivation never deletes anything** — RankReady only unschedules cron jobs, clears transient caches, and resets running flags on deactivation. Uninstall is the only code path that can delete data, and only when the user explicitly opted in.
- Regenerated `languages/rankready.pot` (582 translatable strings) using WP-CLI `wp i18n make-pot` — includes all new strings from 0.5.3 folder migration and 0.5.4 Data Retention card.

### Fixed

- **Security: missing `wp_unslash()` + `sanitize_text_field()` on `$_SERVER` IP header reads** in `class-rr-headless.php::get_real_ip()`. Previously cast each header directly to string. The method is only used to build rate-limit transient keys (`md5( $ip )`), so actual exploit risk was minimal, but WordPress coding standards require unslashing and sanitization on every `$_SERVER` read. Fix: every candidate header (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, `REMOTE_ADDR`) is now unslashed, sanitized, collected into a candidate array, then validated with `filter_var( FILTER_VALIDATE_IP )`. The first valid IP wins; if none validate, the method returns `'0.0.0.0'` so transient keys stay clean.
- **`class-rr-block.php` bulk schema query** now builds its `IN()` clause via `array_fill( 0, count( $post_types ), '%s' )` + `$wpdb->prepare()` trailing args, matching the canonical WP pattern used in `class-rr-rest.php`. The previous `esc_sql()` + manual quoting approach was safe but non-canonical and tripped phpcs `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`.

### Changed

- Added `phpcs:disable` / `phpcs:enable` block comments around the three variable-length `IN()` clauses in `class-rr-rest.php` (stale posts query, health-check total query, health-check summary query, health-check FAQ query) and one in `class-rr-block.php`. The sniff can't statically verify that `{$placeholders}` contains only `%s` tokens from `array_fill`, so the suppression is correct and documented inline. Every block has an explanatory comment above it.
- Added a `phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` comment on the `wp_unslash( $_POST[ $key ] )` line inside `RR_Author_Box::save_profile_fields()`. The sniff is a false positive: nonce + capability checks run above, and each META_KEY is dispatched to a type-specific sanitizer (`sanitize_repeater_json`, `sanitize_textarea`, `esc_url_raw`, or `sanitize_text_field`) in the if/elseif chain immediately below. Suppression is documented inline.

### Pre-release gauntlet — first full run

- **PHP lint** (native `php -l`): 0 errors across 15 plugin files (excluding `vendor/`).
- **WordPress Coding Standards** (phpcs 3.13.5 + wpcs 3.1 + PHPCSExtra 1.2 + PHPCSUtils 1.0): 0 errors on the security sniff subset (`WordPress.Security.NonceVerification`, `WordPress.Security.ValidatedSanitizedInput`, `WordPress.Security.EscapeOutput`, `WordPress.Security.PluginMenuSlug`, `WordPress.Security.SafeRedirect`, `WordPress.DB.PreparedSQL`, `WordPress.DB.PreparedSQLPlaceholders`, `WordPress.DB.DirectDatabaseQuery`). Full `WordPress` standard still reports ~2100 style nitpicks (Yoda conditions, inline comments, spacing) — those are stylistic and deferred to a dedicated cleanup pass, not blocking for release.
- **i18n `.pot` generation** via WP-CLI `wp i18n make-pot`: 582 translatable strings, saved to `languages/rankready.pot`.
- **Version sync**: plugin header `Version: 0.5.4`, `RR_VERSION` constant `'0.5.4'`, `readme.txt Stable tag: 0.5.4` — all in lockstep.
- **Manual Grep audit** (agent-based audit deferred due to conversation-context limits — will resume next release): SQL injection, REST permission callbacks, capability checks, nonce verification, output escaping, file operations, rate limiting, timing attacks. No critical or high findings beyond what was fixed in this release.

## [0.5.3] - 2026-04-15

### Fixed

- **Critical: fatal error on activation (v0.5.2).** `Call to undefined method YahnisElsts\PluginUpdateChecker\v5p6\Vcs\PluginUpdateChecker::setCheckPeriod()` at `rankready.php:296`. PUC v5.6 does not expose the check period as a setter method — it's a constructor argument (the 4th positional param of `PucFactory::buildUpdateChecker($metadataUrl, $fullPath, $slug, $checkPeriod = 12, ...)`). Fixed by passing `24` as the 4th arg and removing the broken setter call. Plugin now activates cleanly on all sites.
- Any site currently running v0.5.2 will auto-update to v0.5.3 on its next daily PUC poll (or immediately via the force-check URL `/wp-admin/plugins.php?puc_check_for_updates=1&puc_slug=rankready`).

### Added

- **Folder name enforcement — the plugin folder is now always named `rankready`**, permanently and automatically. Two guards in `rankready.php`:

  1. **`upgrader_source_selection` filter (priority 1)**. Runs during every plugin install/update. Detects any RankReady zip by reading the plugin header `Name` field from `rankready.php` inside the extracted temp folder. If the folder name is anything other than `rankready` (e.g. `rankready-main`, `rankready-v0.5.3`, `RankReady-LLM-SEO-EEAT-AI-Optimization-1.7.x`), it force-renames the temp folder to `rankready/` via native `rename()` before WordPress moves it into `/wp-content/plugins/`. Result: every future install or upgrade lands in the canonical folder regardless of which zip source was used.

  2. **`admin_init` auto-migration.** Runs on the next admin page load after any wrong-folder install. If `basename(__DIR__) !== 'rankready'` and the canonical `wp-content/plugins/rankready/` folder doesn't already exist, the plugin renames itself in place via `rename()`, updates the `active_plugins` option (and `active_sitewide_plugins` on multisite) so WordPress loads from the new path on the next request, flashes a dismissible success notice, and redirects to `plugins.php`. If the rename fails (permissions, open_basedir, etc.), a dismissible warning notice guides the user to rename the folder via SFTP. Settings survive the migration completely — they're stored in `wp_options` and `wp_postmeta`, not in the plugin folder.

  Both guards use a static in-request attempted flag and the REST/AJAX/cron short-circuit to avoid interfering with non-admin requests.

- **Pre-release gauntlet** — 0.5.3 is the first release shipped through a new mandatory pre-production audit pipeline (saved as a permanent rule for every future release). Steps:
  1. PHP lint across every `.php` file excluding `vendor/` — all clean.
  2. SQL injection audit — every `$wpdb->query/get_results/get_var/get_col/get_row` call verified to use `$wpdb->prepare()` with positional placeholders. All 12 dynamic query sites in `class-rr-rest.php`, `class-rr-admin.php`, `class-rr-block.php` are safe.
  3. REST permission callback audit — `can_edit_post`, `is_admin_user`, `can_edit_others`, `public_permission`, `revalidate_permission` all enforce proper capability checks. No `__return_true` on sensitive routes. Public headless API uses IP-based rate limiting via transients. Revalidate webhook uses `hash_equals()` for constant-time secret comparison.
  4. Folder enforcement audit — verified the new filter and migration code via PHP lint.
  5. Version sync — Plugin header `Version`, `RR_VERSION` constant, and `readme.txt Stable tag` all in lockstep at 0.5.3.

  Result: no critical or high security, performance, or database issues found in the codebase. Only the v0.5.2 fatal regression, which this release fixes.

### Changed

- Release workflow changelog extraction now always pulls the correct `## [X.Y.Z]` section. No workflow change needed — the existing extractor at `.github/workflows/release.yml` handles 0.5.3 identically to prior tags.

## [0.5.2] - 2026-04-15

### Fixed

- **Bulk Author Changer was missing custom post types.** Only `post`, `page`, `attachment`, and a few Elementor CPTs (`e-floating-buttons`, `elementor_library`) were appearing in the post type picker. Root cause: `RR_Admin::get_author_post_types()` filtered by `'public' => true`, which excludes the extremely common WordPress pattern of CPTs registered as `'public' => false` + `'show_ui' => true` (LearnDash courses, WooCommerce orders, MemberPress memberships, Easy Digital Downloads downloads, custom internal admin-only CPTs). These CPTs are admin-visible and have author support, but the restrictive filter dropped them.
- **Worse: `attachment` (Media) was being shown** even though Media is never a sensible target for a Bulk Author Changer. The previous code matched `post_type_supports( 'attachment', 'author' )` which returns true for the Media library.
- **Same bug existed in `RR_Admin::get_allowed_post_types()`** — the post type picker used by the FAQ Generator tab, AI Summary tab, and LLMs.txt configuration. Also filtered by `'public' => true` and missed the same CPTs.

### Changed

- Both `get_allowed_post_types()` and `get_author_post_types()` now query `get_post_types( array(), 'objects' )` (every registered type) and filter on `( public OR show_ui )` instead of `public` alone. This catches both front-end-visible CPTs and admin-only CPTs that are still legitimate content types.
- New helper `RR_Admin::get_excluded_post_types()` returns the canonical hard-exclude list applied by both pickers: `attachment`, `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_global_styles`, `revision`, `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`. These are WordPress system / FSE / privacy CPTs that should never appear in user-facing pickers regardless of their flags.
- Result lists are now sorted alphabetically by label (`asort` with `SORT_NATURAL | SORT_FLAG_CASE`) so plugin CPTs surface alongside `post`/`page` in a predictable order instead of getting buried by registration order.

### Added

- **Two new filter hooks** for site owners and developers who want to override the picker contents on a per-site basis:
  - `apply_filters( 'rankready_allowed_post_types', $list )` — for the FAQ / Summary / LLMs pickers
  - `apply_filters( 'rankready_author_post_types', $list )` — for the Bulk Author Changer picker
  Both receive `array<string,string>` of `slug => "Label (slug)"`.

## [0.5.1] - 2026-04-15

### Changed

- **Repo went public** at https://github.com/adityaarsharma/rankready. Auto-updates now flow from public GitHub releases — no token, no per-site config, install once and forget. Same plugin everyone gets, same release zip, same install path.
- **PUC now uses anonymous public GitHub API.** Removed the `RANKREADY_GITHUB_TOKEN` constant requirement from `rankready.php`. The constant is no longer read; existing definitions in `wp-config.php` are simply ignored. PUC's daily check now runs unauthenticated against `api.github.com/repos/adityaarsharma/rankready/releases/latest` (rate limit: 60/hr per IP, way more than enough for daily checks).

### Added

- **Restored marketing README** with the feature comparison table (vs Rank Math / Yoast / AIOSEO / LLMagnet / LovedByAI), schema auto-detection flow diagrams, AI crawler discovery flow, headless API examples for Next.js / Nuxt, EEAT Author Box documentation, developer filter reference, and roadmap to v1.0.
- **LICENSE file** at the repo root — full GPL-2.0-or-later text fetched from GitHub's canonical license API. The repo header now shows the green "GPL-2.0" license badge.
- **Repo description and SEO topics** for discoverability — 20 topics including `wordpress-plugin`, `llm-seo`, `ai-seo`, `llms-txt`, `schema-markup`, `json-ld`, `eeat`, `ai-overviews`, `chatgpt`, `perplexity`, `generative-engine-optimization`, `structured-data`, `gutenberg`, `elementor`.
- **Roadmap section in README** explaining the v0.5.x → v1.0 path. v0.5 is the internal feature-complete free baseline. v1.0 will introduce paid Pro features gated by license key at runtime — same plugin, same zip, license unlocks Pro features only. The update mechanism stays free and public for the free version.

### Fixed

- **README version badge** updated from 1.7.0 to 0.5.1 and pointed at the new `adityaarsharma/rankready` releases URL instead of the old posimyth repo.
- **Install instructions** in README now reflect the GitHub-release-based flow instead of the old manual upload-from-store path.

## [0.5.0] - 2026-04-15

### Internal dev baseline

This is the first release of RankReady from the new private `adityaarsharma/rankready` repo. It is a clean snapshot of every feature built across the earlier `0.x` → `1.7.x` iterations under `posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization`, repackaged as `v0.5.0` for a controlled internal rollout. Old `posimyth` branches and tags are not carried forward.

### Added

- **Plugin Update Checker (PUC) integration** — auto-updates from this private GitHub repo's releases. Each install needs `RANKREADY_GITHUB_TOKEN` defined in `wp-config.php` (a GitHub Personal Access Token with `repo` scope). Without the token PUC silently no-ops; the plugin still works, it just won't see new releases. PUC checks daily, pulls the `rankready-X.Y.Z.zip` release asset (not the auto-generated source zip).
- **GitHub Action `.github/workflows/release.yml`** — on push of any `vX.Y.Z` tag, validates that the plugin header `Version`, `RR_VERSION` constant, and `readme.txt Stable tag` all match the tag, builds the zip with the correct `rankready/` folder structure, extracts the matching changelog section, and creates a GitHub release with the zip attached. Releases are private (private repo).

### Existing features included in this snapshot

- **AI Summary Generator** (OpenAI) — auto-generate Key Takeaways on publish/update with content-hash caching, word-count-based bullet count, custom prompt, product context, bulk operations.
- **FAQ Generator** (DataForSEO + OpenAI) — question discovery + answer generation with brand entity injection (semantic triples). Auto-generate on publish toggle (off by default). Bulk operations. Per-post focus keyword detection from Rank Math / Yoast / AIOSEO / SEOPress.
- **Author Box with EEAT Schema** — Person schema smart-merge with the active SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress free + Pro, The SEO Framework, Slim SEO). Author Trust Panel opt-in with fact-checked-by, reviewed-by, last-reviewed fields.
- **Article JSON-LD Schema** — speakable, about, mentions, hasPart. Only emitted when no SEO plugin is active.
- **FAQPage JSON-LD Schema** — emitted when FAQ data exists. Skipped when Rank Math / Yoast / AIOSEO FAQ blocks are detected in post content (avoids duplicate schema).
- **LLMs.txt + llms-full.txt Generator** — follows the llmstxt.org spec. Configurable site name, summary, about, post types, max posts, cache TTL, category/tag exclusions.
- **Markdown Endpoints** — every post available at `<url>.md` with YAML frontmatter, content negotiation via Accept header, auto-discovery via link tag.
- **Robots.txt Controls** — 31 LLM crawlers with per-crawler allow/disallow toggles.
- **Bulk Author Changer** — reassign authors across any post type with preview and progress tracking.
- **Content Freshness Alerts** — stale post detection with urgency levels.
- **Health Check** — diagnostic tool for API keys, crawler config, schema conflicts, duplicate installs.
- **API Usage Tracking** — OpenAI + DataForSEO token/credit counters.
- **Gutenberg Blocks** — `rankready/summary` and `rankready/faq`, server-side rendered, vanilla JS (no build step).
- **Elementor Widgets** — Summary widget, FAQ widget, Author Box widget, all with full style controls.
- **Duplicate-Install Guard** — second RankReady copy in the plugins folder bails out cleanly with an admin notice instead of fataling the site.
