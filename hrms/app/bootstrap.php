<?php
/**
 * Application bootstrap — shared by the web front controller and CLI jobs.
 */
use App\Core\Config;

require __DIR__ . '/Core/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.php');

date_default_timezone_set(Config::get('app.timezone', 'UTC'));

if (Config::get('app.env') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
