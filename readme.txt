=== RankReady – llms.txt, .md & AI SEO for ChatGPT, Perplexity, Claude, Google ===
Contributors: posimyththemes, adityaarsharma
Tags: seo, schema, chatgpt, ai-seo, llms.txt
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get cited by ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews. AI summaries, FAQ schema, llms.txt, WebMCP, crawler controls.

== Description ==

RankReady is the first WordPress plugin built end-to-end for the AI search layer. Drop it in alongside your existing SEO plugin (Rank Math, Yoast, AIOSEO — any of them) and start showing up in AI answers, citations, and Overviews. **No conflicts. No replacement. Zero frontend bloat.**

[Visit the official RankReady page →](https://store.posimyth.com/plugins/rankready/?ref=rankreadyreadme)

**40-55% of AI citations go to fewer than 1,000 domains.** If your site isn't on that list, ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews are answering your buyers' questions with someone else's content — and you'll never see the click.

## A quick walkthrough of the whole plugin.

https://www.youtube.com/watch?v=JA-rEwMbqNo

Built by [POSIMYTH Inc.](https://posimyth.com/?ref=rankreadyreadme) — the team behind The Plus Addons for Elementor, NexterWP, and UiChemy. RankReady ships every feature you need to be discovered, read, and cited by AI search engines.


## The Problem: AI Search Is Eating SEO Traffic

Traditional SEO plugins (Rank Math, Yoast SEO, All in One SEO) optimize for Google's blue-link results. They were built for a web where users clicked through to your site. That web is shrinking.

In 2026, AI Overviews, ChatGPT answers, Perplexity citations, and Claude summaries intercept buyer questions **before** Google's classic results ever load. They cite a handful of sources, link to a few, and synthesize the rest — meaning your traffic vanishes into someone else's footnote.

RankReady is the layer above your SEO plugin. It handles the AI-specific signals — llms.txt, FAQPage schema, Markdown endpoints, E-E-A-T, Speakable, WebMCP, AI crawler controls — that decide whether you're the source AI quotes, or the site it never read.

## How It Works: Add Once, Coexists Forever

Install RankReady, pick your LLM provider (OpenAI, Anthropic, Gemini, or DeepSeek), and the plugin handles the rest. It auto-detects your active SEO plugin and **never emits duplicate schema**. Your existing Yoast or Rank Math setup keeps working exactly as before. RankReady just adds the AI-layer features none of them cover.

Frontend impact: zero. All AI generation runs in the WordPress admin — no API calls on page load, no third-party scripts, no extra HTTP requests for your visitors.

## Get Cited by ChatGPT, Perplexity, Claude & Gemini with llms.txt

RankReady serves the [llmstxt.org](https://llmstxt.org) standard at `/llms.txt` (a curated index of your best content) and `/llms-full.txt` (the full content concatenated as Markdown). AI crawlers read these files first to understand your site — think of it as an AI-native sitemap. Configurable post types, max post count, category and tag exclusions, and a per-domain brand identity (site name, summary, about section) you control from the **AI Crawlers** tab.

## AI Summary Generator with Speakable Schema

Generate "Key Takeaways" for any post via your chosen LLM (OpenAI, Anthropic Claude, Google Gemini, or DeepSeek). The summary auto-injects above your content as a styled block with **Speakable schema** — the JSON-LD that Google Assistant, Alexa, and AI voice assistants read aloud. Unlimited manual generations. Set auto-generate-on-publish to cover new posts automatically. Bulk-regenerate across your entire library from the **Content AI** tab.

## FAQ Schema Generator with DataForSEO

The killer feature for AI Overviews. RankReady queries DataForSEO for the real "People Also Ask" questions ranking for your post's focus keyword, then has your chosen LLM write the answers. Output is FAQPage JSON-LD — the schema Google AI Overviews and Perplexity **preferentially cite over plain article text**. Pages with FAQPage schema are 3.2× more likely to appear in AI Overviews. Unlimited manual generations. Setup guide in the FAQ section below.

## E-E-A-T Schema and Author Box for AI Trust Signals

E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) is what AI models use to decide which sources to cite. RankReady ships a full Author Box (photo, bio, headline, topics, credentials, year-started) plus Article, Speakable, FAQPage, HowTo, and ItemList JSON-LD. Auto-detects Rank Math, Yoast, AIOSEO — skips duplicate output, or merges into their schema graph via filters. Display the Author Box anywhere via Gutenberg block or Elementor widget. Configure from the **E-E-A-T** tab.

## Markdown Endpoints and WebMCP Manifest for AI Agents

Every published post is served as clean Markdown at `/post-slug.md` with YAML frontmatter (title, author, dates, schema). AI agents — Claude Desktop, Cursor, ChatGPT plugins, custom MCP clients — read Markdown 10× faster than HTML. Content negotiation via `Accept: text/markdown` lets crawlers fetch the format they prefer with no URL changes.

On top of that, RankReady publishes a [Model Context Protocol](https://modelcontextprotocol.io/) manifest at `/.well-known/mcp.json` listing what an AI agent can do on your site — read posts, list authors, fetch FAQs, query categories. When a Claude Desktop or Cursor user adds your site as an MCP server, this is the file they discover.

## AI Citation Tracking and Bot Activity Analytics

The **Insights** tab gives you four real-time views:

* **Training Bots** — Which AI crawlers indexed which pages (GPTBot, ClaudeBot, Google-Extended, Bytespider, CCBot, and 8 more training-intent bots).
* **Citation Bots** — Which pages were fetched mid-answer (ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, DuckAssistBot). Each hit is a live AI answer that retrieved your page as a source.
* **Real AI Referrals** — Humans clicking through from chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com. 100% server-side via the HTTP Referer header. No third-party scripts. No UTM tagging.
* **Content Fresh** — Freshness scanner with bulk one-click `dateModified` refresh.

All counts are stored locally in your `wp_options` and a custom log table — never sent to POSIMYTH.

## Content Freshness Scanner — 28% More AI Citations

Multiple 2026 studies show fresh content earns ~28% more AI citations, and 65% of all AI citations target content updated within the past year. The Content Freshness Scanner buckets every post into **Stale** (60+ days), **Going stale** (30-59 days), and **Fresh** (under 30 days). Select stale posts and click **Refresh dateModified** to bump the modified timestamp without touching content — a clean signal to AI crawlers on their next visit.

## 31 AI Crawler Controls + Auto robots.txt

Granular allow/block toggles for **31 AI bots**: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Bytespider, CCBot, FacebookBot, Meta-ExternalAgent, Applebot-Extended, Bingbot AI, DuckAssistBot, YouBot, omgilibot, omgili, AI2Bot, ImagesiftBot, Diffbot, ChatGPT-User, Cohere-ai, FriendlyCrawler, Kagibot, Magpie-Crawler, Scrapy, Webzio-Extended, and 2 more. Auto-syncs your choices to `robots.txt` — both the WordPress virtual `robots.txt` filter AND a physical `ABSPATH/robots.txt` if another plugin is intercepting the URL. Plus Content Signals (`ai-train`, `search`, `ai-input` directives per [contentsignals.org](https://contentsignals.org)).

## Compatible with 17 Cache Plugins (LiteSpeed, WP Rocket, Cloudflare APO)

RankReady persists cache-bypass entries to each cache plugin's stored configuration — so server-level caches (LiteSpeed Web Server, FastCGI cache, WP Super Cache mod_rewrite mode) honour the bypass **before PHP runs**. Tested with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer, Hummingbird, Cache Enabler, Comet Cache, Swift Performance, NitroPack, Perfmatters, Cloudflare APO, Pantheon Edge, Kinsta Edge, and WP Engine. Copy-ready `.htaccess` and `nginx` snippets for advanced bypass live in **Settings → Diagnostics**.

## Multilingual llms.txt for WPML, Polylang, TranslatePress, Weglot

Auto-detects WPML, Polylang, TranslatePress, Weglot, and GTranslate. Emits `hreflang` Link HTTP headers for each detected language variant so AI crawlers discover the translated copies of your content alongside the canonical English version.

## Diagnostics: 26 Live Endpoint Probes

The **Diagnostics** card in Settings runs 26 live probes — fetches `/llms.txt`, `/llms-full.txt`, `/.well-known/mcp.json`, every Markdown route, detects active SEO plugins, checks rewrite rules, tests REST routes, scans for cache-plugin conflicts, inspects edge cache HIT/MISS headers, and lists any `template_redirect` callbacks at priority < 5 that might race RankReady's handlers (Bricks Builder, Oxygen, Cwicly). Every failure ships with a one-line fix. One-click copy of a plaintext diagnostic report for support tickets.

## Works Alongside Your Existing SEO Plugin

RankReady is **designed to coexist** with the SEO plugin you already use. It detects active SEO plugins and either skips its own output (when there'd be a duplicate) or merges its data into theirs:

* **Rank Math** — Person and Article schema fields merge into Rank Math's existing JSON-LD graph via filters.
* **Yoast SEO** — Same merge pattern. RankReady never emits duplicate Person nodes when Yoast is active.
* **All in One SEO (AIOSEO)** — Same merge pattern.
* **SEOPress, SEO Framework, Slim SEO** — Coexists; RankReady supplies AI-specific fields none of them cover.

You don't replace your SEO plugin. You add RankReady on top.

## Lightweight: Zero Frontend Impact

All AI generation happens in the WordPress admin — never on page load. Schema and discovery headers add a few hundred bytes. The llms.txt and robots.txt files are cached via a 10-minute transient with `stale-while-revalidate`. Frontend impact: zero. Page Speed Insights and Core Web Vitals are unaffected.

== More Plugins from POSIMYTH ==

RankReady is part of the POSIMYTH Innovations product family. If you build WordPress sites, you'll probably want these too:

* **[The Plus Addons for Elementor](https://theplusaddons.com/?ref=rankreadyreadme)** — 120+ premium Elementor widgets with Smart Animations, Carousels, and advanced filters. Powers 500,000+ sites.
* **[Nexter Blocks – Theme & Extension](https://nexterwp.com/?ref=rankreadyreadme)** — The fast, AI-ready Gutenberg block library and theme framework. Built for Core Web Vitals.
* **[UiChemy – Figma to WordPress](https://uichemy.com/?ref=rankreadyreadme)** — Convert any Figma design into responsive Elementor or Gutenberg layouts in one click. No code.
* **[WDesignKit](https://wdesignkit.com/?ref=rankreadyreadme)** — A growing library of pre-built websites, pages, blocks, and templates for Elementor and Gutenberg.
* **[SproutOS](https://sproutos.com/?ref=rankreadyreadme)** — The AI-native content operating system. Plan, draft, brief, and publish at scale.

== Privacy & Third-Party Services ==

RankReady is privacy-respecting by default. POSIMYTH does not collect, store, or transmit any data from your site. No telemetry. No analytics. No "phone home". Your API keys are stored only in your own `wp_options` table.

The plugin contacts third-party services **only** when you explicitly enter API credentials AND trigger a generation action. Each service is opt-in and uses **your own API key**:

* **OpenAI** ([Terms of Use](https://openai.com/policies/terms-of-use) · [Privacy Policy](https://openai.com/policies/privacy-policy)) — When you generate an AI Summary or FAQ with OpenAI as your provider, the post's title and body text are sent to `https://api.openai.com/v1/chat/completions` using your own API key. The generated response is stored as post meta on your site. Nothing is sent without an explicit click from you.
* **Anthropic Claude** ([Terms of Use](https://www.anthropic.com/legal/consumer-terms) · [Privacy Policy](https://www.anthropic.com/legal/privacy)) — When Anthropic is your provider, the same post text is sent to `https://api.anthropic.com/v1/messages` using your own API key. Same opt-in trigger; same one-shot use.
* **Google Gemini** ([API Terms](https://ai.google.dev/terms) · [Privacy Policy](https://policies.google.com/privacy)) — When Gemini is your provider, the same post text is sent to `https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent` using your own API key.
* **DeepSeek** ([Terms of Use](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html) · [Privacy Policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)) — When DeepSeek is your provider, the same post text is sent to `https://api.deepseek.com/chat/completions` using your own API key.
* **DataForSEO** ([Terms of Service](https://dataforseo.com/terms-of-service) · [Privacy Policy](https://dataforseo.com/privacy-policy)) — When you trigger the FAQ Generator, the post's focus keyword is sent to `https://api.dataforseo.com/v3/serp/google/organic/live/advanced` using your own DataForSEO Login plus Password. Only the keyword string is sent, not the article text. Discovered questions are stored as post meta on your site.

No other endpoints are contacted. The plugin never sends any data on its own initiative — every outbound request is the direct result of an administrator action.

== Installation ==

= Easy install (recommended) =

1. In WordPress admin, go to **Plugins → Add New**.
2. Search for **"RankReady"**.
3. Click **Install Now**, then **Activate**.
4. Visit **RankReady** in the admin menu.
5. Add your AI provider API key (OpenAI, Anthropic, Gemini, or DeepSeek) in the **Settings** tab.
6. Optionally enable llms.txt, Markdown endpoints, and AI crawler controls in the **AI Crawlers** tab.

= Manual install =

1. Download the plugin zip from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin** and select the zip.
3. Activate, then follow steps 4 to 6 above.

= After install =

* Visit your site at `/llms.txt` to confirm the llms.txt file is being served.
* Open any post and use the **AI Summary** meta box to generate your first summary.
* Add the **RankReady Author Box** Gutenberg block (or Elementor widget) to a post to display the author bio.

== Frequently Asked Questions ==

= Will RankReady conflict with Rank Math, Yoast, or AIOSEO? =

No. RankReady is designed to work **alongside** Rank Math, Yoast, All in One SEO, SEOPress, SEO Framework, and Slim SEO. Before injecting any schema, it checks if another schema-generating plugin is active. If yes, it skips its own output or merges fields into the existing schema graph via documented filters. Verifiable with Google's Rich Results Test — no duplicate Article, Person, or FAQPage nodes.

= How does RankReady actually work? =

Three layers: (1) it serves **discovery files** (`/llms.txt`, `/llms-full.txt`, `/.well-known/mcp.json`, `/post-slug.md`) that AI crawlers read to find your content faster; (2) it adds **AI-specific schema** (FAQPage, Speakable, Article, HowTo, ItemList JSON-LD) that AI Overviews preferentially cite; (3) it gives you **controls** over which AI bots see your content, plus analytics on which ones already do. All three layers add up to roughly a 28-3× increase in AI citation rates per multiple 2026 studies.

= Will this slow down my site? =

No. All AI generation happens in the WordPress admin (not on page load). Schema and discovery headers add a few hundred bytes per page. The llms.txt and robots.txt files are cached via a 10-minute transient with `stale-while-revalidate`. Page Speed Insights and Core Web Vitals: unaffected.

= Do I need an AI provider API key? =

Only if you want to use the **AI Summary** or **FAQ Generator** features. The llms.txt generator, Markdown endpoints, AI crawler controls, Article schema, Author Box, AI referral tracking, content freshness scanner, and WebMCP manifest all work without any API key.

= Are there usage limits or monthly caps? =

**No caps.** Manual AI Summary generation and FAQ generation are unlimited in v1.0.0. You pay only your own LLM API usage (typically $0.001 to $0.01 per generation). All other features are completely free with no limits.

= Which AI provider should I pick? =

All four work great. Practical guidance:

* **OpenAI** (`gpt-4o-mini`, `gpt-5`) — Best all-rounder, widest model choice, predictable output. Recommended default. Pay-as-you-go at platform.openai.com.
* **Anthropic Claude** (`claude-sonnet-4`, `claude-opus-4`) — Strongest at long-form summaries and faithful citations. Recommended for long posts (3,000+ words). Console at console.anthropic.com.
* **Google Gemini** (`gemini-2.5-flash`, `gemini-2.5-pro`) — Generous free tier (up to 1,500 requests/day on Flash). Recommended to test before paying. Get a key at aistudio.google.com.
* **DeepSeek** (`deepseek-v4-flash`, `deepseek-v4-pro`) — Cheapest paid option, open-source models. Recommended for high-volume sites. Sign up at platform.deepseek.com.

You can switch providers at any time without losing existing summaries or FAQs.

= How do I set up DataForSEO for the FAQ Generator? =

The FAQ Generator uses DataForSEO to discover real "People Also Ask" questions for each post's focus keyword. Setup walkthrough:

1. Create a DataForSEO account at [dataforseo.com/register](https://dataforseo.com/register). The first $1 of credit is free for new sign-ups — enough for ~200 keyword lookups.
2. After confirming your email, log in to the [DataForSEO dashboard](https://app.dataforseo.com).
3. Go to **Settings → API Access**. Copy your **Login** (your account email) and **Password** (an API password DataForSEO generates separately from your dashboard login).
4. In WordPress, go to **RankReady → Settings**. Scroll to the **DataForSEO** card.
5. Paste the Login and Password fields. Click **Verify credentials** — RankReady performs a live test query and shows your remaining account balance.
6. Open any post, scroll to the **RankReady FAQ** meta box, enter a focus keyword, and click **Generate questions**. DataForSEO returns 5 to 10 real Google "People Also Ask" questions for that keyword.
7. Pick which questions to keep, then click **Generate answers** to have your chosen LLM write the answers. Final FAQPage JSON-LD is auto-injected into the post.

**Cost per FAQ**: about $0.002 per keyword lookup at DataForSEO (the typical 5-question pull), plus your LLM cost for the answer generation. A 5-question FAQ usually costs under one cent total.

**Don't want to use DataForSEO?** You can manually enter FAQ questions in the meta box and skip the DataForSEO step entirely — the answer generation works with any LLM provider on its own.

= What is an "llms.txt" file? =

`llms.txt` is an emerging standard ([llmstxt.org](https://llmstxt.org)) that lets AI models like ChatGPT, Perplexity, and Claude understand your site's structure faster. Think of it as an "AI sitemap" — a curated index of your most important content optimized for LLM consumption. RankReady generates both `/llms.txt` (index) and `/llms-full.txt` (full content) automatically.

= What is WebMCP and the `.well-known/mcp.json` manifest? =

[Model Context Protocol (MCP)](https://modelcontextprotocol.io/) is Anthropic's open standard for letting AI agents discover and call your site's structured content. RankReady publishes a manifest at `/.well-known/mcp.json` listing what an AI agent can do — read posts, list authors, fetch FAQs, query categories. Claude Desktop and Cursor users add your site as an MCP server by pointing at this file. Toggle individual abilities in **AI Crawlers → WebMCP**.

= How does the freshness scanner work? =

In **Insights → Content Fresh**, click **Scan Content Freshness**. RankReady reads every post's `post_modified` date and buckets them into **Stale** (60+ days), **Going stale** (30-59 days), and **Fresh** (under 30 days). Tick the boxes next to stale posts, click **Refresh dateModified**, and RankReady updates the modified timestamp without changing your content. This signals recency to AI crawlers on their next visit.

= Does this work with my caching plugin or Cloudflare? =

Yes. RankReady is tested with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer, Hummingbird, Comet Cache, Cache Enabler, Swift Performance, NitroPack, Perfmatters, Cloudflare APO, Pantheon, Kinsta, and WP Engine. The plugin persists cache-bypass entries to each cache plugin's stored configuration so server-level caches honour the bypass before PHP runs. If your CDN still caches stale `/llms.txt`, copy the `.htaccess` or `nginx` snippet from **Settings → Diagnostics → Server bypass snippets** and add it to your server config.

= Why is my Cloudflare edge serving a stale `/llms.txt`? =

If you're on Cloudflare (especially with APO or a "Cache Everything" page rule), the edge can hold `/llms.txt` for up to 30 days. RankReady v1.0.0 sets `s-maxage=600`, `CDN-Cache-Control`, and `Cloudflare-CDN-Cache-Control` headers so the edge respects a 10-minute TTL. After updating, purge `/llms.txt` once in Cloudflare → Caching → Custom Purge by URL to flush any previously-cached version. Future updates auto-purge.

= Where is my data stored? =

Everything stays on your own WordPress site. Your API keys, DataForSEO credentials, generated summaries, FAQs, and author profiles all live in your own `wp_options` and `wp_postmeta` tables. POSIMYTH does not see, collect, or transmit any of your data.

= How do I check if it is actually working? =

Open **Settings → Diagnostics** and click **Run Diagnostics**. RankReady performs 26 live probes — fetches `/llms.txt`, `/llms-full.txt`, `/.well-known/mcp.json`, every Markdown route, detects active SEO plugins, checks rewrite rules, tests REST routes, scans for cache-plugin conflicts, and inspects edge cache headers. Every failure ships with a one-line fix. Click **Copy Diagnostic Report** for a full plain-text bundle you can paste into support requests.

= How do I uninstall it cleanly? =

By default, RankReady **preserves your data on uninstall** — your settings, API keys, summaries, FAQ data, and author profiles all survive. If you want a complete wipe, enable the "Delete all data on uninstall" toggle in the **Advanced → Tools** tab before uninstalling.

= Is the source code available? =

Yes. RankReady is open source under GPL-2.0-or-later. The complete source ships in the plugin zip on WordPress.org, and product info lives at [posimyth.com](https://posimyth.com/?ref=rankreadyreadme).

== Screenshots ==

1. **AI SEO Dashboard for WordPress** — AI Readiness score at a glance, quick-navigation tiles, persistent right sidebar with What's New, community links, and a 5-star rating widget.
2. **AI Summary & FAQ Schema Generator** — Pick your LLM provider, set auto-generate-on-publish rules, and bulk-regenerate across your library.
3. **E-E-A-T Schema & Author Box** — Author Box configuration plus Article, Speakable, FAQPage, HowTo, ItemList JSON-LD toggles that coexist with Rank Math, Yoast, and AIOSEO.
4. **AI Crawler Controls + llms.txt Generator** — 31-bot allow/block matrix with Markdown endpoints, WebMCP manifest, and Content Signals directives auto-synced to robots.txt.
5. **AI Citation Tracking & Bot Insights** — Bot Activity, AI Citation Candidates, Real AI Referrals, and Content Freshness scanner.
6. **Connect OpenAI, Claude, Gemini & DataForSEO** — Single-screen config for all four LLM providers plus DataForSEO credentials and Diagnostics with 26 live endpoint probes.



== Changelog ==

= 1.0.0 — 2026-05-26 =

**First public release — RankReady is live on WordPress.org.**

Welcome! RankReady is the first WordPress plugin built end-to-end for the AI search layer — ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews. Everything in this release is yours, free, with no caps on the core generators.

* **Unlimited AI Summaries** — generate "Key Takeaways" for every post via OpenAI, Anthropic, Gemini, or DeepSeek with zero per-month limit on manual generation.
* **FAQ schema generator** — discover real user questions via DataForSEO, answer via your chosen LLM, output FAQPage JSON-LD that AI Overviews preferentially cite.
* **llms.txt + Markdown endpoints** — serve the llmstxt.org standard at `/llms.txt`, `/llms-full.txt`, and every post as Markdown at `/post-slug.md` for AI agents.
* **31+ AI crawler controls** — granular allow/block for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, and 26 more, auto-synced to `robots.txt`.
* **E-E-A-T schema + Author Box** — Article, Speakable, and Author JSON-LD signals out of the box, designed to coexist with Rank Math, Yoast, AIOSEO without duplicate output.
* **Content Freshness scanner** — bucket posts into Stale / Going Stale / Fresh, bulk-refresh `dateModified` to signal recency to AI crawlers.
* **Insights dashboard** — Training Bots, Citation Bots, Real AI Referrals, and Content Fresh analytics with demo-data preview.
* **Cache compatibility** — first-class support for WP Rocket, LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer, Hummingbird, Cache Enabler, Comet Cache, Swift Performance, NitroPack, Perfmatters, Cloudflare APO, Pantheon, Kinsta, WP Engine.
* **Multilingual llms.txt** — auto-detects WPML, Polylang, TranslatePress, Weglot, GTranslate; emits hreflang Link headers.
* **Diagnostics suite** — 26 live endpoint probes, plugin-conflict detection, and copy-ready Apache/Nginx server-bypass snippets.

Welcome to RankReady! Feedback or questions? Visit [store.posimyth.com/plugins/rankready](https://store.posimyth.com/plugins/rankready/?ref=rankreadyreadme).

For the full pre-1.0.0 development history, see CHANGELOG.md bundled with the plugin.

== Upgrade Notice ==

= 1.0.0 =
First public release on WordPress.org. Unlimited AI Summaries + FAQ generation, llms.txt + Markdown endpoints, 31+ AI crawler controls, E-E-A-T schema, Insights analytics, content freshness scanner, 17 cache layer integrations.
