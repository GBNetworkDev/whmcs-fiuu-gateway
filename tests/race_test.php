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
    // Holder is slow BEFORE its row exists (e.g. WHMCS still loading the invoice), so the waiter cannot take the fast path.
    $slowFirst = function ($inv, $tid, $amt, $fee, $gw) use ($pdo) {
        usleep(3000 * 1000);
        $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?,?,?,?)')->execute(array($inv, $tid, $amt, $gw));
    };
    return gbfiuu_record_payment(
        array('invoiceid' => 1, 'transid' => 'SLOW', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => $slowFirst, 'log' => function () {}, 'lockWait' => 1)
    );
});
check('waiter returns busy after lockWait (not acked), holder records once', $res === array('recorded', 'busy') && !gbfiuu_should_ack('busy') && count_rows('SLOW') === 1, implode(',', $res));

// 5b. Fast path: row already inserted, holder still in hooks (Bukku ~80 s in prod). A retry / browser return must answer
//     'duplicate' at once, not sit on GET_LOCK for lockWait seconds.
reset_table();
$t = array();
$res = run_parallel(2, function ($i) {
    if ($i === 1) {
        usleep(500 * 1000);
    }
    $pdo = db();
    $t0 = microtime(true);
    $r = gbfiuu_record_payment(
        array('invoiceid' => 1, 'transid' => 'INHOOKS', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 5000), 'log' => function () {}, 'lockWait' => 30)
    );
    return $r . '@' . round(microtime(true) - $t0, 1);
});
$waitSec = (float) substr($res[1], strpos($res[1], '@') + 1);
check('retry during hooks returns duplicate at once (fast path), 1 row', strpos($res[0], 'recorded@') === 0 && strpos($res[1], 'duplicate@') === 0 && $waitSec < 1.0 && count_rows('INHOOKS') === 1, implode(',', $res));

// 6. Log order: "Accepted" is logged BEFORE addInvoicePayment, so a crash inside it still leaves a log entry. If it
//    throws before the row exists, the result is not_recorded (no ack -> Fiuu retries), never "Successful".
reset_table();
$events = array();
$pdo = db();
$r = gbfiuu_record_payment(
    array('invoiceid' => 2, 'transid' => 'LOGFIRST', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array('tranID' => 'LOGFIRST')),
    array('pdo' => $pdo, 'addPayment' => function () use (&$events) { $events[] = 'add'; throw new RuntimeException('db error before insert'); },
        'log' => function ($n, $d, $result) use (&$events) { $events[] = 'log:' . $result; }, 'alert' => function () {}, 'lockWait' => 1)
);
check('accepted logged before addInvoicePayment; throw before insert -> not_recorded, no "Successful", no ack',
    $r === 'not_recorded' && $events[0] === 'log:Accepted, recording payment' && $events[1] === 'add'
    && strpos($events[2], 'log:Not recorded') === 0 && !in_array('log:Successful', $events, true) && !gbfiuu_should_ack($r),
    implode(' > ', $events));

// 6b. Hook throws AFTER WHMCS inserted the row -> 'error' (payment is recorded), acknowledged, alert raised.
reset_table();
$events = array();
$alerts = array();
$pdo = db();
$r = gbfiuu_record_payment(
    array('invoiceid' => 4, 'transid' => 'HOOKFAIL', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
    array('pdo' => $pdo, 'addPayment' => function ($inv, $tid, $amt) use ($pdo) {
        $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?,?,?,?)')->execute(array($inv, $tid, $amt, 'rms'));
        throw new RuntimeException('SolusVM hook TypeError');
    }, 'log' => function ($n, $d, $result) use (&$events) { $events[] = $result; }, 'alert' => function ($m) use (&$alerts) { $alerts[] = $m; }, 'lockWait' => 1)
);
check('hook error after insert -> error, acked, logged + alert, 1 row',
    $r === 'error' && gbfiuu_should_ack($r) && end($events) === 'Successful (hook error after recording)' && count($alerts) === 1 && count_rows('HOOKFAIL') === 1,
    "$r " . implode(' > ', $events));

// 6c. addInvoicePayment returns false (WHMCS refused, e.g. invoice cancelled) and wrote nothing -> not_recorded, no ack.
reset_table();
$events = array();
$pdo = db();
$r = gbfiuu_record_payment(
    array('invoiceid' => 5, 'transid' => 'REFUSED', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
    array('pdo' => $pdo, 'addPayment' => function () { return false; },
        'log' => function ($n, $d, $result) use (&$events) { $events[] = $result; }, 'alert' => function () {}, 'lockWait' => 1)
);
check('addInvoicePayment returns false -> not_recorded, never logged Successful', $r === 'not_recorded' && !in_array('Successful', $events, true)
    && !gbfiuu_should_ack($r) && count_rows('REFUSED') === 0, "$r " . implode(' > ', $events));

// 7. Lock name fits MariaDB's 64-char limit, ignores case/trailing spaces, and is scoped per database.
check('lock name <= 64 chars', strlen(gbfiuu_lock_name(str_repeat('9', 300), str_repeat('d', 64))) <= 64);
check('lock name: same for AB12 / ab12 / "ab12 ", different per database',
    gbfiuu_lock_name('AB12', 'w') === gbfiuu_lock_name('ab12 ', 'w') && gbfiuu_lock_name('ab12', 'prod') !== gbfiuu_lock_name('ab12', 'staging'));

// 7b. Case variants of one tranID at the same moment -> one row (they share the lock now).
reset_table();
$res = run_parallel(2, function ($i) {
    $pdo = db();
    return gbfiuu_record_payment(
        array('invoiceid' => 6, 'transid' => $i ? 'AB12' : 'ab12', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 300), 'log' => function () {}, 'alert' => function () {}, 'lockWait' => 30)
    );
});
check('case variants ab12 / AB12 at once -> exactly 1 row', count_rows('ab12') === 1, implode(',', $res));

// 7c. Caller has an open transaction with a REPEATABLE READ snapshot taken before another request committed the row.
//     The locking read inside the lock must still see it.
reset_table();
$pdo = db();
$pdo->beginTransaction();
$pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn(); // snapshot taken here
db()->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (7, ?, 1.00, "rms")')->execute(array('SNAPSHOT'));
$r = gbfiuu_record_payment(
    array('invoiceid' => 7, 'transid' => 'SNAPSHOT', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
    array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 0), 'log' => function () {}, 'alert' => function () {}, 'lockWait' => 1)
);
$pdo->commit();
check('open transaction snapshot: in-lock locking read sees the committed row -> duplicate, 1 row', $r === 'duplicate' && count_rows('SNAPSHOT') === 1, "result=$r rows=" . count_rows('SNAPSHOT'));

// 7d. Ack matrix used by the endpoints.
check('should_ack: recorded/duplicate/error/invalid yes; busy/not_recorded no',
    gbfiuu_should_ack('recorded') && gbfiuu_should_ack('duplicate') && gbfiuu_should_ack('error') && gbfiuu_should_ack('invalid')
    && !gbfiuu_should_ack('busy') && !gbfiuu_should_ack('not_recorded'));

// 7e. Review 2 / M1: a waiter that arrived BEFORE the holder inserted, while the holder stays in hooks longer than
//     lockWait, must answer 'duplicate' (row exists), not 'busy' + a false "not recorded" alert.
reset_table();
$res = run_parallel(2, function ($i) {
    $pdo = db();
    if ($i === 1) {
        usleep(20 * 1000); // arrive just after the holder took the lock, before its insert (insert happens after 50 ms)
    }
    $alerts = 0;
    $r = gbfiuu_record_payment(
        array('invoiceid' => 8, 'transid' => 'WAITER', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => fake_add_payment($pdo, 3000), 'log' => function () {},
            'alert' => function () use (&$alerts) { $alerts++; }, 'lockWait' => 1)
    );
    return $r . ($alerts ? '+alert' : '');
});
sort($res);
check('waiter times out while the row already exists -> duplicate, no alert, 1 row', $res === array('duplicate', 'recorded') && count_rows('WAITER') === 1, implode(',', $res));

// 7f. Review 2 / L2: addInvoicePayment returns false AND the re-check cannot run (connection lost) -> not_recorded, no ack.
reset_table();
$pdo = db();
$alerts = array();
$r = gbfiuu_record_payment(
    array('invoiceid' => 9, 'transid' => 'FALSELOST', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
    array('pdo' => $pdo, 'addPayment' => function () use ($pdo) {
        $id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        db()->exec("KILL $id"); // another connection kills ours, like a DB restart inside the hooks
        return false;
    }, 'log' => function () {}, 'alert' => function ($m) use (&$alerts) { $alerts[] = $m; }, 'lockWait' => 1)
);
check('false return + lost connection -> not_recorded (no ack), alert raised', $r === 'not_recorded' && !gbfiuu_should_ack($r) && count($alerts) === 1, $r);

// 8. Time limit is raised, never lowered (production runs with 700 s; 0 = unlimited stays unlimited).
$orig = ini_get('max_execution_time');
ini_set('max_execution_time', '700'); gbfiuu_raise_time_limit(600); $a = ini_get('max_execution_time');
ini_set('max_execution_time', '0');   gbfiuu_raise_time_limit(600); $b = ini_get('max_execution_time');
ini_set('max_execution_time', '300'); gbfiuu_raise_time_limit(600); $c = ini_get('max_execution_time');
ini_set('max_execution_time', $orig);
check('time limit 700 stays 700, 0 stays 0, 300 becomes 600', $a === '700' && $b === '0' && $c === '600', "$a,$b,$c");

// 9. If the DB connection dies during hooks, RELEASE_LOCK fails but the result is kept ('recorded', no exception).
reset_table();
$pdo = db();
$killer = db();
$r = 'exception';
try {
    $r = gbfiuu_record_payment(
        array('invoiceid' => 3, 'transid' => 'CONNLOST', 'amount' => '1.00', 'gateway' => 'rms', 'gatewayname' => 'Fiuu', 'log' => array()),
        array('pdo' => $pdo, 'addPayment' => function ($inv, $tid, $amt) use ($pdo, $killer) {
            $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?, ?, ?, ?)')->execute(array($inv, $tid, $amt, 'rms'));
            $killer->exec('KILL CONNECTION ' . (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
        }, 'log' => function () {}, 'lockWait' => 1)
    );
} catch (\Throwable $e) {
    $r = 'exception: ' . $e->getMessage();
}
$free = (int) db()->query("SELECT IS_FREE_LOCK('" . gbfiuu_lock_name('CONNLOST', (string) db()->query('SELECT DATABASE()')->fetchColumn()) . "')")->fetchColumn();
check('connection lost during hooks: result kept, lock freed, 1 row', $r === 'recorded' && $free === 1 && count_rows('CONNLOST') === 1, "$r free=$free");

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
