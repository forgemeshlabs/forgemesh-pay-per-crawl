# ForgeMesh Pay-per-Crawl — design (2026-10-16)

## Intent
A GPL WordPress plugin that answers HTTP 402 (x402 + MPP) to bot traffic on the site's own
domain, lets humans and verified search engines through untouched, and pays the site owner
100% of every settlement directly to their wallet. ForgeMesh is never in the payment path:
the plugin talks to an x402 facilitator itself (PayAI by default, no key; any facilitator URL
can be set). ForgeMesh's revenue is a later Pro/cloud tier (hosted analytics, crawler
intelligence feed, verified listing), not a cut of settlements and not facilitator fees.

## Request flow
1. `template_redirect` (front end) and `rest_pre_dispatch` (REST, optional): skip admin,
   login, cron, AJAX, logged-in users, allow-listed paths, discovery files.
2. Classifier: UA + headers -> class (human | search | social | monitor | seo | ai |
   automation | bot) + bot name. `search` is verified by reverse+forward DNS (cached).
   A UA claiming a search engine that fails verification is `automation`.
3. Policy per class (and per-bot override): free | paid | block. Defaults: human, search,
   social, monitor free; ai, seo, automation, bot paid. Mode `monitor` logs but never gates.
4. Paid + no payment header -> 402: `PAYMENT-REQUIRED` (v2 envelope, base64), JSON body in v1
   format (stock x402-fetch 1.x), `WWW-Authenticate: Payment` (MPP), `Link` to the manifest,
   `Cache-Control: no-store`. Same for HEAD.
5. Payment header present (`PAYMENT-SIGNATURE` v2, `X-PAYMENT` v1, or
   `Authorization: Payment` MPP) -> validate shape against our terms, reject replayed nonces,
   POST /verify then /settle to the facilitator. The page is served only on a non-empty
   transaction hash; receipts go out as `PAYMENT-RESPONSE`, `X-PAYMENT-RESPONSE`,
   `Payment-Receipt`. Rejections are 402 with `invalidReason` in the body.
6. Every decision is one row in `{prefix}fmppc_hits` (class, bot, verified, path, status,
   amount, tx, payer, rail, ip hash). Retention is configurable; daily cron prunes.

## Discovery on the site's domain
`/.well-known/x402.json` (v2 manifest, one resource = the site's pages), `/llms.txt`,
`/agents.txt`, `robots.txt` additions. Enough for x402 indexes and ForgeMesh's unclaimed
crawler to list the site; "List on ForgeMesh" in the admin links to the Verified submit.

## Admin
Dashboard: totals (today / 7d / 30d), hits by bot (class, verified, hits, paid, revenue),
top paths, recent settlements with explorer links, daily bar chart, monitor-mode projection.
Settings: mode, wallet, price, facilitator, class policy, per-bot overrides, scope, retention.
Export: CSV via admin-post with nonce.

## Not in v1
Pro/cloud tier, CDP-keyed facilitator (JWT signing), per-path prices, Solana.
