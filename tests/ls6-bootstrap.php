<?php
/**
 * Bootstraps a LimeSurvey 6 application inside a CLI script so the test
 * can inspect the database directly.
 *
 * Mirrors the bootstrap performed by LS6's index.php, in the same order:
 *   vendor/autoload.php -> yii.php -> LSYii_Application -> config/internal.php
 */

if (!defined('BASEPATH')) {
    // The LS6 root is the directory containing index.php / application/.
    // Resolve it by walking up from this file until application/ is found, so
    // the bootstrap works regardless of the current working directory and of
    // whether the file was required by an absolute or a relative path.
    $root = null;
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($dir . '/application') && is_dir($dir . '/vendor/yiisoft/yii/framework')) {
            $root = $dir;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) { break; }
        $dir = $parent;
    }
    if ($root === null) {
        $root = getcwd();
    }
    $root = realpath($root);
    $system_path = $root . '/vendor/yiisoft/yii/framework';
    define('SELF', pathinfo(__FILE__, PATHINFO_BASENAME));
    define('ROOT', $root);
    define('EXT', '.php');
    define('BASEPATH', rtrim($system_path, '/') . '/');
    define('FCPATH', str_replace(SELF, '', __FILE__));
    define('APPPATH', $root . '/application/');

    // LS6 resolves the request URI when routing; provide a minimal request
    // context so the application can boot outside a real web request.
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/index.php';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? ($root . '/index.php');
    $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? '/index.php';
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
    $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $root;
    // Yii 1.1's CApplication::preinit() derives the 'webroot' alias from
    // dirname($_SERVER['SCRIPT_FILENAME']). Point it at the LS6 entry script
    // so the alias resolves to the installation root, as it does for a real
    // request to index.php.
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';

    require_once $root . '/vendor/autoload.php';
    require_once BASEPATH . 'yii' . EXT;

    // LSYii_Application (web) does not register the 'webroot' alias, only
    // ConsoleApplication does. Register it before the application is built so
    // the plugin folder resolves the same way in both contexts.
    // getPathOfAlias() throws when the alias is undefined, so guard it.
    try {
        $webroot = Yii::getPathOfAlias('webroot');
    } catch (Exception $e) {
        $webroot = null;
    }
    if ($webroot !== $root) {
        Yii::setPathOfAlias('webroot', $root);
    }

    require_once APPPATH . 'core/LSYii_Application' . EXT;

    $config = require_once(APPPATH . 'config/internal' . EXT);
    Yii::$enableIncludePath = false;
    Yii::createApplication('LSYii_Application', $config);
}
