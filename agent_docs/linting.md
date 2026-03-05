# Linting

## Post-Edit Tasks

Only run lint, tests, or self-review when **explicitly asked**. Do not run them automatically after
completing feature work.

## Feedback Loops

**Every change MUST have a verification command. Run locally before finishing.**

| Check  | Command                                        |
| ------ | ---------------------------------------------- |
| Lint   | `composer lint` (PHP) / `npm run lint:js` (JS) |
| Types  | `composer analyze` (PHPStan)                   |
| Tests  | `composer test-noninteractive`                 |
| Visual | `curl` the REST endpoint or check WP admin UI  |

**Don't play whack-a-mole.** Run ALL checks before pushing, not just the one you think you fixed.

### PHP Lint Fix Workflow

```bash
# Auto-fix coding standards
composer format

# Verify — should return clean
composer lint

# Static analysis
composer analyze
```

### JS/CSS Lint Fix Workflow

```bash
npm run lint:js:fix
npm run lint:css:fix

# Verify
npm run lint
```

### Visual Confirmation

```bash
# Check WP admin settings page
# Browse to: /wp-admin/admin.php?page=vip-agentforce

# Check ingestion status via WP-CLI
wp vip-agentforce ingestion status

# Check REST API
curl -s http://localhost/wp-json/vip-agentforce/v1/... | jq
```
