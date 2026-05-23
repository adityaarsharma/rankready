# RankReady — CLAUDE.md

WordPress AI/LLM SEO plugin. Plugin slug: `rankready`. Text domain: `rankready`.
GPL-2.0-or-later. Distributed via WordPress.org. Made by POSIMYTH.

## Where to find context

> **Picking this up cold? Start here, in order:**
>
> 1. **Strategy:** `../../docs/VISION.md` — one-line vision, audience, north star metric, strategic moats
> 2. **Plan:** `../../docs/ROADMAP.md` — v1.2 QA checklist, v1.3 confirmed features, v1.4 considered list, decision log
> 3. **Inventory:** `../../docs/FEATURES.md` — every shipped feature with status, version, admin location
> 4. **Why:** `../../research/INDEX.md` — full research artefacts from May 20 work (market trends, competitors, ranking factors, audits)
>
> Then continue with this file for code-level rules.

## Critical rules — read first

> **AEO Score feature was descoped in v1.1.2-beta.4.** Anything referring to `RR_AEO_Score`, `aeo-sidebar.js`, `aeo-sidebar.css`, `/aeo-score/{id}` REST route, `RR_META_AEO_SCORE`, `RR_META_AEO_SCORE_NUM`, `RR_CRON_AEO`, the admin bar AEO badge, or the AEO dashboard stats no longer exists. The whole feature is being re-planned and will ship separately in a future release. Do not re-add it without explicit instruction.

- **Never load anything on the frontend.** All RankReady JS/CSS is admin-only. Block editor assets load via `enqueue_block_editor_assets`. Admin notices guard with `is_admin()`.
- **No build step.** All JS is vanilla ES5 using `wp.element.createElement`. No JSX, no Babel, no webpack. Whatever you write runs directly in the browser.
- **No duplicate schema.** Before injecting any JSON-LD, check for active Rank Math / Yoast / AIOSEO. Merge into their graph via filters — never emit standalone schema when theirs is active.
- **Every REST endpoint must use a real permission callback.** Never `'permission_callback' => '__return_true'`. Use `can_edit_post()`, `is_admin_user()`, or `can_edit_others()` from `RR_Rest`.
- **All user-supplied values go through sanitization on save, escaping on output.** `sanitize_text_field()`, `sanitize_textarea_field()`, `absint()` on input. `esc_html()`, `esc_attr()`, `esc_url()` on output. `textContent` not `innerHTML` in JS.
- **No `do_shortcode()` inside generator helpers running under WP-Cron.** Use `strip_shortcodes()` before `wp_strip_all_tags()` in `RR_Generator::get_content_string()`. `do_shortcode()` in cron causes side effects (WooCommerce queries, form submissions, etc.).

## Architecture

### Autoloader
`rankready.php` contains a PSR-4-style autoloader: `RR_Foo` → `includes/class-rr-foo.php` (lowercase, hyphens). Every class file must match this pattern.

### Boot sequence (`rankready.php`)
```
Constants defined → Autoloader registered → init hook:
  RR_Admin::init()
  RR_Block::init()
  RR_Rest::init()
  RR_Markdown::init()
  RR_LLMs_Txt::init()
  RR_Crawler_Log::init()
  RR_Headless::init()
  RR_Generator::init()
  RR_Faq::init()
  RR_Limits::init()
  RR_Elementor::init()
```

### Key constants (`rankready.php`)
| Constant | Value | Purpose |
|----------|-------|---------|
| `RR_VERSION` | `1.1.3` | Plugin version — bump on every release |
| `RR_DIR` | `plugin_dir_path(__FILE__)` | Absolute path with trailing slash |
| `RR_URL` | `plugin_dir_url(__FILE__)` | URL with trailing slash |
| `RR_BASENAME` | `plugin_basename(__FILE__)` | For action_links filter |
| `RR_META_SUMMARY` | `_rr_summary` | Generated AI summary text |
| `RR_META_FAQ` | `_rr_faq` | Generated FAQ JSON |
| `RR_OPT_BRAND_TERMS` | `rr_brand_terms` | Newline-separated canonical brand names |

### Class map
| Class | File | Responsibility |
|-------|------|----------------|
| `RR_Admin` | `class-rr-admin.php` | Tabbed settings page, meta boxes, bulk tools |
| `RR_Block` | `class-rr-block.php` | Block/widget registration, schema injection |
| `RR_Rest` | `class-rr-rest.php` | All REST endpoints under `/rankready/v1/` |
| `RR_Generator` | `class-rr-generator.php` | AI summary + FAQ generation orchestration |
| `RR_LLM` | `class-rr-llm.php` | Multi-provider abstraction (OpenAI/Claude/Gemini/DeepSeek) |
| `RR_Markdown` | `class-rr-markdown.php` | `.md` endpoints, AI bot detection, Dualmark headers |
| `RR_LLMs_Txt` | `class-rr-llms-txt.php` | `/llms.txt` + `/llms-full.txt` generation |
| `RR_Faq` | `class-rr-faq.php` | FAQ storage, DataForSEO integration |
| `RR_Limits` | `class-rr-limits.php` | Monthly free-tier usage gates (5 summaries, 5 FAQs) |
| `RR_Author_Box` | `class-rr-author-box.php` | EEAT author profile block + schema |
| `RR_Crawler_Log` | `class-rr-crawler-log.php` | AI bot visit counting |
| `RR_Headless` | `class-rr-headless.php` | REST fields for Next.js/Nuxt/Astro |

## REST API (`class-rr-rest.php`)

Namespace: `rankready/v1`

| Method | Route | Permission | Purpose |
|--------|-------|------------|---------|
| `POST` | `/generate` | `can_edit_post` | Trigger AI summary generation |
| `POST` | `/generate-faq` | `can_edit_post` | Trigger FAQ generation |
| `GET` | `/status/{id}` | `can_edit_post` | Poll generation job status |
| `POST` | `/test-connection` | `is_admin_user` | Ping active AI provider |
| `GET/POST` | `/settings/*` | `is_admin_user` | Settings read/write |
| `POST` | `/bulk-generate` | `can_edit_others` | Bulk summary generation |

**Permission callbacks:**
- `can_edit_post($request)` — `current_user_can('edit_post', $request->get_param('id'))` — post-specific.
- `is_admin_user()` — `current_user_can('manage_options')`.
- `can_edit_others()` — `current_user_can('edit_others_posts')`.

Never use `__return_true` as permission callback.

## Multi-Provider LLM (`class-rr-llm.php`)

`RR_LLM::generate(string $prompt, array $opts): array` — provider-agnostic. Active provider from `get_option('rr_ai_provider', 'openai')`.

Supported providers: `openai` → `RR_LLM_OpenAI`, `anthropic` → `RR_LLM_Anthropic`, `gemini` → `RR_LLM_Gemini`, `deepseek` → `RR_LLM_DeepSeek`.

`RR_LLM::active_provider_ready(): bool` — returns true if the active provider has a key configured.

## EDD / Auto-update

Free release: native WordPress.org updater. No PUC, no custom update logic in the free ZIP.

Beta/Pro: `RR_SL_Plugin_Updater` (EDD Software Licensing) against `store.posimyth.com`. Only active when `defined('RR_BETA_BUILD')` and `class-rr-pro-beta.php` is present. The WP.org free ZIP never includes either.

EDD item ID: `463989`. License key from `get_option('rr_license_key', '')` or `RR_BETA_LICENSE` constant.

## Markdown endpoints (`class-rr-markdown.php`)

- `/{slug}.md` — serves clean Markdown with YAML frontmatter.
- AI bot detection via `is_ai_bot($user_agent)` — 12 bot signatures (GPTBot, ClaudeBot, PerplexityBot, etc.). When detected, serves Markdown without requiring `Accept: text/markdown`.
- Response headers: `X-Robots-Tag: noindex`, `X-Markdown-Tokens: {count}`.
- Token count uses `mb_strlen($markdown, 'UTF-8')` (not `strlen`) — handles CJK/Arabic/Hindi correctly.
- HTML entities decoded with `html_entity_decode(..., ENT_QUOTES, 'UTF-8')` before output.

## Schema coexistence

Before injecting Article/Speakable/Person schema, check:
- `class_exists('RankMath')` → use `rank_math/json_ld` filter to merge
- `class_exists('WPSEO_Frontend')` → use `wpseo_schema_graph` filter to merge
- `class_exists('AIOSEO')` → use `aioseo_schema_output` filter to merge
- SEOPress, TSF, SlimSEO — same pattern

RankReady's own Article schema only fires as a standalone `<script type="application/ld+json">` when none of the above are active.

## Settings tab → option map

| Tab | `settings_group` | Key options |
|-----|-----------------|-------------|
| Settings | `rr_settings_group` | `rr_ai_provider`, `rr_openai_key`, `rr_anthropic_key`, `rr_gemini_key`, `rr_deepseek_key` |
| Content AI | `rr_content_group` | `rr_summary_prompt`, `rr_faq_count`, `rr_summary_position` |
| Authority | `rr_authority_group` | Author profile fields, schema toggles |
| AI Crawlers | `rr_llms_group` | `rr_llms_enable`, `rr_robots_enable`, `rr_md_enable`, `rr_brand_terms`, per-bot allow/block |
| Advanced | `rr_headless_group` | Bulk tools, data wipe toggle, debug log |

## Vision

**RankReady is the AI/LLM SEO layer for WordPress** — the plugin you add on top of your existing SEO plugin (Rank Math, Yoast, AIOSEO) to make your content discoverable, readable, and citable by AI search engines (ChatGPT, Perplexity, Google AI Overviews, Claude, Gemini).

Traditional SEO optimises for Google's blue-link results. RankReady optimises for **the layer above that** — the AI summaries, citations, and answer engines that intercept traffic before users even see the SERP.

**North star metric:** posts with RankReady installed get cited by at least one AI engine within 30 days of publish.

**Positioning:** The only WordPress plugin purpose-built for Answer Engine Optimisation (AEO). Not an SEO plugin. Not a content tool. The AEO infrastructure layer.

**Brand voice:** Confident, technical, direct. No fluff. Speaks to developers and advanced WordPress users who understand the shift from keyword ranking to AI citation.

---

## Roadmap

Versions follow `MAJOR.MINOR.PATCH`. Beta builds use `X.Y.Z-beta.N` distributed via EDD. WP.org stable tags are clean `X.Y.Z` only.

### v1.1.3 — Brand Terms + DeepSeek v4 + agent discovery + stability (current)
**Theme: Lock the foundation before the next big feature.**

- [x] Brand Terms field (AI Crawlers tab) — canonical brand names for entity consistency
- [x] DeepSeek default model switched to `deepseek-v4-flash` (auto-migration for legacy values)
- [x] Homepage Link header for agent discovery (RFC 8288) — `Link: </llms.txt>; rel="describedby"`
- [x] `X-AEO-Version: 1.0` response header restored on `.md` endpoints (Dualmark spec marker)
- [x] Multi-byte token count fix on markdown endpoints (`mb_strlen` instead of `strlen`)
- [x] HTML entity decode on homepage markdown post titles
- [x] Null-coalescing guard on generator response provider
- [x] `strip_shortcodes()` instead of `do_shortcode()` in `RR_Generator::get_content_string()` — prevents WP-Cron side-effects from WooCommerce/forms
- [x] EDD plugin updater text domain fixed (`nexter-pro-extensions` → `rankready`)
- [x] Dead `run_generation_direct()` removed from generator
- [x] AEO Score feature descoped — full re-plan + redesign, will ship as a separate feature in a later release

### v1.2.0 — AEO Score (re-plan)
**Theme: Tell every post exactly how AI-ready it is — properly this time.**

The previous AEO Score sidebar + admin bar + REST endpoint was removed in v1.1.2-beta.4. Re-planning from scratch with proper UX (branding, score-card design, in-editor placement) and engine (validated Zyppy weights, confirmed factor coverage, calibration against real cited posts). Treat the rest of this roadmap as draft until that plan lands.

### v1.3.0+ — Citation Intelligence (Pro)
**Theme: Show users WHERE they're being cited and WHY they're being missed.**

Free:
- [ ] **Content Gap Scanner** — compare your post against top AI-cited content on the same topic. Show what they have that you don't. (DataForSEO + LLM analysis)
- [ ] **Page Speed card** — surface FCP/LCP from Core Web Vitals API in Dashboard
- [ ] **llms.txt copy reframe** — UI description shifts to "AI agent discoverability" (not "citation driver" — per 300K-domain study showing zero statistical correlation with citations)
- [ ] **CPT support (free)** — extend posts/pages support to Custom Post Types

Pro:
- [ ] **Citation Tracker** — monitor which AI engines cite your content, which posts get cited most, which queries trigger citation. The #1 community ask.
- [ ] **AI Referral Traffic** — separate GA4/GSC segment for traffic coming from AI engines vs. traditional search

### v1.3.0 — EEAT Authority (Pro)
**Theme: Make authorship a ranking signal.**

- [ ] **Full EEAT Author Schema** — Person JSON-LD with credentials, Wikidata ID, ORCID, sameAs links, jobTitle, knowsAbout
- [ ] **Author Profile completeness score** — how well does this author signal E-E-A-T to AI engines
- [ ] **Organisation schema** — About page + company JSON-LD wired to author profiles
- [ ] **AI Crawler Analytics dashboard** — which bots, which pages, when, how often
- [ ] **HowTo + ItemList schema auto-detection** — detect how-to structure in content and wrap in correct schema

### v1.4.0 — Headless + Developer (Pro)
**Theme: RankReady data anywhere.**

- [ ] **Headless REST API** — full post AEO data (score, checks, schema, markdown) as structured JSON for Next.js / Nuxt / Astro
- [ ] **WPGraphQL integration** — AEO score + author data in GraphQL schema
- [ ] **Webhooks** — trigger external pipelines when a post is scored or published
- [ ] **CLI commands** — `wp rankready score <post_id>`, `wp rankready bulk-score`

### v2.0.0 — RankReady Pro launch
**Theme: Freemium → paid conversion.**

- [ ] License key + EDD integration (store.posimyth.com) for all Pro features
- [ ] Per-site pricing (not per-domain). POSIMYTH store standard.
- [ ] Every free feature stays free, forever. Pro adds capabilities on top.
- [ ] Pro onboarding flow — guided setup for Citation Tracker + Author Schema

---

## Deferred / Won't do (free tier)

| Feature | Reason |
|---------|--------|
| Domain authority score | Requires DataForSEO — API cost, not self-serve |
| Page speed CWV | Requires PageSpeed Insights API — separate data source |
| Content uniqueness check | ML-level comparison — compute cost too high for free |
| Social engagement signals | External API + privacy concerns |
| Backlink count display | DataForSEO cost, Rank Math already does this |
| PluginPostStatusInfo score line | Deferred — needs branded design treatment before shipping |
| AEO Score (full feature) | Re-planning — sidebar UI, scoring engine, REST API, admin bar badge all stripped in v1.1.2-beta.4 |

---

## Release checklist

**Before every beta/stable release, run all of these in order. No exceptions.**

1. Bump `Version:` header in `rankready.php`
2. Bump `RR_VERSION` constant in `rankready.php`
3. Bump `Stable tag:` in `readme.txt` (for stable only — keep at last stable for betas)
4. Add changelog entry in `readme.txt` (== Changelog == section)
5. Add upgrade notice in `readme.txt` (== Upgrade Notice == section)
6. Update `CHANGELOG.md` with the same entry
7. `php -l` on every modified PHP file
8. Run audits below (mandatory)
9. Build ZIP with `--exclude` flags from `.distignore`
10. Verify ZIP contents — no `.git/`, `CLAUDE.md`, `ORBIT-PRE-RELEASE.md`, dev `.md` files
11. For beta: upload to EDD store, set Version Number to **exact match** of plugin header (including `-beta.N` suffix), point Update File to the new ZIP

## Always-run audits (release gate)

These must pass before tagging or pushing to EDD. They catch the bug classes that have actually bitten this project before.

### A. Static checks
- `php -l` every changed file — zero syntax errors
- Grep for `aeo`, `AEO`, `RR_META_AEO`, `RR_CRON_AEO`, `AEO_Score`, `aeo-sidebar`, `rrAeoData` across `includes/` + `assets/` + root PHP — must return **zero** matches in shipped code (AEO feature is descoped; any reappearance is a regression)
- Grep for hardcoded API keys, `eval(`, `base64_decode(` — must return zero
- Grep for `'nexter-pro-extensions'`, `'nexter-blocks'`, any non-`rankready` text domain in `__()`/`_e()`/`esc_html__()` calls

### B. Sub-agent audit
Spawn the vibe-code-auditor pattern (general-purpose agent):
- AEO removal sanity check (grep above)
- AI-slop patterns in changed files: dead code, hallucinated APIs, fake comments
- Security: bare `echo` of user data, `$_GET`/`$_POST` without nonce + sanitize, unprepared SQL
- WP.org submission readiness: plugin header completeness, text-domain consistency
- Production readiness score — must be ≥85/100 to ship

### C. Conformance checks (post-deploy)
After deploying to a staging site, run:
- **isitagentready.com** — homepage should hit 125/125 (100%). Failure usually = missing Link header on homepage (`RR_Markdown::add_homepage_link_headers`)
- **Dualmark.dev** — `.md` endpoints must include `X-AEO-Version: 1.0`, `X-Robots-Tag: noindex`, `X-Markdown-Tokens` headers (`RR_Markdown::handle_request`)

### D. EDD update test
After uploading to the store:
- Confirm Version Number in EDD Licensing → Versions matches the plugin header **exactly** (e.g. `1.1.2-beta.5`, not `1.1.2`)
- On a test site running the previous version, run "Check again" in Dashboard → Updates
- Confirm the new version appears in the update banner
- Click update, confirm download succeeds, confirm post-update version reads correctly

## Bug classes already fixed — DO NOT REINTRODUCE

These bugs have all been fixed across v1.1.x and v1.2.0 development. The fixes are non-obvious — if you "refactor" them without understanding the why, the bugs come back. Sorted by when they were caught.

### Caught in v1.1.x beta cycle

| Bug | Wrong | Right | Location |
|-----|-------|-------|----------|
| Shortcode side-effects under WP-Cron | `do_shortcode( $post->post_content )` in helpers that run via cron — fires WooCommerce queries, form submissions | `strip_shortcodes( $post->post_content )` then `wp_strip_all_tags()` | `RR_Generator::get_content_string()` |
| Multi-byte token undercount | `strlen( $markdown )` for token-count header | `mb_strlen( $markdown, 'UTF-8' )` — handles CJK/Arabic/Hindi | `RR_Markdown::handle_request()` (X-Markdown-Tokens) |
| Homepage markdown title double-encoded | Raw `&#8211;` in title output | `html_entity_decode( $title, ENT_QUOTES, 'UTF-8' )` | `RR_Markdown::serve_homepage_markdown()` |
| `.md` URL serving homepage instead of post | `is_home()` returns true before query resolution → wrong template | Check `rr_md_path` query var first, return early if set | `RR_Markdown::handle_accept_header()` (issue #1) |
| Generator PHP notice on unexpected API response | Direct access `$result['provider']` | `$result['provider'] ?? null` (null-coalescing) | `RR_Generator::run_generation()` |
| Wrong text domain in EDD updater | `'nexter-pro-extensions'` (4 strings, copied from sister product) | `'rankready'` | `RR_SL_Plugin_Updater.php` |
| EDD Version Number mismatch on beta uploads | Version Number = `1.1.2` but file is `rankready-1.1.2-beta.4.zip` → update loop confusion | Version Number must match plugin header **exactly** including `-beta.N` suffix | EDD store config (not code) |
| Missing Link header on homepage for agent discovery | No `Link:` response header on `/` → fails isitagentready.com check | `Link: </llms.txt>; rel="describedby"; type="text/plain"` when llms.txt enabled | `RR_Markdown::add_homepage_link_headers()` |
| Missing `X-AEO-Version` on `.md` responses | Removed during AEO cleanup but it's a spec marker, not a feature | `header( 'X-AEO-Version: 1.0' )` alongside other `.md` response headers | `RR_Markdown::handle_request()` |

### Caught in v1.2.0 rc cycle (rc.3 → rc.12) — slift.co compat investigation

These are the **highest-impact lessons**. The v1.2.0 cycle exposed 15 distinct bug classes, all caused by assumptions that don't hold across real-world plugin matrices. Read carefully.

| # | Bug class | Wrong | Right | First caught |
|---|-----------|-------|-------|--------------|
| 1 | **Settings group cross-contamination** | Two forms sharing the same `register_setting()` group. Posting form A nulls every option in group A that wasn't in POST → sanitize_on_off(null) returns 'off' → silent toggle-off for unrelated settings | One isolated group per form. We now have 6: `SETTINGS_GROUP` (API), `CONTENT_GROUP` (Summary+FAQ), `LLMS_GROUP` (Crawlers), `BRAND_GROUP` (4 brand fields), `DATA_GROUP` (uninstall), `EEAT_SCHEMA_GROUP` | rc.3 — Brand Identity save wiped 11 AI Crawlers toggles |
| 2 | **Plugin presence ≠ feature enabled** | `if ( defined( 'SEOPRESS_VERSION' ) ) return;` — assumes SEOPress is serving llms.txt just because it's installed. AIOSEO had the same pattern | Check the actual feature option: `! empty( $seopress['seopress_pro_llms_txt'] )`. If we can't verify the feature is on, RankReady serves | rc.8 — slift.co's SEOPress 9.8.5 installed but llms.txt feature off → 404 |
| 3 | **Reading naked constants** | `$plugin = 'SEOPress ' . SEOPRESS_VERSION;` — fatals under PHP 8+ if only `SEOPRESS_PRO_VERSION` is defined | Ternary fallback: `defined('SEOPRESS_VERSION') ? SEOPRESS_VERSION : ( defined('SEOPRESS_PRO_VERSION') ? SEOPRESS_PRO_VERSION : '?' )` | rc.12 — caught by Docker matrix test with SEOPress Pro standalone |
| 4 | **Filter priority races** | `add_filter( 'robots_txt', $cb, 10 )` — SEOPress / Yoast / RankMath robots editors overwrite at higher priority | `PHP_INT_MAX` so we always run LAST and **append** (never replace). For `template_redirect`: priority **1** to beat page builders (Bricks/Elementor default = 10) | rc.2 (template_redirect) + rc.8 (robots_txt) |
| 5 | **Robots.txt interceptor bypass** | Even at PHP_INT_MAX, SEOPress Pro's robots module registers a custom `/robots.txt` rewrite that bypasses WP's `robots_txt` filter entirely | Detect interceptor (`RR_Llms_Txt::detect_robots_txt_interceptor()`) → write a physical `/robots.txt` file. Physical files win at the webserver level before WP routing runs | rc.9 |
| 6 | **Hard-coded post type defaults** | `array( 'post' )` as default for `RR_OPT_LLMS_POST_TYPES` — sites without any posts (slift.co was 1 page, 0 posts) get empty llms.txt | Default to `array( 'post', 'page' )`. Never assume "post" exists; always read the option | rc.8 — slift.co had 1 page only |
| 7 | **Visible branding in machine output** | `'Generated by RankReady v1.2.0-rc.4 (https://github.com/...)'` in llms.txt footer | One unbranded line: `'Generated from RankReady'`. No URL, no version. Gated behind `RR_OPT_HIDE_BRANDING` (Pro toggle, hides even that line) | rc.11 |
| 8 | **Branded structural markers** | `# -- LLM & AI Crawler Rules (RankReady) --` as the robots.txt block header. Sync regex matched the visible text — change the wording, regex breaks | Functional markers separate from visible content: `# BEGIN RankReady` / `# END RankReady` (like `# BEGIN WordPress`). Sync regex matches BEGIN/END only | rc.11 |
| 9 | **Nested forms = invalid HTML** | Pro "Enable" button rendered as `<form method="post">` inside the tab's outer settings form. Browsers silently drop the inner form | Use `wp_nonce_url() + add_query_arg()` GET link, handle via `admin_init` server-side. Capability check + per-option nonce + server-side whitelist of allowed options | rc.7 |
| 10 | **Schema defer without escape hatch** | `if ( defined( 'WPSEO_VERSION' ) ) return;` in `RR_Block::maybe_inject_schema()` — skips standalone schema even if Yoast's schema feature is disabled | Provide `rankready_force_standalone_schema` filter (default false for back-compat). Users with SEO plugin schema turned off can opt back in | rc.9 |
| 11 | **Sync triggered only on settings change** | `sync_physical_robots_txt()` fires only on `update_option_*` hooks. Plugin upgrades / fresh activations don't trigger it → physical file never written | Also run on plugin activation, on rewrite-rule flush, on detection of robots.txt interceptor (auto-write file when SEOPress robots module turns on) | rc.9 |
| 12 | **Loopback assumptions in Docker** | Diagnostics' `wp_remote_get( home_url('/llms.txt') )` from inside a wp-env container fails with cURL error 7 — the container can't reach its own published port | Documented quirk, not a code bug. In production loopback works. For Docker dev, run probes from the host. Future: add a `WP_HOME_INTERNAL` override constant the probes can fall back to | rc.10 (observation) |
| 13 | **Health Check trusted options instead of probing** | "LLMs.txt enabled" displayed just because the option is 'on' — doesn't catch interception, rewrite failures, or empty body | Live 22-probe Diagnostics that **actually fetches** each endpoint, checks status code, body content, headers, and reports specific conflicts (which plugin is intercepting, what to disable) | rc.5 |
| 14 | **Forensic Diagnostics missing key dumps** | Initial report had pass/fail but no "why" — support couldn't debug without follow-up | Copy Diagnostic Report now includes: every hook on `robots_txt` + their priorities, every `template_redirect` callback at priority ≤5, SEO plugin actual option dump (which features are on), per-cache-plugin exclusion verification, physical files in webroot, response headers from every endpoint, mu-plugins list. One paste = full debug context | rc.10 |
| 15 | **Pro toggle gating** | A Pro toggle that's wired up but stored option works for everyone, leaking Pro functionality to Free | Toggle stored but only effective when `rr_is_pro() === true`. Free tier shows the toggle disabled with "Pro Coming Soon" badge + locked-preview body explaining what they'll get | rc.11 |

### Universal pattern: the locked-preview UX

When a Pro-only feature has a free-tier card, never render the controls when off. Render:
1. Card title (always visible)
2. Goal line (1-sentence outcome promise, always visible)
3. Locked preview body: "What you get when enabled:" bullet list + Enable button (free shows disabled state + Pro Coming Soon badge)

Implemented via `RR_Admin::render_locked_preview()` + `RR_Admin::handle_quick_enable()` (rc.7).

## What NOT to do

- Don't add `console.log` in production JS
- Don't hardcode API endpoints in JS — pass via `wp_localize_script` like other RankReady features do
- Don't add `!important` to CSS without a comment explaining why
- Don't use `$wpdb->query()` with user data — always `$wpdb->prepare()`. For literal queries with zero variables, add `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` with a justification comment.
- Don't store API keys anywhere except `wp_options` via `update_option()`
- Don't add features that require Pro — mark with the lock icon pattern and "Launching with RankReady Pro" copy
- Don't add Pro features to the free ZIP — the WP.org review team will reject it
- Don't call `spawn_cron()` outside of WP-Cron scheduling contexts
- Don't re-add the AEO Score feature without explicit instruction — it's being re-planned, not bug-fixed back in
- Don't use `do_shortcode()` in any helper that may run under WP-Cron — use `strip_shortcodes()`
- Don't use `empty()` to check whether a cached numeric value exists — `empty(0)` is `true`. Use `isset()` for presence checks.
