# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
