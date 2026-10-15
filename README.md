# whmcs-fiuu-gateway (GB Network fork)

This is GB Network's fork of the Fiuu (formerly Razer Merchant Services / MOLPay) WHMCS gateway plugin
**Shopping-Cart-Plugins-Fiuu_WHMCS_6.1**, with a small local patch.

Upstream: https://github.com/FiuuPayment/Shopping-Cart-Plugins-Fiuu_WHMCS. All credit for the plugin goes to Fiuu. The
vendor code is published here only to document and share the patch below. If you are from Fiuu and would prefer this
removed, or would like to merge the fix upstream, please open an issue.

## Branch and commit layout
- The first commit, tagged `vendor-6.1`, is the files exactly as deployed on 2026-10-14. They are unmodified vendor
  code, including Fiuu's May 2025 fix that removed `session_start()` from `fiuu_return.php`.
- The commits after it hold GB Network patches. Each patch is small and documented below and in `CHANGELOG.md`.

Install path: `<whmcs root>/modules/gateways/` (keep the web user as owner, mode 644).

| File | Role |
|---|---|
| `rms.php` | Gateway module ("Fiuu"). It builds the POST form to `pay.fiuu.com` with `returnurl` and `callbackurl` (notify). |
| `return/fiuu_return.php` | **Browser return** after payment. Fiuu POSTs the customer back here. **Patched.** |
| `notify/fiuu_notify.php` | Server-to-server payment notification (the `callbackurl`). This is what reliably records payments. |
| `callback/fiuu_callback.php`, `callback/rms_callback.php` | Legacy callback endpoints (unchanged). |

## GB patch 1: keep the customer logged in after paying (2026-10-14)
**Symptom:** after a successful Fiuu payment, *"Go to Merchant online store"* lands on **Secure Client Login**. The
customer is logged out. CHIP is not affected.

**Root cause:**
1. Fiuu returns the customer with a **cross-site POST** from `pay.fiuu.com`.
2. The WHMCS session cookie has no `SameSite` attribute, so browsers treat it as `Lax` and do **not** send it on a
   cross-site POST.
3. `fiuu_return.php` immediately includes `init.php`. WHMCS sees no cookie, creates a new empty session and overwrites
   the customer's login cookie.

Fiuu's 2025 fix only removed an extra `session_start()`. `init.php` still starts a session. CHIP returns via GET,
which carries the cookie, so it is unaffected.

**Fix:** a "same-site bounce" at the very top of `fiuu_return.php`, before `init.php`. On the first, cross-site POST
the script does not load WHMCS. It returns a tiny page that re-submits the same POST fields, plus `gb_bounced=1`, to
the same URL. That second POST comes from our own origin, so the browser sends the session cookie. The original
vendor code then runs unchanged: `skey` verification, duplicate check, `addInvoicePayment`, and the redirect to the
invoice.
- The payment logic and signature checks are untouched, and the notify endpoint is untouched.
- There is a `<noscript>` Continue button, and field names and values are HTML-escaped.
- The page is not cached and is not indexed.

This follows the WHMCS developer guidance (Payment Gateways > Callback Files): record payments via the
server-to-server callback, and return the visitor to the Client Area with a same-site navigation.

**Upstream PR:** https://github.com/FiuuPayment/Shopping-Cart-Plugins-Fiuu_WHMCS/pull/4 (same fix, rebased on upstream
master). Once it is merged and released, a vendor update no longer needs this patch.

**Rollback:** restore the `vendor-6.1` version of `return/fiuu_return.php`.

## Updating the vendor plugin
1. Put the new vendor files on a branch, starting from `vendor-6.1`.
2. Diff them against the deployed files.
3. Re-apply the GB patches.
4. Run `php -l`.
5. Run a RM1 Fiuu test (DuitNow QR) and confirm the customer lands on the Paid invoice while still logged in.


## GB patch 2: charge the current amount, not the page-render amount (2026-10-15)
**Symptom:** invoice set to Fiuu, customer switches the payment method (e.g. Credit Card) and clicks "Make Payment"
before the page reloads. Fiuu opens anyway, the customer pays, and the invoice stays Unpaid with a gateway fee
outstanding. CHIP shows an error instead.

**Root cause:** the vendor `rms_link()` prints a form that POSTs straight to `pay.fiuu.com` with the amount and
`vcode` computed when the page was rendered. Changing the method submits the gateway form and the fee module adds
the new method's fee, but the old Fiuu button is still clickable and posts the old amount. CHIP is not affected
because its button goes through `chip/redirect.php`, which re-reads the invoice at click time.

**Fix:** `rms_link()` prints a same-origin button to `fiuu/redirect.php?invoiceid=<id>&t=<hmac>`. That page re-reads
the invoice, refuses unless it is Unpaid and still `rms`, then auto-POSTs to Fiuu with the current balance and a
fresh `vcode`. The notify/return/callback files are unchanged.

**Rollout:** copy `fiuu/config.php.example` to `fiuu/config.php`. Start with `mode => 'clients'` (test client only),
then `all`. Delete `config.php` or set `off` to roll back instantly.
