# RenRouter

RenRouter est un **micro-routeur PHP moderne**, léger et sécurisé, conçu pour les projets PHP sans framework ou pour servir de noyau à un micro‑framework maison.

Il fournit une orchestration HTTP claire (routing, dispatch, vues, erreurs), une gestion déclarative de la sécurité (authentification et rôles) et une abstraction propre de la requête HTTP.

---

## ✨ Fonctionnalités clés

* Routage HTTP basé sur **AltoRouter**
* Déclaration fluide et lisible des routes
* Protection des routes par **authentification et rôles**
* Gestion centralisée des **exceptions HTTP** (401, 403, 404, 500)
* Pages d’erreurs dédiées
* Support AJAX / Turbo / XHR
* Abstraction de la requête (`Request`)
* Gestion sécurisée des fichiers uploadés (`UploadedFile`)
* Compatible PSR‑3 (logger optionnel)

---

## 🧱 Architecture

```
RenRouter/
├── src/
│   ├── Router.php
│   ├── Security/
│   │   └── Auth.php
│   ├── Http/
│   │   ├── Request.php
│   │   ├── UploadedFile.php
│   │   └── Exception/
│   │       ├── HttpException.php
│   │       ├── UnauthorizedHttpException.php
│   │       ├── ForbiddenHttpException.php
│   │       └── NotFoundHttpException.php
├── views/
│   ├── base.php
│   └── errors/
│       ├── 401.php
│       ├── 403.php
│       ├── 404.php
│       └── 500.php
└── public/
    └── index.php
```

---

## 🚀 Exemple d’utilisation

```php
$router->route(
    '/user/[i:id]',
    [$userController, 'show'],
    'GET',
    'user.show',
    [
        'auth'  => true,
        'roles' => ['admin', 'editor']
    ]
);
```

* L’utilisateur doit être **authentifié**
* Il doit posséder **au moins un des rôles** indiqués

---

## 🔐 Sécurité & rôles

RenRouter adopte une approche **déclarative** :

* Aucune logique de sécurité dans les contrôleurs
* Les règles sont définies **au niveau des routes**
* Un utilisateur peut avoir **un ou plusieurs rôles**

```php
['auth' => true, 'roles' => ['user']]
```

Exceptions levées automatiquement :

| Situation         | Exception                 | Code HTTP |
| ----------------- | ------------------------- | --------- |
| Non connecté      | UnauthorizedHttpException | 401       |
| Rôle invalide     | ForbiddenHttpException    | 403       |
| Route inexistante | NotFoundHttpException     | 404       |

---

## ❗ Gestion des erreurs

Les erreurs HTTP sont centralisées dans le routeur et rendues via des vues dédiées :

```
views/errors/403.php
views/errors/404.php
```

Le message de l’exception est disponible dans la vue via `$errorMessage`.

---

## 📦 Prérequis

* PHP ≥ 8.1
* Extension `fileinfo` activée
* Composer

---

## 🎯 Philosophie

RenRouter vise :

* la **clarté** plutôt que la magie
* la **sécurité par défaut**
* une **base saine** pour des projets évolutifs

Ce n’est pas un framework, mais un **noyau fiable** pour construire le vôtre.

---

## 📄 Licence

MIT — utilisation libre, modification autorisée.
