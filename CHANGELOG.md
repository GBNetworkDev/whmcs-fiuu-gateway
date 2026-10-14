# Changelog (GB Network fork)

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
