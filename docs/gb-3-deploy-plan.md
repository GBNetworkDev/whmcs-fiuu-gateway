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
**GBNetworkDev/whmcs-einvoice#1** (review: Awis). Both can be deployed independently. Validation test 2 (mass pay)
only fully passes once the e_invoice fix is live too.

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
sha256sum $B/* > $B/SHA256SUMS
```

## 2. Deploy (Fiuu gateway)
```
install -o gbnet -g gbnet -m 644 modules/gateways/fiuu/gb_payment.php $W/modules/gateways/fiuu/gb_payment.php
for f in notify/fiuu_notify.php callback/fiuu_callback.php callback/rms_callback.php return/fiuu_return.php; do
  install -o gbnet -g gbnet -m 644 modules/gateways/$f $W/modules/gateways/$f; /opt/cpanel/ea-php82/root/usr/bin/php -l $W/modules/gateways/$f; done
```
Optional (recommended): stop LiteSpeed from aborting gateway scripts when Fiuu disconnects. Add this at the top of
`$W/.htaccess`:
```
<IfModule LiteSpeed>
RewriteEngine On
RewriteRule ^modules/gateways/ - [E=noabort:1,E=noconntimeout:1]
</IfModule>
```

## 3. Validation (use a test client and RM1 invoices, clearly marked [TEST])
1. **Single payment.** Pay a RM1 invoice through Fiuu (DuitNow QR or FPX). Expected results:
   - `tblaccounts` has 1 row for the tranID.
   - The gateway log has 1 `Successful`, plus `Already recorded (duplicate ignored)` for the browser return or any retry.
   - The domlog notify line is `302` within a few seconds.
   - With whmcs-einvoice#1 live: the Bukku mapping shows `synced` 1-2 minutes later.
   - The customer is still logged in (gb-1).
2. **Mass pay.** Use 3 RM1 invoices, then Client Area > Mass Pay > Fiuu. Expected: all 3 children Paid, a
   "Mass Invoice Payment Credit"/"Credit Applied" pair for each child, client credit 0, 1 transaction, 1 gateway log.
3. **Duplicate replay.** Take the exact fields of test 1 from the gateway log (signed by Fiuu) and POST them 3 times
   in parallel to `notify/fiuu_notify.php`. Expected: still 1 `tblaccounts` row, and new log lines say
   `Already recorded`. Before the patch this created duplicates.
   (The concurrency logic is already tested against MariaDB 11.4 in `tests/race_test.php`. The vendor logic recorded
   5 of 5 parallel requests; the patched logic recorded 1 of 5.)
4. **Daily monitor (SQL, read-only).** Both queries should return 0 rows:
```
-- mass-pay parent Paid but a child still Unpaid
SELECT p.id parent, c.id child FROM tblinvoices p JOIN tblinvoiceitems i ON i.invoiceid=p.id AND i.type='Invoice'
JOIN tblinvoices c ON c.id=i.relid WHERE p.status='Paid' AND c.status='Unpaid';
-- same tranID recorded more than once on one invoice
SELECT transid, invoiceid, COUNT(*) FROM tblaccounts WHERE transid<>'' AND amountin>0 AND date>=CURDATE()-INTERVAL 7 DAY
GROUP BY transid, invoiceid HAVING COUNT(*)>1;
```

## 4. Rollback
Copy the files back from `$B` (`cp -p $B/<file> <original path>`). No database changes are made by the patch.

## 5. Follow-up (not in this patch)
- e_invoice (whmcs-einvoice repo): `InvoiceSyncService::sync()` fetches all Bukku contacts, products and invoices on every call. Replace
  this with filtered lookups or a cache so a sync takes seconds, not 80 s.
- Offer the gb-3 lock fix upstream to Fiuu, as was done with PR #4.
