# RenRouter

**RenRouter** est un micro-routeur PHP moderne, léger et sécurisé — conçu pour servir de couche HTTP à un micro-framework personnalisé ou à tout projet ne nécessitant pas un framework complet.

Il fournit une orchestration HTTP propre (routage, dispatch, vues, gestion des erreurs), une sécurité déclarative (authentification et rôles), des moteurs de templates interchangeables (PHP natif ou Twig), et un camouflage de stack technique par extension d'URL.

---

## ✨ Fonctionnalités principales

- Routage HTTP propulsé par **AltoRouter**
- Enregistrement des routes **fluent et lisible** via `get()`, `post()`, `route()`
- **RouterFactory** — pattern builder propre, sans arguments `null` positionnels
- **Moteurs de templates interchangeables** : fichiers PHP natifs ou Twig (conventions Symfony)
- **Camouflage d'extension d'URL** — servir `/contact.html` ou `/about.aspx` tandis que les routes restent propres en interne
- Protection déclarative des routes : **authentification et contrôle d'accès par rôles**
- Gestion centralisée des exceptions HTTP (401, 403, 404, 500) avec vues d'erreur dédiées
- Support de la syntaxe `Controller@method` en plus des callables et des noms de vues
- Classe de base **AbstractController** avec rendu, redirections, réponses JSON, messages flash et helpers de requête
- Support optionnel des loggers PSR-3
- PHP 8.1+ avec propriétés `readonly` et types de retour `never`

---

## 🧱 Architecture

```
RenRouter/
├── Router.php                        Routeur et dispatcher principal
├── RouterFactory.php                 Builder fluent pour l'assemblage du Router
│
├── Controller/
│   └── AbstractController.php        Contrôleur de base (rendu, redirect, json, flash, guards)
│
├── Template/
│   ├── TemplateEngineInterface.php   Contrat pour les moteurs de templates
│   ├── PhpTemplateEngine.php         Renderer PHP natif (défaut)
│   └── TwigEngine.php                Adaptateur Twig (miroir des conventions Symfony)
│
├── Security/
│   └── Auth.php                      Helper d'authentification basé sur la session
│
├── Http/
│   ├── Request.php                   Abstraction de la requête HTTP
│   ├── UploadedFile.php              Wrapper sécurisé pour les fichiers uploadés
│   └── Exception/
│       ├── HttpException.php
│       ├── UnauthorizedHttpException.php   (401)
│       ├── ForbiddenHttpException.php      (403)
│       └── NotFoundHttpException.php       (404)

Utilisation/
├── views/  (ou templates/ pour Twig)
│   ├── base.php                      Layout principal ($pg_content injecté)
│   └── errors/
│       ├── 401.php
│       ├── 403.php
│       ├── 404.php
│       └── 500.php
│
└── public/
    └── index.php                     Contrôleur frontal
```

---

## 🚀 Démarrage rapide

### 1. Installation

```bash
composer require sethrensei/ren-router
composer require sethrensei/ren-router:version
```

### 2. Bootstrap (templates PHP)

```php
use RenRouter\RouterFactory;

$router = RouterFactory::create(__DIR__ . '/../views')
    ->withLogger($logger)               // PSR-3, optionnel
    ->withUrlExtension('.html')         // /contact devient /contact.html publiquement
    ->withSecurityRoute('auth.login')   // route de redirection si non authentifié
    ->build();

$router
    ->get('/',          'home/index',  'home.app')
    ->get('/login',     'auth/login',  'auth.login')
    ->post('/login',    [$security, 'login'],  'auth.login.post')
    ->get('/logout',    [$security, 'logout'], 'auth.logout')
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

## 🔀 Enregistrement des routes

| Méthode | Signature | Cas d'usage |
|---|---|---|
| `get()` | `get(uri, target, name, options)` | Route GET simple |
| `post()` | `post(uri, target, name, options)` | Route POST simple |
| `route()` | `route(uri, target, method, name, options)` | N'importe quelle méthode ou `GET\|POST` |

**Les cibles** peuvent être :

```php
// Un nom de vue (rendu par le moteur de templates)
->get('/about', 'pages/about', 'page.about')

// Un callable
->get('/ping', fn(Router $r, array $p) => print('pong'), 'app.ping')

// Une méthode de contrôleur
->get('/users', [$userController, 'index'], 'user.index')

// Une chaîne "Classe@méthode"
->get('/users', 'App\Controller\UserController@index', 'user.index')
```

**Les patterns AltoRouter** sont supportés dans les URIs :

```php
->get('/user/[i:id]',        ...)   // entier
->get('/post/[a:slug]',      ...)   // alphanumérique + tiret
->get('/file/[*:path]',      ...)   // tout, y compris les slashes
->get('/lang/[en|fr|de:lg]', ...)   // options fixes
```

---

## 🔐 Sécurité & Autorisation

La sécurité se déclare **au niveau de la route**, pas dans les contrôleurs.

```php
->get('/dashboard', 'app/dashboard', 'app.dashboard', [
    'auth'  => true,
    'roles' => ['ROLE_USER', 'ROLE_EDITOR'],
])
```

| Option | Type | Comportement |
|---|---|---|
| `auth` | `bool` | Redirige vers la route de sécurité si non authentifié |
| `roles` | `string\|string[]` | Lève une 403 si aucun rôle ne correspond |

Exceptions levées automatiquement :

| Situation | Exception | Code HTTP |
|---|---|---|
| Non authentifié | `UnauthorizedHttpException` | 401 |
| Rôle insuffisant | `ForbiddenHttpException` | 403 |
| Route introuvable | `NotFoundHttpException` | 404 |
| Toute autre erreur | `HttpException` / `Throwable` | 500 |

### Helper Auth

```php
use RenRouter\Security\Auth;

// Écriture (à appeler juste après vérification des identifiants)
Auth::login(['id' => 1, 'name' => 'Alice', 'roles' => ['ROLE_USER']]);
Auth::logout();
Auth::refreshSession();   // régénère l'ID, conserve les données

// Lecture
Auth::check();                           // bool
Auth::id();                              // int|string|null
Auth::user();                            // tableau utilisateur complet
Auth::get('name');                       // champ unique
Auth::roles();                           // string[]
Auth::hasRole('ROLE_ADMIN');             // bool
Auth::hasAnyRole(['ROLE_A', 'ROLE_B']);  // bool — au moins un
Auth::hasAllRoles(['ROLE_A', 'ROLE_B']); // bool — tous requis
```

---

## 🎮 Contrôleurs

Étendez `AbstractController` pour accéder immédiatement à tous les helpers de réponse.

```php
use RenRouter\Controller\AbstractController;
use RenRouter\Router;

class PostController extends AbstractController
{
    public function __construct(Router $router)
    {
        parent::__construct($router);   // injecté une fois, disponible partout
    }

    public function index(array $params): void
    {
        $this->requireAuth();
        $this->render('posts/index', ['posts' => []]);
    }

    public function show(array $params): void
    {
        $post = PostRepository::find((int) $params['id'])
            ?? $this->notFound("Post #{$params['id']} introuvable.");

        $this->render('posts/show', ['post' => $post]);
    }

    public function delete(array $params): void
    {
        $this->requireRole('ROLE_ADMIN');
        // suppression…
        $this->flashSuccess('Article supprimé.');
        $this->redirectToRoute('posts.index');
    }

    public function store(array $params): void
    {
        $this->requireAuth();
        $data = $this->postData(only: ['title', 'body']);

        if (empty($data['title'])) {
            $this->jsonError('Le titre est requis.', 422);
        }

        $this->json(['success' => true], 201);
    }
}
```

### API AbstractController

| Catégorie | Méthode | Description |
|---|---|---|
| **Guards** | `requireAuth(?Router)` | Redirige vers le login si non authentifié |
| | `requireRole(roles, ?Router)` | Requiert auth + rôle correspondant |
| | `denyUnless(bool, message)` | Lève une 403 si la condition est fausse |
| | `notFound(message): never` | Lève une 404 immédiatement |
| **Rendu** | `render(view, data, ?Router)` | Rendu via le moteur de templates |
| | `renderPartial(view, data, ?Router): string` | Retourne le HTML rendu en chaîne |
| **Redirections** | `redirectToRoute(name, params, status, ?Router)` | Redirection vers une route nommée |
| | `redirect(url, status, ?Router)` | Redirection vers une URL brute |
| **JSON** | `json(data, status): never` | Réponse JSON + exit |
| | `jsonError(message, status, extra): never` | Enveloppe `{error, message, code}` + exit |
| **Flash** | `flash(type, message)` | Écrit un message flash en session |
| | `flashSuccess(message)` | Raccourci type `success` |
| | `flashError(message)` | Raccourci type `error` |
| | `getFlash(type): array` | Lit et efface un type de flash |
| | `getAllFlash(): array` | Lit et efface tous les flashs |
| **Requête** | `input(key, default, from)` | Lecture POST/GET/les deux |
| | `postData(only): array` | Données POST, optionnellement filtrées |
| | `isMethod(method): bool` | Vérifie la méthode HTTP |
| | `isAjax(): bool` | Détecte XHR / Accept JSON |

---

## 🌐 Intégration Twig

`TwigEngine` reproduit exactement l'intégration Twig de Symfony.

Ces fonctions sont disponibles dans chaque template automatiquement :

| Fonction Twig | Équivalent PHP | Exemple de résultat |
|---|---|---|
| `path('route', {id:1})` | `Router::path()` | `/contact.html` |
| `url('route', {id:1})` | `Router::url()` | `https://example.com/contact.html` |
| `asset('css/app.css')` | `Router::asset()` | `https://example.com/css/app.css` |
| `route_exists('name')` | `Router::hasRoute()` | `true` / `false` |

```twig
{# templates/home/index.twig #}
<a href="{{ path('page.about') }}">À propos</a>
<a href="{{ url('page.contact') }}">Contact</a>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">

{% if route_exists('admin.dashboard') %}
    <a href="{{ path('admin.dashboard') }}">Admin</a>
{% endif %}
```

Pour les extensions et globals personnalisés :

```php
$twig = TwigEngine::create(viewsPath: __DIR__ . '/templates', debug: true);

$twig->addGlobal('app_name', 'MonApp');
$twig->addFunction('format_date', fn(\DateTimeInterface $d) => $d->format('d/m/Y'));
$twig->getTwig()->addExtension(new \Twig\Extension\StringLoaderExtension());

$router = RouterFactory::create(__DIR__ . '/templates')
    ->withTemplateEngine($twig)
    ->build();
```

---

## 🎭 Camouflage par extension d'URL

Trompez les scanners et les bots en exposant une fausse stack technique :

```php
// Les routes sont toujours définies sans extension :
->get('/contact', 'pages/contact', 'page.contact')

// Les URLs publiques reçoivent le suffixe automatiquement :
// /contact.html  →  camouflage Apache/Nginx (site statique)
// /contact.aspx  →  camouflage IIS / ASP.NET
// /contact.jsp   →  camouflage Java / Tomcat
```

```php
RouterFactory::create(__DIR__ . '/views')
    ->withUrlExtension('.aspx')
    ->build();
```

Les URLs générées suivent la même règle :

```php
$router->url('page.contact');   // https://example.com/contact.aspx
$router->path('page.contact');  // /contact.aspx
```

L'extension est retirée des requêtes entrantes avant le matching — les définitions de routes ne changent jamais.

---

## ❗ Gestion des erreurs

Les erreurs sont capturées centralement par le routeur. En **production**, des vues dédiées sont rendues :

```
views/errors/401.php   — Non autorisé
views/errors/403.php   — Accès interdit
views/errors/404.php   — Page introuvable
views/errors/500.php   — Erreur serveur interne
```

Les variables `$exception` et `$code` sont disponibles dans les vues d'erreur.

En **développement** (`APP_ENV=DEV`), le message d'exception brut et la trace complète sont affichés en texte.

Il est également possible de mapper des codes d'erreur vers des routes nommées :

```php
$router->setErrorRoute(404, 'error.notfound');
$router->setErrorRoute(403, 'error.forbidden');
```

---

## 🔗 Génération d'URLs

```php
// URL absolue
$router->url('user.show', ['id' => 42]);
// => https://example.com/user/42.html

// Chemin relatif
$router->path('user.show', ['id' => 42]);
// => /user/42.html

// Asset (ne reçoit jamais l'extension de camouflage)
$router->asset('img/logo.png');
// => https://example.com/img/logo.png

// Redirection nommée
$router->redirect('home.app');
$router->redirectUrl('https://example.com');
```

---

## 📦 Prérequis

| | |
|---|---|
| PHP | ≥ 8.1 |
| AltoRouter | `composer require altorouter/altorouter` |
| Twig | `composer require twig/twig` |
| Symfony Dotenv | `composer require symfony/dotenv` |
| HTMLPurifier | `composer require ezyang/htmlpurifier"` |
| PSR-3 logger, HTTP message and factory | any PSR-3 compatible package |

---

## 🎯 Philosophie

RenRouter repose sur trois principes :

- **La clarté avant la magie** — chaque comportement est explicite et traçable
- **La sécurité par défaut** — auth et rôles déclarés à la route, pas enfouis dans les contrôleurs
- **Un cœur solide et extensible** — remplacez le moteur de templates, ajoutez un logger, surchargez n'importe quel composant

Ce n'est pas un framework. C'est une **fondation fiable** pour en construire un.

---

## 📄 Licence

MIT — libre d'utilisation, de modification et de distribution.