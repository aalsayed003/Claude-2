<?php
namespace App\Core;

class View
{
    public static function render(string $template, array $data = [], string $layout = 'app'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === 'plain') {
            echo $content;
            return;
        }
        $layoutFile = dirname(__DIR__) . '/Views/layouts/' . $layout . '.php';
        require $layoutFile;
    }
}
