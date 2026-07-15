<?php
/**
 * HTTP Basic Authentication gate for admin pages.
 * Include this file at the top of any page that should be protected.
 * Status and dashboard pages do NOT include this file.
 */
$cfg = require __DIR__ . '/../config.php';
$user = $cfg['admin_user'] ?? '';
$pass = $cfg['admin_pass'] ?? '';

// Skip auth if credentials are not configured
if ($user === '' && $pass === '') {
    return;
}

$givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!hash_equals($user, $givenUser) || !hash_equals($pass, $givenPass)) {
    header('WWW-Authenticate: Basic realm="Hunted Admin"');
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}
