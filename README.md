# RenRouter

**RenRouter** is a modern, lightweight and secure PHP micro-router — designed to be the routing core of a custom framework or as a standalone HTTP layer for projects that don't need a full framework.

It provides clean HTTP orchestration (routing, dispatching, views, error handling), declarative security (authentication and roles), pluggable template engines (PHP or Twig), and URL extension spoofing for stack obfuscation.

---

## ✨ Key Features

- HTTP routing powered by **AltoRouter**
- **Fluent, readable** route registration with `get()`, `post()`, `route()` shortcuts
- **RouterFactory** — clean builder pattern, no positional `null` arguments
- **Pluggable template engines**: native PHP files or Twig (Symfony-style)
- **URL extension spoofing** — serve `/contact.html` or `/about.aspx` while routes stay clean internally
- Declarative route protection: **authentication and role-based access control**
- Centralized HTTP exception handling (401, 403, 404, 500) with dedicated error views
- `Controller@method` string target support alongside callables and view names
- **AbstractController** base class with rendering, redirects, JSON responses, flash messages and request helpers
- PSR-3 logger support (optional)
- PHP 8.1+ with `readonly` properties and `never` return types

---

## 🧱 Architecture

```
RenRouter/
├── Router.php                        Core router and dispatcher
├── RouterFactory.php                 Fluent builder for Router assembly
│
├── Controller/
│   └── AbstractController.php        Base controller (render, redirect, json, flash, guards)
│
├── Template/
│   ├── TemplateEngineInterface.php   Contract for template engines
│   ├── PhpTemplateEngine.php         Native PHP file renderer (default)
│   └── TwigEngine.php                Twig adapter (mirrors Symfony conventions)
│
├── Security/
│   └── Auth.php                      Session-based auth helper (login, logout, roles)
│
├── Http/
│   ├── Request.php                   HTTP request abstraction
│   ├── UploadedFile.php              Secure file upload wrapper
│   └── Exception/
│       ├── HttpException.php
│       ├── UnauthorizedHttpException.php   (401)
│       ├── ForbiddenHttpException.php      (403)
│       └── NotFoundHttpException.php       (404)

Using/
├── views/  (or templates/ for Twig)
│   ├── base.php                      Layout wrapper ($pg_content injected)
│   └── errors/
│       ├── 401.php
│       ├── 403.php
│       ├── 404.php
│       └── 500.php
│
└── public/
    └── index.php                     Front controller
```

---

## 🚀 Quick Start

### 1. Install

```bash
composer require sethrensei/ren-router
composer require sethrensei/ren-router:version
```

### 2. Bootstrap (PHP templates)

```php
use RenRouter\RouterFactory;

$router = RouterFactory::create(__DIR__ . '/../views')
    ->withLogger($logger)               // PSR-3, optional
    ->withUrlExtension('.html')         // /contact becomes /contact.html publicly
    ->withSecurityRoute('auth.login')   // redirect target when not authenticated
    ->build();

$router
    ->get('/',        'home/index',  'home.app')
    ->get('/login',   'auth/login',  'auth.login')
    ->post('/login',  [$security, 'login'],  'auth.login.post')
    ->get('/logout',  [$security, 'logout'], 'auth.logout')
    ->get('/dashboard', 'app/dashboard', 'app.dashboard', ['auth' => true])
    ->get('/admin',     'admin/index',   'admin.index',   ['auth' => true, 'roles' => ['ROLE_ADMIN']])
    ->run();
```

### 3. Bootstrap (Twig)

```php
$router = RouterFactory::create(__DIR__ . '/../templates')
    ->withTwig(
        debug:     ($_ENV['APP_ENV'] === 'DEV'),
        cachePath: __DIR__ . '/../var/cache/twig',
    )
    ->withUrlExtension('.html')
    ->build();
```

---

## 🔀 Route Registration

| Method | Signature | Use case |
|---|---|---|
| `get()` | `get(uri, target, name, options)` | Single GET route |
| `post()` | `post(uri, target, name, options)` | Single POST route |
| `route()` | `route(uri, target, method, name, options)` | Any method or `GET\|POST` |

**Targets** can be:

```php
// A view name (rendered by the template engine)
->get('/about', 'pages/about', 'page.about')

// A callable
->get('/ping', fn(Router $r, array $p) => print('pong'), 'app.ping')

// A controller method array
->get('/users', [$userController, 'index'], 'user.index')

// A "Class@method" string
->get('/users', 'App\Controller\UserController@index', 'user.index')
```

**AltoRouter patterns** are supported in URIs:

```php
->get('/user/[i:id]',        ...)   // integer
->get('/post/[a:slug]',      ...)   // alphanumeric + dash
->get('/file/[*:path]',      ...)   // anything including slashes
->get('/lang/[en|fr|de:lg]', ...)   // fixed options
```

---

## 🔐 Security & Authorization

Security is declared **at route level**, not inside controllers.

```php
->get('/dashboard', 'app/dashboard', 'app.dashboard', [
    'auth'  => true,
    'roles' => ['ROLE_USER', 'ROLE_EDITOR'],
])
```

| Option | Type | Behaviour |
|---|---|---|
| `auth` | `bool` | Redirects to the security route if not authenticated |
| `roles` | `string\|string[]` | Throws 403 if no matching role is found |

Automatically thrown exceptions:

| Situation | Exception | HTTP Code |
|---|---|---|
| Not authenticated | `UnauthorizedHttpException` | 401 |
| Wrong role | `ForbiddenHttpException` | 403 |
| No matching route | `NotFoundHttpException` | 404 |
| Any other error | `HttpException` / caught `Throwable` | 500 |

### Auth helper

```php
use RenRouter\Security\Auth;

// Write (call right after credential verification)
Auth::login(['id' => 1, 'name' => 'Alice', 'roles' => ['ROLE_USER']]);
Auth::logout();
Auth::refreshSession();   // regenerate ID, keep data

// Read
Auth::check();                          // bool
Auth::id();                             // int|string|null
Auth::user();                           // full user array
Auth::get('name');                      // single field
Auth::roles();                          // string[]
Auth::hasRole('ROLE_ADMIN');            // bool
Auth::hasAnyRole(['ROLE_A', 'ROLE_B']); // bool — at least one
Auth::hasAllRoles(['ROLE_A', 'ROLE_B']);// bool — all required
```

---

## 🎮 Controllers

Extend `AbstractController` for instant access to all response helpers.

```php
use RenRouter\Controller\AbstractController;
use RenRouter\Router;

class PostController extends AbstractController
{
    public function __construct(Router $router)
    {
        parent::__construct($router);   // inject once, use everywhere
    }

    public function index(array $params): void
    {
        $this->requireAuth();
        $this->render('posts/index', ['posts' => []]);
    }

    public function show(array $params): void
    {
        $post = PostRepository::find((int) $params['id'])
            ?? $this->notFound("Post #{$params['id']} not found.");

        $this->render('posts/show', ['post' => $post]);
    }

    public function delete(array $params): void
    {
        $this->requireRole('ROLE_ADMIN');
        // delete…
        $this->flashSuccess('Post deleted.');
        $this->redirectToRoute('posts.index');
    }

    public function store(array $params): void
    {
        $this->requireAuth();
        $data = $this->postData(only: ['title', 'body']);

        if (empty($data['title'])) {
            $this->jsonError('Title is required.', 422);
        }

        $this->json(['success' => true], 201);
    }
}
```

### AbstractController API

| Category | Method | Description |
|---|---|---|
| **Guards** | `requireAuth(?Router)` | Redirect to login if not authenticated |
| | `requireRole(roles, ?Router)` | Requires auth + matching role |
| | `denyUnless(bool, message)` | Throws 403 when condition is false |
| | `notFound(message): never` | Throws 404 immediately |
| **Rendering** | `render(view, data, ?Router)` | Renders via the template engine |
| | `renderPartial(view, data, ?Router): string` | Returns rendered HTML as string |
| **Redirects** | `redirectToRoute(name, params, status, ?Router)` | Named route redirect |
| | `redirect(url, status, ?Router)` | Raw URL redirect |
| **JSON** | `json(data, status): never` | JSON response + exit |
| | `jsonError(message, status, extra): never` | `{error, message, code}` + exit |
| **Flash** | `flash(type, message)` | Write flash to session |
| | `flashSuccess(message)` | Shorthand for type `success` |
| | `flashError(message)` | Shorthand for type `error` |
| | `getFlash(type): array` | Read + clear one type |
| | `getAllFlash(): array` | Read + clear all types |
| **Request** | `input(key, default, from)` | Read from POST/GET/both |
| | `postData(only): array` | All POST, optionally filtered |
| | `isMethod(method): bool` | Check HTTP method |
| | `isAjax(): bool` | XHR / JSON Accept detection |

---

## 🌐 Twig Integration

`TwigEngine` mirrors Symfony's Twig integration exactly.

These functions are available in every template automatically:

| Twig function | PHP equivalent | Example output |
|---|---|---|
| `path('route', {id:1})` | `Router::path()` | `/contact.html` |
| `url('route', {id:1})` | `Router::url()` | `https://example.com/contact.html` |
| `asset('css/app.css')` | `Router::asset()` | `https://example.com/css/app.css` |
| `route_exists('name')` | `Router::hasRoute()` | `true` / `false` |

```twig
{# templates/home/index.twig #}
<a href="{{ path('page.about') }}">About</a>
<a href="{{ url('page.contact') }}">Contact</a>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">

{% if route_exists('admin.dashboard') %}
    <a href="{{ path('admin.dashboard') }}">Admin</a>
{% endif %}
```

For custom extensions and globals:

```php
$twig = TwigEngine::create(viewsPath: __DIR__ . '/templates', debug: true);

$twig->addGlobal('app_name', 'MyApp');
$twig->addFunction('format_date', fn(\DateTimeInterface $d) => $d->format('d/m/Y'));
$twig->getTwig()->addExtension(new \Twig\Extension\StringLoaderExtension());

$router = RouterFactory::create(__DIR__ . '/templates')
    ->withTemplateEngine($twig)
    ->build();
```

---

## 🎭 URL Extension Spoofing

Confuse security scanners and bots by exposing a fake tech stack:

```php
// Routes are always defined without extension:
->get('/contact', 'pages/contact', 'page.contact')

// Public-facing URLs get the suffix automatically:
// /contact.html  →  ".html" camouflage (Apache/Nginx static site)
// /contact.aspx  →  ".aspx" camouflage (IIS / ASP.NET)
// /contact.jsp   →  ".jsp"  camouflage (Java / Tomcat)
```

```php
RouterFactory::create(__DIR__ . '/views')
    ->withUrlExtension('.aspx')
    ->build();
```

Generated URLs follow the same rule:

```php
$router->url('page.contact');   // https://example.com/contact.aspx
$router->path('page.contact');  // /contact.aspx
```

The extension is stripped from incoming requests before matching — your route definitions never need to change.

---

## ❗ Error Handling

Errors are caught centrally by the router. In **production**, dedicated view files are rendered:

```
views/errors/401.php   — Unauthorized
views/errors/403.php   — Forbidden
views/errors/404.php   — Not Found
views/errors/500.php   — Internal Server Error
```

The `$exception` and `$code` variables are available inside error views.

In **development** (`APP_ENV=DEV`), the raw exception message and stack trace are printed as plain text.

You can also map error codes to named routes:

```php
$router->setErrorRoute(404, 'error.notfound');
$router->setErrorRoute(403, 'error.forbidden');
```

---

## 🔗 URL Generation

```php
// Absolute URL
$router->url('user.show', ['id' => 42]);
// => https://example.com/user/42.html

// Relative path
$router->path('user.show', ['id' => 42]);
// => /user/42.html

// Asset (never gets the fake extension)
$router->asset('img/logo.png');
// => https://example.com/img/logo.png

// Named redirect
$router->redirect('home.app');
$router->redirectUrl('https://example.com');
```

---

## 📦 Requirements

| | |
|---|---|
| PHP | ≥ 8.1 |
| AltoRouter | `composer require altorouter/altorouter` |
| Twig *(optional)* | `composer require twig/twig` |
| PSR-3 logger *(optional)* | any PSR-3 compatible package |

---

## 🎯 Philosophy

RenRouter is built around three principles:

- **Clarity over magic** — every behaviour is explicit and traceable
- **Security by default** — auth and roles declared at the route, not buried in controllers
- **A solid, extensible core** — swap the template engine, add a logger, override any component

It is not a framework. It is a **reliable foundation** to build one.

---

## 📄 License

MIT — free to use, modify and distribute.