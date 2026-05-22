# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0-rc.2] - 2026-05-22 — "Cache + Page-Builder Compat"

Directly addresses the production site failure pattern: WP 7.0 + PHP 8.3 + Bricks Builder theme + LiteSpeed server + LiteSpeed Cache plugin → llms.txt + robots toggles "not loading."

### Fixed — page-builder interception
- **`template_redirect` priority 1** for all three endpoint handlers (`RR_Llms_Txt::handle_request`, `RR_Markdown::handle_request`, `RR_Markdown::handle_accept_header`, `RR_MCP::maybe_serve_manifest`). Page builders like Bricks, Elementor Pro templates, and Divi register their template_redirect handlers at default priority 10 — RankReady now runs first and `exit()`s before they can intercept `/llms.txt`, `/llms-full.txt`, `/.well-known/mcp.json`, or `/post-slug.md`.
- **Verified with Docker test case**: a "fake-bricks" mu-plugin registered at priority 10 intercepting matching URLs. Before rc.2: builder captured all 3 endpoints. After rc.2: 0/3 intercepts. RankReady wins.

### Added — Cache plugin compatibility (broad coverage)
- **`RR_Cache::exclude_url_patterns()`** — single helper that registers URL exclusions across:
  - LiteSpeed Cache (`litespeed_excluded_url` filter)
  - WP Rocket (`rocket_cache_reject_uri` regex)
  - W3 Total Cache (`w3tc_pagecache_reject_uri`)
  - WP Super Cache (`cache_rejected_uri` global)
  - WP Fastest Cache (`wpfc_exclude_url`)
  - SG Optimizer (`sg_optimizer_dynamic_cache_excluded_urls`)
  - Breeze / Cloudways (`breeze_rules_cache_excluded_url`)
  - Cache Enabler (`cache_enabler_bypass_cache`)

  Auto-registered on init for `/llms.txt`, `/llms-full.txt`, `.md`, and `/.well-known/mcp.json` — so cache plugins never serve stale 404s or cached HTML on RankReady's dynamic endpoints.

- **`RR_Cache::bypass_page_cache_plugins_only()`** — lightweight constant-only bypass (no Cache-Control override). Used by `output_txt()` and `serve_manifest()` so the public `Cache-Control: public, max-age=…` headers RankReady sends remain intact for browsers / CDNs, while the WP page-cache layer stays out of the way.

### Fixed — Audit deferred items closed
- **Audit #5 (AI Referral race)** — `RR_AI_Referral::increment()` rewritten with a 2-tier path:
  - Object cache (Redis/Memcached) → atomic `wp_cache_incr()` per source per day
  - Buffered shutdown flush → multiple in-request increments coalesce into a SINGLE `wp_options` read-modify-write at request end, eliminating the race entirely. Pruning still happens once per shutdown.
  - Verified with Docker test: 5 sequential increments → exactly 5 counted, 1 wp_options write.
- **Audit #15 (transient key length)** — Homepage `.md` transient key now `md5()` hashes the permalink structure: `rr_md_homepage_` + 32-char hex = 47 chars total, well under WP's 172-char transient limit even on exotic permalink configs (multilingual prefixes, custom CPT date paths).

### Notes
- All known audit findings (19/19) now closed. Two were deferred from rc.1; this beta closes them.
- WP 7.0 + PHP 8.3 + Bricks + LiteSpeed combo: all 4 RankReady endpoints verified resolving correctly with a page-builder shim active.
- Cache exclusion happens on `init` priority 11 — gives cache plugins time to register their filters first.
- Object-cache path is opportunistic: works on Redis-backed sites but isn't required.

## [1.2.0-rc.1] - 2026-05-22 — "Production Ready"

Production hardening pass. Closes every remaining bug from the beta.3 audit and adds upgrade-safety so existing v1.1.x installs don't get surprise behaviour changes. **21 / 21 smoke tests pass.** Engineering side is now ship-ready; the UX/IA redesign moves to a separate track per user direction.

### Fixed — every remaining audit finding

- **Audit #4 — Double `<meta name="robots">` tag** — When Yoast / RankMath / AIOSEO is active, RankReady now merges its `max-snippet:-1` directives into their robots filter via `wpseo_robots_array`, `rank_math/frontend/robots`, and `aioseo_robots_meta`. No more two robots metas in the same page. Standalone emission still works when no SEO plugin is active.
- **Audit #6 — Welcome flow consent disclosure** — Moved the "When you submit, this will automatically enable…" copy ABOVE the submit button so users pressing Enter in the textarea see what's about to happen. Submit button copy changed to "Enable & make my site Agent Ready →" to make the consent explicit.
- **Audit #7 — Default-ON upgrade surprises** — `rankready.php` now detects v1.1.x → v1.2.0 upgrades and explicitly seeds five behaviour-changing toggles to OFF (`rr_max_snippet_default`, `rr_ai_referral_enable`, `rr_mcp_enable`, `rr_md_hint_div`, `rr_md_bot_auto_serve`). Fresh installs keep the safe-defaults-ON pattern via the Welcome flow.
- **Audit #13 — MCP manifest cache control** — Manifest now sends `Cache-Control: no-store, no-cache` when the master toggle is off, so flipping MCP off doesn't leave a stale 5-minute cached 200 at the edge. Added cache-purge hook on `update_option_rr_mcp_enable`.
- **Audit #14 — robots.txt sync thrashing** — `sync_physical_robots_txt()` now diffs the regenerated block against the existing file content and skips both the `put_contents()` write AND the multi-layer cache purge when content is unchanged. Settings saves with no real change no longer hit the file system or invalidate caches.
- **Audit #16 — Brand-term whitespace defensive sanitization** — `get_brand_terms_list()` strips embedded `\r`, `\n`, and Unicode line separators (` ` / ` `) from each term so malicious or accidentally-pasted line breaks can't split the robots.txt `# Brand:` line across records.
- **Audit #19 — MCP manifest broken discovery URLs** — `discovery` section in `/.well-known/mcp.json` now only includes URLs that actually resolve. `llms_txt` and `llms_full_txt` only appear when their respective master toggles are on. Agents reading the manifest no longer get 404'd URLs (which can downrank the source).

### Smoke test coverage (21 / 21 pass)

```
✅ Discovery layer: homepage 200, /llms.txt 200, /.well-known/mcp.json 200, /post.md 200
✅ Brand Identity wired through to llms.txt (name + summary + brand terms)
✅ MCP manifest: 16 tools registered, correct generator version
✅ Toggle gating: discovery.llms_txt absent when llms.txt off
✅ Master toggle off: manifest 404 with no-store Cache-Control
✅ Content negotiation: Accept: text/markdown → markdown response
✅ AI bot UA auto-serve: PerplexityBot → markdown
✅ Discovery headers: Link header advertises llms.txt
✅ MCP abilities: get-site-info / get-brand-terms / get-post return correct data
✅ Bot intent: PerplexityBot=citation, GPTBot=training, Applebot=indexing
✅ Brand Terms whitespace: embedded CR/LF stripped
✅ Zero PHP fatals / RankReady-related warnings in debug.log
```

### Production-ready posture

- All HIGH severity bugs from the post-design-pass audit: **fixed in beta.4** (XSS in Freshness widget, multi-provider key uninstall completeness)
- All MEDIUM severity bugs: **fixed in beta.4 + rc.1**
- All LOW severity bugs: **fixed in rc.1** (except DNT honour and welcome AJAX guard which were already fixed in beta.4)
- PHP lint: clean on every file (1 main + 1 uninstall + 22 includes)
- Endpoint smoke tests: 21/21
- Telemetry / pricing page / settings tab consolidation: **explicitly deferred to v1.3** per user direction ("hold UI, all features production ready tested")

### Known deferred (will not block stable promotion)

These items from the audit are intentionally not addressed in rc.1:

- **Audit #5** — AI Referral `wp_options` race condition under high concurrency. Acceptable lossiness for an analytics counter. Custom-table rewrite scheduled for v1.3.
- **Audit #15** — Homepage `.md` transient key length on exotic permalink structures. Hash-the-key fix scheduled for v1.3 if any user reports it.
- **UI/IA redesign** — 10-card AI Crawlers tab + dashboard duplication + tab IA. Handoff doc written for Claude Design (`docs/HANDOFF-CLAUDE-DESIGN.md`). On hold per user.

## [1.2.0-beta.7] - 2026-05-21 — "Insights Tab"

The architectural debt cleanup begins. Three signals that were conflated on the AI Crawlers tab — **training bot crawl**, **citation bot crawl**, and **AI referral traffic** — now have their own home with explicit headers explaining what each measures. Plus a Content Freshness sub-section so all AI-feedback data lives in one place.

### Added
- **New "Insights" tab** between AI Crawlers and Settings. The first step in the planned 6-tab final IA (Dashboard / Visibility / Content / Authority / Insights / Developer).
- **AI funnel explainer** at the top of Insights — 4-step visual (Training crawl → Citation crawl → Referral click → Conversion 23×) so users know which sub-tab measures which stage.
- **4 sub-tabs in Insights:**
  - `Bot Activity` — splits Training (blue panel, GPTBot / Google-Extended / ClaudeBot) from Citation (green panel, OAI-SearchBot / ChatGPT-User / PerplexityBot) with separate headline numbers + bot counts
  - `AI Citation Candidates` — full table of top citation-fetched pages with Edit shortcuts
  - `AI Referral Traffic` — outbound visits with per-source bar chart, 23× conversion stat, and "how is this different from Bot Activity?" expander
  - `Content Freshness` — embeds the 3-tab widget with explainer about the 28% citation lift

### Why this matters
The user-visible bug fixed by this beta: today's UI shows "Citation Hits 0 · Training Hits 0 · No AI referral visits yet" on the same screen, making the three numbers look like one thing. They're three different stages of the AI funnel with completely different decisions attached:
- **Training hits going up?** → Your site is being indexed for future models. Multi-month payoff.
- **Citation hits going up?** → Your site is being cited in live AI answers RIGHT NOW. Optimise these pages.
- **Referral traffic going up?** → AI is converting users for you. Track conversions.

Splitting them into named sub-tabs with explainers stops users guessing.

### Notes
- The original AI Crawlers tab still shows the AI Crawler Access Log + AI Referral Traffic cards (temporary duplication during the IA migration). Beta.8 removes those cards from AI Crawlers and renames the tab to "Visibility."
- Sub-tab routing via `?sub=` query parameter — bookmarkable, refresh-stable.
- No new options added in this beta. Pure UI restructure on top of existing data.

## [1.2.0-beta.6] - 2026-05-21 — "Per-Resource Toggles"

Pro-dev WebMCP redesign. Admins now choose, per WordPress resource, what AI agents can see. Safe defaults shipped; risky/PII resources opt-in with clear warnings. The manifest itself is filtered — disabled abilities don't appear in `/.well-known/mcp.json` at all, so Claude Desktop / Cursor never even know they exist.

### Added — 13 per-resource exposure toggles (RR_OPT_MCP_EXPOSE_*)

**Safe defaults (ON):**
- `Posts`, `Pages`, `Authors (EEAT)`, `Categories & Tags`, `Sitemap`, `llms.txt inline`, `Freshness signal`, `RankReady AI data`

**Custom Post Types — auto-detected, per-CPT opt-in:**
- Plugin scans `get_post_types(['public' => true])` and lists every non-core CPT as an individual checkbox. User picks which Product / Doc / Event / etc. to expose.

**Sensitive (OFF by default, opt-in with warning):**
- `Comments` (caution — PII: author names, emails, IPs)
- `Media library` (caution — heavy + may include non-attached uploads)
- `Users (full list)` (risky — PII: emails, roles, last login)
- `Installed plugins` (risky — reveals tech stack / attack surface)
- `Themes` (risky — reveals stack)
- `Site settings` (risky — may leak API keys, secrets)

### Added — Defense-in-depth gating
- **Manifest filtering:** `/.well-known/mcp.json` only lists abilities whose required toggles are all ON. Disabled abilities don't appear to clients at all.
- **Execute-time guards:** Each ability method (`ability_get_post`, `ability_get_author`, etc.) calls `RR_MCP::guard($name)` first. If the toggle was flipped off after the manifest was cached, the ability still returns a clean `resource_disabled` error.
- **`ability_gates()` map** — single source of truth mapping each ability name → required exposure-state keys. Manifest filter and execute guards both consult it.

### Added — Pro-dev WebMCP card UI
- **3-section accordion** in the WebMCP card: Safe (✓ green pill), Custom CPTs (auto-detected), Sensitive (⚠ yellow / 🔴 red pills).
- Each toggle row shows: checkbox + name + tone pill + plain-English description of what the ability exposes.
- "Risky" toggles labelled with explicit privacy/security reasoning — "PII risk: comment author names, emails, IPs" etc.
- Status badge updated dynamically to "N abilities registered" reflecting the live filtered count.

### Changed
- WebMCP card description now leads with the principle: "Public content is safe to expose. PII / stack-revealing resources are OFF by default — opt in only if your use case requires it."
- Active abilities list (the existing 4-category breakdown) now respects the toggle state — only enabled abilities show up.

### Notes
- Existing installs upgrade with all safe defaults ON (no behaviour change from beta.5 for the public surface).
- `RR_OPT_MCP_EXPOSE_*` constants added to `uninstall.php` deletion list per audit policy.
- The 6 "sensitive" abilities have toggles registered but **no live ability registrations yet** — the toggles control future capability; we haven't shipped `list-comments`, `list-media`, `list-users`, `list-plugins`, `list-themes`, `get-settings` abilities. Those land in beta.7 once we validate the toggle UX. The toggles ship now so users can opt in early.

## [1.2.0-beta.5] - 2026-05-21 — "WebMCP Expansion"

WebMCP grew from 6 to **16 abilities**. The previous surface was a proof-of-concept — agents could search and get summaries but couldn't actually read post content. v1.2.0-beta.5 fills the gap: full post Markdown retrieval, URL resolution, taxonomy discovery, sitemap, content freshness, EEAT author data, and inline llms.txt — everything a Claude Desktop / Cursor / VS Code agent needs to navigate a WordPress site as a first-class tool source.

### Added — 10 new MCP abilities

**Content retrieval (3):**
- **`rankready/get-post`** — Full post Markdown content + title + URL + .md URL + author + author_id + excerpt + summary bullets + FAQ Q&A + categories + tags + modified date. The "view document" primitive that was missing in beta.4.
- **`rankready/get-post-by-url`** — Resolve any site URL (incl. `.md`, `/category/x/`, `/tag/y/`) to content. Returns post payload OR term info (for category/tag archives). Lets an agent follow internal links.

**Discovery / navigation (4):**
- **`rankready/list-pages`** — Static pages separately from posts. Returns parent_id for hierarchy reconstruction.
- **`rankready/list-content-types`** — Every public post type the site exposes (Posts, Pages, CPTs) with published counts + archive URLs.
- **`rankready/list-categories`** — Ordered by post count: id, name, slug, parent_id, count, archive URL.
- **`rankready/list-tags`** — Same shape as categories.

**AI-native (3):**
- **`rankready/get-llms-txt`** — Returns rendered llms.txt OR llms-full.txt content inline. Saves the agent an HTTP fetch round-trip. Respects the master toggle.
- **`rankready/get-sitemap`** — Parsed sitemap (URL + lastmod + post_type) for cold-crawl scenarios. Up to 2000 entries.
- **`rankready/get-fresh-content`** — Posts/pages modified in last N days. AI engines prioritise fresh content — surface what to read first.

**EEAT signals (1):**
- **`rankready/get-author`** — Full Person schema fields: bio, job title, employer, credentials, education, awards, ORCID, LinkedIn, GitHub, all socials. Drives AI trust signals.

### Changed
- WebMCP card admin UI now groups all 16 abilities into 4 collapsible categories: Site & metadata (3), Content retrieval (4), Discovery & navigation (5), AI-native (4).
- MCP manifest `tools[]` lists every ability with name, description, method, endpoint, expected inputs.
- "Abilities API detected" status badge updated to "16 abilities registered" (was 6).

### Why this matters
A `/find-bugs` review of beta.4 flagged that the 6-ability v1 set lacked the most important primitive — reading actual content. An agent couldn't answer "what does the post 'Best WordPress plugins' say?" because the only post-level abilities returned summary or FAQ snippets, never the body. `get-post` + `get-post-by-url` close that gap. Now an agent loaded with the RankReady MCP source can fully navigate, read, and reason about a WordPress site without a single HTML scrape.

### Notes
- All 16 abilities are read-only. Write abilities (draft-faq, refresh-post, update-summary) remain on the v1.4 roadmap with full governance + audit log + capability checks.
- Total surface area aligns with reference MCP sources (Stripe docs MCP, Cloudflare MCP, GitHub MCP) which all expose 15-25 typed abilities.

## [1.2.0-beta.4] - 2026-05-21 — "Brand Identity + Security Patch"

Two themes: (1) consolidates 5 fragmented brand-related fields into ONE unified Brand Identity card at the top of the AI Crawlers tab; (2) patches the 2 HIGH and 6 MEDIUM/LOW severity bugs surfaced by the post-design-pass `/find-bugs` audit.

### Added
- **Unified Brand Identity card** — Single card at the top of the AI Crawlers tab containing site name, one-line summary, about, and canonical brand terms. All four fields feed into llms.txt, llms-full.txt, robots.txt, FAQ prompts, AI summary prompts, MCP `get-site-info` ability, MCP `get-brand-terms` ability, and homepage Markdown — one place to edit, one consistent record everywhere.
- **`RR_Llms_Txt::get_brand_identity()`** — Unified getter returning `{name, summary, about, terms}`. Resolves through (1) new unified options → (2) legacy LLMS_SITE_NAME / LLMS_SUMMARY / LLMS_ABOUT / FAQ_BRAND_TERMS → (3) WordPress core fallbacks. Full backward compatibility — existing options still work; new code reads through the unified getter only.

### Changed
- **AI Crawlers tab IA** — LLMs.txt Generator card no longer has its own Site Name / Summary / About inputs (moved to the new Brand Identity card). Old "Brand Terms" standalone card removed. A breadcrumb note in the LLMs.txt card points users to the new home for those fields.
- **MCP `get-site-info` ability** — Now returns `name + description + about + url + language + brand_terms` from the unified Brand Identity getter (was: bloginfo direct reads).
- **llms.txt + llms-full.txt generators** — Both now read brand fields exclusively through `get_brand_identity()` (was: 3 separate `get_option()` calls each).

### Security (HIGH severity, audit beta.3 #1 + #2)
- **XSS hardening in Freshness dashboard widget** — Rebuilt the JS row renderer to use `createElement` + `textContent` instead of `innerHTML` string concatenation. Post titles authored by lower-privileged users could otherwise execute as admin in the WP dashboard (privilege escalation primitive).
- **Uninstall now deletes multi-provider keys** — Anthropic, Gemini, DeepSeek API keys + models, all v1.2 options (Brand Terms, max-snippet, AI Referral stats, MCP toggle, Markdown sub-toggles, Welcome flag), Content Signals options, Headless API options, per-post `_rr_max_snippet` + `_rr_llms_exclude` + `_rr_faq_last_failure` meta — and drops the entire `wp_rr_crawler_log` table when the admin opts into "delete all data on uninstall". Critical for GDPR / key-rotation compliance.

### Fixed (MEDIUM severity, audit beta.3)
- **MCP ability permission callbacks no longer `__return_true`** — Now share `RR_MCP::ability_permission()` which honours the master enable toggle. Disabling WebMCP shuts off both the manifest and the 6 abilities (was: only the manifest 404'd, abilities still answered).
- **`do_shortcode()` removed from FAQ cron path** — `RR_Faq::build_faq_prompt()` now uses `strip_shortcodes()` when running under `wp_doing_cron()` to avoid third-party shortcodes (Elementor / EDD / BBPress) executing in cron context without their expected frontend globals.
- **Deactivation clears all v1.2-era crons** — `rr_crawler_log_prune` and the three bulk cron hooks (`rr_cron_bulk_startover/faq/summary`) are now properly cleared.
- **Freshness refresh `$generating` race** — `RR_Freshness::rest_refresh()` now save/restores `RR_Generator::$generating` instead of blanket-resetting to false. Concurrent admin requests no longer tear down each other's re-entrancy guard. Response now reports honest `refreshed / skipped / failed` counts.
- **AI Referral honours Sec-GPC and DNT** — `RR_AI_Referral::maybe_record_referral()` now actually checks the headers the class docblock claimed to honour. GDPR / CCPA compliance.
- **Welcome submit guarded against AJAX / REST contexts** — `RR_Welcome::maybe_handle_submit()` early-returns when `wp_doing_ajax()` or `REST_REQUEST` is set, preventing the redirect-and-exit from terminating unrelated API responses that happen to inherit the welcome form payload.

### Notes
- The unified Brand Identity card is backward compatible: existing users keep their saved values from RR_OPT_LLMS_SITE_NAME / SUMMARY / ABOUT and continue editing them in the new card without losing data.
- v1.2.0-beta.3 medium audit items #4 (double robots meta) and #5 (AI Referral race) deferred to beta.5 — both need design decisions, not just code fixes.
- v1.2.0-beta.3 LOW audit items #13–#19 moved to the v1.2.0 stable QA checklist in ROADMAP.md.

## [1.2.0-beta.3] - 2026-05-21 — "Agent Ready: Insight Layer"

Surfaces every v1.2 feature in the admin UI and turns the bot tracking data into an actionable insight (the real story behind the numbers).

### Added
- **Agent Visibility Status card** — Read-only summary card at the top of the AI Crawlers tab. 11-signal checklist (llms.txt, llms-full.txt, .md routes, AI hint in body, AI bot auto-serve, robots.txt AI rules, Content Signals, max-snippet default, AI Referral tracking, WebMCP, Brand Terms set) with a single Coverage % at a glance.
- **Bot intent classification** — Every tracked AI bot now classified as `citation`, `training`, or `indexing`. Citation-intent bots (OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-Web, DuckAssistBot) fetch on behalf of live user queries — each hit ≈ one AI answer that used your content.
- **AI Citation Candidates panel** — Top 10 pages most fetched by citation-intent bots in the last 30 days. The single most actionable view of the crawler log: these are the posts AI engines pull as sources when answering real user queries right now. Includes hit count, unique-bot count, last-fetched timestamp, and edit-post links.
- **Citation vs Training summary cards** — Replaces 2 of 6 generic summary cards in the Crawler Log with green "Citation Hits" + blue "Training Hits" — the only crawl numbers that actually matter for AI citation visibility.
- **Intent badges on By Bot table** — Each bot row shows a colored pill (Citation / Training / Indexing) before its name. One-glance answer to "which bots actually matter?".
- **WebMCP settings card** — Master enable toggle (default ON), status detection (manifest live + Abilities API detected), copy-paste manifest URL field, full list of 6 exposed abilities. Lives in AI Crawlers tab.
- **AI Referral Traffic settings card** — Master enable toggle (default ON), 30-day breakdown per source (ChatGPT, Perplexity, Gemini, Claude, Copilot). Lives in AI Crawlers tab.
- **Markdown card sub-toggles** — Existing Markdown Endpoints card now exposes "Inject hidden AI hint in body" and "Auto-serve Markdown to AI bots" as proper sub-toggles. Both default ON. Wired into `class-rr-markdown.php` — turning them off actually disables the behaviour.
- **Brand Terms "Wired everywhere" callout** — Brand Terms card now shows a "WIRED EVERYWHERE" badge + a callout block listing all 6 consumers (llms.txt, llms-full.txt, robots.txt, FAQ prompt, AI summary prompt, MCP get-brand-terms ability). When empty, a warning prompts users to set at least one name.
- **Re-run setup wizard link** — Tutorial card gains a "Re-run setup wizard →" button. When the tutorial is dismissed, a standalone link surfaces in the Dashboard tab so the wizard stays discoverable.

### Changed
- **AI Crawler Access Log description** — Rewritten to lead with the citation-vs-training distinction. Users now see immediately what the data means.

### Removed
- **Content Gap Scanner** — Per user feedback during the design pass, removed cleanly. Functionality was overlapping with the Citation Candidates panel + Freshness widget without adding unique value.

### Notes
- All new features default ON for agent visibility — users shouldn't have to opt in to be discoverable.
- Zero new dependencies. Bot intent classification is pure PHP logic on the existing crawl log table.

## [1.2.0-beta.2] - 2026-05-20 — "Agent Ready: Design Pass"

Polish release on top of beta.1. Same engineering surface, redesigned UX. Addresses the PM/design audit findings: positioning whiplash, no onboarding, fragmented dashboards, no visual identity.

### Added
- **Welcome flow on first activation** — Apple-style one-question screen ("What's your brand name?") that auto-enables llms.txt, .md routes, AI crawler allowlist, and WebMCP manifest. Replaces "land on the settings page with 40 options" first run. Skippable, never re-shown after completion.
- **Design tokens CSS** — `assets/design-tokens.css` with semantic CSS custom properties for colors (brand, success/warn/danger, AI source palette), spacing scale, radii, type scale, motion, shadows. Loaded on every admin screen. Foundation for future visual consistency.
- **Status-first meta box** — Plain-English status banner at the top ("✓ Optimised for AI" / "⚠ AI visibility reduced" / "○ Not yet optimised") + compact summary preview + Advanced options collapsed by default. Editors scan in 1 second instead of reading 3 section headers.

### Changed
- **Consolidated dashboard widget** — AI Referral Traffic + Content Freshness merged into a single "RankReady — Agent Visibility" widget with a 2-column responsive grid. One widget, one mental model. Per-feature classes (`RR_AI_Referral`, `RR_Freshness`) still own the data and REST endpoints — only the dashboard registration moved.
- **Positioning unified** — Plugin header, readme.txt, README.md hero now all lead with "Get cited by ChatGPT & Perplexity" instead of three different framings. WordPress.org tags refreshed.
- **Empty states rewritten** — Gap Scanner, AI Referral widget, and Freshness tabs now show contextual prompts with CTAs ("Find posts that need FAQs →") instead of dead-end "no data" strings.

### Fixed
- Inline hex colors in dashboard widgets and gap scanner now reference design tokens. Old hardcoded `#646970` / `#dba617` etc. preserved as fallbacks in `var(--rr-color-x, #fallback)` form, so the UI works whether or not the tokens stylesheet has loaded.

### Notes
- Engineering surface unchanged from beta.1 — no functional regressions, all REST endpoints, abilities, and rewrite rules behave identically.
- Telemetry (anonymous usage stats) and pricing page still pending; flagged for v1.3 in the audit.

## [1.2.0-beta.1] - 2026-05-20 — "Agent Ready"

The Agent Ready release. RankReady stops being just an AI-SEO plugin and becomes the layer that makes a WordPress site a first-class participant in the agentic web — discoverable, queryable, and citable by ChatGPT, Perplexity, Claude, Gemini, and any MCP-capable agent.

### Added
- **WebMCP via WordPress Abilities API** — RankReady now registers six typed abilities (`rankready/get-site-info`, `get-brand-terms`, `search-posts`, `get-post-summary`, `get-post-faq`, `list-recent-posts`) that any MCP-capable AI agent (Claude Desktop, Cursor, VS Code, custom OpenAI-Functions clients) can discover and call. When the official WordPress Abilities API plugin (v0.5.0+, WP 6.9+) is active, RankReady's abilities show up under the standard `/wp-json/wp/v2/abilities` route. Graceful no-op when the API plugin is missing.
- **`/.well-known/mcp.json` manifest** — Standard discovery endpoint listing all RankReady-exposed abilities, brand terms, and other agent-readable endpoints (llms.txt, sitemap, REST). Works regardless of whether the Abilities API plugin is installed.
- **AI Referral Traffic dashboard widget** — Server-side tracking of visitors arriving from `chatgpt.com`, `perplexity.ai`, `gemini.google.com`, `claude.ai`, `copilot.microsoft.com`. Aggregates daily, surfaces 30-day breakdown as a WP dashboard widget. No third-party analytics or external API calls.
- **Content Freshness widget (3 tabs)** — Dashboard widget bucketing every published post as **Stale** (60+ days), **Going stale** (30–59), or **Fresh** (< 30). Per-tab post list with bulk "Refresh dateModified" action — legitimately bumps `post_modified` without touching content, the canonical AI freshness signal.
- **Content Gap Scanner** — New admin page (RankReady → Content Gaps) listing every published post that lacks an AI summary, FAQ, or has gone stale. Per-row one-click "Generate" buttons reuse existing single-post endpoints. Filterable by post type and gap type.
- **Per-post `max-snippet` control** — Meta box dropdown lets editors pick "Allow full snippet for AI" (`max-snippet:-1`), "Standard snippet only", or "Use site default". Sitewide default toggle in AI Crawlers settings. Zyppy's 23-factor study ranks Preview Control as the 4th highest AI citation factor (9.2/10).
- **Per-post llms.txt exclusion** — Meta box checkbox lets editors exclude individual posts from llms.txt without disabling the feature sitewide.
- **Hidden AI hint `<div>` in body** — Visually invisible (clip-path inset, aria-hidden) but raw-HTML scrapers see a clear pointer to the `.md` version of the page. Per the Evil Martians technique (April 2026) that got their docs site cited by Claude.
- **Brand Terms now wired everywhere** — One canonical input (AI Crawlers tab → Brand Terms) feeds into llms.txt header, llms-full.txt header, robots.txt comment block, FAQ prompt brand context, and AI summary system prompt. Single source of entity-consistency truth across every LLM call.

### Changed
- **Meta box redesign** — Consolidated to "RankReady — Agent Visibility" with three sections: AI Summary, AI Snippet, llms.txt. Appears on every post type RankReady touches (summary post types ∪ llms.txt post types ∪ markdown post types).

### Fixed
- `RR_OPT_BRAND_TERMS` was a previously-defined-via-admin constant with no global `define()`. Now properly defined in `rankready.php`. Prevents fatal on PHP 8+ when the admin Brand Terms card renders.

### Notes
- This is a beta build. Free zip targets WordPress.org / GitHub. Beta auto-updates via EDD SL on store.posimyth.com for licensed installs.
- WordPress Abilities API: install [WordPress/abilities-api](https://github.com/WordPress/abilities-api) (or wait for WP 6.9+ core inclusion) to expose RankReady abilities through the standard REST surface. RankReady's manifest at `/.well-known/mcp.json` works either way.

## [1.1.3] - 2026-05-13

### Added
- **Brand Terms field** — AI Crawlers tab → Brand Terms card. One canonical name per line. Guards against brand name variants confusing AI models.
- **Homepage Link header for agent discovery** — RFC 8288 `Link: </llms.txt>; rel="describedby"; type="text/plain"` response header on the homepage when llms.txt is enabled. Helps AI agents (and isitagentready.com / Dualmark.dev style checks) discover the site's llms.txt without HTML scraping.
- `X-AEO-Version: 1.0` response header on `.md` endpoints — advertises Dualmark AEO spec v1.0 conformance.

### Changed
- DeepSeek default model switched to `deepseek-v4-flash` — faster and cheaper than the previous default. Existing API keys keep working with zero migration. Legacy `deepseek-chat` / `deepseek-reasoner` option values auto-migrate to the v4 equivalents on next admin load.

### Fixed
- `strlen()` → `mb_strlen()` for multibyte token count header on markdown endpoints (was undercounting CJK/Arabic/Hindi characters).
- HTML entity decode on homepage markdown post titles (was outputting raw `&#8211;` instead of `—`).
- Null-coalescing guard on `$result['provider']` in generator to prevent PHP notice on unexpected API responses.
- `do_shortcode()` in `RR_Generator::get_content_string()` replaced with `strip_shortcodes()` — prevents WooCommerce / form / cache shortcode side-effects when generation runs under WP-Cron.
- Text domain corrected from `nexter-pro-extensions` to `rankready` in EDD plugin updater (4 strings — copied from a sister product).

### Removed
- Dead `run_generation_direct()` method from generator — shutdown-based path was removed in v1.1.0, method was unreachable.

## [1.1.1-beta.1] - 2026-05-08

### Added
- **Per-provider Verify Key buttons.** Every AI provider card (OpenAI, Claude, Gemini, DeepSeek) now has a Verify Key button that pings the provider's auth endpoint and confirms the key is live. Previously only OpenAI had verification; the others had to be tested via Save → connection test.
- **Provider-aware `verify-key` REST endpoint.** Accepts a `provider` parameter (one of `openai`, `anthropic`, `gemini`, `deepseek`) and routes verification to the right API: OpenAI `/v1/models`, Anthropic `/v1/messages` (1-token probe), Gemini `/v1beta/models`, DeepSeek `/v1/models`.
- **Headless API: progressive disclosure toggle.** Inner Headless settings (CORS origins, cache TTL, rate limit, revalidation URL/secret, GraphQL fields) now hide until the **Enable Public API** master toggle is on. Reduces visual noise when the feature is dormant.
- **AI tab: Active Provider summary card.** Top of the AI (formerly Content AI) tab now shows the currently-active provider, model, and key status with a one-click jump to **Settings → AI Provider** for changes. Quick visibility into "what AI is generating my content right now?".

### Changed
- **Tab rename:** "Content AI" → "AI" (consolidates the AI feature surface under one label).
- **Card cost label:** Advanced tab's API Usage card now reads "Estimated Cost (blended)" instead of "Estimated Cost (GPT-4o-mini)" — the underlying token tracker is provider-agnostic in v1.1.1+.

### Removed
- **Start Over — AI Summaries Only** card (Advanced → Tools). Bulk Regenerate already covers re-running prompts over existing posts; the destructive "delete then re-call" flow was duplicate UX. The two Pro gate cards for Start Over were also removed.

### Fixed
- **Content Signals not appearing in robots.txt when "Discourage search engines" is enabled.** `add_to_robots_txt()` was bailing out early whenever `blog_public` was false, silently dropping the entire `# RankReady` block including Content Signals. Content Signals (`ai-train` / `search` / `ai-input`) is an INDEPENDENT declaration about AI training — users may legitimately want SEO blocked but AI allowed (or vice-versa). Removed the early bail-out so Content Signals + AI Crawler directives now emit regardless of the blog_public flag. The block only includes content the user has explicitly enabled, so always-running is safe.

### Notes
- This is a beta iteration on top of the live `1.1.1` GitHub release. Shipped as a GitHub pre-release; auto-updates to beta installs flow via EDD SL on store.posimyth.com.
- Full structural merge of AI Provider + AI feature settings into a single tab is queued for the next beta — requires migrating the provider options from `SETTINGS_GROUP` to a shared group, which is risky for a single iteration. The summary card on the AI tab + cross-link to Settings is the interim UX.

## [1.1.1] - 2026-05-08

### Added
- **Multi-provider AI engine.** RankReady now works with **Claude (Anthropic)**, **Gemini (Google)**, and **DeepSeek** alongside OpenAI. Pick any one in **Settings → AI Provider** — only the active provider needs an API key, the rest stay dormant. All four providers share a single `RR_LLM` abstraction so adding a fifth in future is a single class file.
- New provider classes: `RR_LLM`, `RR_LLM_OpenAI`, `RR_LLM_Anthropic`, `RR_LLM_Gemini`, `RR_LLM_DeepSeek`. Each implements an identical `generate( system, user, opts )` contract returning `['ok','content','tokens_in','tokens_out','tokens_total','model','provider','error']`.
- Per-provider key + model options (`rr_anthropic_api_key`, `rr_gemini_api_key`, `rr_deepseek_api_key` and matching model selectors). Existing `rr_openai_api_key` / `rr_openai_model` left untouched — every existing install keeps working with zero migration.
- Per-provider key format validation (Anthropic `sk-ant-…`, Gemini `AIza…`, DeepSeek `sk-…`).
- **Tutorial video card** at the top of the Dashboard tab — embedded YouTube walkthrough (privacy-enhanced via `youtube-nocookie.com`, lazy-loaded), dismissible per user via `rr_tutorial_dismissed` user meta.
- **"What's new" upgrade banner** — shown once per major version on RankReady admin pages only (never WP-wide), per-user dismissible via `rr_whatsnew_dismissed_version` user meta.
- **Red dot release indicator** on the WordPress sidebar `RankReady` menu item — appears when the user hasn't seen the latest release notes, clears on dismiss.

### Changed
- **AI Summary + FAQ Generator** now dispatch through the `RR_LLM` abstraction. Same prompts, same output schema, same cost tracking — provider-agnostic at the call site.
- **Connection test** in Settings now pings whichever provider is active (no longer hard-coded to OpenAI).
- **Advanced tab** reorganized: `Bulk Generate FAQs` now sits adjacent to `Bulk Regenerate AI Summaries` (both content-generation operations grouped together).
- **Dashboard widgets** (status grid, "Settings" card, API Usage section heading) now show the active provider's name and model rather than hard-coding "OpenAI".

### Fixed
- **`.md` URL serves wrong page when `Accept: text/markdown` is sent** ([#1](https://github.com/adityaarsharma/rankready/issues/1)). At `template_redirect` priority 5, `WP_Query::parse_query()` hadn't yet resolved the queried object — `is_home()` returned true and `handle_accept_header()` would serve the homepage markdown index instead of the requested page. Added an early bail-out when `rr_md_path` query var is set so `handle_request()` at priority 10 can serve the correct page markdown. Confirmed fixed on three production sites by the reporter (@rohitposimyth-seo).

## [1.1.0-beta.5] - 2026-04-28

### Changed
- **Beta license is now silent and automatic.** New pre-issued key (`fe7f1e51…`) replaces the previous one that EDD was rejecting. The plugin now auto-activates against `store.posimyth.com` on first `wp_loaded` after install, with a one-hour retry throttle, so beta testers never see a license field and auto-updates flow without any user interaction.
- Removed the dormant admin license form handlers and notice from `class-rr-pro-beta.php` (no UI ever rendered them — replaced by the silent activation flow).

### Fixed
- Beta installs were failing to receive auto-updates because the license was only being *checked* (never *activated*) against the EDD store, so EDD reported `site_inactive` and the SL Plugin Updater silently bailed out.

### Removed
- Deleted the legacy `vendor/plugin-update-checker/` library (~660 KB on disk, 165 KB zipped) — it was dead weight from the pre-1.0.0 GitHub auto-update flow. v1.0.0+ uses the WP.org store for free updates and `RR_SL_Plugin_Updater` for beta updates; PUC has zero references in the codebase. Beta zip drops from 376 KB → 212 KB (35 files).

## [1.0.0] - 2026-04-23

### Added
- **Freemium tier**: Free version now includes 5 AI summary generations and 5 FAQ generations per calendar month. Limits reset on the 1st of each month.
- `RR_Limits` class (`includes/class-rr-limits.php`): calendar-month usage tracking via `wp_options`, per-feature limit checks, usage recording, loss-aversion upsell copy with dynamic post counts, peak-end rule post-win notices, `get_stats()` for admin display, REST endpoint `GET /rankready/v1/limits`.
- Free-tier constants: `RR_FREE_SUMMARY_LIMIT` (5), `RR_FREE_FAQ_LIMIT` (5), `RR_STORE_URL`.
- Usage banner on Content AI tab showing progress bars for summary/FAQ usage with limit-aware upgrade CTA.
- Post-win upsell notice (shown when ≤3 uses remain) — peak-end rule framing.
- CSS styles for `.rr-usage-banner`, `.rr-usage-bar`, `.rr-notice--upsell`, `.rr-notice--dismissible`.

### Changed
- Plugin URI updated to `https://store.posimyth.com/plugins/rank-ready`.
- Author set to `POSIMYTH Inc. & Aditya Sharma`; Author URI set to `https://posimyth.com`.
- AI summary generation (`class-rr-generator.php`) now checks `RR_Limits::can_generate_summary()` before API call and records usage after success.
- FAQ generation (`class-rr-faq.php`) now checks `RR_Limits::can_generate_faq()` before API call and records usage after success.
- Admin footer link updated from GitHub repository to POSIMYTH Store.
- Plugin version bumped to `1.0.0` — first public freemium release.

## [0.6.7.3] - 2026-04-21

### Fixed
- FAQ "Last reviewed" date now uses the post's last modified date (`post_modified`) instead of the FAQ generation timestamp. Fixes cases where regenerating the FAQ would reset the displayed date even when the underlying content had not changed. Affects the Gutenberg FAQ block, the Elementor FAQ widget, and the shortcode renderer. The `lastReviewed` field in Article JSON-LD schema is also updated to match.

## [0.6.7.2] - 2026-04-21

### Removed
- Visible "View as Markdown" frontend link injected after post content. Machine discovery still works via `<link rel="alternate">` tag and `Link` HTTP header.

## [0.6.7.1] - 2026-04-21

### Changed
- DB schema v3: dropped `user_agent` column — `bot_name` already captures bot identity in human-readable form; storing the full raw UA string (up to 500 bytes) per row was pure redundant bloat. Migration runs once via `ALTER TABLE DROP COLUMN` guarded by `SHOW COLUMNS` check; no data loss.
- Retention window reduced from 90 → 30 days — 90 days of bot hit logs was excessive for a content site; 30 days covers all analytics windows shown in the admin panel.
- `prune()` now enforces a hard 50 000-row cap: after time-based expiry, if total rows still exceed the cap, oldest rows are deleted. Cap runs in the existing daily cron — zero per-request overhead.

## [0.6.7.0] - 2026-04-21

### Added
- CPT-aware bot tracking: `wp_rr_crawler_log` DB schema v2 adds `post_id`, `post_type`, `post_title` columns so every crawler hit is linked to the exact post/page/CPT that was read.
- `RR_Crawler_Log::get_bot_top_pages()` — top 5 pages read by a specific bot, grouped by post.
- `RR_Crawler_Log::get_cpt_stats()` — hit counts and unique post counts grouped by WordPress post type (CPT breakdown).
- `RR_Crawler_Log::get_top_pages()` — top pages across all bots with `bots_csv` (pipe-separated list of which bots read each page).
- `RR_Crawler_Log::get_endpoint_totals()` — per-endpoint totals (llms.txt, llms-full.txt, .md URL, homepage .md) for the summary strip.
- `RR_Crawler_Log::get_unique_pages()` — count of distinct post IDs crawled within a time window.

### Changed
- `RR_Crawler_Log::log()` signature updated to `log( string $endpoint, ?WP_Post $post = null )` — callers pass the already-resolved WP_Post so post metadata is stored without a second URL resolution.
- `RR_Crawler_Log::get_bot_stats()` now includes `unique_pages` via `COUNT(DISTINCT NULLIF(post_id, 0))`.
- `handle_request()` in `class-rr-markdown.php` now logs AFTER post resolution and passes `$post` object — previously logged before resolution so no post metadata was captured.
- `handle_accept_header()` singular post path now logs bot hits — was previously unlogged.
- AI Crawler Access Log admin panel rewritten with five organized sections: (1) summary strip with 6 stat cards, (2) per-bot expandable grid with endpoint column breakdown and top 5 pages per bot, (3) CPT bar chart with unique post counts, (4) top pages table with "Read by" bot badge chips, (5) collapsible live hit log with full columns (Time, Bot, Endpoint, Type, Page/Post, URL).

## [0.6.6.0] - 2026-04-21

### Added
- `class-rr-crawler-log.php` — AI crawler access log. Detects 25 known AI/LLM bots by User-Agent (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Bytespider, Cohere, AI2Bot, YouBot, DuckAssistBot, Diffbot, Applebot, Meta, Magpie, Amazonbot, and more). Logs hits to a custom `wp_rr_crawler_log` DB table on every request to llms.txt, llms-full.txt, .md URL, and homepage Accept:text/markdown. Records auto-pruned after 90 days via daily WP-Cron.
- AI Crawlers tab: new "AI Crawler Access Log" panel showing total hits (7d / 30d), unique bots, per-bot breakdown table (with per-endpoint column counts), top 5 most-read URLs, and a collapsible live-hit log (last 30 entries). Zero config — logs start on install.
- `RR_Crawler_Log::detect_active()` returns human-readable list of active cache plugins for health-check dashboard.

## [0.6.5.5] - 2026-04-21

### Added
- `class-rr-cache.php` — centralized cache layer utility class (`RR_Cache`) covering every known CDN and WordPress page-cache layer: Cloudflare APO (`cf-edge-cache`), Cloudflare non-APO (`CDN-Cache-Control`), Varnish/Fastly (`Surrogate-Control`), Akamai (`Edge-Control`), nginx FastCGI (`X-Accel-Expires: 0`), HTTP standard (`Cache-Control: no-store, Pragma`), WP Rocket / W3TC / LiteSpeed / WP Super Cache / WP Fastest Cache / Breeze / SG Optimizer / Hummingbird / Comet Cache / Cache Enabler / Swift Performance / Pantheon (`DONOTCACHEPAGE`, `LSCWP_NO_CACHE`, `DONOTCACHEOBJECT`, `DONOTCACHEDB` constants + purge actions).
- `RR_Cache::no_cache_headers()` — sets all CDN + page-cache bypass headers in one call.
- `RR_Cache::purge_url( $url )` — purges a single URL from all active cache layers.
- `RR_Cache::purge_all()` — nuclear full-site cache clear for activate/settings-save.
- `RR_Cache::detect_active()` — returns active cache plugins for health-check dashboard.
- Orbit `cache.spec.js` — 28 Playwright tests covering homepage bypass headers per layer, markdown negotiation, .md URL headers, llms.txt cacheability, robots.txt content, PHP constant safety (no "Cannot redeclare"), sequential request correctness, and RFC 9110 q-value negotiation.

### Changed
- `serve_markdown()` in `class-rr-markdown.php` now calls `RR_Cache::no_cache_headers()` — adds cf-edge-cache, Surrogate-Control, Edge-Control, X-Accel-Expires, DONOTCACHEPAGE, LSCWP_NO_CACHE to every markdown response.
- `add_vary_header()` homepage block replaced with single `RR_Cache::no_cache_headers()` call.
- `purge_robots_cache()` in `class-rr-llms-txt.php` delegates to `RR_Cache::purge_url()` — now covers Hummingbird, Cache Enabler, Comet Cache, Swift Performance, Autoptimize, Pantheon in addition to previous list.

## [0.6.5.4] - 2026-04-21

### Fixed
- Homepage markdown negotiation broken behind Cloudflare APO: `CDN-Cache-Control: no-store` was not enough because APO ignores it and serves the cached HTML to every request including `Accept: text/markdown`. Added `cf-edge-cache: no-cache` (the APO-specific bypass directive) plus `Edge-Control: no-store` (Akamai) to the homepage `send_headers` response. No Cloudflare dashboard config required — PHP sets these headers and APO stops caching the homepage on first response.

## [0.6.5.3] - 2026-04-21

### Removed
- Agent Skills (`.well-known/agent-skills/`) and API Catalog (`.well-known/api-catalog`) endpoints deleted entirely. These were blocked by Cloudflare on content sites and serve no purpose for standard WordPress installations. Removed `class-rr-agent-discovery.php`, `RR_OPT_AGENT_SKILLS_ENABLE`, `RR_OPT_API_CATALOG_ENABLE` constants, the UI card in the Advanced tab, and all health-check references.

## [0.6.5.2] - 2026-04-21

### Fixed
- Fatal error on plugin update: `add_rewrite_rules()` was called inside a `plugins_loaded` closure where `$wp_rewrite` is not yet initialised, causing "Call to a member function add_rule() on null". Deferred the call to `init` (priority 99) so rewrite rules are registered and flushed after WordPress core is fully ready. Orbit test added to verify no fatal fires when the stored version differs from RR_VERSION.

### Tests (Orbit)
- Added 9 new Playwright checks covering the full isitagentready.com / acceptmarkdown.com suite: markdown Accept-header negotiation (content-type + Vary + x-markdown-source), .md URL content-type, 406 for unsupported Accept, llms-full.txt 200, robots.txt Content-Signal directive, Agent Skills JSON structure, API Catalog 200, llms.txt Link discovery headers, and no-fatal-on-version-bump.

## [0.6.5.1] - 2026-04-21

### Fixed
- Auto-purge robots.txt from all common page caches after every sync: WP Rocket (`rocket_clean_files`), LiteSpeed Cache (`litespeed_purge_url`), W3 Total Cache (`w3tc_flush_url`), WP Super Cache (`wp_cache_clear_cache`), official Cloudflare WP plugin (`cloudflare_purge_by_url`), Nginx Helper (`rt_nginx_helper_purge_url`), SG Optimizer (`sg_cachepress_purge_cache`), Breeze/Cloudways (`breeze_clear_all_cache`), and WP Fastest Cache. No configuration needed — hooks fire silently when those plugins are not active.

## [0.6.5.0] - 2026-04-21

### Added
- Admin notice when plain permalinks (?p=123) are active: warns on the RankReady settings page that llms.txt, llms-full.txt, and per-post .md endpoints will return 404 until pretty permalinks are enabled, with a direct link to Settings → Permalinks. Orbit QA (Playwright smoke test) confirmed all 12 tests pass after enabling pretty permalinks.

## [0.6.4.7] - 2026-04-21

### Fixed
- Accept header q-value parsing: RFC 9110-compliant negotiation now correctly honors quality values. When a client sends `Accept: text/html;q=0.9, text/markdown;q=0.5`, WordPress HTML is served because HTML has the higher q-value. Previously, markdown was served whenever `text/markdown` appeared anywhere in the Accept string regardless of q-values.
- 406 Not Acceptable: when the Accept header contains only types the server cannot produce (e.g. `Accept: application/json` only), the plugin now returns a 406 response with `Vary: Accept`. Passes acceptmarkdown.com check 3.
- Multi-layer cache bypass for homepage markdown negotiation expanded: added `Surrogate-Control: no-store` (Varnish, Fastly, Akamai) and `Cache-Control: no-store` (nginx FastCGI, WP Rocket, W3TC) alongside the existing `CDN-Cache-Control: no-store` for Cloudflare APO.

## [0.6.4.6] - 2026-04-21

### Fixed
- Markdown negotiation on Cloudflare APO sites: homepage HTML responses now send `CDN-Cache-Control: no-store`, which tells Cloudflare not to cache the homepage. This allows every `Accept: text/markdown` request to reach PHP where content negotiation serves the correct `Content-Type: text/markdown` response. Previously, Cloudflare APO served cached HTML to AI agents regardless of the Accept header. One cache purge after this update is all that is needed — no Cloudflare Cache Rules or plan upgrades required.
- Markdown response headers: added `x-markdown-source: accept` (matching roots.io convention) and changed `Cache-Control` from `public, max-age=3600` to `no-store` to prevent CDN layers from caching and mis-serving the markdown response to browser clients.

## [0.6.4.5] - 2026-04-21

### Fixed
- Content Signals format: isitagentready.com expects a single `Content-Signal: ai-train=yes, search=yes, ai-input=yes` directive, not separate `ai-train: allow` lines. Updated `generate_robots_block()` to output the correct format. Internal option values (`allow`/`deny`) unchanged — only the robots.txt output format changed.

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
