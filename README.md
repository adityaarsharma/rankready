<div align="center">

# RankReady — llms.txt, .md & AI SEO for ChatGPT, Perplexity, Claude, Gemini

### Get cited by ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews — without conflicting with your existing SEO plugin.

[![WordPress.org Plugin Version](https://img.shields.io/wordpress/plugin/v/rankready-ai-llm-seo?label=WordPress.org&style=for-the-badge&color=10b981)](https://wordpress.org/plugins/rankready-ai-llm-seo/)
[![WordPress.org Downloads](https://img.shields.io/wordpress/plugin/dt/rankready-ai-llm-seo?style=for-the-badge&color=10b981)](https://wordpress.org/plugins/rankready-ai-llm-seo/)
[![WordPress.org Rating](https://img.shields.io/wordpress/plugin/rating/rankready-ai-llm-seo?style=for-the-badge&color=10b981)](https://wordpress.org/plugins/rankready-ai-llm-seo/#reviews)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/rankready-ai-llm-seo?style=for-the-badge&color=10b981)](https://wordpress.org/plugins/rankready-ai-llm-seo/)
[![PHP Required](https://img.shields.io/wordpress/plugin/required-php/rankready-ai-llm-seo?style=for-the-badge&color=10b981)](https://wordpress.org/plugins/rankready-ai-llm-seo/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2+-blue.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0)

**[Download from WordPress.org →](https://wordpress.org/plugins/rankready-ai-llm-seo/)** · **[Official Page](https://store.posimyth.com/plugins/rankready/?ref=github)** · **[Support Forum](https://wordpress.org/support/plugin/rankready-ai-llm-seo/)**

</div>

---

## Full Walkthrough

<div align="center">

[![Watch the RankReady walkthrough on YouTube](https://img.youtube.com/vi/JA-rEwMbqNo/maxresdefault.jpg)](https://www.youtube.com/watch?v=JA-rEwMbqNo "Watch the RankReady walkthrough")

▶ **[Watch on YouTube](https://www.youtube.com/watch?v=JA-rEwMbqNo)**

</div>

---

## The Problem: AI Search Is Eating SEO Traffic

**40–55% of AI citations go to fewer than 1,000 domains.** If your site isn't on that list, ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews are answering your buyers' questions with someone else's content — and you'll never see the click.

Traditional SEO plugins (Rank Math, Yoast, AIOSEO) optimize for Google's blue-link results. They were built for a web where users clicked through. **That web is shrinking.** RankReady is the layer *above* your SEO plugin — handling the AI-specific signals (llms.txt, FAQPage schema, Markdown endpoints, E-E-A-T, Speakable, WebMCP, AI crawler controls) that decide whether you're the source AI quotes or the site it never read.

## How It Works: Add Once, Coexists Forever

Install RankReady, pick your LLM provider (OpenAI, Anthropic, Gemini, or DeepSeek), and the plugin handles the rest. It auto-detects your active SEO plugin and **never emits duplicate schema**. Your existing Yoast or Rank Math setup keeps working exactly as before.

**Frontend impact: zero.** All AI generation runs in the WordPress admin. No API calls on page load. No third-party scripts. No extra HTTP requests for your visitors.

---

## Features

### llms.txt + llms-full.txt Generator
Serves the [llmstxt.org](https://llmstxt.org) standard at `/llms.txt` (curated index) and `/llms-full.txt` (concatenated Markdown). AI crawlers read these first — think of it as an AI-native sitemap. Configurable post types, max post count, category/tag exclusions, per-domain brand identity.

### AI Summary Generator with Speakable Schema
Generate "Key Takeaways" via OpenAI, Anthropic Claude, Google Gemini, or DeepSeek. Auto-injects with Speakable JSON-LD that Google Assistant, Alexa, and AI voice assistants read aloud. Unlimited manual generations. Set auto-generate-on-publish to cover new posts automatically. Bulk-regenerate across your library.

### FAQ Schema Generator with DataForSEO
Queries DataForSEO for real "People Also Ask" questions ranking for your post's focus keyword, then your chosen LLM writes the answers. Output is FAQPage JSON-LD — the schema Google AI Overviews and Perplexity **preferentially cite** over plain article text. Pages with FAQPage schema are 3.2× more likely to appear in AI Overviews.

### E-E-A-T Schema and Author Box for AI Trust Signals
E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) is what AI models use to decide which sources to cite. RankReady ships a full Author Box (photo, bio, headline, topics, credentials, year-started) plus Article, Speakable, FAQPage, HowTo, and ItemList JSON-LD. Auto-detects Rank Math, Yoast, AIOSEO — skips duplicate output, or merges into their schema graph via filters.

### Markdown Endpoints + WebMCP Manifest for AI Agents
Every post served as clean Markdown at `/post-slug.md` with YAML frontmatter (title, author, dates, schema). AI agents read Markdown 10× faster than HTML. Content negotiation via `Accept: text/markdown` lets crawlers fetch the format they prefer.

Plus a [Model Context Protocol](https://modelcontextprotocol.io/) manifest at `/.well-known/mcp.json` listing what AI agents can do on your site — read posts, list authors, fetch FAQs, query categories. Claude Desktop and Cursor users add your site as an MCP server by pointing at this file.

### AI Citation Tracking and Bot Activity Analytics
The Insights tab gives you four real-time views:

- **Training Bots** — Which AI crawlers indexed which pages (GPTBot, ClaudeBot, Google-Extended, Bytespider, CCBot, and 8 more).
- **Citation Bots** — Which pages were fetched mid-answer (ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, DuckAssistBot).
- **Real AI Referrals** — Humans clicking through from chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com. 100% server-side. No third-party scripts.
- **Content Freshness** — Bulk dateModified refresh.

### Content Freshness Scanner — 28% More AI Citations
Multiple 2026 studies show fresh content earns ~28% more AI citations, and 65% of all AI citations target content updated within the past year. Bucket posts into Stale (60+ days), Going stale (30–59 days), Fresh (under 30 days). One-click bulk `dateModified` refresh signals recency to AI crawlers on their next visit.

### 31 AI Crawler Controls + Auto robots.txt
Granular allow/block toggles for **31 AI bots**: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Bytespider, CCBot, FacebookBot, Meta-ExternalAgent, Applebot-Extended, DuckAssistBot, YouBot, AI2Bot, ImagesiftBot, Diffbot, Cohere-ai, and more. Auto-syncs to both virtual + physical `robots.txt`. Plus Content Signals (`ai-train`, `search`, `ai-input` directives per [contentsignals.org](https://contentsignals.org)).

### Compatible with 17 Cache Plugins
WP Rocket · LiteSpeed Cache · W3 Total Cache · WP Super Cache · WP Fastest Cache · Breeze · SG Optimizer · Hummingbird · Cache Enabler · Comet Cache · Swift Performance · NitroPack · Perfmatters · **Cloudflare APO** · Pantheon Edge · Kinsta Edge · WP Engine.

The plugin persists cache-bypass entries to each cache plugin's stored configuration so server-level caches honour the bypass before PHP runs. Copy-ready `.htaccess` and `nginx` snippets for advanced bypass live in **Settings → Diagnostics**.

### Multilingual llms.txt for WPML, Polylang, TranslatePress, Weglot
Auto-detects WPML, Polylang, TranslatePress, Weglot, and GTranslate. Emits `hreflang` Link HTTP headers for each detected language variant so AI crawlers discover the translated copies alongside the canonical English version.

### Diagnostics — 26 Live Endpoint Probes
The Diagnostics card runs 26 live probes — fetches `/llms.txt`, `/llms-full.txt`, `/.well-known/mcp.json`, every Markdown route, detects active SEO plugins, checks rewrite rules, tests REST routes, scans for cache-plugin conflicts, inspects edge cache HIT/MISS headers, lists any `template_redirect` callbacks at priority < 5 that might race RankReady's handlers (Bricks Builder, Oxygen, Cwicly). Every failure ships with a one-line fix. One-click copy-to-clipboard plaintext report for support tickets.

---

## Installation

### From WordPress.org (recommended)

1. WordPress admin → **Plugins → Add New**
2. Search for **"RankReady"**
3. Click **Install Now**, then **Activate**
4. Visit **RankReady** in the admin menu
5. Add your AI provider API key (OpenAI, Anthropic, Gemini, or DeepSeek) in **Settings**
6. Optionally enable llms.txt, Markdown endpoints, and AI crawler controls in **AI Crawlers**

### Manual install

Download the latest zip from **[WordPress.org → RankReady](https://wordpress.org/plugins/rankready-ai-llm-seo/)** and upload via **Plugins → Add New → Upload Plugin**.

### After install

- Visit your site at `/llms.txt` to confirm the file is being served
- Open any post and use the **AI Summary** meta box to generate your first summary
- Add the **RankReady Author Box** Gutenberg block (or Elementor widget) to a post to display the author bio

---

## Works Alongside Your SEO Plugin

| Plugin | How RankReady coexists |
|---|---|
| **Rank Math** | Person + Article schema merged via filters into Rank Math's existing JSON-LD graph |
| **Yoast SEO** | Same merge pattern — no duplicate Person nodes when Yoast is active |
| **All in One SEO (AIOSEO)** | Same merge pattern |
| **SEOPress · SEO Framework · Slim SEO** | Coexists; RankReady supplies AI-only fields none of them cover |

You **don't replace** your SEO plugin. You add RankReady on top.

---

## More Products from POSIMYTH

| Product | What it does |
|---|---|
| **[The Plus Addons for Elementor](https://theplusaddons.com/?ref=rankreadygithub)** | 120+ premium Elementor widgets · 500,000+ sites |
| **[Nexter Blocks – Theme & Extension](https://nexterwp.com/?ref=rankreadygithub)** | The fast, AI-ready Gutenberg block library + theme framework |
| **[UiChemy – Figma to WordPress](https://uichemy.com/?ref=rankreadygithub)** | Convert any Figma design into Elementor or Gutenberg layouts |
| **[WDesignKit](https://wdesignkit.com/?ref=rankreadygithub)** | Pre-built websites, pages, blocks, and templates for Elementor and Gutenberg |
| **[SproutOS](https://sproutos.com/?ref=rankreadygithub)** | AI-native content operating system. Plan, draft, brief, and publish at scale |

Browse all POSIMYTH GitHub repos → **[github.com/posimyth](https://github.com/posimyth)**

---

## About the Author

Built and maintained by **[Aditya Sharma](https://adityaarsharma.com)** — Marketing & Growth at POSIMYTH Innovations. Building tools for the AI SEO era.

- Website — [adityaarsharma.com](https://adityaarsharma.com)
- GitHub — [github.com/adityaarsharma](https://github.com/adityaarsharma)
- Company — [posimyth.com](https://posimyth.com/?ref=rankreadygithub)

---

## Privacy & Third-Party Services

RankReady is privacy-respecting by default. POSIMYTH does not collect, store, or transmit any data from your site. Your API keys stay in your own `wp_options` table.

Third-party services contacted **only** when you explicitly enter API credentials AND trigger a generation action: [OpenAI](https://openai.com/policies/privacy-policy) · [Anthropic](https://www.anthropic.com/legal/privacy) · [Gemini](https://policies.google.com/privacy) · [DeepSeek](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html) · [DataForSEO](https://dataforseo.com/privacy-policy). Full disclosure in the in-plugin [readme.txt](readme.txt).

---

## License

GPL-2.0-or-later. Full text in [LICENSE](LICENSE).

---

<div align="center">

**[⭐ Star this repo](https://github.com/adityaarsharma/rankready)** if RankReady helps you get cited by AI.

Made by [POSIMYTH](https://posimyth.com/?ref=rankreadygithub) · Crafted by [Aditya Sharma](https://adityaarsharma.com)

</div>
