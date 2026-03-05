# Visual Verification

## REST API

Namespace: `vip-agentforce/v1`

```bash
# Unauthenticated (public endpoints only)
curl -s http://vip-agentforce.vipdev.lndo.site/wp-json/vip-agentforce/v1/sync-progress | jq

# Authenticated REST call via wp eval (bypasses cookie auth)
vip dev-env exec --slug=vip-agentforce -- wp eval "
wp_set_current_user(1);
\$request = new WP_REST_Request('GET', '/vip-agentforce/v1/sync-progress');
\$response = rest_do_request(\$request);
echo json_encode(\$response->get_data(), JSON_PRETTY_PRINT);
" --user=1
```

## Browser Automation

Use browser automation to navigate to the dev site and visually verify UI changes.
Two tools are available:

**1. Claude Chrome integration** (`mcp__claude-in-chrome__*`):

- `mcp__claude-in-chrome__navigate` — open a URL
- `mcp__claude-in-chrome__read_page` — read rendered page content
- `mcp__claude-in-chrome__find` — locate elements on page
- `mcp__claude-in-chrome__computer` — take screenshots, click, interact
- `mcp__claude-in-chrome__read_console_messages` — check browser console for JS errors
- `mcp__claude-in-chrome__read_network_requests` — inspect network traffic (API calls, failed loads)

**2. agent-browser** (https://github.com/vercel-labs/agent-browser):

```bash
agent-browser open http://vip-agentforce.vipdev.lndo.site/
agent-browser snapshot    # inspect DOM elements
agent-browser screenshot --output /tmp/check.png
```

**Key pages to check** (get current URLs from `vip dev-env info --slug=vip-agentforce`):

- Frontend: `http://vip-agentforce.vipdev.lndo.site/`
- Admin settings: `http://vip-agentforce.vipdev.lndo.site/wp-admin/admin.php?page=vip-agentforce`
- Auto-login: use LOGIN URL from `vip dev-env info`

Especially useful for: CMP chatbot widget rendering, settings page changes, frontend asset loading.
