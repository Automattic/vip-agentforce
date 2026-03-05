# Dev Environment

The dev environment is `vip dev-env` with slug `vip-agentforce`.

Run `vip dev-env info --slug=vip-agentforce` to get current URLs and ports. Defaults:

- **Domain**: `http://vip-agentforce.vipdev.lndo.site/`
- **WP Admin**: `http://vip-agentforce.vipdev.lndo.site/wp-admin/`
- **phpMyAdmin**: `http://vip-agentforce-pma.vipdev.lndo.site/`
- **Mailpit**: `http://vip-agentforce-mailpit.vipdev.lndo.site/`
- **Multisite**: yes (single site at blog_id=1)

These values (especially ports and login URLs) may change between restarts.

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
