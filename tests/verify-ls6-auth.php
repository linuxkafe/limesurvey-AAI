<?php
/**
 * End-to-end verification of the AuthAAI plugin against a real LimeSurvey 6
 * installation.
 *
 * Exercises the same code path LS6 uses for an AAI login:
 *   Authentication::prepareLogin()  -> dispatches 'beforeLogin'
 *   AuthAAI::beforeLogin()         -> claims the auth (setAuthPlugin)
 *   LSUserIdentity::authenticate()  -> dispatches 'newUserSession'
 *   AuthAAI::newUserSession()       -> creates / logs in the user
 *
 * Usage (inside the LS6 container):
 *   php /var/www/html/tests/verify-ls6-auth.php
 */

// Shared bootstrap: resolves the LS6 root, registers the 'webroot' alias and
// provides the request context LS6 needs outside a real web request.
require_once __DIR__ . '/ls6-bootstrap.php';

$passed = 0;
$failed = 0;
function assertTrue($cond, $msg) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok   $msg\n"; }
    else { $failed++; echo "  FAIL $msg\n"; }
}

echo "LimeSurvey version: " . App()->getConfig('versionnumber') . "\n";
echo "PHP: " . PHP_VERSION . "\n\n";

// ---------------------------------------------------------------------------
// Helpers: drive the real LS6 event flow
// ---------------------------------------------------------------------------

/**
 * Runs the LS6 'beforeLogin' dispatch exactly as Authentication::prepareLogin()
 * does it, and returns the resulting identity.
 */
function runBeforeLogin() {
    $event = new PluginEvent('beforeLogin');
    $event->set('identity', new LSUserIdentity('', ''));
    App()->getPluginManager()->dispatchEvent($event);
    return array($event, $event->get('identity'));
}

/** Runs the LS6 'newUserSession' dispatch as LSUserIdentity::authenticate() does. */
function runNewUserSession(PluginEvent $beforeEvent, LSUserIdentity $identity) {
    $event = new PluginEvent('newUserSession', $identity);
    $event->set('identity', $identity);
    App()->getPluginManager()->dispatchEvent($event);
    return $event;
}

$attrs = array(
    'eppn' => 'up487513@up.pt',
    'nmec' => '487513',
    'cn'   => 'Miguel Ferraz Carneiro dos Santos Costa',
    'mail' => 'mferraz@uporto.pt',
);

echo "== 1. Plugin is loaded and recognised by LS6 ==\n";
$plugin = Plugin::model()->findByAttributes(array('name' => 'AuthAAI'));
assertTrue($plugin !== null, 'AuthAAI present in lime_plugins');
assertTrue($plugin->active == 1, 'AuthAAI is active');
// getPluginInfo() must run BEFORE the class is autoloaded, otherwise
// $extensionConfig stays null and isCompatible is reported as false.
$info = App()->getPluginManager()->getPluginInfo('AuthAAI');
assertTrue(is_array($info) && !empty($info['description']), 'getPluginInfo() resolves: ' . ($info['description'] ?? 'n/a'));
assertTrue(!empty($info['isCompatible']), 'config.xml reports compatible with this LS version');
// Load the plugin the way LS6 does: through the PluginManager, so that
// init() runs and the event subscriptions are registered.
$loaded = App()->getPluginManager()->loadPlugin('AuthAAI', $plugin->id);
assertTrue($loaded !== null, 'PluginManager::loadPlugin() returns the plugin');
assertTrue(class_exists('AuthAAI'), 'AuthAAI class is loaded');
$subsProp = new ReflectionProperty(App()->getPluginManager(), 'subscriptions');
$subs = $subsProp->getValue(App()->getPluginManager());
assertTrue(isset($subs['beforeLogin']), 'beforeLogin event has subscribers');
assertTrue(isset($subs['newUserSession']), 'newUserSession event has subscribers');

echo "\n== 2. LS6 accepts the plugin's config.xml ==\n";
$extConfig = \ExtensionConfig::loadFromFile(getcwd() . '/plugins/AuthAAI/config.xml');
assertTrue($extConfig !== null, 'config.xml parsed by ExtensionConfig');
assertTrue($extConfig->isCompatible() === true, 'ExtensionConfig::isCompatible() == true');
assertTrue($extConfig->getName() === 'AuthAAI', 'config.xml name == AuthAAI');

echo "\n== 3. beforeLogin() claims the auth when AAI attributes are present ==\n";
$_SERVER = array_merge($_SERVER, $attrs);
list($beforeEvent, $identity) = runBeforeLogin();
assertTrue($identity->plugin === 'AuthAAI', 'identity->plugin == AuthAAI (got "' . $identity->plugin . '")');
assertTrue($beforeEvent->isStopped(), 'beforeLogin event stopped -> LS6 skips the login form');
assertTrue($identity->username === '487513', 'username from nmec (got "' . $identity->username . '")');

echo "\n== 4. beforeLogin() does NOT claim without AAI attributes ==\n";
// Remove only the AAI attributes, keeping the request context intact
// (LS6 needs REQUEST_URI and friends to resolve the route).
foreach (array('eppn', 'nmec', 'cn', 'mail') as $aaiAttr) {
    unset($_SERVER[$aaiAttr]);
}
list($beforeEvent2, $identity2) = runBeforeLogin();
assertTrue($identity2->plugin !== 'AuthAAI', 'plugin not claimed (got "' . $identity2->plugin . '")');
assertTrue(!$beforeEvent2->isStopped(), 'event not stopped -> normal login form shown');

echo "\n== 5. newUserSession() creates the user on first AAI login ==\n";
$_SERVER = array_merge($_SERVER, $attrs);
// Start from a clean slate so the test is repeatable.
$u = User::model()->findByAttributes(array('users_name' => '487513'));
if ($u !== null) {
    Permission::model()->deleteAllByAttributes(array('uid' => $u->uid));
    $u->delete();
}
list($beforeEvent, $identity) = runBeforeLogin();
$session = new PluginEvent('newUserSession', $identity);
$session->set('identity', $identity);
App()->getPluginManager()->dispatchEvent($session);
$created = User::model()->findByAttributes(array('users_name' => '487513'));
assertTrue($created !== null, 'user created with username from nmec');
if ($created !== null) {
    assertTrue($created->email === 'mferraz@uporto.pt', 'email from mail attribute: ' . $created->email);
    assertTrue($created->full_name === 'Miguel Ferraz Carneiro dos Santos Costa', 'full_name from cn attribute: ' . $created->full_name);
    assertTrue($created->lang === 'auto', 'lang == auto');
    assertTrue($created->canLogin() === true, 'canLogin() == true (user is active)');
    assertTrue(!empty($created->password), 'password stored');
    assertTrue(strlen($created->password) === 60, 'password is bcrypt (60 chars), got ' . strlen($created->password));
}

echo "\n== 6. newUserSession() logs in the existing user on second login ==\n";
list($beforeEvent, $identity) = runBeforeLogin();
$session = new PluginEvent('newUserSession', $identity);
$session->set('identity', $identity);
App()->getPluginManager()->dispatchEvent($session);
$result = $session->get('result');
assertTrue($result instanceof LSAuthResult, 'LSAuthResult produced');
assertTrue($result->getCode() === 0, 'result code == ERROR_NONE (got ' . $result->getCode() . ')');
assertTrue($identity->id !== null, 'identity->id set to the user uid (' . $identity->id . ')');
assertTrue($identity->user !== null && $identity->user->uid === $identity->id, 'identity->user populated');

echo "\n== 7. guard: newUserSession() ignores other auth methods ==\n";
$ev = new PluginEvent('newUserSession');
$otherIdentity = new LSUserIdentity('', '');
$otherIdentity->plugin = 'Authdb';
$otherIdentity->username = 'admin';
$ev->set('identity', $otherIdentity);
App()->getPluginManager()->dispatchEvent($ev);
assertTrue($ev->get('result') === null || $ev->get('result')->getCode() !== 0, 'AuthAAI did not authenticate an Authdb login');

echo "\n== 8. permission_create_survey grants the 'surveys' global permission ==\n";
$created = User::model()->findByAttributes(array('users_name' => '487513'));
if ($created !== null) {
    // Enable the plugin setting through the real PluginSetting model, the way
    // the LS6 plugin settings screen does it.
    $pluginId = Plugin::model()->findByAttributes(array('name' => 'AuthAAI'))->id;
    PluginSetting::model()->deleteAllByAttributes(array('plugin_id' => $pluginId, 'key' => 'permission_create_survey'));
    $setting = new PluginSetting();
    $setting->plugin_id = $pluginId;
    $setting->model = null;
    $setting->model_id = null;
    $setting->key = 'permission_create_survey';
    $setting->value = '1';
    $setting->save();

    $_SERVER = array_merge($_SERVER, array('eppn' => 'up999999@up.pt', 'nmec' => '999999', 'cn' => 'Test Student', 'mail' => 'student999999@up.pt'));
    $u2 = User::model()->findByAttributes(array('users_name' => '999999'));
    if ($u2 !== null) { $u2->delete(); }
    list($beforeEvent, $identity) = runBeforeLogin();
    $session = new PluginEvent('newUserSession', $identity);
    $session->set('identity', $identity);
    App()->getPluginManager()->dispatchEvent($session);
    $created2 = User::model()->findByAttributes(array('users_name' => '999999'));
    assertTrue($created2 !== null, 'second user created');
    if ($created2 !== null) {
        $has = Permission::model()->hasGlobalPermission('surveys', 'create', $created2->uid);
        assertTrue($has === true, 'hasGlobalPermission(surveys, create) == true for the new user');
        $hasAll = Permission::model()->hasGlobalPermission('surveys', 'delete', $created2->uid);
        assertTrue($hasAll === true, 'hasGlobalPermission(surveys, delete) == true (all crud flags granted)');
        // Clean up so the test is repeatable.
        Permission::model()->deleteAllByAttributes(array('uid' => $created2->uid));
        $created2->delete();
    }
    // Restore the setting to its default so reruns behave identically.
    PluginSetting::model()->deleteAllByAttributes(array('plugin_id' => $pluginId, 'key' => 'permission_create_survey'));
}

echo "\n";
echo "----------------------------------------\n";
echo "passed: $passed   failed: $failed\n";
exit($failed > 0 ? 1 : 0);
