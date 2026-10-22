<?php
/**
 * Diagnostic probe for AAI/Shibboleth attribute delivery.
 *
 * Deploy this to the LimeSurvey web root (next to index.php) and request it
 * while authenticated through the Shibboleth SP. It reports whether the
 * attributes reach PHP, and through which channel, which is the first thing
 * to check when AAI login stops working.
 *
 * It never prints the attribute values themselves, only their presence and
 * length, so it is safe to leave reachable briefly.
 *
 * Delete the file once the diagnosis is done.
 */

header('Content-Type: text/plain; charset=utf-8');

$attributes = array('eppn', 'nmec', 'cn', 'mail', 'givenName', 'sn');

echo "AAI attribute delivery probe\n";
echo str_repeat('=', 60) . "\n";
echo 'PHP version : ' . PHP_VERSION . "\n";
echo 'SAPI        : ' . PHP_SAPI . "\n";
echo 'Server      : ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo 'Request URI : ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";
echo 'Script name : ' . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . "\n";
echo 'variables_order: ' . ini_get('variables_order') . "\n";
echo 'clear_env   : ' . (ini_get('clear_env') !== false ? ini_get('clear_env') : 'n/a (mod_php)') . "\n\n";

echo "Attribute delivery\n";
echo str_repeat('-', 60) . "\n";
printf("%-12s %-14s %-14s %-10s %s\n", 'attribute', '$_SERVER', 'getenv()', '$_ENV', 'length');
foreach ($attributes as $attr) {
    $inServer = isset($_SERVER[$attr]) ? 'yes' : 'no';
    $env = getenv($attr);
    $inEnv = ($env === false) ? 'no' : 'yes';
    $inEnvArr = isset($_ENV[$attr]) ? 'yes' : 'no';
    $len = ($env === false) ? 0 : strlen((string) $env);
    printf("%-12s %-14s %-14s %-10s %d\n", $attr, $inServer, $inEnv, $inEnvArr, $len);
}

echo "\nDiagnosis\n";
echo str_repeat('-', 60) . "\n";

$viaServer = false;
$viaGetenv = false;
foreach ($attributes as $attr) {
    if (isset($_SERVER[$attr])) { $viaServer = true; }
    if (getenv($attr) !== false) { $viaGetenv = true; }
}

if ($viaServer) {
    echo "OK: attributes are visible in \$_SERVER. The AuthAAI plugin can read them.\n";
    echo "     If AAI login still fails, check that the Shibboleth SP is issuing the\n";
    echo "     session for this exact URL (the 'eppn' attribute must be present).\n";
} elseif ($viaGetenv) {
    echo "PARTIAL: attributes are only in the process environment, not in \$_SERVER.\n";
    echo "          The AuthAAI plugin reads getenv() as a fallback, so this still works.\n";
    echo "          If AAI login fails, the attributes are not reaching PHP at all:\n";
    echo "            - Apache + mod_php : check SetEnv / mod_shib configuration\n";
    echo "            - Apache + php-fpm : check that SetEnv values are forwarded\n";
    echo "            - nginx  + php-fpm : add fastcgi_param for each attribute\n";
    echo "                               (e.g. fastcgi_param eppn \$eppn;)\n";
    echo "                               and consider clear_env = no in the FPM pool\n";
} else {
    echo "FAIL: no AAI attributes reached PHP.\n";
    echo "      The Shibboleth SP is not exporting them for this request.\n";
    echo "      Check:\n";
    echo "        1. the request URL is the one protected by the SP\n";
    echo "        2. the SP session is valid (not expired)\n";
    echo "        3. the attribute release includes eppn, nmec, cn and mail\n";
    echo "        4. the front controller in use is the one under SP protection\n";
    echo "           (this script reports SCRIPT_NAME above)\n";
}

echo "\nNote: this file exposes no attribute values and should be deleted after use.\n";
