<?php
namespace App\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, $handler): void  { $this->routes['GET'][$path]  = $handler; }
    public function post(string $path, $handler): void { $this->routes['POST'][$path] = $handler; }

    public function dispatch(string $method, string $uri): void
    {
        $path = trim(parse_url($uri, PHP_URL_PATH) ?? '', '/');
        $base = trim(Config::get('app.base_url', '/'), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = trim(substr($path, strlen($base)), '/');
        }
        $path = $path === '' ? 'dashboard' : $path;

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            http_response_code(404);
            View::render('errors/404', ['path' => $path], 'plain');
            return;
        }
        [$class, $action] = $handler;
        $controller = new $class();
        $controller->$action();
    }
}
