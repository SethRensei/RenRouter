<?php
declare(strict_types=1);

namespace RenRouter\Template;

/**
 * Interface TemplateEngineInterface
 *
 * Contract that every template engine adapter must fulfill.
 * Allows the Router to remain agnostic about the underlying
 * rendering technology (PHP files, Twig, Blade, Plates…).
 *
 * @package RenRouter\Template
 */
interface TemplateEngineInterface
{
    /**
     * Renders a template and returns the rendered HTML string.
     *
     * @param string               $view View name / path (engine-specific format)
     * @param array<string, mixed> $data Variables injected into the template
     *
     * @return string Rendered HTML
     */
    public function render(string $view, array $data = []): string;

    /**
     * Returns whether the engine supports a given view name.
     * Useful for fallback chains or multi-engine setups.
     *
     * @param string $view
     * @return bool
     */
    public function supports(string $view): bool;
}
