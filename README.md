# RenRouter

RenRouter is a **modern, lightweight and secure PHP micro‑router**, designed for projects without a full framework or as the core of a custom micro‑framework.

It provides clean HTTP orchestration (routing, dispatching, views, errors), declarative security (authentication and roles), and a proper HTTP request abstraction.

---

## ✨ Key Features

* HTTP routing powered by **AltoRouter**
* Fluent and readable route definitions
* Route protection with **authentication and roles**
* Centralized **HTTP exception handling** (401, 403, 404, 500)
* Dedicated error pages
* AJAX / Turbo / XHR support
* HTTP request abstraction (`Request`)
* Secure file uploads (`UploadedFile`)
* Optional PSR‑3 logger support

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

## 🚀 Usage Example

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

* The user **must be authenticated**
* The user must have **at least one of the required roles**

---

## 🔐 Security & Roles

RenRouter follows a **declarative security model**:

* No authentication logic inside controllers
* Security rules are defined **at route level**
* A user may have **one or multiple roles**

```php
['auth' => true, 'roles' => ['user']]
```

Automatically thrown exceptions:

| Situation         | Exception                 | HTTP Code |
| ----------------- | ------------------------- | --------- |
| Not authenticated | UnauthorizedHttpException | 401       |
| Invalid role      | ForbiddenHttpException    | 403       |
| Route not found   | NotFoundHttpException     | 404       |

---

## ❗ Error Handling

HTTP errors are centrally handled by the router and rendered using dedicated views:

```
views/errors/403.php
views/errors/404.php
```

The exception message is available in the view through `$errorMessage`.

---

## 📦 Requirements

* PHP ≥ 8.1
* `fileinfo` extension enabled
* Composer

---

## 🎯 Philosophy

RenRouter focuses on:

* **clarity over magic**
* **security by default**
* a **solid and extensible core**

It is not a framework, but a **reliable foundation** to build one.

---

## 📄 License

MIT — free to use and modify.
