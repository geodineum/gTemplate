# gTemplate — Integration Contract

**Role:** WordPress parent theme providing gCore framework wiring, gNode-Client connectivity, 3-tier face rendering, REST API, and multi-manager integration for the Geodineum ecosystem.

gTemplate is a PHP parent theme (72 files under `inc/`; `functions.php` is a thin loader for the 9-phase bootstrap) wired via the gCore MU-plugin (declared in `composer.json`). It is the sole theme-level producer of contact-form COMMS messages and of `[gform]` capture streams (`{site_id}:forms:*`), and registers a 19-dimensional service topology with the gNode daemon.

---

## 1. PROVIDES

Interfaces other components (child themes, operators, gNode daemon, Geodineum-COMMS) may rely on.

### 1.1 REST API

| Endpoint | Signature | Evidence |
|---|---|---|
| Contact form submit | `POST /wp-json/{namespace}/contact/submit` `{ name, email, subject?, message, ... }` → validation errors: JSON `{ success: false, error: string }`; delivery outcome: `text/html` fragment (see §4.2) | `inc/rest/resources/forms.php:28-56, 182-417` |
| CSRF token | `GET /wp-json/{namespace}/csrf-token` → `{ success: true, token: string, expires_in: int }` | `inc/rest/resources/forms.php:59-63, 427-435` |
| Generic form submit (`[gform]`) | `POST /wp-json/{namespace}/form/submit` `{ form_id, consent, _form_load_time, _js_challenge, ...fields }` → `{ success: bool, message?: string, error?: string }` | `inc/rest/resources/forms.php:66-70, 83-169` |

> **Not exhaustive.** The three endpoints above are the **primary form-producer surface** — the contracted, stable integration points for contact/`[gform]` producers. gTemplate registers roughly 35 REST routes in total; the full route table is **not exhaustively contracted here** and lives in the code (`inc/rest/**`, `inc/integrations/**`). The additional public-facing endpoints below are the ones an adopter (child theme, frontend, HTMX widget) would reasonably integrate against.

**Additional public frontend endpoints** (verified in code):

| Endpoint | Method | Access | Evidence |
|---|---|---|---|
| `/wp-json/{namespace}/ai/chat` | POST | **public** (`__return_true`; per-IP rate-limit in handler) | `inc/integrations/content/shortcode.php:466-469` |
| `/wp-json/{namespace}/ai/generate` | POST | capability-gated (`current_user_can('edit_posts')`) | `inc/integrations/content/shortcode.php:488-491` |
| `/wp-json/{namespace}/render` | POST | **public** (`__return_true`) | `inc/rest/resources/render.php:35-38` |
| `/wp-json/{namespace}/render-all` | GET | **public** (`__return_true`) | `inc/rest/resources/render.php:29-32` |
| `/wp-json/{namespace}/page/{id}` | GET | **public** (`__return_true`) | `inc/rest/resources/pages.php:26-29` |
| `/wp-json/{namespace}/post/{id}` | GET | **public** (`__return_true`) | `inc/rest/resources/posts.php:28-31` |
| `/wp-json/{namespace}/bundle/post/{id}` | GET | **public** (`__return_true`) | `inc/rest/resources/posts.php:42-45` |
| `/wp-json/{namespace}/template/{name}` | GET | **public** (`__return_true`) | `inc/integrations/content/shortcode.php:442-445` |
| `/wp-json/{namespace}/cookie-consent` | GET / POST | GET **public**; POST nonce-gated (`gtemplate_rest_verify_nonce`) | `inc/integrations/features/cookie.php:340-356` |
| `/wp-json/{namespace}/language` | GET / POST | GET **public**; POST nonce-gated (`gtemplate_rest_verify_nonce`) | `inc/integrations/content/translate.php:308-341` |

`{namespace}` is filterable via `gtemplate_rest_namespace` (see FILTER_REGISTRY.md).

### 1.2 COMMS stream production

gTemplate XADDs contact-form messages to the COMMS stream.

- **Stream key:** `{site_id}:gnode:comms:{environment}` — literal braces (Redis Cluster hash-tag). `site_id` derives from domain or config; `environment` from `registration.yaml` `metadata.environment` or `WP_ENVIRONMENT_TYPE`. (`inc/rest/resources/forms.php:292`)
- **XADD format:** `XADD {site_id}:gnode:comms:{environment} * [ id, type, timestamp, site_id, environment, priority, sender(JSON), content(JSON), metadata(JSON), dispatch(JSON) ]` (`inc/rest/resources/forms.php:299-330`)

Field table — see §4.

### 1.3 Forms capture stream production (`[gform]`)

Every `[gform]` submission accepted by `POST /form/submit` is XADD'd to a per-form ValKey stream. This is the audience-data surface the dashboard mines.

- **Stream key:** `{site_id}:forms:{form_id}` — literal braces (hash-tag); `form_id` sanitized to `[a-z0-9_-]`, default `default`. (`inc/rest/resources/forms.php:145`)
- **XADD format:** `XADD {site_id}:forms:{form_id} * [ form_id, ts, iso, fp, uri, ua, consent, fields(JSON) ]` (`inc/rest/resources/forms.php:146-155`)
- **Rate-limit key:** `{site_id}:forms:rl:{fp}` — INCR + 3600s EXPIRE, max 20 submissions/hour/fingerprint. (`inc/rest/resources/forms.php:137-142`)
- **Retention:** producer-side `XTRIM ~5000` per stream. (`inc/rest/resources/forms.php:157`)
- **Privacy:** `fp` is `substr(sha256(ip|ua|site_id), 0, 24)` — the raw IP is never stored; consent is mandatory (rejected without it). (`inc/rest/resources/forms.php:110-131`)

Field table — see §4.3.

### 1.4 PHP helpers / config

| Function | Signature | Evidence |
|---|---|---|
| `gtemplate_get_registration_config()` | → array `{ site_id, service, capabilities(19D), metadata{environment,theme}, valkey{user,password_file} }` | `inc/bootstrap/gcore-init.php:23-25` |
| `gtemplate_get_site_id()` | → string | `inc/helpers/init-helpers.php:128-143` |
| `gtemplate_detect_environment()` | → DTAP enum | `inc/helpers/init-helpers.php:191-216` |
| `gtemplate_gnode_keybased()` | → keybased client exposing `getStorage()->xadd(...)` | `inc/rendering/helpers.php:24-26` |

### 1.5 Theme filter/action contract (for child themes)

Public hooks documented in `FILTER_REGISTRY.md` (prefix `gtemplate_*`):

- **Filters:** `gtemplate_face_count`, `gtemplate_rest_namespace`, `gtemplate_content_sources`, `gtemplate_template_variables`, `gtemplate_dynamic_css`, `gtemplate_js_config`
- **Actions:** `gtemplate_before_layout`, `gtemplate_after_layout`, `gtemplate_render_navigation`
- **Security-sensitive (environment gate / access control):**
  - `gtemplate_environment_gate_grant_access` — filter, default `false`, arg `string $environment`; return `true` to grant a visitor entry to a **gated non-prod environment** without a site-wide ViewKey cookie (`inc/environment-gate.php:158`).
  - `gtemplate_gate_exempt_paths` — filter, default `['/sw.js','/manifest.json','/manifest.webmanifest']`; request paths **exempted from the ViewKey gate** (served to all visitors, incl. unauthenticated) (`inc/environment-gate.php:673`).

Evidence: `FILTER_REGISTRY.md`.

### 1.6 Topology registration (→ gNode daemon)

`TopologyManager.forceRegister()` writes a 19-dimensional service registration. Manager/service configs live in `{site_id}:config:{category}` keys — **Hashes / strings, not streams** — written via `SETEX` (e.g. `{site_id}:config:registration`, `inc/registration.php:314-318`). Key schema documented at `inc/integrations/gnode/config.php:16`. (cross_deps)

---

## 2. CONSUMES / REQUIRES

| Need | Expected format | From component | Evidence |
|---|---|---|---|
| gNode-Client service | `gNodeClientInterface`: `queueContactForm(name, email, subject, message, metadata) → string\|null`, `getStorage() → ValKeyStorage` | gCore MU-plugin | `inc/rest/resources/forms.php:248-273` |
| ValKey stream-key pattern | `{site_id}:gnode:comms:{environment}` with literal braces (hash-tag) | Geodineum-COMMS | `inc/rest/resources/forms.php:292` |
| COMMS message contract | Scalar `id,type,timestamp,site_id,environment,priority` + JSON `sender,content,metadata,dispatch` (see §4) | Geodineum-COMMS | `inc/rest/resources/forms.php:299-330`; `Geodineum-COMMS/CONTRACT.md` |
| Registration config schema | YAML: `version, site_id, service{type,tier,update_mode}, capabilities{19D}, metadata{environment,theme,type,domain}, valkey{user,password_file}, managers{}` | Operator provision | `registration.yaml`, `inc/gNodeConfigLoader.php` |
| WP environment mapping | `WP_ENVIRONMENT_TYPE`: development→testing, local→testing, staging→staging, acceptance→acceptance, production→production | WordPress | `inc/helpers/init-helpers.php:199-206` |
| ValKey credentials | ValKey ACL user at port `47445`; from registration `valkey.user` + `valkey.password_file` | gCore managed | `inc/gNodeConfigLoader.php:401, 609-610` |
| gCore CommsManager | Service `CommsManager`: `initialize({site_id, node_id, use_gnode, gnode_client})`, `getRecentMessages(siteId, env, count)`, `getStats`, `getSiteSettings`, `saveSiteSettings`, `testChannel`, `getDaemonStatus` | gCore | `inc/integrations/managers/comms.php:26-46` |

Also consumes from gCore via service locator: `getService('gnode_client')`, `getService('CommsManager')`, `getService('TopologyManager')`, `getService('SecurityManager')`. (cross_deps)

---

## 3. DELIVERY PATHS

Contact-form submission uses graceful degradation, no single point of failure:

1. **PRIMARY** — `$gNodeClient->queueContactForm(name, email, subject, message, metadata)` → `messageId` (`inc/rest/resources/forms.php:248-280`)
2. **FALLBACK** — direct ValKey: `gtemplate_gnode_keybased()->getStorage()->xadd(key, '*', fields)` → `entryId` (`inc/rest/resources/forms.php:283-340`)
3. **FINAL FALLBACK** — WordPress `wp_mail()` (`inc/rest/resources/forms.php:353-401`)

Both XADD paths (primary, fallback) are intended to produce the identical message shape. No documented contract guarantees byte-identical output across the two paths (see Adherence).

---

## 4. WIRE FORMATS

### 4.1 COMMS stream message

**Stream:** `{site_id}:gnode:comms:{environment}` (literal braces).
**Source:** `inc/rest/resources/forms.php:299-330`. **Daemon consumes:** `Geodineum-COMMS/CONTRACT.md §1`.

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | UUID, scalar | recommended | |
| `type` | scalar enum `contact\|alert\|error\|test\|system` | yes | |
| `timestamp` | ISO-8601, scalar | recommended | |
| `site_id` | string, scalar | optional | COMMS derives from stream key, not body |
| `environment` | DTAP, scalar | yes | **drives non-prod send gate**; top-level field is authoritative |
| `priority` | int 1-5, scalar | optional | |
| `sender` | JSON `{name?, email?, phone?, ip?, user_agent?}` | optional | |
| `content` | JSON `{subject, body, attachments?}` | yes | |
| `metadata` | JSON `{form_type?, source_url?, face_id?, environment?, ...}` | optional | `metadata.environment` is informational only |
| `dispatch` | JSON `{channels:[], status, attempts, last_attempt?, next_retry?}` | optional | contact path ships `channels:['email']` (`forms.php:324`), not `[]` |

All non-scalar fields are `json_encode`'d strings on the wire.

### 4.2 REST contact submit

**`POST /wp-json/{namespace}/contact/submit`** (`inc/rest/resources/forms.php:28-56, 182-417`)

Request: `name`(text, req), `email`(email, req), `subject`(text, opt), `message`(textarea, req), `_form_load_time`(int), `_js_challenge`(string), `source_url`(url), `face_id`(int), honeypot fields.
Response — anti-spam/validation failures: JSON `{ success: false, error: string }` (honeypot hits get a decoy `{ success: true, message }`). Delivery outcomes bypass the JSON shape: success echoes a `text/html` fragment (`<div class="form-success">…`) and exits (`forms.php:342-351`); total delivery failure echoes `<div class="form-error">…` with status 500 (`forms.php:406-416`).

Anti-spam: honeypot fields (`forms.php:189-200`), form timing > 3s (`forms.php:202-211`), JS challenge prefix `gcore_` (`forms.php:213-221`).

### 4.3 Forms capture stream entry (`[gform]`)

**Stream:** `{site_id}:forms:{form_id}` (literal braces). **Source:** `inc/rest/resources/forms.php:146-155`.

| Field | Type | Notes |
|---|---|---|
| `form_id` | string `[a-z0-9_-]` | sanitized; `default` when `[gform]` has no `id` |
| `ts` | unix epoch, string | server time at capture |
| `iso` | ISO-8601 | `current_time('c')` |
| `fp` | 24-hex string | `substr(sha256(ip\|ua\|site_id), 0, 24)` — raw IP never stored |
| `uri` | URL | source page (`source_url` param or referer) |
| `ua` | string | user agent, truncated to 200 chars |
| `consent` | `"1"` | consent is mandatory; submissions without it are rejected 400 |
| `fields` | JSON object | visitor-supplied fields only — control/honeypot fields stripped (`forms.php:119-125`) |

Consumers must tolerate producer-side `XTRIM ~5000` retention and the rate-limit companion key `{site_id}:forms:rl:{fp}` (INCR, 3600s TTL, max 20/h).

### 4.4 Registration config key

**Key:** `{site_id}:config:registration` (ValKey GET). **Content:** JSON-encoded registration config — `version, site_id, service, capabilities(19D), metadata, valkey`. **Source:** `inc/registration.php:85-86, 314-318`.

### 4.5 Filter registry

`FILTER_REGISTRY.md` — public hooks per §1.5.

---

## 5. PUBLIC TYPES

- `gNodeClientInterface` (`gCore\gNode`) — `queueContactForm()`, `getStorage()`
- `WP_REST_Request`, `WP_REST_Response` — WordPress REST framework
- `ValKeyStorage` (`gCore\gNode\Storage`) — `XADD`, `GET`, `SETEX` abstraction
- Registration config array: `{version, site_id, service{type,tier,update_mode}, capabilities{19D}, metadata{type,theme,environment,domain}, valkey{user,password_file}, managers{}}`
- COMMS message entry (post-XADD): `{id, type, timestamp, site_id, environment, priority, sender, content, metadata, dispatch}`

---

## 6. EXAMPLE — direct ValKey XADD path

```php
// inc/rest/resources/forms.php:283-340 (shape)
$site_id     = gtemplate_get_site_id();
$environment = gtemplate_detect_environment();        // DTAP enum
$key         = "{{$site_id}}:gnode:comms:{$environment}"; // literal braces

$entryId = gtemplate_gnode_keybased()->getStorage()->xadd($key, '*', [
    'id'          => wp_generate_uuid4(),
    'type'        => 'contact',
    'timestamp'   => gmdate('c'),
    'site_id'     => $site_id,
    'environment' => $environment,                     // top-level: drives gate
    'priority'    => 3,
    'sender'      => json_encode(['name' => $name, 'email' => $email]),
    'content'     => json_encode(['subject' => $subject, 'body' => $message]),
    'metadata'    => json_encode(['form_type' => 'contact', 'environment' => $environment]),
    'dispatch'    => json_encode(['channels' => ['email'], 'status' => 'pending', 'attempts' => 0]),
]);
```

---

## 7. ADHERENCE

Known observations and cross-component status involving gTemplate.

**Wire adherence (verified ADHERES):** The gTemplate direct-XADD path (`forms.php:299-330`) builds the brace-literal `{site_id}:gnode:comms:{environment}` key with top-level scalar fields plus `json_encode`'d `sender/content/metadata/dispatch`, matching Geodineum-COMMS `parse_message` (`stream_reader.rs:215-303`). The non-prod-gating field — **top-level scalar `environment`, not nested `metadata.environment`** — is stamped by gTemplate and read directly by the consumer. Field-by-field match with the gNode-Client and child-theme producers; no producer/consumer wire mismatch.

**Internal inconsistencies (within gTemplate):**

1. **Environment default divergence.** `forms.php:291` defaults `environment` to `'staging'` when config is missing; `gtemplate_detect_environment()` (`inc/helpers/init-helpers.php:191-216`) defaults to `'production'`. Pre-production forms can be incorrectly gated. This is an internal gTemplate inconsistency, **not** a wire mismatch — COMMS gates fail-safe either way.
2. **Double-stamped environment.** `forms.php:304, 321` stamps both `message.environment` and `metadata.environment`. Per `Geodineum-COMMS/CONTRACT.md §3`, only top-level `message.environment` drives the daemon's non-prod send gate; `metadata.environment` is informational. Harmless (consumer reads only the top-level field) but redundant.
3. **Dual-path drift risk.** `forms.php` implements dual-path XADD (gNode-Client + direct fallback) with no documented contract guaranteeing identical messages. Drift risk if one path is updated without the other.
4. **Registration-lookup trust.** `inc/registration.php:85` trusts that `{site_id}:config:registration` was written with literal braces; no validation that the key exists or matches format.
5. **CommsManager non-prod flag.** `comms.php:39-44` initializes with a `use_gnode` boolean but does not expose the daemon's `--allow-nonprod-send` flag, blocking non-prod real sends even when the daemon permits them.
6. **gNodeClient dual-assignment.** `gcore-init.php:88-90` assigns `gNodeClient` as keybased-client and storage provider, assuming a single interface without runtime type validation.
7. **Inconsistent status headers.** `forms.php:342-351` success path echoes HTML and exits with implicit 200 (no explicit `status_header(200)`); error path uses `status_header(500)` (`forms.php:413`).
8. **No rate-limit tunables.** Contact-form rate limiting depends on gCore SecurityManager injection (`gcore-init.php:111-112`) with no public per-endpoint API; the `[gform]` limit (20/h/fingerprint) is hardcoded (`forms.php:137-142`).
9. **CSRF nonce, not distributed state.** `forms.php:428` uses a WordPress nonce. In a multi-server setup, a nonce from server A may not validate on server B if the nonce action differs.

**Ecosystem enum drift (low severity, no live failure):** The CommsMessage `type` enum disagrees across artifacts — `Geodineum-COMMS/CONTRACT.md §1` allows `custom`; `outbound_alert.yaml` omits `custom`; the gNode-Client docblock (`gNodeClient.php:4686`) lists `contact-form`, which appears in neither. `parse_message` does not validate `type` against an enum (stores any string), so this degrades gracefully; the documented surfaces simply disagree.

**Limitations relevant to consumers:**
- Direct-XADD fallback (`forms.php:283-340`) bypasses gNode-Client batching/retries; a failed XADD is logged but lost (no retry queue).
- `[gform]` capture has no fallback path: if ValKey storage is unreachable the submission is rejected 500 (`forms.php:165-167`) — nothing is queued or mailed.
- `[gform]` rate-limit enforcement is best-effort: a ValKey error during INCR is swallowed and the submission proceeds (`forms.php:143`).
- Free-tier mode (`GTEMPLATE_FREE_TIER` constant) silently disables all gNode comms with no operator warning on submit.
- gNode-Client interface is assumed but not validated at runtime; a future gCore interface change causes runtime failure in `forms.php`.
- Stream retention for `{site_id}:gnode:comms:{environment}` relies on daemon cleanup, not ValKey TTL or gTemplate-side controls.
- The gNodeConfigLoader APCu cache and the ValKey constellation generation counter are not cross-validated; a stale APCu config entry can persist until its TTL expires even after ValKey changes.
