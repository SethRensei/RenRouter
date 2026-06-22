<?php
declare(strict_types=1);

namespace RenRouter\Template;

use InvalidArgumentException;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;
use RenRouter\Router;

/**
 * Class TwigEngine
 *
 * Twig template engine adapter for RenRouter.
 *
 * Integrates Twig exactly as Symfony does:
 *  - Templates resolved from a configurable `templates/` directory
 *  - Debug mode awareness (dumps, auto-reload)
 *  - Built-in `path()`, `url()`, `asset()` Twig functions
 *  - Custom Twig extensions / filters / functions support
 *  - Optional cache directory for compiled templates
 *  - Auto-escaping enabled by default (XSS protection)
 *
 * Usage:
 * ```php
 * $twig = TwigEngine::create(
 *     viewsPath: __DIR__ . '/templates',
 *     router: $router,
 *     debug: true,
 *     cachePath: __DIR__ . '/var/cache/twig',
 * );
 *
 * $router = new Router(
 *     viewsPath: __DIR__ . '/templates',
 *     templateEngine: $twig,
 * );
 * ```
 *
 * @package RenRouter\Template
 */
final class TwigEngine implements TemplateEngineInterface
{
    private readonly Environment $twig;
    private readonly string $templateExtension;

    /**
     * @param Environment $twig               Configured Twig Environment
     * @param string      $templateExtension  File extension for templates (default: 'twig')
     */
    public function __construct(Environment $twig, string $templateExtension = 'twig')
    {
        $this->twig = $twig;
        $this->templateExtension = ltrim($templateExtension, '.');
    }

    /**
     * Factory method — mirrors Symfony's TwigBundle setup.
     *
     * @param string      $viewsPath  Absolute path to templates directory
     * @param Router|null $router     Router instance for url()/path()/asset() functions
     * @param bool        $debug      Enable Twig debug mode (auto-reload, dump())
     * @param string|null $cachePath  Absolute path for compiled template cache (null = disabled)
     * @param string      $ext        Template file extension (default: 'twig')
     * @param array       $options    Extra Twig Environment options
     *
     * @return self
     * @throws InvalidArgumentException
     */
    public static function create(
        string $viewsPath,
        ?Router $router = null,
        bool $debug = false,
        ?string $cachePath = null,
        string $ext = 'twig',
        array $options = []
    ): self {
        $real = realpath(rtrim($viewsPath, DIRECTORY_SEPARATOR));

        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new InvalidArgumentException(
                "Twig views path '{$viewsPath}' is not a readable directory."
            );
        }

        if ($cachePath !== null) {
            if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true) && !is_dir($cachePath)) {
                throw new RuntimeException("Cannot create Twig cache directory: '{$cachePath}'.");
            }
            if (!is_writable($cachePath)) {
                throw new RuntimeException("Twig cache directory '{$cachePath}' is not writable.");
            }
        }

        $loader = new FilesystemLoader($real);

        $twigOptions = array_merge([
            'debug' => $debug,
            'auto_reload' => $debug,
            'strict_variables' => $debug,
            'autoescape' => 'html',     // XSS protection enabled by default
            'cache' => $cachePath ?? false,
            'charset' => 'UTF-8',
        ], $options);

        $environment = new Environment($loader, $twigOptions);

        if ($debug) {
            $environment->addExtension(new DebugExtension());
        }

        $engine = new self($environment, $ext);

        // Register router-aware global functions if a Router is provided
        if ($router !== null) {
            $engine->registerRouterFunctions($router);
        }

        return $engine;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $view View name relative to templates dir (e.g. 'home/index')
     */
    public function render(string $view, array $data = []): string
    {
        $template = $this->normalizeTemplateName($view);

        try {
            return $this->twig->render($template, $data);
        } catch (\Twig\Error\LoaderError $e) {
            throw new RuntimeException(
                "Twig template '{$template}' not found: " . $e->getMessage(),
                0,
                $e
            );
        } catch (\Twig\Error\RuntimeError | \Twig\Error\SyntaxError $e) {
            throw new RuntimeException(
                "Twig rendering error in '{$template}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $view): bool
    {
        try {
            $this->twig->getLoader()->getSourceContext(
                $this->normalizeTemplateName($view)
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Exposes the underlying Twig Environment for custom configuration.
     * Allows adding extensions, filters, globals, etc.
     *
     * ```php
     * $engine->getTwig()->addExtension(new MyCustomExtension());
     * $engine->getTwig()->addGlobal('app_name', 'MyApp');
     * ```
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * Adds a Twig global variable (accessible in all templates as {{ name }}).
     *
     * @param string $name  Variable name
     * @param mixed  $value Value (string, array, object…)
     */
    public function addGlobal(string $name, mixed $value): self
    {
        $this->twig->addGlobal($name, $value);
        return $this;
    }

    /**
     * Adds a custom Twig function (callable from templates).
     *
     * @param string   $name     Function name in Twig
     * @param callable $callable PHP callable
     * @param array    $options  Twig function options
     */
    public function addFunction(string $name, callable $callable, array $options = []): self
    {
        $this->twig->addFunction(new TwigFunction($name, $callable, $options));
        return $this;
    }

    /* =========================================================
       Internal helpers
       ========================================================= */

    /**
     * Ensures the view name has the correct template extension.
     *
     * 'home/index'       => 'home/index.twig'
     * 'home/index.twig'  => 'home/index.twig'  (idempotent)
     */
    private function normalizeTemplateName(string $view): string
    {
        $suffix = '.' . $this->templateExtension;

        return str_ends_with($view, $suffix) ? $view : $view . $suffix;
    }

    /**
     * Registers the standard router-aware Twig functions.
     *
     * Mirrors Symfony's built-in functions:
     *  - path(name, params)  → relative URL  (Symfony: path())
     *  - url(name, params)   → absolute URL  (Symfony: url())
     *  - asset(path)         → asset URL     (Symfony: asset())
     */
    private function registerRouterFunctions(Router $router): void
    {
        // {{ path('route.name', {id: 1}) }}
        $this->twig->addFunction(new TwigFunction(
            'path',
            static fn(string $name, array $params = []): string => $router->path($name, $params)
        ));

        // {{ url('route.name', {id: 1}) }}
        $this->twig->addFunction(new TwigFunction(
            'url',
            static fn(string $name, array $params = []): string => $router->url($name, $params)
        ));

        // {{ asset('css/app.css') }}
        $this->twig->addFunction(new TwigFunction(
            'asset',
            static fn(string $assetPath): string => $router->asset($assetPath)
        ));

        // {{ route_exists('route.name') }}
        $this->twig->addFunction(new TwigFunction(
            'route_exists',
            static fn(string $name): bool => $router->hasRoute($name)
        ));
    }
}