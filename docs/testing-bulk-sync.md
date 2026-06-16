# Testing Bulk Sync: Step-by-Step Guide

> **Prerequisites:** VIP dev-env running (`composer start`), plugin active.

> **⚠️ Important: WP-Cron is a pseudo-cron.** WordPress doesn't use a real system
> cron. Instead, scheduled events only fire when someone makes an HTTP request to
> the site (a page visit). In a local dev environment with no browser traffic,
> **cron events will never fire on their own** — they'll show as "overdue" in
> `queue-status`. This is normal and expected behavior.
>
> To process the queue locally, either:
> - **Trigger cron manually:** `vip dev-env exec -- wp cron event run vip_agentforce_process_ingestion_queue`
> - **Use `process-queue` directly:** `vip dev-env exec -- wp vip-agentforce ingestion process-queue`
>
> On production (WordPress VIP), this isn't an issue — constant traffic triggers
> WP-Cron, and VIP also runs a system-level cron as a safety net.
>
> See: [WP-Cron overview](https://developer.wordpress.org/plugins/cron/) •
> [Hooking into system cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) •
> [`wp cron event run`](https://developer.wordpress.org/cli/commands/cron/event/run/)

---

## 1. Configure for Local Testing

You can test with either a **mock API** (no real Salesforce calls) or a **real Salesforce instance**.

### Option A: Mock API (recommended for most testing)

```php
<?php
define( 'VIP_AGENTFORCE_CONFIGS', [
    'ingestion_api_instance_url' => 'https://fake.salesforce.com',
    'ingestion_api_token'        => 'mock:202',
    'ingestion_api_source_name'  => 'test-source',
    'ingestion_api_object_name'  => 'test-object',
    'ingestion_api_sync_all_posts' => true,
] );
define( 'VIP_AGENTFORCE_DEVELOPER_MODE', true );
define( 'VIP_AGENTFORCE_MOCK_INGESTION_API', true );
```

Use a normal fake token or `mock:202` for the default success path. For failure
smoke tests, change only the token:

```php
'ingestion_api_token' => 'mock:401',            // Unauthorized
'ingestion_api_token' => 'mock:403',            // Forbidden
'ingestion_api_token' => 'mock:429',            // Rate limited
'ingestion_api_token' => 'mock:500',            // Server error
'ingestion_api_token' => 'mock:network',        // Transport failure
'ingestion_api_token' => 'mock:rotate-recover', // 401 once, then rotate local token option to mock:202
```

The mock also accepts `vip_agentforce_mock_scenario` from request query/body
params and records calls in the `vip_agentforce_mock_ingestion_requests` option.

### Option B: Real Salesforce instance

To make actual API calls to Salesforce Data Cloud:

```php
<?php
define( 'VIP_AGENTFORCE_CONFIGS', [
    'ingestion_api_instance_url' => 'https://your-instance.salesforce.com',
    'ingestion_api_token'        => 'your-real-token',
    'ingestion_api_source_name'  => 'your-source-name',
    'ingestion_api_object_name'  => 'your-object-name',
    'ingestion_api_sync_all_posts' => true,
] );
define( 'VIP_AGENTFORCE_DEVELOPER_MODE', true );
// Omit VIP_AGENTFORCE_MOCK_INGESTION_API or set to false
```

Verify the plugin is active:

```bash
vip dev-env exec -- wp plugin list --name=vip-agentforce --format=table
```

---

## 2. Seed Test Posts

Create 20 published posts to have data to work with:

```bash
# Create 20 posts in one shot
for i in $(seq 1 20); do
  vip dev-env exec -- wp post create --post_type=post --post_status=publish --post_title="Test Post $i" --post_content="Content for test post number $i."
done
```

Verify they exist:

```bash
vip dev-env exec -- wp post list --post_type=post --post_status=publish --fields=ID,post_title --format=table
```

> **Note:** Creating posts triggers `save_post` hooks, which automatically queues
> each post for individual sync. Drain the queue before proceeding so we start
> the bulk sync test from a clean state.

```bash
# Drain the individual queue (posts were auto-queued on creation)
vip dev-env exec -- wp vip-agentforce ingestion process-queue --all

# Reset sync progress if any
vip dev-env exec -- wp vip-agentforce ingestion sync --reset
```

---

## 3. Check Initial State

Verify no sync is running and the queue is empty:

```bash
# Check sync progress (should say "No sync has been initiated")
vip dev-env exec -- wp vip-agentforce ingestion sync --status

# Check queue status (should show 0 syncs, 0 deletions)
vip dev-env exec -- wp vip-agentforce ingestion queue-status
```

---

## 4. Start a Bulk Sync

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync
```

Expected output:
```
Success: Bulk sync queued: 20 posts will be processed by cron.
Use `wp vip-agentforce ingestion sync-status` to monitor progress.
```

---

## 5. Check Progress (Before Cron Runs)

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Expected: `RUNNING`, 0/20 processed, cursor at 0. The sync is queued but no cron has ticked yet.

---

## 6. Try Starting a Second Sync (Should Block)

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync
```

Expected:
```
Error: A sync is already in progress. Use --status to check progress or --reset to clear a stuck sync.
```

---

## 7. Process a Small Batch Manually

Instead of waiting for cron, fire the queue processor manually with a small batch to see pagination in action. You need an active bulk sync before running `process-queue`.

If you don't already have one running, start it first:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync
```

Then process only 5 posts:

```bash
vip dev-env exec -- wp vip-agentforce ingestion process-queue --batch-size=5
```

Now check progress — should show 5/20:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Expected: `RUNNING`, 5/20 processed (25.0%), cursor pointing at the 5th post's ID.

---

## 8. Process Another Batch (See Cursor Advance)

```bash
# Process 5 more
vip dev-env exec -- wp vip-agentforce ingestion process-queue --batch-size=5
```

Check progress again:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Expected: 10/20 processed (50.0%), cursor advanced.

---

## 9. Simulate a New Post During Sync

While the sync is running (10/20 done), publish a new post:

```bash
vip dev-env exec -- wp post create --post_type=post --post_status=publish --post_title="Mid-Sync Post" --post_content="Published while bulk sync is running."
```

Check queue status — the new post should be individually queued via the save_post hook:

```bash
vip dev-env exec -- wp vip-agentforce ingestion queue-status
```

Expected: `Posts queued for sync: 1` (the new post), plus the active bulk sync info.

---

## 10. Process Remaining Posts (Including the New One)

Drain everything:

```bash
vip dev-env exec -- wp vip-agentforce ingestion process-queue --all
```

This processes: the 1 individually queued post first, then resumes bulk sync batches until done.

Check final status:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Expected: `COMPLETED`, shows total synced count, duration. The "Mid-Sync Post" was synced twice (once from queue, once from cursor) — that's fine, it's idempotent.

---

## 11. Test the REST Endpoint

Query progress via the REST API (as an authenticated admin user):

```bash
# Fetch sync progress
vip dev-env exec -- wp eval '
  wp_set_current_user(1);
  $request = new WP_REST_Request("GET", "/vip-agentforce/v1/sync-progress");
  $response = rest_do_request($request);
  echo json_encode($response->get_data(), JSON_PRETTY_PRINT) . "\n";
'
```

Expected: JSON with `status: "completed"`, counters, timestamps.

---

## 12. Reset and Start Fresh

```bash
# Reset the completed sync
vip dev-env exec -- wp vip-agentforce ingestion sync --reset

# Verify it's cleared
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Expected: `No sync has been initiated.`

Now you can start another sync:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync
```

---

## 13. Test Deletion During Sync

Start a sync but don't process it yet:

```bash
vip dev-env exec -- wp vip-agentforce ingestion sync
```

Delete a post while the sync is running:

```bash
# Grab the first post ID
POST_ID=$(vip dev-env exec -- wp post list --post_type=post --post_status=publish --field=ID --format=csv | tail -n +3 | head -1)

# Delete it
vip dev-env exec -- wp post delete $POST_ID --force
```

Check queue — the deletion should be queued:

```bash
vip dev-env exec -- wp vip-agentforce ingestion queue-status
```

Expected: `Posts queued for deletion: 1`

Process the queue:

```bash
vip dev-env exec -- wp vip-agentforce ingestion process-queue --batch-size=5
```

The deletion is processed first (highest priority), then bulk sync uses remaining capacity.

---

## 14. Test Resetting a Running Sync

```bash
# Start a sync (if not already running)
vip dev-env exec -- wp vip-agentforce ingestion sync --reset
vip dev-env exec -- wp vip-agentforce ingestion sync

# Process a partial batch
vip dev-env exec -- wp vip-agentforce ingestion process-queue --batch-size=3

# Now reset mid-sync
vip dev-env exec -- wp vip-agentforce ingestion sync --reset
```

Expected: Warning about resetting a running sync, then `Success: Sync progress has been reset.`

---

## 15. Let Cron Handle It (End-to-End)

For the full async experience, start a sync and let WP-Cron process it.

> **Reminder:** WP-Cron is triggered by page visits, not a background timer.
> In local dev you must trigger cron manually via WP-CLI — events won't fire on
> their own without HTTP traffic. See the note at the top of this guide.

```bash
# Reset and start fresh
vip dev-env exec -- wp vip-agentforce ingestion sync --reset
vip dev-env exec -- wp vip-agentforce ingestion sync

# Trigger WP-Cron manually (simulates what happens every minute)
vip dev-env exec -- wp cron event run vip_agentforce_process_ingestion_queue

# Check progress after each cron tick
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

Run the cron event a few more times to see it advance:

```bash
# Tick 2
vip dev-env exec -- wp cron event run vip_agentforce_process_ingestion_queue
vip dev-env exec -- wp vip-agentforce ingestion sync --status

# Tick 3
vip dev-env exec -- wp cron event run vip_agentforce_process_ingestion_queue
vip dev-env exec -- wp vip-agentforce ingestion sync --status
```

With 21 posts and batch size 100, it should complete in one cron tick. To see pagination, lower the batch size:

```bash
# Add a filter to reduce batch size to 5 for testing
vip dev-env exec -- wp eval '
  // Check current batch size
  echo "Default batch size: " . \Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron::DEFAULT_BATCH_SIZE . "\n";
'
```

---

## 16. Cleanup

Remove all test posts when done:

```bash
vip dev-env exec -- wp post delete $(vip dev-env exec -- wp post list --post_type=post --post_status=any --field=ID --format=csv | tr '\n' ' ') --force

# Reset sync state
vip dev-env exec -- wp vip-agentforce ingestion sync --reset
```

---

## Quick Reference

| Command | What it does |
|---|---|
| `wp vip-agentforce ingestion sync` | Start async bulk sync |
| `wp vip-agentforce ingestion sync --status` | Show progress |
| `wp vip-agentforce ingestion sync --reset` | Clear sync state |
| `wp vip-agentforce ingestion sync-status` | Dedicated status subcommand |
| `wp vip-agentforce ingestion queue-status` | Queue + cron + bulk sync info |
| `wp vip-agentforce ingestion process-queue` | Process one batch now |
| `wp vip-agentforce ingestion process-queue --all` | Drain everything |
| `wp vip-agentforce ingestion process-queue --batch-size=5` | Control batch size |
| `wp cron event run vip_agentforce_process_ingestion_queue` | Simulate a cron tick |

---

## Troubleshooting

### Sync progresses unexpectedly between status checks

Setting `cron: false` in your dev-env config does **not** fully freeze WP-Cron
execution paths. WP-Cron can still be triggered by WordPress requests (including
WP-CLI commands), so sync progress may advance between checks even without
waiting for a minute boundary.

For deterministic, manual progression, use:

```bash
# Process a specific number of posts
vip dev-env exec -- wp vip-agentforce ingestion process-queue --batch-size=5

# Or trigger the cron event directly
vip dev-env exec -- wp cron event run vip_agentforce_process_ingestion_queue
```
