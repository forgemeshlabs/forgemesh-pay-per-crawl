=== ForgeMesh Pay-per-Crawl ===
Contributors: forgemeshlabs
Tags: ai crawlers, bots, paywall, x402, analytics
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bot ledger plus an HTTP 402 price tag: see every AI, SEO and script crawler, keep search engines free, charge x402-capable agents in USDC on Base.

== Description ==

Pay-per-Crawl shows you every AI crawler, SEO crawler and script that fetches your pages, verifies the real search engines by reverse DNS so SEO is untouched, and lets you answer everything else with a standard HTTP 402 price in USDC on Base, paid to your own wallet. Be clear about what pays today: x402-capable agents do; the big AI labs' crawlers (GPTBot, ClaudeBot, PerplexityBot and friends) do not yet pay anyone per page, and they simply get no content from a charging site. Until they do, this is an honest bot ledger with the price already on the door. You see who takes what, you can block or charge any of it, and when a crawler does pay, it goes straight to you. Nobody in between holds the money. Humans and verified search engines read free.

* **Humans see nothing different.** Browsers get the page as always.
* **Verified search engines stay free.** Googlebot, Bingbot, DuckDuckBot, Applebot and friends are checked by reverse DNS so your SEO is untouched. A crawler that only *claims* to be Googlebot is treated as a script.
* **AI crawlers, SEO crawlers and scripts get HTTP 402 Payment Required.** The response carries the x402 challenge (v2 header and v1 body, so every current client can pay) and an MPP challenge. An x402-capable client pays USDC on Base and retries; a crawler that cannot pay gets no content; the page is served only after the payment is settled on-chain.
* **You keep 100%.** The payer's USDC goes directly to the wallet you enter. Nothing is held by the plugin, by ForgeMesh, or by anyone else.
* **Bot analytics in wp-admin.** Who is crawling (by name and class), how often, from how many sources, which paths, what was paid, every settlement with its transaction link. Start in *Monitor* mode and see what the bots would have paid before you charge anything.
* **Hosted account (optional, free).** A hosted dashboard for up to 5 sites, a crawler-intelligence feed that keeps the bot list current, and a listing in the paid-sites directory. Everything above works without it.
* **Pro (optional, $4.99/month on the same key).** Pricing rules by bot and content group, a revenue report with a settlements CSV, daily paywall monitoring with alerts, and a Monday digest.
* **Bot directory.** Every crawler the plugin knows, explained for humans: who runs it, what it does with your pages, how it is verified, its robots.txt line, and what your site currently does with it. Searchable, grouped by purpose, and it grows as new bots appear on your site.
* **Discovery built in.** The plugin serves `/.well-known/x402.json`, `/llms.txt` and `/agents.txt` and adds lines to `robots.txt`, so paying agents and x402 indexes find your paid pages.

= How a payment works =

This is what happens when an x402-capable client fetches a page.

1. A bot requests a page and gets `402 Payment Required` with the price, asset (USDC on Base) and your wallet.
2. The bot signs a USDC transfer authorization and retries with it in a header.
3. The plugin sends that authorization to an x402 **facilitator**, which checks it and submits it on-chain. The facilitator never holds funds.
4. The plugin serves the page only when the facilitator returns a transaction hash, and records the hit, the payer and the hash.

= Third-party services =

The plugin makes outbound requests only when a payment is presented, and only to the x402 facilitator configured in Settings:

* **PayAI facilitator** (default): `https://facilitator.payai.network` — [terms](https://payai.network/terms) · [privacy](https://payai.network/privacy). It receives the payer's signed authorization and the payment terms (your wallet address, amount, the page URL). No visitor data, no site content.
* Or **any facilitator URL you enter**, including one you run yourself.

* **ForgeMesh Pay-per-Crawl Pro** (only after you enter a Pro license key): `https://proxy.forgemesh.io` — [terms](https://proxy.forgemesh.io/terms) · [privacy](https://proxy.forgemesh.io/privacy). The plugin sends your license key, the site's host, name, mode, price and payout wallet, daily per-bot counts (bot name, class, hits, paid, revenue, number of distinct hashed sources) and settlement records (transaction hash, payer wallet, path, amount). No visitor IPs, no page content, no human user agents. It pulls a crawler list from the same service once a day. Without a Pro key or the Crawler Atlas opt-in (off by default), the plugin never contacts ForgeMesh.

Search-engine verification uses ordinary DNS lookups.

= Privacy =

Visitor IP addresses are never stored. Bot sources are counted by a daily-salted hash. Human page views are stored only as a count (class "human", no user agent) when that option is on. Rows are deleted after the retention period you set.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Pay-per-Crawl → Settings**, paste your payout wallet (a 0x address on Base, for example from Coinbase Wallet or MetaMask), choose a price, save.
3. Watch **Pay-per-Crawl → Bot analytics** for a day or two in Monitor mode.
4. Switch the mode to **Charge**.

If your site is behind Cloudflare or another proxy, set the *Client IP header* so search-engine verification sees the real client address. Choose a header your proxy sets itself (CF-Connecting-IP, X-Real-IP, True-Client-IP); with X-Forwarded-For the plugin uses the right-most address, the one your proxy appended.

== Frequently Asked Questions ==

= Will this hurt my Google ranking? =

No. Verified search engines are free by default and are checked by reverse DNS, the same method Google documents for verifying Googlebot. Social link previews and uptime monitors are also free by default.

= What do bots pay with? =

USDC on the Base network, using the x402 protocol (HTTP 402) or MPP. Payers sign a transfer authorization; a facilitator submits it on-chain. When a payment is made, the USDC arrives in your own wallet within seconds.

= Do I need a Coinbase account or an API key? =

No. The default facilitator needs no account. You only need a wallet address.

= Can a bot read the page without paying? =

Not through WordPress: the 402 is answered before any template runs, and the page body is empty. Pages cached by a CDN or a full-page cache plugin may be served to bots from the cache; exclude bot user agents from that cache or put the cache behind the plugin.

= Does it work with caching plugins? =

The 402 is sent with `Cache-Control: no-store`, and paid responses are marked private. Full-page caches that serve HTML before WordPress loads will bypass the gate for cached pages.

= What if the facilitator is down? =

Payers get a 402 with the reason, can retry, and are never charged. Humans are never affected.

== Screenshots ==

1. Bot analytics: hits per day, who is crawling, paid fetches and revenue.
2. Settings: wallet, price, mode, and which classes pay.
3. A 402 challenge as a bot sees it.
4. Bot directory: every known crawler grouped by purpose, searchable, with who runs it, its robots.txt token, verification and what your site does with it.
5. Pricing rules (Pro): charge, free or block by post type, category, path, bot name, purpose or class, each with its own price.
6. AI Crawler Audit: summary of operators, bandwidth and value, with the one-time unlock for the full report.

== Changelog ==

= 0.2.5 =
* Settings: the payout wallet field and the first-run notice link to a wallet setup guide (seed phrase safety, MetaMask step by step, cold wallets).

= 0.2.4 =
* Hosted account is now free: dashboard, crawler-intel feed, directory listing and badge need a key but no subscription. Pro ($4.99/month, same key) keeps pricing rules, the revenue report with CSV, paywall monitoring and the Monday digest.
* Pricing rules are enforced only while the key is on a Pro plan; a free key keeps the single site price. The license check now records the plan.
* Settings section 5 renamed to Hosted account with the two tiers spelled out.

= 0.2.3 =
* Settings: plain-language descriptions for the Client IP header (what each header is, who sets it, why verification depends on it) and the facilitator Authorization header; warns when the site is behind a proxy but no header is selected, or the selected header is absent.
* Analytics: shows how many agents the built-in bot list knows and when it was last revised; Pro shows when the crawler feed last refreshed.
* Wording: "Without a Pro key or the Crawler Atlas opt-in (off by default), the plugin never contacts ForgeMesh."
* Pro: pricing rules. Charge, free or block by post type, category, tag, path, bot name, bot purpose or bot class, each with its own price; first match wins; content price groups are advertised as separate manifest entries.
* AI Crawler Audit page (free summary; $19 one-time unlock verified offline).
* Bot directory: searchable, grouped reference of every known crawler with this site's own sightings and policy; grows from the shipped list, the Pro feed and the ledger.
* Honest positioning: the plugin is a bot ledger with an x402 price tag; the big AI labs' crawlers do not pay per page yet.

= 0.2.2 =
* Security: exempt-path matching is anchored. Look-alike paths (/robots.txt.x, /sitemapfoo, /.well-known/../post, an allow path /pricing matching /pricing-secret) no longer bypass the paywall; dot segments and %2e / %2f paths are gated.

= 0.2.1 =
* Security: with a multi-value client-IP header (X-Forwarded-For), use the right-most address so a crawler cannot prepend a search-engine IP and bypass the paywall.

= 0.2.0 =
* Hosted account (free key): hourly sync to the hosted dashboard, crawler-intel feed merged into the classifier, directory opt-in. Pro (same key, $4.99/month): pricing rules, revenue report, paywall monitoring, digest.
* CSV export neutralises formula-leading cells.

= 0.1.0 =
* First release: classifier with 80+ known agents, x402 v1 and v2 plus MPP challenges, facilitator verify/settle, discovery files, bot analytics, CSV export.
