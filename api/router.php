<?php
/**
 * RxCore — Vercel Serverless PHP Front Router
 * Routes /, /index.php, and /pages/*.php through api/ so vercel-php
 * serves the existing XAMPP directory layout without moving files.
 */

$rootDir = dirname(__DIR__);
$route   = ltrim((string)($_GET['__route'] ?? 'index.php'), '/');

unset($_GET['__route'], $_REQUEST['__route']);

$isValid = ($route === 'index.php')
    || (bool)preg_match('/^pages\/[a-zA-Z0-9_-]+\.php$/', $route);

$target = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route);

if (!$isValid || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '404 Not Found';
    exit;
}

$_SERVER['SCRIPT_NAME']     = '/' . $route;
$_SERVER['PHP_SELF']        = '/' . $route;
$_SERVER['SCRIPT_FILENAME'] = $target;

chdir(dirname($target));
require $target;
