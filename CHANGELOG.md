# Changelog (GB Network fork)

## gb-3 (2026-11-12, prepared, not deployed)
### Fixed
- Same Fiuu tranID recorded twice (INV-1017315, tranID 4166432931; also 4 cases Aug-Sep 2026: invoices 147609,
  148841, 149371, 149364). Fiuu sent the notify 3x in the same second, and the vendor check-then-insert had no lock.
  New `fiuu/gb_payment.php` takes a MariaDB `GET_LOCK` per tranID and checks `tblaccounts` inside the lock. It is used
  by notify, both callbacks and return.
- Mass payment half applied (INV-1017375, 2 of 5 child invoices), gateway side only. The gateway log is now written
  before `addInvoicePayment()`, so an accepted payment is always visible even if a hook fails later.
  `ignore_user_abort(true)` and `set_time_limit(600)` keep the request running if Fiuu hangs up.

### Root cause outside this module (not in this repo)
- What actually stopped INV-1017375 was the e_invoice addon's InvoicePaid hook, which ran a Bukku sync inline for
  each child invoice (~80 s each). That is fixed in GBNetworkDev/whmcs-einvoice#1. This repo has no e_invoice/Bukku
  code.

### Not changed
- The vendor skey line `md5($tranID...)` is kept as is. `$tranID` is populated by WHMCS from the POST. If it were
  empty, every skey check would fail and no Fiuu payment would ever be recorded.

### Not included (manual, needs approval)
- Existing data is not touched. The 3 unpaid children of INV-1017375 (151758, 151650, 151606; RM960.86) and the
  duplicate transaction rows on the 5 affected invoices must be fixed by Finance in WHMCS.

### Verified (2026-11-12)
- `tests/race_test.php` on MariaDB 11.4: the vendor code records the same tranID 5x (bug reproduced); the patched
  code records it once (1 recorded + 4 duplicate). Different tranIDs are not serialised, the lock is released when the
  holder is killed, a waiter returns `busy` after lockWait, and a hook exception is logged instead of being fatal.
  8/8 pass.
- Base files in git match production byte-for-byte (sha256) for the four endpoints, `rms.php`, `fiuu/lib.php` and
  `fiuu/redirect.php`.

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
