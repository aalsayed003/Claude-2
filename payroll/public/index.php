<?php
/**
 * Front controller. Point your web server's document root here.
 */
use App\Core\Auth;

require dirname(__DIR__) . '/app/bootstrap.php';

Auth::start();

$router = require dirname(__DIR__) . '/app/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
