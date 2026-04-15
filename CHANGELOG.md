# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
