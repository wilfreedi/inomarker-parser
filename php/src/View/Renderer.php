<?php

declare(strict_types=1);

namespace App\View;

final class Renderer
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    /** @param array<string, mixed> $params */
    public function render(string $template, array $params = []): string
    {
        $templatePath = $this->viewsPath . '/' . $template . '.php';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template does not exist: {$template}");
        }

        $renderComponent = fn (string $component, array $componentParams = []): string
            => $this->renderComponent($component, $componentParams);
        $params['renderComponent'] = $renderComponent;

        extract($params, EXTR_SKIP);
        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $params */
    private function renderComponent(string $component, array $params = []): string
    {
        $componentPath = $this->viewsPath . '/components/' . $component . '.php';
        if (!file_exists($componentPath)) {
            throw new \RuntimeException("Component does not exist: {$component}");
        }

        extract($params, EXTR_SKIP);
        ob_start();
        include $componentPath;

        return (string) ob_get_clean();
    }
}
