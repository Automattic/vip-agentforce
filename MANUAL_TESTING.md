# Manual Testing Guide

## Prerequisites

### 1. Configure Salesforce Credentials

Add the following to your `wp-config.php` or define via environment:

```php
define( 'VIP_AGENTFORCE_CONFIGS', json_encode( [
    'salesforce_instance_url'    => 'https://YOUR_INSTANCE.c360a.salesforce.com',
    'ingestion_api_token'        => 'YOUR_BEARER_TOKEN',
    'ingestion_api_source_name'  => 'VIP_AgentForce_Integration',
    'ingestion_api_object_name'  => 'wordpress_post',
] ) );
```

### 2. Enable Ingestion Filter

Add to your theme's `functions.php` or a mu-plugin:

```php
// Enable ingestion for all published posts
add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
```

Or for specific post types:

```php
add_filter( 'vip_agentforce_should_ingest_post', function( $should_ingest, $post ) {
    return 'post' === $post->post_type;
}, 10, 2 );
```

### 3. Debug Logging (Optional)

To see what's happening, add a failure listener:

```php
add_action( 'vip_agentforce_post_ingestion_failed', function( $failure ) {
    error_log( 'Ingestion failed: ' . print_r( $failure, true ) );
} );

add_action( 'vip_agentforce_post_deletion_failed', function( $failure ) {
    error_log( 'Deletion failed: ' . print_r( $failure, true ) );
} );
```

---

## Test Cases

### TC1: Post Ingestion - Success

**Steps:**
1. Create a new post in WordPress admin
2. Set status to "Published"
3. Click "Publish"

**Expected:**
- Post is sent to Salesforce Data Cloud Ingestion API
- API returns HTTP 202 Accepted
- Post meta `vip_agentforce_ingestion_attempted` is set with timestamp

**Verify in Salesforce:**
- Record appears in Data Cloud with `site_id_blog_id_post_id` format (e.g., `101_1_123`)

---

### TC2: Post Ingestion - Draft Not Ingested

**Steps:**
1. Create a new post
2. Keep status as "Draft"
3. Click "Save Draft"

**Expected:**
- Post is NOT sent to Salesforce
- No `vip_agentforce_ingestion_attempted` meta is set

---

### TC3: Post Ingestion - No Filter Returns False

**Steps:**
1. Remove or disable the `vip_agentforce_should_ingest_post` filter
2. Publish a post

**Expected:**
- Post is NOT sent to Salesforce (safety feature)
- No errors thrown

---

### TC4: Post Ingestion - Missing Config

**Steps:**
1. Remove `ingestion_api_token` from config
2. Publish a post

**Expected:**
- `vip_agentforce_post_ingestion_failed` action fires
- Failure code: `api_error`
- Error message: "Missing required API configuration"

---

### TC5: Post Ingestion - API Error (Invalid Token)

**Steps:**
1. Set an invalid `ingestion_api_token`
2. Publish a post

**Expected:**
- `vip_agentforce_post_ingestion_failed` action fires
- Failure code: `api_error`
- Response contains non-202 status code

---

### TC6: Post Ingestion - Transform Failure

**Steps:**
1. Add a filter that returns null:
   ```php
   add_filter( 'vip_agentforce_transform_post', '__return_null', 20 );
   ```
2. Publish a post

**Expected:**
- `vip_agentforce_post_ingestion_failed` action fires
- Failure code: `transform_failed`

---

### TC7: Post Update - Re-ingestion

**Steps:**
1. Publish a post (TC1)
2. Edit the post title
3. Click "Update"

**Expected:**
- Updated post is sent to Salesforce API
- Same `site_id_blog_id_post_id` identifier (upsert behavior)

---

### TC8: Post Unpublish - Deletion from Salesforce

**Steps:**
1. Publish a post (ensure it was ingested)
2. Change status from "Published" to "Draft"
3. Click "Update"

**Expected:**
- Delete API is called for this post
- `vip_agentforce_ingestion_attempted` meta is cleared on success

---

### TC9: Post Delete - Permanent Deletion

**Steps:**
1. Publish a post (ensure it was ingested)
2. Move post to Trash
3. Empty trash (permanent delete)

**Expected:**
- Delete API is called before post is removed
- Uses `before_delete_post` hook

---

### TC10: Post Unpublish - Non-ingested Post Skipped

**Steps:**
1. Create a post while ingestion filter is disabled
2. Publish it (won't be ingested)
3. Enable ingestion filter
4. Change post to Draft

**Expected:**
- No delete API call (post was never ingested)
- Logger records: "Post was not previously ingested, skipping deletion"

---

### TC11: Delete Non-published Post - Skipped

**Steps:**
1. Create a draft post
2. Delete it permanently

**Expected:**
- No delete API call
- Logger records: "Deleted post was not published, skipping Salesforce deletion"

---

## Verifying API Calls

### Using Browser Dev Tools

1. Open Network tab
2. Filter by "salesforce" or your instance URL
3. Publish/update a post
4. Look for POST request to `/api/v1/ingest/sources/...`

### Using curl

Test the API directly:

```bash
export TOKEN="your_bearer_token"

curl -X POST "https://YOUR_INSTANCE.c360a.salesforce.com/api/v1/ingest/sources/VIP_AgentForce_Integration/wordpress_post" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "data": [
      {
        "site_id_blog_id_post_id": "101_1_999",
        "site_id": "101",
        "blog_id": "1",
        "post_id": "999",
        "title": "Test Post",
        "content": "Test content",
        "published": true,
        "post_status": "publish",
        "post_type": "post"
      }
    ]
  }'
```

Expected response: HTTP 202 Accepted

---

## Checking Post Meta

Via WP-CLI:

```bash
wp post meta get <post_id> vip_agentforce_ingestion_attempted
```

Via PHP:

```php
$timestamp = get_post_meta( $post_id, 'vip_agentforce_ingestion_attempted', true );
if ( $timestamp ) {
    echo "Ingestion attempted at: " . date( 'Y-m-d H:i:s', $timestamp );
}
```

---

## Troubleshooting

### "Missing required API configuration"
- Check all 4 config values are set: `salesforce_instance_url`, `ingestion_api_token`, `ingestion_api_source_name`, `ingestion_api_object_name`

### Posts not being ingested
- Verify `vip_agentforce_should_ingest_post` filter is returning `true`
- Check post status is `publish`

### API returning non-202
- Verify token is valid and not expired
- Check source name and object name match Salesforce configuration
- Review response body for error details
