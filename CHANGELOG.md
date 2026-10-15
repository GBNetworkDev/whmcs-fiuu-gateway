# Changelog (GB Network fork)

## gb-2 (2026-10-15)
### Fixed
- Invoice stayed Unpaid after a Fiuu payment when the customer changed the payment method and clicked "Make Payment"
  before the page reloaded. The payment-gateway-charges module had already added the new method's fee, but the stale
  Fiuu form still posted the old amount and vcode. 83 invoices since Aug 2023 were affected.
- The Fiuu button now goes to `fiuu/redirect.php` (same pattern as CHIP's `chip/redirect.php`). At click time it
  re-reads the invoice and only forwards to Fiuu if the invoice is still Unpaid and still set to `rms`, using the
  current balance and a fresh vcode. Otherwise the customer goes back to the invoice and the stop is logged.
- Rollout switch `fiuu/config.php`: `off` (vendor behaviour), `clients` (canary allowlist) or `all`. No `config.php`
  means `off`.
- Tests: `php tests/gb2_redirect_test.php`.

## gb-1 (2026-10-14)
### Fixed
- `return/fiuu_return.php`: the customer was logged out after a successful Fiuu payment and landed on Secure Client
  Login (reproduced with a RM1 DuitNow QR test; CHIP is unaffected). A same-site bounce now runs
  before `init.php`. The first cross-site POST from Fiuu is re-submitted from our origin, with the same fields plus
  `gb_bounced=1`, so the browser sends the WHMCS session cookie. The vendor validation, duplicate check and
  `addInvoicePayment` code is unchanged. See README "GB patch 1".

## vendor-6.1
- Fiuu WHMCS plugin 6.1 exactly as deployed on 2026-10-14. This includes Fiuu's May 2025 removal of
  `session_start()` from `fiuu_return.php`.
