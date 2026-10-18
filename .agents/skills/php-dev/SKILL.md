---
name: php-dev
description: Develop, review, refactor, test, and maintain PHP applications, especially JSON REST APIs using Slim Framework 4, Composer, PSR standards, PDO, MySQL, and Oracle. Use for Slim route handlers, middleware, dependency injection, security, database integration, testing, production deployment, and modernization of existing PHP codebases.
---

# PHP API Development — Slim 4

## Workflow

1. Inspect the existing `composer.json`, PHP runtime, extensions, application bootstrap, database drivers, tests, and deployment environment before proposing modifications.
2. Match the project's established conventions where safe. For new APIs, prefer the recommendations below; avoid introducing a full-stack framework or ORM without a clear need.
3. Check supported PHP versions and current package compatibility from official sources before selecting versions or upgrading dependencies. Do not assume that the latest library release is compatible with the deployed PHP runtime.
4. Implement a narrow, tested change with explicit interfaces, dependency injection, consistent error responses, and secure defaults.
5. Run the project's applicable formatting, static-analysis, test, and Composer checks; state which checks could not be run and why.

## Runtime and standards

- Target a supported, actively maintained PHP branch for new work (prefer PHP 8.4+ when deployment permits; verify support and compatibility). Preserve compatibility with an older supported PHP branch when the project requires it.
- Use `declare(strict_types=1);` for application PHP source files; type parameters, return values, and properties. Use enums, readonly features, and modern syntax only when compatible with the minimum PHP version.
- Follow PSR-12 formatting and PSR-4 autoloading. Use PSR-7 request/response, PSR-15 middleware, PSR-11 container interfaces, and PSR-3 logging where relevant.
- Keep controllers/actions thin: translate HTTP input/output; place business logic in application services and persistence in repositories/query services.
- Use Composer with a committed `composer.lock` for applications. Use `composer install --no-dev --optimize-autoloader` for reproducible production installs; run `composer audit` and check platform requirements in CI.

## Slim 4 API conventions

- Use `slim/slim` 4.x and a compatible PSR-7 implementation such as `slim/psr7`.
- Register a PSR-11 dependency container when dependencies become nontrivial; PHP-DI (`php-di/php-di`) is a sensible default, not a mandatory component. Prefer constructor injection to service location.
- Use `public/index.php` as the sole web-exposed front controller, with web-server document root pointed to `public/`. Keep secrets, `vendor/`, configuration, and logs outside the web-exposed directory.
- Split bootstrap, container definitions, middleware, and routes into small files. Group endpoints under a consistent prefix such as `/api/v1` if public clients require API versioning.
- Use Slim's body parsing middleware for JSON; validate `Content-Type`, payload structure, required fields, types, lengths, and allowed values explicitly. `getParsedBody()` alone is not validation.
- Return PSR-7 response objects with explicit HTTP status codes and `Content-Type: application/json`. Do not echo JSON, leak PHP warnings, or mix HTML into API output.
- Add routing middleware before adding error middleware. Slim uses **last-in, first-out** middleware execution: generally register body parsing, routing, then error middleware last so it wraps the stack. Insert authentication/CORS/rate-limiting middleware deliberately after reasoning about execution order and preflight requests.
- Configure production error middleware with `displayErrorDetails=false`; use a consistent JSON error handler (optionally `application/problem+json`, RFC 9457). Log exceptions server-side without exposing stack traces, SQL statements, credentials, or personal data.
- Prefer conventional semantics: `GET` read-only, `POST` create/commands, `PUT` replace, `PATCH` partial update, `DELETE` delete; return `201` and a Location header on creation when appropriate, `204` without a body for successful no-content responses, `400` malformed input, `401` unauthenticated, `403` forbidden, `404` missing, `409` conflict, `422` invalid semantics, and `500` unexpected failures. Ensure consistent pagination, filtering, and timezone-aware timestamps.
- For external or multi-consumer APIs, maintain an OpenAPI 3.x contract and contract/integration tests as appropriate.

### Minimal JSON route example

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = AppFactory::create();

$app->get('/api/v1/health', static function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);
$app->run();
```

This example is a minimal starting point, not a complete production bootstrap: add structured JSON error handling, logging, environment config, security middleware, and dependency wiring for real services.

## Suggested project layout

```text
app/
  bootstrap.php
  dependencies.php
  middleware.php
  routes.php
public/
  index.php
src/
  Action/                 # HTTP handlers/controllers
  Domain/                 # Domain objects and rules, when needed
  Service/                # Application use cases
  Repository/             # Persistence adapters / queries
  Middleware/
  Http/                   # JSON response and error helpers
  Infrastructure/         # DB, logging, external integrations
config/
tests/
  Unit/
  Integration/
  Api/
composer.json
composer.lock
phpunit.xml
```

Adapt the folder names to the existing codebase. Avoid speculative layers or helpers that add no value.

## Databases: PDO, MySQL, Oracle

- Use PDO with parameterized queries for **values**; never interpolate untrusted values into SQL. Whitelist identifiers such as column names, sort directions, and table names because placeholders cannot bind identifiers.
- Configure `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` and an explicit fetch mode. With MySQL, use `charset=utf8mb4`; consider `PDO::ATTR_EMULATE_PREPARES => false` when supported and appropriate. Confirm driver-specific behavior before assuming portable binding, transactions, pagination, or `RETURNING` syntax.
- Use `pdo_mysql` for MySQL. For Oracle, confirm whether `pdo_oci` is installed and supported in the deployment; `oci8` is an alternative with a different API, not a PDO drop-in. Do not make either driver mandatory for projects that do not use it.
- Keep credentials in environment variables or a secrets manager, never hard-coded in source or emitted in errors/logs. Give database users least privilege and use TLS as required by the environment.
- Use explicit transactions for multi-step writes; handle rollback and concurrency or idempotency as needed. Avoid N+1 queries, unbounded results, and database-specific features in supposedly portable integration tests.

## API security

- Authenticate and authorize separately; enforce permissions for each protected resource and tenant. Prefer established token/session mechanisms and verify signature, audience, expiry, and issuer when using JWTs.
- Validate all input; escape output according to its **rendering context** (HTML, SQL, shell, etc.). JSON encoding is not a substitute for authorization. Do not introduce CSRF middleware by default for purely stateless bearer-token APIs; require CSRF protection for browser cookie/session-authenticated state-changing requests.
- Restrict CORS to approved origins/methods/headers where possible; never assume CORS is authentication. Limit body size, request rates, and timeouts; protect sensitive endpoints against enumeration and brute force.
- Use `password_hash()` and `password_verify()` for passwords and store password hashes, not plaintext. Keep secrets and personally identifiable data out of logs; add request correlation IDs.
- Apply HTTPS, secure headers where relevant, and security updates. Do not return exception messages directly to clients.

## Logging, tests, quality and performance

- Use Monolog as a PSR-3 logger if logging is needed; prefer structured logs with request IDs and configurable stdout/stderr or centralized log collection in managed environments. Rotate files only if deploying to hosts where file logs are appropriate.
- Use PHPUnit for unit, HTTP-level, and integration tests. Mock at stable boundaries (interfaces); use Phake only where it fits the existing project, otherwise PHPUnit test doubles are sufficient.
- Test success and failures: schema validation, authentication/authorization, error serialization, status codes, middleware ordering, transactional behavior, and pagination. Use SQLite in-memory only for DB-independent logic; use MySQL/Oracle test environments for driver-specific SQL and integration paths.
- Prefer meaningful risk-based coverage over an arbitrary universal 95% threshold. Measure coverage for critical services and endpoints while prioritizing behavior, edge cases, and regressions.
- Run PHPStan at a gradually increasing strictness level; run one formatter/linter consistently (PHP-CS-Fixer **or** PHP_CodeSniffer, according to repository policy).
- Enable OPcache in production, profile slow queries, paginate large results, and avoid per-request full-table scans. Add caching only with a clear invalidation strategy.

## Typical commands

```bash
composer validate --strict
composer audit
composer check-platform-reqs
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
./vendor/bin/phpcs --standard=PSR12 src/ tests/
# Or, when the project uses PHP-CS-Fixer:
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

Do not assume all listed tools are installed; inspect `composer.json` and use the project's scripts first.

## Official references

- PHP versions and support: https://www.php.net/supported-versions.php
- PHP documentation: https://www.php.net/manual/en/
- Slim 4: https://www.slimframework.com/docs/v4/
- Slim middleware: https://www.slimframework.com/docs/v4/concepts/middleware.html
- Slim body parsing: https://www.slimframework.com/docs/v4/middleware/body-parsing.html
- PHP-DI and Slim: https://php-di.org/doc/frameworks/slim.html
- Composer: https://getcomposer.org/doc/
- PHPUnit: https://docs.phpunit.de/
- PHPStan: https://phpstan.org/user-guide/getting-started
- PSR standards: https://www.php-fig.org/psr/
- Packagist (verify releases): https://packagist.org/
