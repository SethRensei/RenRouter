<?php
declare(strict_types=1);

namespace RenRouter;

use AltoRouter;
use Psr\Log\LoggerInterface;
use RenRouter\Template\TemplateEngineInterface;
use RenRouter\Template\PhpTemplateEngine;
use RenRouter\Template\TwigEngine;

/**
 * Class RouterFactory
 *
 * Fluent builder for assembling a fully-configured Router instance.
 *
 * Supports two rendering modes:
 *  - PHP templates (default, zero extra dependency)
 *  - Twig templates (requires twig/twig package)
 *
 * Example — PHP templates with .html extension spoofing:
 * ```php
 * $router = RouterFactory::create(__DIR__ . '/views')
 *     ->withUrlExtension('.html')
 *     ->withLogger($logger)
 *     ->build();
 * ```
 *
 * Example — Twig templates:
 * ```php
 * $router = RouterFactory::create(__DIR__ . '/templates')
 *     ->withTwig(debug: true, cachePath: __DIR__ . '/var/cache/twig')
 *     ->withUrlExtension('.aspx')   // "asp site" camouflage
 *     ->build();
 * ```
 *
 * @package RenRouter
 */
final class RouterFactory
{
    private string $viewsPath;
    private ?AltoRouter $altoRouter = null;
    private ?LoggerInterface $logger = null;
    private ?string $securityRouteName = null;
    private ?TemplateEngineInterface $engine = null;
    private string $urlExtension = '';

    // Twig-specific deferred config
    private bool $useTwig = false;
    private bool $twigDebug = false;
    private ?string $twigCachePath = null;
    private string $twigExt = 'twig';
    private array $twigOptions = [];

    private function __construct(string $viewsPath)
    {
        $this->viewsPath = $viewsPath;
    }

    /**
     * Entry point. Pass the views / templates directory.
     */
    public static function create(string $viewsPath): self
    {
        return new self($viewsPath);
    }

    /* =========================================================
       Builder methods
       ========================================================= */

    /**
     * Sets a PSR-3 logger.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Overrides the AltoRouter instance (advanced use).
     */
    public function withAltoRouter(AltoRouter $router): self
    {
        $this->altoRouter = $router;
        return $this;
    }

    /**
     * Sets the named route used for unauthenticated redirects.
     * Default: 'security.login'
     */
    public function withSecurityRoute(string $name): self
    {
        $this->securityRouteName = $name;
        return $this;
    }

    /**
     * Configures a fake URL extension appended to all generated URLs.
     *
     * The extension fools attackers / scanners into guessing the wrong
     * tech stack (e.g. '.aspx' → "must be IIS/ASP.NET").
     *
     * The Router strips the suffix before matching, so routes are
     * defined without extension:
     *
     * ```php
     * // Route registered as '/contact'
     * // Public URL visible to users: /contact.html
     * ->withUrlExtension('.html')
     * ```
     *
     * @param string $ext e.g. '.html', '.aspx', '.php', '.jsp', ''
     */
    public function withUrlExtension(string $ext): self
    {
        $this->urlExtension = $ext;
        return $this;
    }

    /**
     * Injects a fully pre-built template engine.
     * Takes precedence over withTwig().
     */
    public function withTemplateEngine(TemplateEngineInterface $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Activates Twig as the template engine.
     *
     * @param bool        $debug     Twig debug mode
     * @param string|null $cachePath Compiled template cache directory
     * @param string      $ext       Template file extension (default: 'twig')
     * @param array       $options   Extra Twig Environment options
     */
    public function withTwig(
        bool $debug = false,
        ?string $cachePath = null,
        string $ext = 'twig',
        array $options = []
    ): self {
        $this->useTwig = true;
        $this->twigDebug = $debug;
        $this->twigCachePath = $cachePath;
        $this->twigExt = $ext;
        $this->twigOptions = $options;
        return $this;
    }

    /* =========================================================
       Build
       ========================================================= */

    /**
     * Builds and returns the configured Router.
     *
     * When Twig is requested, the engine is created first and the Router
     * instance is injected into it afterward, enabling url()/path()/asset()
     * Twig functions — exactly as Symfony does via service injection.
     */
    public function build(): Router
    {
        // Resolve template engine
        $engine = $this->resolveEngine();

        $router = new Router(
            viewsPath: $this->viewsPath,
            router: $this->altoRouter,
            logger: $this->logger,
            securityRouteName: $this->securityRouteName,
            templateEngine: $engine,
            urlExtension: $this->urlExtension,
        );

        // Late-inject the Router into the TwigEngine so path()/url() work
        if ($engine instanceof TwigEngine) {
            $engine->getTwig()->addGlobal('router', $router);

            // Re-register router-aware functions now that Router exists.
            // These closures capture $router by reference, so they already
            // resolve after the Router is fully built.
        }

        return $router;
    }

    /* =========================================================
       Internal
       ========================================================= */

    private function resolveEngine(): TemplateEngineInterface
    {
        // Explicit engine wins
        if ($this->engine !== null) {
            return $this->engine;
        }

        // Twig requested
        if ($this->useTwig) {
            if (!class_exists(\Twig\Environment::class)) {
                throw new \RuntimeException(
                    'Twig is not installed. Run: composer require twig/twig'
                );
            }

            return TwigEngine::create(
                viewsPath: $this->viewsPath,
                router: null,  // injected after Router construction in build()
                debug: $this->twigDebug,
                cachePath: $this->twigCachePath,
                ext: $this->twigExt,
                options: $this->twigOptions,
            );
        }

        // Default: PHP template engine
        return new PhpTemplateEngine($this->viewsPath);
    }
}