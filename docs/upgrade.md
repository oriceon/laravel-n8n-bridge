![Laravel N8N Bridge](images/banner.png)

# ⬆️ Upgrade Guide

← [Back to README](../README.md)

---

## v1.x → v2.0 — Many-to-many credentials

### What changed

The single `credential_id` foreign key column on `n8n__endpoints__lists` and `n8n__tools__lists` has been replaced with two **pivot tables**:

| Old | New                                       |
|---|-------------------------------------------|
| `n8n__endpoints__lists.credential_id` | `n8n__endpoints__credentials` (pivot)     |
| `n8n__tools__lists.credential_id` | `n8n__tools__credentials` (pivot) |

This allows multiple credentials to be attached to the same endpoint or tool. Any of their keys will be accepted.

**Access rules:**
- No credentials attached → **401 Unauthorized** — every endpoint and tool must have at least one credential (see note below)
- One credential attached → only that credential's key is accepted (same as before)
- Two or more credentials attached → **new** — any of their keys are accepted

> **Note on "no credentials" behaviour change:** in v1 a resource with no `credential_id` accepted any valid key. In v2 a resource with no credentials in the pivot table rejects all requests with 401. Run the migration (which copies existing `credential_id` values to the pivot table) before deploying — resources that previously had a credential will continue to work.

---

### Migration steps

**1. Run the new migration**

```bash
php artisan migrate
```

The migration (`2026_04_22_000001_add_credential_pivot_tables.php`) automatically:
- Creates the two pivot tables
- Copies all existing `credential_id` values into the pivot tables (zero data loss)
- Drops the old `credential_id` columns

**2. Update custom code that references `credential_id`**

If you have application code that references `credential_id` directly on endpoints or tools, update it:

```php
// Before
$endpoint->credential_id = $credential->id;
$endpoint->save();

N8nEndpoint::where('credential_id', $id)->get();

// After
$endpoint->credentials()->syncWithoutDetaching([$credential->id]);

N8nEndpoint::whereHas('credentials', fn($q) => $q->where('id', $id))->get();
```

**3. Update factories (if you extended them)**

```php
// Before
N8nEndpoint::factory()->create(['credential_id' => $credential->id]);

// After
N8nEndpoint::factory()->forCredential($credential)->create();
```

**4. Update `n8n:endpoint:rotate` usage (endpoints with multiple credentials)**

Previously, key rotation on an endpoint with one credential worked via `n8n:endpoint:rotate`. This still works for endpoints with exactly one credential. If an endpoint now has multiple credentials, use `n8n:credential:rotate` directly:

```bash
# Rotate the key for a specific credential (works regardless of how many resources use it)
php artisan n8n:credential:rotate {credential-id} --grace=300
```

---

### New `n8n:credential:attach` options

The attach command has new `--detach-*` flags:

```bash
# Detach from a specific inbound endpoint
php artisan n8n:credential:attach {id} --detach-inbound=invoice-paid

# Detach from a specific tool
php artisan n8n:credential:attach {id} --detach-tool=invoices

# Detach from everything (makes all resources open)
php artisan n8n:credential:attach {id} --detach-all
```

`--all` now uses additive attach (previously it was replace, now it does not remove existing credentials from resources).

---

### New Eloquent relations

`N8nEndpoint` and `N8nTool` now have a `credentials()` `BelongsToMany` relation instead of a `credential()` `BelongsTo`:

```php
// Attach
$endpoint->credentials()->syncWithoutDetaching([$credentialId]);

// Detach
$endpoint->credentials()->detach($credentialId);

// Check
$endpoint->credentials()->pluck('id')->contains($credentialId);

// Load eagerly
N8nEndpoint::with('credentials')->get();
```

`N8nCredential` now has `inboundEndpoints()` and `tools()` as `BelongsToMany` relations (previously `hasMany`):

```php
$credential->inboundEndpoints()->get();
$credential->tools()->get();
```

---

### Rollback

The migration has a `down()` method. It restores the `credential_id` columns and populates them with the first credential from the pivot table (if multiple were attached, only the first is kept):

```bash
php artisan migrate:rollback
```

> **Warning:** Rolling back loses any secondary credentials you attached after the upgrade.

---

## v2.x — Slash-separated route paths

### What changed

Inbound endpoint slugs and tool names can now contain **forward slashes**, creating a natural namespace in the URL path.

This is a **non-breaking, additive change**. No migration is required. All existing hyphen-style slugs and tool names continue to work exactly as before.

---

### Inbound endpoints

```bash
# Before — hyphen separator (still works)
php artisan n8n:endpoint:create invoices-paid --handler="..."
# URL: /n8n/in/invoices-paid

# After — slash separator (new option)
php artisan n8n:endpoint:create invoices/paid --handler="..."
# URL: /n8n/in/invoices/paid
```

Both styles coexist in the same application:

```bash
php artisan n8n:endpoint:create invoices/paid    --handler="App\N8n\InvoicePaidHandler"
php artisan n8n:endpoint:create invoices/overdue --handler="App\N8n\InvoiceOverdueHandler"
php artisan n8n:endpoint:create orders/shipped   --handler="App\N8n\OrderShippedHandler"
```

Update the n8n HTTP Request node URL accordingly:
- Before: `https://myapp.com/n8n/in/invoices-paid`
- After: `https://myapp.com/n8n/in/invoices/paid`

The slug is stored as-is in the database (e.g. `invoices/paid`) and matched against the full incoming URL path.

---

### Tools

```bash
# Before — hyphen separator (still works)
php artisan n8n:tool:create billing-invoices --handler="..."
# URL: /n8n/tools/billing-invoices

# After — slash separator (new option)
php artisan n8n:tool:create billing/invoices --handler="..."
# URL: /n8n/tools/billing/invoices
```

#### Path resolution rules for slash-style tool names

When a request arrives at `/n8n/tools/{path}`, the controller resolves the tool name and optional resource ID:

| HTTP method | Rule |
|---|---|
| `GET`, `POST` | Try the full path as a tool name first. If no tool matches, split the last segment as the resource ID. |
| `PUT`, `PATCH`, `DELETE` | Always split the last segment as the resource ID. |

Examples with tool `billing/invoices` registered:

| Request | Tool `billing/invoices` exists? | Resolved as |
|---|---|---|
| `GET /n8n/tools/billing/invoices` | ✅ yes | Collection → calls `get()` |
| `GET /n8n/tools/billing/invoices` | ❌ no | Tool `billing`, item `invoices` → calls `getById()` |
| `GET /n8n/tools/billing/invoices/42` | ✅ yes | Tool `billing/invoices`, item `42` → calls `getById()` |
| `PATCH /n8n/tools/billing/invoices/42` | any | Last segment is always ID → calls `patch()` on tool `billing/invoices` |

---

### No migration required

The `slug` and `name` columns already store strings. Slash-style values are stored as-is (e.g. `billing/invoices`). Existing rows are unaffected.
