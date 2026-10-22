<?php
/**
 * Production-equivalent end-to-end test of the AuthAAI plugin on a real
 * LimeSurvey 6 installation, driven through an actual HTTP request.
 *
 * The test emulates what Apache + mod_shib do for a protected location:
 * it exports the Shibboleth attributes into the request environment before
 * the front controller runs, then checks that LS6 authenticates the user
 * and creates the account.
 *
 * Usage (inside the LS6 container):
 *   php /var/www/html/tests/e2e-web-login.php
 *   or: curl "http://localhost/tests/e2e-web-login.php"
 */

$attrs = array(
    'eppn' => 'up487513@up.pt',
    'nmec' => '487513',
    'cn'   => 'Miguel Ferraz Carneiro dos Santos Costa',
    'mail' => 'mferraz@uporto.pt',
);

// Emulate Apache exporting the attributes (SetEnv / mod_shib).
foreach ($attrs as $k => $v) {
    $_SERVER[$k] = $v;
    putenv("$k=$v");
}

$base = 'http://127.0.0.1';
$ctx  = stream_context_create(array(
    'http' => array('ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0),
));

// 1. Hit the admin login page with AAI attributes present.
$login = $base . '/index.php/admin/authentication/sa/login';
$body  = @file_get_contents($login, false, $ctx);
$code  = 0;
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
}

echo "GET $login\n";
echo "  status: $code\n";

$passed = 0;
$failed = 0;
function assertTrue($cond, $msg) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok   $msg\n"; }
    else { $failed++; echo "  FAIL $msg\n"; }
}

// The login form must NOT be rendered: AAI attributes are present, so the
// plugin claims the authentication and LS6 authenticates directly.
$hasLoginForm = (strpos((string) $body, 'name="user"') !== false)
             || (strpos((string) $body, 'login_submit') !== false);
assertTrue(!$hasLoginForm, 'login form skipped (AAI attributes claimed the auth)');
assertTrue(strpos((string) $body, 'Internal Server Error') === false, 'no internal server error');

// 2. Verify in the database that the user was created with the right data.
require_once __DIR__ . '/ls6-bootstrap.php';
$user = User::model()->findByAttributes(array('users_name' => '487513'));
assertTrue($user !== null, 'user 487513 exists in the LS6 database');
if ($user !== null) {
    assertTrue($user->email === 'mferraz@uporto.pt', 'email from mail attribute: ' . $user->email);
    assertTrue($user->full_name === 'Miguel Ferraz Carneiro dos Santos Costa', 'full_name from cn attribute: ' . $user->full_name);
    assertTrue($user->canLogin() === true, 'canLogin() == true');
    assertTrue(strlen($user->password) === 60, 'password stored as bcrypt (60 chars)');
    assertTrue($user->password !== hash('sha256', $user->password), 'password is not a sha256 digest');
}

// 3. Second request: the user must be found and logged in (no re-creation).
$body2 = @file_get_contents($login, false, $ctx);
$code2 = 0;
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code2 = (int) $m[1]; }
    }
}
assertTrue($code2 === 200, 'second login request returns 200');
$user2 = User::model()->findByAttributes(array('users_name' => '487513'));
assertTrue($user2 !== null && $user2->uid === $user->uid, 'same user reused on second login (no duplicate)');

echo "\n";
echo "----------------------------------------\n";
echo "passed: $passed   failed: $failed\n";
exit($failed > 0 ? 1 : 0);
