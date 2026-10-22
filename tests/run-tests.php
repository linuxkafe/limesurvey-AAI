<?php
/**
 * Test harness for the AuthAAI plugin.
 *
 * Reproduces the pieces of the LimeSurvey 6 runtime that AuthAAI depends on
 * (AuthPluginBase, PluginBase, User, Permission, PluginEvent) so the plugin
 * logic can be exercised without a full LS6 installation.
 *
 * Usage: php tests/run-tests.php
 */

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------------------------------------------------------------------------
// Minimal LS6 runtime stubs
// ---------------------------------------------------------------------------

class LSAuthResult {
    private $code;
    private $message;
    public function __construct($code = 0, $message = '') {
        $this->code = $code;
        $this->message = $message;
    }
    public function getCode() { return $this->code; }
    public function getMessage() { return $this->message; }
}

class LSUserIdentity {
    public $username = '';
    public $password = '';
    public $plugin = 'Authdb';
    public $id = null;
    public $user = null;
}

class PluginEvent {
    private $name;
    private $content = [];
    private $stopped = false;
    public function __construct($name) { $this->name = $name; }
    public function getEventName() { return $this->name; }
    public function set($k, $v) { $this->content[$k] = $v; }
    public function get($k, $default = null) {
        return array_key_exists($k, $this->content) ? $this->content[$k] : $default;
    }
    public function stop() { $this->stopped = true; }
    public function isStopped() { return $this->stopped; }
}

class PluginBase {
    protected $storage = 'DummyStorage';
    protected static $description = '';
    protected static $name = 'PluginBase';
    protected $settings = [];
    protected $event = null;
    protected $api = null;
    public function getEvent() { return $this->event; }
    public function setEvent($e) { $this->event = $e; }
    protected function get($key = null, $model = null, $id = null, $default = null) {
        return $default;
    }
}

class AuthPluginBase extends PluginBase {
    const ERROR_NONE = 0;
    const ERROR_UNKNOWN_IDENTITY = 100;
    const ERROR_USERNAME_INVALID = 10;
    const ERROR_AUTH_METHOD_INVALID = 30;

    protected $_username = null;
    protected $_password = null;

    protected function getUserName() { return $this->_username; }
    protected function getPassword() { return $this->_password; }
    protected function setUsername($username) {
        $this->_username = $username;
        $event = $this->getEvent();
        $identity = $event->get('identity');
        $identity->username = $username;
        $event->set('identity', $identity);
        return $this;
    }
    public function setAuthSuccess(User $user, $event = null) {
        if (empty($event)) { $event = $this->getEvent(); }
        $identity = $event->get('identity');
        $identity->id = $user->uid;
        $identity->user = $user;
        $event->set('identity', $identity);
        $event->set('result', new LSAuthResult(self::ERROR_NONE));
        return $this;
    }
    public function setAuthFailure($code = self::ERROR_UNKNOWN_IDENTITY, $message = '', $event = null) {
        if (empty($event)) { $event = $this->getEvent(); }
        $identity = $this->getEvent()->get('identity');
        $identity->id = null;
        $event->set('result', new LSAuthResult($code, $message));
        return $this;
    }
    public function setAuthPlugin($event = null) {
        if (empty($event)) { $event = $this->getEvent(); }
        $identity = $event->get('identity');
        $identity->plugin = get_class($this);
        $event->stop();
        return $this;
    }
}

class User {
    public $uid = 0;
    public $users_name = '';
    public $password = '';
    public $full_name = '';
    public $parent_id = 0;
    public $lang = '';
    public $email = '';
    public static $stored = [];
    public function setPassword($p, $save = false) {
        $this->password = password_hash($p, PASSWORD_DEFAULT);
        return $this;
    }
    public function save() {
        if ($this->users_name === '' || $this->email === '') { return false; }
        $this->uid = count(self::$stored) + 1;
        self::$stored[$this->users_name] = $this;
        return true;
    }
}

class Permission {
    public static $granted = [];
    public static function model() {
        return new self();
    }
    public function setGlobalPermission($uid, $permType, array $perms = ['read_p']) {
        self::$granted[$uid][$permType] = $perms;
    }
    public function hasGlobalPermission($permType, $crud = 'read', $uid = null) {
        if (!isset(self::$granted[$uid][$permType])) return false;
        $crudFlag = strtolower($crud) . '_p';
        return in_array($crudFlag, self::$granted[$uid][$permType]);
    }
}

class LimesurveyApi {
    public function getUserByName($username) {
        if (!is_string($username) || $username === '') { return null; }
        return isset(User::$stored[$username]) ? User::$stored[$username] : null;
    }
}

function createPassword($len = 12) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len);
}

require __DIR__ . '/../AuthAAI.php';

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function assertTrue($cond, $msg) {
    if ($cond) { $GLOBALS['passed']++; echo "  ok   $msg\n"; }
    else { $GLOBALS['failed']++; echo "  FAIL $msg\n"; }
}
function assertEq($expected, $actual, $msg) {
    if ($expected === $actual) { $GLOBALS['passed']++; echo "  ok   $msg\n"; }
    else {
        $GLOBALS['failed']++;
        echo "  FAIL $msg (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

/** Build a plugin instance wired to a fresh event, mimicking LS6 dispatch. */
function makePlugin(array $server, array $env = []) {
    $p = new AuthAAI();
    $p->setEvent(new PluginEvent('beforeLogin'));
    // $api is protected on PluginBase, exactly as in LS6.
    // Since PHP 8.1 reflection can access protected members without
    // setAccessible(), which is deprecated as of PHP 8.5.
    $apiProp = new ReflectionProperty(PluginBase::class, 'api');
    $apiProp->setValue($p, new LimesurveyApi());
    $_SERVER = array_merge($server);
    putenv('PATH=/usr/bin:/bin');
    foreach ($env as $k => $v) { putenv("$k=$v"); }
    return $p;
}

function clearEnv() {
    foreach (['eppn','nmec','cn','mail'] as $k) { putenv($k); }
}

echo "== 1. Attribute reading from \$_SERVER (Apache + mod_php) ==\n";
clearEnv();
$p = makePlugin([
    'eppn' => 'up487513@up.pt',
    'nmec' => '487513',
    'cn'   => 'Miguel Ferraz Carneiro dos Santos Costa',
    'mail' => 'mferraz@uporto.pt',
]);
$m = new ReflectionMethod($p, 'getShibAttribute');
assertEq('up487513@up.pt', $m->invoke($p, 'eppn'), 'eppn read from $_SERVER');
assertEq('487513', $m->invoke($p, 'nmec'), 'nmec read from $_SERVER');
assertEq(null, $m->invoke($p, 'absent'), 'absent attribute returns null');

echo "\n== 2. Attribute reading from getenv() (nginx + php-fpm) ==\n";
clearEnv();
$p = makePlugin([], ['eppn' => 'up487513@up.pt', 'nmec' => '487513']);
assertEq('up487513@up.pt', $m->invoke($p, 'eppn'), 'eppn read from getenv() when absent in $_SERVER');
assertEq('487513', $m->invoke($p, 'nmec'), 'nmec read from getenv()');
clearEnv();

echo "\n== 3. Multi-valued attributes ==\n";
clearEnv();
$p = makePlugin(['eppn' => 'a@up.pt', 'mail' => ['x@up.pt', 'y@up.pt']]);
assertEq('x@up.pt', $m->invoke($p, 'mail'), 'array attribute takes first value');
$p = makePlugin(['eppn' => 'a@up.pt', 'mail' => 'x@up.pt;y@up.pt']);
assertEq('x@up.pt', $m->invoke($p, 'mail'), '";" separated attribute takes first value');

echo "\n== 4. beforeLogin() claims the authentication ==\n";
clearEnv();
$p = makePlugin([
    'eppn' => 'up487513@up.pt',
    'nmec' => '487513',
    'cn'   => 'Miguel Ferraz Carneiro dos Santos Costa',
    'mail' => 'mferraz@uporto.pt',
]);
$identity = new LSUserIdentity();
$p->getEvent()->set('identity', $identity);
$p->beforeLogin();
assertEq('487513', $identity->username, 'username set from nmec');
assertEq('AuthAAI', $identity->plugin, 'plugin claimed (AuthAAI)');
assertTrue($p->getEvent()->isStopped(), 'event stopped (login form skipped)');

echo "\n== 5. beforeLogin() is a no-op without AAI attributes ==\n";
clearEnv();
$p = makePlugin(['HTTP_USER_AGENT' => 'curl']);
$identity = new LSUserIdentity();
$p->getEvent()->set('identity', $identity);
$p->beforeLogin();
assertEq('', $identity->username, 'username untouched without eppn');
assertEq('Authdb', $identity->plugin, 'plugin NOT claimed without eppn');
assertTrue(!$p->getEvent()->isStopped(), 'event NOT stopped without eppn');

echo "\n== 6. Missing nmec falls back to eppn (no PHP warning) ==\n";
clearEnv();
$p = makePlugin(['eppn' => 'up487513@up.pt', 'cn' => 'X', 'mail' => 'm@up.pt']);
$identity = new LSUserIdentity();
$p->getEvent()->set('identity', $identity);
$p->beforeLogin();
assertEq('up487513@up.pt', $identity->username, 'username falls back to eppn when nmec missing');

echo "\n== 7. newUserSession() ignores other auth methods ==\n";
clearEnv();
$p = makePlugin(['eppn' => 'up487513@up.pt', 'nmec' => '487513']);
$ev = new PluginEvent('newUserSession');
$identity = new LSUserIdentity();
$identity->plugin = 'Authdb';
$ev->set('identity', $identity);
$p->setEvent($ev);
$p->newUserSession();
assertEq(null, $ev->get('result'), 'no result set when plugin is not AuthAAI (guard works)');

echo "\n== 8. newUserSession() creates the user on first AAI login ==\n";
clearEnv();
User::$stored = [];
$p = makePlugin(['eppn' => 'up487513@up.pt', 'nmec' => '487513', 'cn' => 'Miguel Ferraz', 'mail' => 'm@up.pt']);
$ev = new PluginEvent('newUserSession');
$identity = new LSUserIdentity();
$identity->plugin = 'AuthAAI';
$identity->username = '487513';
$ev->set('identity', $identity);
$p->setEvent($ev);
$p->beforeLogin();
$p->newUserSession();
assertTrue(isset(User::$stored['487513']), 'user created with username from nmec');
assertEq('Miguel Ferraz', User::$stored['487513']->full_name, 'full_name set from cn');
assertEq('m@up.pt', User::$stored['487513']->email, 'email set from mail');
assertTrue(strlen(User::$stored['487513']->password) > 0, 'password hashed');
assertTrue(password_verify('x', User::$stored['487513']->password) === false, 'password is not plaintext');

echo "\n== 9. newUserSession() logs in an existing user ==\n";
clearEnv();
$p = makePlugin(['eppn' => 'up487513@up.pt', 'nmec' => '487513']);
$ev = new PluginEvent('newUserSession');
$identity = new LSUserIdentity();
$identity->plugin = 'AuthAAI';
$identity->username = '487513';
$ev->set('identity', $identity);
$p->setEvent($ev);
$p->beforeLogin();
$p->newUserSession();
assertEq(0, $ev->get('result')->getCode(), 'auth succeeded for existing user');

echo "\n== 10. newUserSession() without AAI attributes fails explicitly ==\n";
clearEnv();
$p = makePlugin([]);
$ev = new PluginEvent('newUserSession');
$identity = new LSUserIdentity();
$identity->plugin = 'AuthAAI';
$identity->username = 'ghost';
$ev->set('identity', $identity);
$p->setEvent($ev);
$p->newUserSession();
assertEq(100, $ev->get('result')->getCode(), 'ERROR_UNKNOWN_IDENTITY set when no attributes (no silent pass)');
assertTrue(!isset(User::$stored['ghost']), 'no user created without attributes');

echo "\n== 11. full_name truncated to the 50 char column limit ==\n";
clearEnv();
User::$stored = [];
$long = str_repeat('A', 80);
$p = makePlugin(['eppn' => 'a@up.pt', 'nmec' => '900001', 'cn' => $long, 'mail' => 'm@up.pt']);
$ev = new PluginEvent('newUserSession');
$identity = new LSUserIdentity();
$identity->plugin = 'AuthAAI';
$ev->set('identity', $identity);
$p->setEvent($ev);
$p->beforeLogin();
$p->newUserSession();
assertEq(50, strlen(User::$stored['900001']->full_name), 'full_name truncated to 50 chars');

echo "\n";
echo "----------------------------------------\n";
echo "passed: {$GLOBALS['passed']}   failed: {$GLOBALS['failed']}\n";
exit($GLOBALS['failed'] > 0 ? 1 : 0);
