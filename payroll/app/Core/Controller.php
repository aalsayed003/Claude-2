<?php
namespace App\Core;

abstract class Controller
{
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::app();
    }

    protected function view(string $template, array $data = [], string $layout = 'app'): void
    {
        View::render($template, $data, $layout);
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }

    protected function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /** Reject POSTs without a valid CSRF token. */
    protected function verifyCsrf(): void
    {
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            echo 'Invalid or expired form token. Go back and try again.';
            exit;
        }
    }

    protected function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
    }

    protected function input(string $key, $default = null)
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }
}
