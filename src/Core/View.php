<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private static string $viewPath = '';

    public static function setPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    /** @param array<string,mixed> $data */
    public static function render(string $name, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::renderPartial($name, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderPartial($layout, $data + [
            'content' => $content,
            'title'   => $data['title'] ?? Config::get('app.name', 'Piazza Pulita'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function renderPartial(string $name, array $data = []): string
    {
        $file = self::$viewPath . '/' . ltrim($name, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vista non trovata: {$name} ({$file})");
        }

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
