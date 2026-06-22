<?php
declare(strict_types=1);

namespace RenRouter\Template;

use InvalidArgumentException;
use RuntimeException;

/**
 * Class PhpTemplateEngine
 *
 * Renders plain PHP template files using output buffering.
 * Mirrors the original Router::render() / resolveViewPath() logic,
 * encapsulated behind TemplateEngineInterface.
 *
 * Layout support: if a `base.php` file exists in the views root,
 * it is used as the outer layout. The inner view content is
 * available as $pg_content inside base.php.
 *
 * @package RenRouter\Template
 */
final class PhpTemplateEngine implements TemplateEngineInterface
{
    /** @var string Resolved absolute path to views directory */
    private readonly string $viewsPath;

    /**
     * @param string $viewsPath Absolute path to the views directory
     * @throws InvalidArgumentException
     */
    public function __construct(string $viewsPath)
    {
        $real = realpath(rtrim($viewsPath, DIRECTORY_SEPARATOR));

        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new InvalidArgumentException(
                "Views path '{$viewsPath}' is not a readable directory."
            );
        }

        $this->viewsPath = $real;
    }

    /**
     * {@inheritDoc}
     */
    public function render(string $view, array $data = []): string
    {
        $viewFile = $this->resolveViewPath($view);

        // Inject variables into the view scope
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $pg_content = (string) ob_get_clean();

        // Wrap in layout if base.php exists
        $baseFile = $this->viewsPath . DIRECTORY_SEPARATOR . 'base.php';
        if (is_file($baseFile) && is_readable($baseFile)) {
            ob_start();
            require $baseFile;
            return (string) ob_get_clean();
        }

        return $pg_content;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $view): bool
    {
        try {
            $this->resolveViewPath($view);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolves and validates a view file path.
     * Provides strong protection against path traversal attacks.
     *
     * @param string $view View name (e.g. 'home', 'errors/404')
     * @return string Absolute file path
     *
     * @throws InvalidArgumentException On null bytes in view name
     * @throws RuntimeException         On path traversal or unreadable file
     */
    private function resolveViewPath(string $view): string
    {
        // Reject null bytes
        if (str_contains($view, "\0")) {
            throw new InvalidArgumentException('Invalid view name: null byte detected.');
        }

        // Strip traversal sequences
        $normalized = str_replace(['../', '..' . DIRECTORY_SEPARATOR], '', $view);
        $candidate = $this->viewsPath . DIRECTORY_SEPARATOR . $normalized . '.php';
        $realFile = realpath($candidate);

        if ($realFile === false || !str_starts_with($realFile, $this->viewsPath)) {
            throw new RuntimeException(
                "View '{$view}' resolves outside the allowed views directory."
            );
        }

        if (!is_file($realFile) || !is_readable($realFile)) {
            throw new RuntimeException("View file '{$view}.php' is not readable.");
        }

        return $realFile;
    }
}