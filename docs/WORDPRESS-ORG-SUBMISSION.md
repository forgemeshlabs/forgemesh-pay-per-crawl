# wordpress.org submission packet (operator steps)

Everything below is ready; only the account and the submit button need you.

## 1. Account
Create a wordpress.org account at https://login.wordpress.org/register with hello@forgemesh.io,
username `forgemeshlabs` (matches `Contributors:` in readme.txt). Display name: ForgeMesh Labs.
Do not use a personal name anywhere on the profile.

## 2. Submit
https://wordpress.org/plugins/developers/add/
- Upload `forgemesh-pay-per-crawl-0.1.0.zip` (from `./build.sh` or the GitHub release).
- The page shows the current estimated review wait. Reviews come by email to hello@; reply in the
  same thread with fixes (re-upload the zip there, not a new submission).
- Slug requested: `forgemesh-pay-per-crawl`.

## 3. What reviewers check (already handled in the code)
- GPLv2+ license header and LICENSE file.
- Unique prefix `fmppc_` / `FMPPC_` on every function, class, option, table, transient, hook.
- All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), all input sanitized
  (settings sanitize callback, nonces on export, capability `manage_options`).
- Third-party service disclosure in readme.txt ("Third-party services"): PayAI facilitator URL,
  terms, privacy, what is sent. If they ask for more, add the exact payload fields
  (signed authorization, your wallet, amount, page URL).
- No calls home. The plugin never contacts forgemesh.io; the only link is the "List on ForgeMesh"
  button the admin clicks.
- No tracking, no external scripts or styles, no minified code without source.
- `uninstall.php` removes the table, options and transients.
- "Tested up to" in readme.txt must be the current WordPress major (7.1 now; bump on each release).
- Direct DB queries are prepared and carry `phpcs:ignore` comments; reviewers accept this for a
  custom table.

## 4. After approval
They email SVN access. Release flow:
```
svn co https://plugins.svn.wordpress.org/forgemesh-pay-per-crawl wp-svn
rsync -a --exclude-from=.distignore ./ wp-svn/trunk/
cp docs/screenshots/dashboard.png wp-svn/assets/screenshot-1.png
cp docs/screenshots/settings.png  wp-svn/assets/screenshot-2.png
cd wp-svn && svn add --force . && svn cp trunk tags/0.1.0 && svn ci -m "0.1.0"
```
Banner (772x250 and 1544x500) and icon (256x256) go in `assets/` as `banner-772x250.png`,
`banner-1544x500.png`, `icon-256x256.png`; make them nameless (ForgeMesh mark only).

## 5. Common first-review requests and the answer
- "Why does it need a remote service?" The facilitator submits the payer's USDC authorization on
  Base; the plugin cannot do that itself and holds no keys. The site owner may use any facilitator.
- "Is the free version crippled?" No: every feature works with no account, key or subscription.
- "Data sent to third parties?" Only on a presented payment: the signed authorization, payTo,
  amount, page URL. No visitor data. Documented in readme.
