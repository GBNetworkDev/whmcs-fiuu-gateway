<?php
// Minimal WHMCS stand-in for tests/endpoint_test.sh. NOT for production.
namespace WHMCS\Database {
    class Capsule
    {
        public static function connection()
        {
            return new class {
                public function getPdo()
                {
                    return \fake_pdo();
                }
            };
        }
    }
}

namespace {
    function fake_pdo()
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(getenv('TEST_DSN') ?: 'mysql:host=db;dbname=t', 'root', getenv('TEST_PASS') ?: 't',
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        }
        return $pdo;
    }

    // WHMCS exposes request variables as globals (the vendor skey line uses $tranID).
    foreach ($_POST as $k => $v) {
        if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
            $GLOBALS[$k] = $v;
        }
    }
    $CONFIG = array('SystemURL' => 'https://whmcs.test');
}
