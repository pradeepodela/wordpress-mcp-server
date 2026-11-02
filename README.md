# WordPress MCP Server (key auth)

A single-file WordPress plugin that turns any self-hosted WordPress site into a
**remote [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server**
with simple **API-key authentication**. Install it, copy the generated key, and
connect Claude (Claude Code, the claude.ai web connector, or Claude Desktop) — or
any MCP client — directly to your site.

It's built on the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
library and the WordPress **Abilities API**, so each tool is a registered ability
exposed over the MCP **Streamable HTTP** transport.

---

## Features

A connected client gets **10 tools**:

| Tool | What it does |
|------|--------------|
| `get-site-info` | Site name, URL, tagline, WP version, post/page counts |
| `list-posts` | List / search posts by status, type, or keyword |
| `get-post` | Full content and fields of one post by ID |
| `create-post` | Create a post or page — HTML or Gutenberg blocks, categories, tags, SEO meta, scheduling |
| `update-post` | Update any field of an existing post (only the fields you pass) |
| `publish-post` | Publish an existing draft/pending post |
| `delete-post` | Trash a post, or permanently delete with `force` |
| `list-terms` | List categories or tags (id, name, slug, count) |
| `upload-media` | Sideload a file into the Media Library from a URL or base64 |
| `set-featured-image` | Set a post's featured image from an attachment ID or image URL |

Extras baked into `create-post` / `update-post`:

- **Full content** — accepts raw HTML or Gutenberg block markup, with all formatting.
- **Categories & tags** — passed by name; created automatically if they don't exist.
- **SEO meta** — `seo_title`, `seo_description`, `focus_keyword` written to
  **Rank Math** and/or **Yoast** if active (falls back to `_mcp_*` post meta otherwise).
- **Scheduling** — pass a future `date` (site timezone) with `status=publish` to
  schedule the post (`future` status).

---

## Requirements

- WordPress **6.9+** (ships the Abilities API in core) — or the
  [Abilities API feature plugin](https://github.com/WordPress/abilities-api) on older versions.
- The [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) library/plugin active.
- PHP 7.4+.

---

## Install

1. Download the plugin zip (or `my-mcp-server.php`).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → upload → **Activate**.
   - If you upload over an older copy, choose **Replace current with uploaded**.
3. A strong API key is auto-generated on activation.

### Get your key & endpoint

Go to **Settings → MCP Server**. You'll see:

- **Endpoint URL** — `https://YOUR-SITE/wp-json/my-plugin/mcp`
- **API Key** — editable; type your own (min 16 chars) and **Save**, or click
  **Generate new key** for a fresh random one.
- **Full connection URL** — endpoint with `?key=…` appended (use this for clients
  that can't send custom headers, like the claude.ai web connector).

Prefer wp-config? Set the key there and the admin field locks to read-only:

```php
define( 'MY_MCP_KEY', 'your-long-random-secret' );
```

---

## Connect a client

### Claude Code (CLI)

```bash
claude mcp add --transport http gym-wp "https://YOUR-SITE/wp-json/my-plugin/mcp" \
  --header "X-API-Key: YOUR_KEY"
```

### claude.ai web / Claude Desktop connector

The web UI has no custom-header field, so put the key in the URL instead:

```
https://YOUR-SITE/wp-json/my-plugin/mcp?key=YOUR_KEY
```

Add it as a **Custom connector** with no auth — the key rides in the URL.

### Any MCP client

- **Transport:** Streamable HTTP
- **URL:** `https://YOUR-SITE/wp-json/my-plugin/mcp`
- **Auth:** header `X-API-Key: YOUR_KEY` *or* `?key=YOUR_KEY` query param

---

## Verify with curl

```bash
curl -s https://YOUR-SITE/wp-json/my-plugin/mcp \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

A valid key returns `serverInfo` with name **"My Keyed MCP Server"**. A wrong or
missing key returns `401`.

> Note: after `initialize`, a `tools/list` call needs both the `Mcp-Session-Id`
> header returned by `initialize` and `MCP-Protocol-Version: 2025-06-18`.

---

## How it works

- **Abilities** are registered on `wp_abilities_api_init` under the `site` category
  (the category must already be registered, or WordPress silently skips it).
- The MCP server is created on `mcp_adapter_init` via `create_server()`, listing the
  ten abilities as its tools and gating them with a key-check callback.
- A `determine_current_user` filter logs the request in as an administrator when a
  valid key is present — the Streamable HTTP transport requires a real WP user for
  session creation (otherwise you get `-32010`).

---

## Security notes

- The key grants **administrator-level** access to the listed tools — treat it like a password.
- Prefer defining `MY_MCP_KEY` in `wp-config.php` and keep it out of version control.
- Always serve over **HTTPS** so the key isn't sent in the clear.
- Rotate the key from **Settings → MCP Server → Generate new key** if it leaks
  (remember to update every connected client).

---

## License

MIT — see [LICENSE](LICENSE).
