# Dev Environment

The dev environment is `vip dev-env` with slug `vip-agentforce`.

Run `vip dev-env info --slug=vip-agentforce` to get current URLs and ports. Defaults:

- **Domain**: `http://vip-agentforce.vipdev.lndo.site/`
- **WP Admin**: `http://vip-agentforce.vipdev.lndo.site/wp-admin/`
- **phpMyAdmin**: `http://vip-agentforce-pma.vipdev.lndo.site/`
- **Mailpit**: `http://vip-agentforce-mailpit.vipdev.lndo.site/`
- **Multisite**: yes (single site at blog_id=1)

These values (especially ports and login URLs) may change between restarts.

## Local Configuration (`env.php`)

`env.php` lives in the plugin root, is gitignored, and is auto-loaded if present.
Use it to inject `VIP_AGENTFORCE_CONFIGS` and feature flags so the WP Admin
settings page and frontend behave as if the integration is wired up — without
hitting a real Salesforce org. See `docs/setup.md` for the full reference.

Minimum useful template for agent-driven testing:

```php
<?php
// Pretend the org-level integration config came back from the VIP Config API.
define( 'VIP_AGENTFORCE_CONFIGS', [
    'salesforce_instance_url'    => 'https://example.my.salesforce.com',
    'ingestion_api_instance_url' => 'https://your-instance.salesforce.com',
    'ingestion_api_token'        => 'fake-token-for-local-dev',
    'ingestion_api_source_name'  => 'wpvip_agents',
    'ingestion_api_object_name'  => 'wordpress_post',
] );

// Unlocks `dev/setup.php` (extra admin tools, debug helpers).
define( 'VIP_AGENTFORCE_DEVELOPER_MODE', true );

// Short-circuits real Salesforce HTTP calls and logs request bodies via
// `error_log()` — visible in `vip dev-env logs --service=php`.
define( 'VIP_AGENTFORCE_MOCK_INGESTION_API', true );
```

`env.php` is `require_once`'d on every plugin bootstrap (see
`vip-agentforce.php`), so edits take effect on the next request — no
container restart required. Verify the constants are live:

```bash
vip dev-env exec --slug=vip-agentforce -- wp eval "
var_export( defined( 'VIP_AGENTFORCE_CONFIGS' ) ? VIP_AGENTFORCE_CONFIGS : 'undefined' );
echo PHP_EOL;
var_export( defined( 'VIP_AGENTFORCE_MOCK_INGESTION_API' ) ? VIP_AGENTFORCE_MOCK_INGESTION_API : false );
" --user=1
```

## WP-CLI (via vip dev-env exec)

All `wp` commands go through `vip dev-env exec`:

```bash
# Run any WP-CLI command
vip dev-env exec --slug=vip-agentforce -- wp <command>

# Evaluate arbitrary PHP in the WordPress context
vip dev-env exec --slug=vip-agentforce -- wp eval "<php code>"

# Run as a specific user (needed for permissions)
vip dev-env exec --slug=vip-agentforce -- wp eval "<code>" --user=1
```

## Ingestion CLI Commands

```bash
# Sync status
vip dev-env exec --slug=vip-agentforce -- wp vip-agentforce ingestion sync-status

# Queue status
vip dev-env exec --slug=vip-agentforce -- wp vip-agentforce ingestion queue-status

# Trigger a sync
vip dev-env exec --slug=vip-agentforce -- wp vip-agentforce ingestion sync

# Process the queue
vip dev-env exec --slug=vip-agentforce -- wp vip-agentforce ingestion process-queue

# Delete records from Salesforce
vip dev-env exec --slug=vip-agentforce -- wp vip-agentforce ingestion delete <record-id>
```

## Logs

```bash
# PHP logs (errors, warnings, error_log() output)
vip dev-env logs --slug=vip-agentforce --service=php

# Nginx access/error logs
vip dev-env logs --slug=vip-agentforce --service=nginx

# Database logs
vip dev-env logs --slug=vip-agentforce --service=database

# Follow logs in real-time
vip dev-env logs --slug=vip-agentforce --service=php --follow

# Write a test log entry, then read it back
vip dev-env exec --slug=vip-agentforce -- wp eval "error_log('TEST: my message');"
vip dev-env logs --slug=vip-agentforce --service=php 2>&1 | grep "TEST:"
```

Note: `WP_DEBUG_LOG` is set to `/dev/stderr` which routes to the PHP service logs.

## Database

```bash
# Run SQL queries
vip dev-env exec --slug=vip-agentforce -- wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%agentforce%';"

# Check options
vip dev-env exec --slug=vip-agentforce -- wp option get <option_name> --format=json

# List transients
vip dev-env exec --slug=vip-agentforce -- wp transient list --format=table

# phpMyAdmin and DB port — get current values from:
#   vip dev-env info --slug=vip-agentforce
```

## Shell Access

```bash
# Open a shell inside the container
vip dev-env shell --slug=vip-agentforce

# Run a single command inside the container (as root)
vip dev-env shell --slug=vip-agentforce --root -- <command>
```

## Cron

```bash
# List cron events
vip dev-env exec --slug=vip-agentforce -- wp cron event list --format=table

# Run a specific cron event
vip dev-env exec --slug=vip-agentforce -- wp cron event run <hook-name>
```
