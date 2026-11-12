<?php
/**
 * Concurrency test for GB patch 3 against a real MariaDB (GET_LOCK needs the real server).
 *
 *   docker run -d --name fiuu-test-db -e MARIADB_ROOT_PASSWORD=t -e MARIADB_DATABASE=t mariadb:11.4
 *   docker run --rm --link fiuu-test-db:db -v $PWD:/app -w /app <php-8.x image with pdo_mysql + pcntl> php tests/race_test.php
 *
 * Scenario = INV-1017315: Fiuu sends the same notify N times at the same moment.
 *  - vendor:  check-then-insert, no lock    -> expected to record the payment more than once (bug reproduced)
 *  - patched: gbfiuu_record_payment()       -> exactly one row, the rest 'duplicate'
 * Plus: lock is released if the holder dies (kill -9), and a waiter gives up with 'busy' after lockWait.
 */

require __DIR__ . '/../modules/gateways/fiuu/gb_payment.php';

$dsn = getenv('TEST_DSN') ?: 'mysql:host=db;dbname=t';
$user = getenv('TEST_USER') ?: 'root';
$pass = getenv('TEST_PASS') ?: 't';

function db()
{
    global $dsn, $user, $pass;
    $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    return $pdo;
}

function reset_table()
{
    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS tblaccounts');
    $pdo->exec('CREATE TABLE tblaccounts (id INT AUTO_INCREMENT PRIMARY KEY, invoiceid INT, transid VARCHAR(255), amountin DECIMAL(16,2), gateway VARCHAR(255)) ENGINE=InnoDB');
}

function count_rows($transid)
{
    $st = db()->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?');
    $st->execute(array($transid));
    return (int) $st->fetchColumn();
}

/** Fake addInvoicePayment: slow (like WHMCS + hooks), inserts the tblaccounts row first like WHMCS does. */
function fake_add_payment(PDO $pdo, $delayMs)
{
    return function ($inv, $tid, $amt, $fee, $gw) use ($pdo, $delayMs) {
        usleep(50 * 1000);
        $st = $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?,?,?,?)');
        $st->execute(array($inv, $tid, $amt, $gw));
        usleep($delayMs * 1000); // hooks (mass-pay children, Bukku...) still running
    };
}

/** Run $n children that start at the same instant; each returns a short status string via a pipe file. */
function run_parallel($n, callable $job)
{
    $start = microtime(true) + 0.5;
    $pids = array();
    $tmp = sys_get_temp_dir() . '/race_' . getmypid() . '_' . mt_rand();
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            while (microtime(true) < $start) {
                usleep(200);
            }
            $r = $job($i);
            file_put_contents("$tmp.$i", (string) $r);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $out[] = @file_get_contents("$tmp.$i");
        @unlink("$tmp.$i");
    }
    return $out;
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

$N = 5;

// 1. Vendor logic (baseline): reproduce the double payment.
reset_table();
$res = run_parallel($N, function ($i) {
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?');
    $st->execute(array('4166432931'));
    if ((int) $st->fetchColumn() > 0) {
        return 'dup';
    }
    $add = fake_add_payment($pdo, 300);
    $add(151858, '4166432931', '106.81', 0, 'rms');
    return 'recorded';
});
$rows = count_rows('4166432931');
check('vendor check-then-insert records the same tranID more than once (bug reproduced)', $rows > 1, "rows=$rows results=" . implode(',', $res));

// 2. Patched logic: exactly once.
reset_table();
$res = run_parallel($N, function ($i) {
    $pdo = db();
    return gbfiuu_record_payment(
        array('invoiceid' => 151858, 'transid' => '4166432931', 'amount' => '106.81', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 300), 'log' => function () {}, 'lockWait' => 30)
    );
});
$rows = count_rows('4166432931');
$counts = array_count_values($res);
check('patched: same tranID x5 at once -> exactly 1 payment row', $rows === 1, "rows=$rows");
check('patched: 1 recorded + 4 duplicate', ($counts['recorded'] ?? 0) === 1 && ($counts['duplicate'] ?? 0) === $N - 1, implode(',', $res));

// 3. Different tranIDs are not blocked by each other.
reset_table();
$t0 = microtime(true);
$res = run_parallel($N, function ($i) {
    $pdo = db();
    return gbfiuu_record_payment(
        array('invoiceid' => 1000 + $i, 'transid' => 'T' . $i, 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 1000), 'log' => function () {}, 'lockWait' => 30)
    );
});
$elapsed = microtime(true) - $t0;
check('patched: 5 different tranIDs all recorded in parallel (no global lock)', array_count_values($res) === array('recorded' => $N) && $elapsed < 3.5, sprintf('%.1fs', $elapsed));

// 4. Holder dies (kill -9) after the lock but before the insert -> lock is freed, next request records it.
reset_table();
$pid = pcntl_fork();
if ($pid === 0) {
    $pdo = db();
    gbfiuu_record_payment(
        array('invoiceid' => 152028, 'transid' => '4174268594', 'amount' => '1155.04', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => function () { sleep(30); }, 'log' => function () {}, 'lockWait' => 5)
    );
    exit(0);
}
usleep(700 * 1000);
posix_kill($pid, SIGKILL);
pcntl_waitpid($pid, $st);
$pdo = db();
$r = gbfiuu_record_payment(
    array('invoiceid' => 152028, 'transid' => '4174268594', 'amount' => '1155.04', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
    array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 0), 'log' => function () {}, 'lockWait' => 5)
);
check('lock released when the holding PHP process is killed; retry records the payment', $r === 'recorded' && count_rows('4174268594') === 1, "result=$r");

// 5. Waiter gives up with 'busy' (and records nothing) while the holder is still inside hooks.
reset_table();
$res = run_parallel(2, function ($i) {
    if ($i === 1) {
        usleep(300 * 1000);
    }
    $pdo = db();
    return gbfiuu_record_payment(
        array('invoiceid' => 1, 'transid' => 'SLOW', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 4000), 'log' => function () {}, 'lockWait' => 1)
    );
});
check('waiter returns busy after lockWait, holder records once', $res === array('recorded', 'busy') && count_rows('SLOW') === 1, implode(',', $res));

// 6. Log order: gateway log written BEFORE addInvoicePayment, so a crash inside hooks still leaves a log entry.
reset_table();
$events = array();
$pdo = db();
$r = gbfiuu_record_payment(
    array('invoiceid' => 2, 'transid' => 'LOGFIRST', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array('tranID' => 'LOGFIRST')),
    array('pdo' => $pdo, 'addPayment' => function () use (&$events) { $events[] = 'add'; throw new RuntimeException('hook died'); },
        'log' => function ($n, $d, $result) use (&$events) { $events[] = 'log:' . $result; }, 'lockWait' => 1)
);
check('log written before addInvoicePayment; hook exception is logged, not fatal', $r === 'error' && $events[0] === 'log:Successful' && $events[1] === 'add' && strpos($events[2], 'log:Error') === 0, implode(' > ', $events));

// 7. Lock name fits MariaDB's 64-char limit.
check('lock name <= 64 chars', strlen(gbfiuu_lock_name(str_repeat('9', 300))) <= 64);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
