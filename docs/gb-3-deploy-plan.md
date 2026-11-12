# GB patch 3: deploy and validation plan

WHMCS root: `/home/gbnet/public_html/secure` (owner `gbnet:gbnet`, mode 644). PHP: ea-php82 (LiteSpeed lsphp).

## Root cause (evidence from production, 2026-11-12)
| | INV-1017375 (mass pay, half applied) | INV-1017315 (tranID recorded twice) |
|---|---|---|
| What happened | Fiuu notify `POST /modules/gateways/notify/fiuu_notify.php` at 11:32:50 returned **HTTP 500**. #151954 was paid at 11:32:51. Its Bukku sync finished at 11:34:10. #151760 was paid at 11:34:11, then the request died. #151758, #151650 and #151606 were never processed. | 3 notify POSTs for tranID 4166432931 arrived at 10:59:12 from 46.137.193.22 and 18.142.65.127. Two passed the `COUNT(*)` check before either had inserted, giving tx 101783 and 101784. |
| Code cause | `addInvoicePayment()` pays every mass-pay child in the same request. Each child fires InvoicePaid, and the e_invoice hook calls Bukku inline. `syncInvoicesWithBukku()` fetches all Bukku contacts, products and invoices on every call, which takes about 80 s. `logTransaction()` comes after `addInvoicePayment()`, so there is no gateway log. A retry sees the transid and exits, so nothing ever resumes. | The check-then-insert has no lock. `$_SESSION['prev_skey']` cannot work for server-to-server calls, because there is no cookie and each call gets a new session. |
| Scale | Paid invoices took an average of **84 s** to sync in Oct-Nov, for all gateways (rms: 437 of 440 took 60 s or more). | Same pattern on 4 older Fiuu tranIDs (Aug-Sep): #147609, #148841, #149371, #149364. Each has 2 transactions for one payment. |

## Scope
This repo fixes the Fiuu side only: one payment per tranID, and the gateway log written before the payment is applied.
The ~80 s InvoicePaid hook that cut INV-1017375 off belongs to the e_invoice addon. It is fixed (and deployed separately) in
**GBNetworkDev/whmcs-einvoice#1** (review: Awis).

**Deploy order matters.** gb-3 alone does not save a slow mass pay: `ignore_user_abort()`/`set_time_limit()` do not stop
LiteSpeed's `extappkill` (~121 s idle), and on Linux `max_execution_time` only counts CPU time. If gb-3 goes live before
whmcs-einvoice#1, a 5-child mass pay is still killed half way, and from then on the fast path answers every Fiuu retry
with "duplicate". So either deploy whmcs-einvoice#1 first, or install the `.htaccess` rule in step 2 (it is
**mandatory** until whmcs-einvoice#1 is live, and recommended after). Validation test 2 (mass pay) only fully passes
with both.

## 0. Data cleanup (can be done now, independent of the patch)
1. **INV-1017375 / client 402.** Clients > (client) > Billing > Add Credit: RM960.86, description
   `Baki mass pay INV-1017375 (Fiuu 4174268594) - auto apply gagal 09/11`. Then on each invoice use
   Apply Credit for the full balance: #151758 RM215.99, #151650 RM183.49, #151606 RM561.38.
   Services on #151606 are due 13/11 and #151650 on 14/11, so do this first.
   (This matches what WHMCS itself writes: "Mass Invoice Payment Credit for Invoice #X" followed by "Credit Applied".)
2. **INV-1017315 (#151858).** Invoice > Transactions: delete tx **101784** (the second RM106.81, same tranID).
   Afterwards the invoice should still be Paid with balance 0.00, and the client credit should stay RM0.00 (Umie
   already removed it).
3. Do the same review for #147609, #148841, #149371 and #149364: remove the duplicate transaction and any leftover
   overpayment credit.

## 1. Backup
```
TS=$(date +%Y%m%d%H%M%S); B=/root/backups/fiuu-gb3-$TS; W=/home/gbnet/public_html/secure; mkdir -p $B
cp -p $W/modules/gateways/notify/fiuu_notify.php $W/modules/gateways/callback/fiuu_callback.php \
      $W/modules/gateways/callback/rms_callback.php $W/modules/gateways/return/fiuu_return.php \
      $W/.htaccess $B/
[ -f $W/modules/gateways/fiuu/gb_payment.php ] && cp -p $W/modules/gateways/fiuu/gb_payment.php $B/
sha256sum $B/* > $B/SHA256SUMS
find $W/modules/gateways -name .htaccess    # must print nothing: a nested .htaccess with its own RewriteEngine
                                            # would stop the rule in step 2 from applying to the gateway scripts
```

## 2. Deploy (Fiuu gateway)
```
PHP=/opt/cpanel/ea-php82/root/usr/bin/php; FILES="fiuu/gb_payment.php notify/fiuu_notify.php callback/fiuu_callback.php callback/rms_callback.php return/fiuu_return.php"
# Run as a script (bash deploy.sh), not pasted into the root shell: a failed step stops the script, not your session.
set -e
for f in $FILES; do $PHP -l modules/gateways/$f; done              # lint BEFORE copying, gb_payment.php included
STAGE=/root/fiuu-gb3-stage-$TS; mkdir -p $STAGE                     # staged outside the docroot (never web-served)
for f in $FILES; do install -D -o gbnet -g gbnet -m 644 modules/gateways/$f $STAGE/$f; done
install -d -o gbnet -g gbnet -m 755 $W/modules/gateways/fiuu
for f in $FILES; do mv -f $STAGE/$f $W/modules/gateways/$f; done   # same filesystem as /home? if not, cp -p then mv in place
for f in $FILES; do cmp modules/gateways/$f $W/modules/gateways/$f && $PHP -l $W/modules/gateways/$f; done   # deployed == git
```
**Mandatory (until whmcs-einvoice#1 is live, see Scope):** stop LiteSpeed from killing gateway scripts when Fiuu
disconnects. Put this at the very top of `$W/.htaccess`, **outside** the `### BEGIN WHMCS` / `### END WHMCS` block
(WHMCS rewrites that block when friendly URLs are regenerated and would drop the rule):
```
# GB patch 3: let Fiuu gateway requests finish (LiteSpeed noabort), see whmcs-fiuu-gateway docs/gb-3-deploy-plan.md
<IfModule LiteSpeed>
RewriteEngine On
RewriteRule ^modules/gateways/ - [E=noabort:1,E=noconntimeout:1]
</IfModule>
```
Then make lsphp pick up the new files: `touch` them (OPcache revalidates on mtime) or, if OPcache
`validate_timestamps` is off, run a graceful LiteSpeed restart (`/usr/local/lsws/bin/lswsctrl restart`, a few seconds).
Check with one GET of `notify/fiuu_notify.php` (it should still answer, no 500) and the error_log.

**Behaviour changes to know (review 2):**
- Fiuu gets HTTP 503 (no 302, no CBTOKEN) only when the payment is not recorded yet: a lock timeout with no row, or
  addInvoicePayment failing before the insert. Each 503 writes an admin activity-log line `Fiuu: tranID ... not
  recorded ...` that tells staff to requery the tranID in the Fiuu portal and record it by hand if it is still unpaid
  45 min later. Fiuu documents retries for the **callback URL** (no `CBTOKEN:MPSTATOK` ack: up to 3 more tries,
  15 min apart). Whether Fiuu also retries a **notify** on 503 is not documented, so treat a notify 503 alert as manual.
- Callbacks now send `CBTOKEN:MPSTATOK` after processing. A callback for an unknown invoice (`checkCbInvoiceID` stops
  the script) is therefore not acknowledged and Fiuu retries it up to 3 times; harmless, it just adds
  `Invoice ID Not Found` lines. A callback without `nbcb=1` still posts the vendor IPN to Fiuu before recording, so
  Fiuu treats it as delivered regardless of a 503 (vendor behaviour, unchanged).
- The vendor `$_SESSION['prev_skey']` duplicate guard now saves the skey only after Fiuu is acknowledged, so a retry
  of a 503 is not dropped as "Duplicate response".
- Output printed by WHMCS or hooks during recording is discarded, so the ack/redirect stays clean.

## 3. Validation (use a test client and RM1 invoices, clearly marked [TEST])
1. **Single payment.** Pay a RM1 invoice through Fiuu (DuitNow QR or FPX). Expected results:
   - `tblaccounts` has 1 row for the tranID.
   - The gateway log has `Accepted, recording payment` then 1 `Successful`, plus `Already recorded (duplicate ignored)`
     for the browser return or any retry.
   - The domlog notify line is `302` within a few seconds (callback: 200 with body `CBTOKEN:MPSTATOK`).
   - No `503` for this tranID. A 503 means "not recorded, Fiuu please retry" and comes with an admin activity-log
     entry `Fiuu: tranID ... NOT recorded` or `(lock busy)`.
   - With whmcs-einvoice#1 live: the Bukku mapping shows `synced` 1-2 minutes later.
   - The customer is still logged in (gb-1).
2. **Mass pay.** Use 3 RM1 invoices, then Client Area > Mass Pay > Fiuu. Expected: all 3 children Paid, a
   "Mass Invoice Payment Credit"/"Credit Applied" pair for each child, client credit 0, 1 transaction, 1 gateway log.
3. **Duplicate replay.** Take the exact fields of test 1 from the gateway log (signed by Fiuu) and POST them 3 times
   in parallel to `notify/fiuu_notify.php`. Expected: still 1 `tblaccounts` row, and new log lines say
   `Already recorded`. Before the patch this created duplicates.
   (The concurrency logic is already tested against MariaDB 11.4 in `tests/race_test.php`. The vendor logic recorded
   5 of 5 parallel requests; the patched logic recorded 1 of 5.)
   Also replay once more while test 1's notify is still inside its hooks (before the e_invoice fix this is ~80 s):
   the replay must return within a second or two (fast path), not hang for 60 s.
4. **Daily monitor (SQL, read-only).** Both queries should return 0 rows:
```
-- mass-pay parent Paid but a child still Unpaid
SELECT p.id parent, c.id child FROM tblinvoices p JOIN tblinvoiceitems i ON i.invoiceid=p.id AND i.type='Invoice'
JOIN tblinvoices c ON c.id=i.relid WHERE p.status='Paid' AND c.status='Unpaid';
-- same Fiuu tranID recorded more than once (on any invoice)
SELECT transid, COUNT(*) n, COUNT(DISTINCT invoiceid) invoices, GROUP_CONCAT(invoiceid) FROM tblaccounts
WHERE gateway='rms' AND transid<>'' AND amountin>0 AND date>=CURDATE()-INTERVAL 30 DAY
GROUP BY transid HAVING COUNT(*)>1;
-- payments the gateway could not record (Fiuu was told to retry): should be empty or resolved by a later retry
SELECT date, description FROM tblactivitylog WHERE description LIKE 'Fiuu: tranID %' AND date>=CURDATE()-INTERVAL 7 DAY;
```
5. **Last check before announcing.** One real RM1 payment through Fiuu after the deploy: 1 `tblaccounts` row, gateway
   log `Accepted` + `Successful`, invoice Paid.

## 4. Rollback
```
for f in notify/fiuu_notify.php callback/fiuu_callback.php callback/rms_callback.php return/fiuu_return.php; do
  cp -p $B/$(basename $f) $W/modules/gateways/$f; done
# Remove only the GB patch 3 lines from .htaccess (do NOT copy the old file back: that would also undo any WHMCS
# friendly-URL changes made since the backup). Delete the block from "# GB patch 3: let Fiuu gateway requests finish"
# to its "</IfModule>".
# fiuu/gb_payment.php may stay: nothing includes it after the endpoints are restored. Then reload lsphp.
```
No database changes are made by the patch.

## 5. Staging checks (before production)
On a WHMCS 8.13.3 staging with the module installed and a dummy merchant secret (outbound email off, provisioning
servers, registrars and notifications disabled, test client with plain line items only):
0. Done on staging 12 Nov 2026 (mystaging, WHMCS 8.13.3, Apache, dummy merchant, 748225f + whmcs-einvoice#1):
   single 302/1 row; 5x parallel notify 1 row, all <0.7 s; lock held by hand 503 after 60 s then the retry records;
   return+notify together 1 row; callback CBTOKEN after recording; 3-child mass pay all Paid, credit 0; bad skey
   rejected; e-invoice sync ran after the response (notify 0.8 s). Not testable there: the LiteSpeed rule (staging runs
   Apache), and Bukku write calls (the sandbox token is read-only).
1. Log the `tblaccounts` insert time against the InvoicePaid hook start: the row must be committed before the hooks
   (the fast path relies on it).
2. Replay one signed notify 5x in parallel, inside the first ~100 ms and again inside the hook window: 1 row, all replies
   within ~2 s.
3. Hold the lock by hand (`SELECT GET_LOCK('<gbfiuu_lock_name>', 120)` in a mysql shell) and POST the notify: it answers
   503 after 60 s and records nothing; the next POST after releasing records it.
4. A hook that sleeps 150 s, with and without the `.htaccess` rule: without it LiteSpeed kills the request, with it the
   request completes.
5. Browser return and notify for the same tranID at the same moment, customer session kept (gb-1): 1 row.
6. 3-child mass pay with whmcs-einvoice#1 installed: all children Paid, 1 transaction, monitor queries return 0 rows.
`tests/race_test.php` (lock logic vs MariaDB) and `tests/endpoint_test.php` (the 4 endpoints over HTTP with a fake WHMCS)
cover the rest without staging.

## 6. Follow-up (not in this patch)
- e_invoice (whmcs-einvoice repo): `InvoiceSyncService::sync()` fetches all Bukku contacts, products and invoices on every call. Replace
  this with filtered lookups or a cache so a sync takes seconds, not 80 s.
- Offer the gb-3 lock fix upstream to Fiuu, as was done with PR #4.
