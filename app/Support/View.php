<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Native PHP template renderer with layouts, partials (components) and
 * shared data. Templates keep presentation only; escaping is explicit
 * through the e() helper.
 */
final class View
{
    /** Shared data available in every template (e.g. auth, business profile). */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template inside its layout.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * Render a template without a layout.
     *
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/resources/views/' . str_replace(['\\', '..'], ['/', ''], $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View [{$template}] not found at {$file}");
        }

        extract(array_merge(self::$shared, $data), EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Render a component from resources/views/components. */
    public static function component(string $name, array $data = []): string
    {
        return self::partial('components/' . $name, $data);
    }

    /** Escape for HTML output. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
