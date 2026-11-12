<?php
/**
 * End-to-end test of the four Fiuu endpoints over real HTTP (php -S) against MariaDB, with a fake WHMCS core
 * (tests/fake_whmcs). Checks what Fiuu actually sees: 302 / 503 / CBTOKEN, the skey check and dedupe.
 *
 *   docker run -d --name fiuu-test-db -e MARIADB_ROOT_PASSWORD=t -e MARIADB_DATABASE=t mariadb:11.4
 *   docker run --rm --link fiuu-test-db:db -v $PWD:/app -w /app <php-8.2 image with pdo_mysql + pcntl> php tests/endpoint_test.php
 */

$repo = realpath(__DIR__ . '/..');
$root = sys_get_temp_dir() . '/fiuu_ep_' . getmypid();
@mkdir($root, 0700, true);
exec('cp -a ' . escapeshellarg($repo . '/tests/fake_whmcs/.') . ' ' . escapeshellarg($root));
exec('cp -a ' . escapeshellarg($repo . '/modules') . ' ' . escapeshellarg($root . '/modules'));

function db()
{
    // Reconnect when asked: a connection inherited across pcntl_fork() is closed by the exiting child.
    static $pdo = null;
    if ($pdo === null || func_num_args() > 0) {
        $pdo = new PDO(getenv('TEST_DSN') ?: 'mysql:host=db;dbname=t', 'root', getenv('TEST_PASS') ?: 't', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    }
    return $pdo;
}
function reset_db()
{
    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS tblaccounts');
    $pdo->exec('CREATE TABLE tblaccounts (id INT AUTO_INCREMENT PRIMARY KEY, invoiceid INT, transid VARCHAR(255), amountin DECIMAL(16,2), gateway VARCHAR(255), KEY (transid)) ENGINE=InnoDB');
    $pdo->exec('DROP TABLE IF EXISTS testlog');
    $pdo->exec('CREATE TABLE testlog (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20), text TEXT) ENGINE=InnoDB');
}
function rows($tid)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?');
    $st->execute(array($tid));
    return (int) $st->fetchColumn();
}
function last_gwlog()
{
    $e = events('gwlog');
    return $e ? $e[count($e) - 1] : null;
}
function events($type)
{
    $pdo = db();
    return $pdo->query("SELECT text FROM testlog WHERE type = " . $pdo->quote($type) . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
}

$port = 8099;
$server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', "$root/server.log", 'w')), $pipes);
usleep(800 * 1000);

/** Signed Fiuu POST like the real gateway sends (skey per Fiuu spec). */
function fiuu_fields($invoice, $tranID, $status = '00', $extra = array(), $secret = 'TESTSECRET')
{
    $f = array('tranID' => $tranID, 'orderid' => (string) $invoice, 'status' => $status, 'domain' => 'testmerchant',
        'amount' => '1.00', 'currency' => 'MYR', 'appcode' => 'A1', 'paydate' => '2026-11-12 15:00:00', 'channel' => 'fpx', 'cust_name' => 'Test', 'email' => 'test@example.invalid');
    $key0 = md5($f['tranID'] . $f['orderid'] . $f['status'] . $f['domain'] . $f['amount'] . $f['currency']);
    $f['skey'] = md5($f['paydate'] . $f['domain'] . $key0 . $f['appcode'] . $secret);
    return array_merge($f, $extra);
}

function post($path, array $fields)
{
    global $port;
    $ctx = stream_context_create(array('http' => array('method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($fields), 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 90)));
    $body = @file_get_contents("http://127.0.0.1:$port/$path", false, $ctx);
    $code = 0;
    $loc = '';
    foreach ($http_response_header ?? array() as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
            $code = (int) $m[1];
        }
        if (stripos($h, 'Location:') === 0) {
            $loc = trim(substr($h, 9));
        }
    }
    return array('code' => $code, 'body' => (string) $body, 'loc' => $loc);
}

$fail = 0;
function check($name, $ok, $detail = '')
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $fail++;
    }
}
function d($r)
{
    return $r['code'] . ' ' . ($r['loc'] ?: substr(trim($r['body']), 0, 60));
}

$N = 'modules/gateways/notify/fiuu_notify.php';
$C = 'modules/gateways/callback/fiuu_callback.php';
$R = 'modules/gateways/callback/rms_callback.php';
$T = 'modules/gateways/return/fiuu_return.php';

// notify (server-to-server, nbcb=2)
reset_db();
$r = post($N, fiuu_fields(101, '5000000001', '00', array('nbcb' => 2)));
check('notify: valid payment -> 302 viewinvoice, 1 row, log Accepted+Successful', $r['code'] === 302 && strpos($r['loc'], 'viewinvoice.php?id=101') !== false
    && rows('5000000001') === 1 && events('gwlog') === array('Accepted, recording payment', 'Successful'), d($r));
$r = post($N, fiuu_fields(101, '5000000001', '00', array('nbcb' => 2)));
check('notify: replay -> 302, still 1 row, logged duplicate', $r['code'] === 302 && rows('5000000001') === 1 && last_gwlog() === 'Already recorded (duplicate ignored)', d($r));

reset_db();
$r = post($N, fiuu_fields(102, '5000000002', '00', array('nbcb' => 2), 'WRONGSECRET'));
check('notify: bad skey -> not recorded, Unsuccessful, 302 clientarea', rows('5000000002') === 0 && events('gwlog') === array('Unsuccessful') && strpos($r['loc'], 'clientarea.php') !== false, d($r));
$f = fiuu_fields(102, '5000000002', '00', array('nbcb' => 2));
unset($f['skey']);
$f['skey'] = array('0');
$r = post($N, $f);
check('notify: skey sent as array -> no fatal, Unsuccessful, not recorded', $r['code'] === 302 && rows('5000000002') === 0 && last_gwlog() === 'Unsuccessful', d($r));

reset_db();
$r = post($N, fiuu_fields(9001, '5000000003', '00', array('nbcb' => 2)));
check('notify: WHMCS refuses payment -> 503 (Fiuu retries), 0 rows, never "Successful", admin alert',
    $r['code'] === 503 && rows('5000000003') === 0 && !in_array('Successful', events('gwlog'), true) && count(events('activity')) === 1, d($r));
$r = post($N, fiuu_fields(9002, '5000000004', '00', array('nbcb' => 2)));
check('notify: DB error before insert -> 503, 0 rows', $r['code'] === 503 && rows('5000000004') === 0, d($r));
reset_db();
$r = post($N, fiuu_fields(9003, '5000000005', '00', array('nbcb' => 2)));
check('notify: hook fails after insert -> 302 (recorded), 1 row, admin alert', $r['code'] === 302 && rows('5000000005') === 1
    && last_gwlog() === 'Successful (hook error after recording)' && count(events('activity')) === 1, d($r));

// 5 identical notifies at once, the first one slow before its insert (like INV-1017315 from 2 Fiuu IPs)
reset_db();
$pids = array();
for ($i = 0; $i < 5; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $r = post($N, fiuu_fields(9004, '5000000006', '00', array('nbcb' => 2)));
        file_put_contents("$root/par.$i", $r['code']);
        posix_kill(getmypid(), SIGKILL); // skip PDO destructors: do not close the parent's inherited socket
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $st);
}
db(true);
$codes = array();
for ($i = 0; $i < 5; $i++) {
    $codes[] = @file_get_contents("$root/par.$i");
}
check('notify x5 at once (slow first) -> exactly 1 row, all answered 302', rows('5000000006') === 1 && count(array_filter($codes, function ($c) { return $c === '302'; })) === 5, implode(',', $codes));

// callback (nbcb=1): CBTOKEN only after the payment is recorded
reset_db();
$r = post($C, fiuu_fields(103, '5000000007', '00', array('nbcb' => 1)));
check('callback: valid -> body is exactly CBTOKEN:MPSTATOK, 1 row', $r['code'] === 200 && trim($r['body']) === 'CBTOKEN:MPSTATOK' && rows('5000000007') === 1, d($r));
$r = post($C, fiuu_fields(103, '5000000007', '00', array('nbcb' => 1)));
check('callback: replay -> CBTOKEN, still 1 row', trim($r['body']) === 'CBTOKEN:MPSTATOK' && rows('5000000007') === 1, d($r));
$r = post($C, fiuu_fields(9001, '5000000008', '00', array('nbcb' => 1)));
check('callback: not recorded -> 503 and NO CBTOKEN (Fiuu retries)', $r['code'] === 503 && strpos($r['body'], 'CBTOKEN') === false && rows('5000000008') === 0, d($r));
$r = post($C, fiuu_fields(104, '5000000009', '11', array('nbcb' => 1)));
check('callback: failed payment (status 11) -> acked with CBTOKEN, not recorded', trim($r['body']) === 'CBTOKEN:MPSTATOK' && rows('5000000009') === 0, d($r));
$r = post($R, fiuu_fields(105, '5000000010', '00', array('nbcb' => 1)));
check('rms_callback: valid -> CBTOKEN, 1 row', trim($r['body']) === 'CBTOKEN:MPSTATOK' && rows('5000000010') === 1, d($r));

// browser return (gb-1 bounce, then the real POST); dedupes against the notify
reset_db();
$r = post($T, fiuu_fields(106, '5000000011'));
check('return: first cross-site POST -> bounce page, nothing recorded', $r['code'] === 200 && strpos($r['body'], 'gb_bounced') !== false && rows('5000000011') === 0, d($r));
post($N, fiuu_fields(106, '5000000011', '00', array('nbcb' => 2)));
$r = post($T, fiuu_fields(106, '5000000011', '00', array('gb_bounced' => 1)));
check('return after notify -> 302 viewinvoice, still 1 row', $r['code'] === 302 && strpos($r['loc'], 'viewinvoice.php?id=106') !== false && rows('5000000011') === 1, d($r));
$r = post($T, fiuu_fields(9001, '5000000012', '00', array('gb_bounced' => 1)));
check('return when not recorded -> customer still redirected to the invoice (302), server notify will retry', $r['code'] === 302 && rows('5000000012') === 0, d($r));

proc_terminate($server);
$errs = trim(@file_get_contents("$root/server.log"));
$php_errors = preg_match_all('/PHP (Fatal|Warning|Parse|Deprecated)[^\n]*/', $errs, $m) ? $m[0] : array();
// Pre-existing vendor reads of optional POST fields (e.g. no nbcb on a browser return) are not ours to fix here.
$php_errors = array_values(array_filter($php_errors, function ($e) { return !preg_match('/Undefined array key "(nbcb|cust_name|email)"/', $e); }));
check('no PHP fatal/warning/deprecated in the endpoints', !$php_errors, implode(' | ', array_slice($php_errors, 0, 3)));
exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
