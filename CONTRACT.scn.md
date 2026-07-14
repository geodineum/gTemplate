# gTemplate :: CONTRACT primer (SCN)

> one-line: SCN primer — TRUTH = code on disk, this file is a point-in-time compression. Companion: CONTRACT.md (authoritative).

## ::ROLE

WordPress parent theme = web-tier producer in the Geodineum ecosystem. gNode = Sun · ValKey = backend. Stateless/state-aware via `{site_id}`-prefixed ValKey keys + COMMS streams. Sole theme-level producer of contact-form COMMS messages AND of `[gform]` capture streams `{site_id}:forms:*`. Wired into gCore via MU-plugin service locator.

## ::ANCHOR

- COMMS stream key: `{site_id}:gnode:comms:{environment}` (literal braces = hash-tag) · `forms.php:292`
- Forms capture key: `{site_id}:forms:{form_id}` (literal braces; `form_id` sanitized `[a-z0-9_-]`, default `default`) · `forms.php:145`
- Config key: `{site_id}:config:registration` (ValKey GET, JSON) · `inc/registration.php:85`
- REST (PRIMARY form-producer surface; NOT exhaustive — ~35 routes total, full table in code `inc/rest/**`+`inc/integrations/**`): `POST /wp-json/{namespace}/contact/submit` · `forms.php:28-56, 182-417` | `GET /wp-json/{namespace}/csrf-token` · `forms.php:59-63, 427-435` | `POST /wp-json/{namespace}/form/submit` ([gform]) · `forms.php:66-70, 83-169`
- REST public frontend (verified): `POST /ai/chat` public(`__return_true`, per-IP RL in handler) · `shortcode.php:466-469` | `POST /ai/generate` cap-gated(`edit_posts`) · `shortcode.php:488-491` | `POST /render` public · `render.php:35-38` | `GET /render-all` public · `render.php:29-32` | `GET /page/{id}` public · `pages.php:26-29` | `GET /post/{id}` + `GET /bundle/post/{id}` public · `posts.php:28-31, 42-45` | `GET /template/{name}` public · `shortcode.php:442-445` | `/cookie-consent` GET public / POST nonce(`gtemplate_rest_verify_nonce`) · `cookie.php:340-356` | `/language` GET public / POST nonce · `translate.php:308-341`
- COMMS XADD fields: `id,type,timestamp,site_id,environment,priority,sender,content,metadata,dispatch` (scalar + json_encode'd) · `forms.php:299-330`
- Forms XADD fields: `form_id,ts,iso,fp,uri,ua,consent,fields(JSON)` · `forms.php:146-155`
- PRIMARY send: `$gNodeClient->queueContactForm(name,email,subject,message,metadata)→messageId` · `forms.php:248-280`
- FALLBACK send: `gtemplate_gnode_keybased()->getStorage()->xadd(key,'*',fields)→entryId` · `forms.php:283-340`
- FINAL send: `wp_mail()` · `forms.php:353-401`
- Helpers: `gtemplate_get_site_id()` `init-helpers.php:128-143` · `gtemplate_detect_environment()` `init-helpers.php:191-216` · `gtemplate_get_registration_config()` `gcore-init.php:23-25` · `gtemplate_gnode_keybased()` `rendering/helpers.php:24-26`
- Filter contract: `FILTER_REGISTRY.md` (prefix `gtemplate_*`)
- ValKey ACL: port `47445`, creds from `valkey.user`+`valkey.password_file` · `gNodeConfigLoader.php:401, 609-610`

## ::ARCHITECTURE

PHP parent theme (72 files under `inc/`; `functions.php` = 23-line thin loader). 9-phase autoload bootstrap (`inc/bootstrap/autoload.php`): 1 constants · 2 Composer · 3 config loader · 4 environment gate · 5 helpers · 6 gCore init · 7 rendering · 8 assets · 9 integrations/REST/CLI.
Design: **graceful degradation** gNode→ValKey→wp_mail (contact path; no single point of failure — [gform] capture is ValKey-only, fails 500). **Environment-gated** non-prod comms via top-level scalar `environment` field. **Filter-hook public contract** (`FILTER_REGISTRY.md`) for child-theme parameterization. **Per-site isolation** via `{site_id}` key prefix. gNodeConfigLoader = 4-tier config cache: in-mem → APCu+constellation-generation-check → ValKey → YAML. 3-tier face rendering bundle→gNode-Tera→PHP; parent-shipped disk-fallback Tera templates in `templates/faces/` (`template-helpers.php:45`). Defensive NULL-checks at component boundaries. Structured errors via `gtemplate_track_error()` → gCore ErrorManager | `error_log()` fallback.

## ::IO

INPUTS:
- ← Operator: `registration.yaml` | `wp-config-geodineum.yaml` → site_id, environment, valkey creds, service reg · `inc/gNodeConfigLoader.php`
- ← ValKey GET `{site_id}:config:registration` (JSON config) · `registration.php:85`
- ← gCore MU-plugin services: `gnode_client`, `CommsManager`, `TopologyManager`, `SecurityManager`
- ← WordPress: `WP_REST_Request`, options, posts/pages, nonces, `wp_mail()`, `wp_generate_uuid4()`, `WP_ENVIRONMENT_TYPE`
- ← HTTP: `POST .../contact/submit` | `POST .../form/submit` ([gform]: `form_id, consent, _form_load_time, _js_challenge, ...fields`)

OUTPUTS:
- → Geodineum-COMMS: `XADD {site_id}:gnode:comms:{environment} *` top-level scalar `id/type/timestamp/site_id/environment/priority` + json_encode'd `sender/content/metadata/dispatch` · `forms.php:299-330`
- → Dashboard/consumers: `XADD {site_id}:forms:{form_id} *` `[form_id, ts, iso, fp, uri, ua, consent, fields(JSON)]` + `XTRIM ~5000` · `forms.php:146-157` — audience-data surface
- → gNode daemon: `TopologyManager.forceRegister()` 19D service reg · `{site_id}:config:{category}` manager configs = Hash/string (NOT stream), written via `SETEX` · `registration.php:314-318` · schema `config.php:16`
- → HTTP: [gform]+validation = JSON `{success:bool, message?|error?}` · contact delivery outcome = `text/html` fragment + exit (`forms.php:342-351` success, `406-416` error 500)
- → Child themes: filter/action hooks

## ::CONTRACT

PROVIDES:
- REST contact-submit + csrf-token + form-submit endpoints (namespace via `gtemplate_rest_namespace`, default `gtemplate/v1` · `init-helpers.php:102-105`)
- COMMS stream messages on `{site_id}:gnode:comms:{environment}` (field shape per CONTRACT.md §4.1)
- Forms capture stream `{site_id}:forms:{form_id}` (field shape per CONTRACT.md §4.3): `fp` = `substr(sha256(ip|ua|site_id),0,24)` hashed fingerprint, raw IP NEVER stored · `forms.php:115` | consent mandatory (400 without) · `forms.php:106-110` | rate-limit key `{site_id}:forms:rl:{fp}` INCR+3600s TTL, max 20/h · `forms.php:137-142` | retention `XTRIM ~5000` · `forms.php:157`
- PHP helpers: `gtemplate_get_site_id/detect_environment/get_registration_config/gnode_keybased`
- Child-theme hooks (`FILTER_REGISTRY.md`): filters `gtemplate_face_count|rest_namespace|content_sources|template_variables|dynamic_css|js_config` · actions `gtemplate_before_layout|after_layout|render_navigation`
- SECURITY filters (env gate / access control): `gtemplate_environment_gate_grant_access` (default `false`, arg `$environment`) grants entry to gated non-prod env w/o ViewKey cookie · `environment-gate.php:158` | `gtemplate_gate_exempt_paths` (default `[/sw.js,/manifest.json,/manifest.webmanifest]`) exempts paths from ViewKey gate, served to all incl. unauth · `environment-gate.php:673`
- 3-tier rendering dispatch: bundle → gNode Tera → PHP fallback

CONSUMES:
- gCore `gNodeClientInterface`: `queueContactForm(...)→string|null`, `getStorage()→ValKeyStorage` · `forms.php:248-280`
- gCore `CommsManager`: `initialize({site_id, node_id, use_gnode, gnode_client})`, `getRecentMessages`, `getStats`, `getSiteSettings`, `saveSiteSettings`, `testChannel`, `getDaemonStatus` · `comms.php:26-46`
- Geodineum-COMMS wire contract: brace-literal key + scalar/JSON field split (`Geodineum-COMMS/CONTRACT.md`)
- Operator registration config (YAML 19D capabilities schema)
- WP env map: development→testing · local→testing · staging→staging · acceptance→acceptance · production→production · `init-helpers.php:199-206`
- ValKey ACL @ `47445`

## ::USECASES

- Contact-form submit + multi-layer anti-spam (honeypot/timing>3s/JS-challenge `gcore_`) → COMMS daemon (email/Telegram/SMS)
- `[gform]` generic data capture → per-form stream `{site}:forms:<id>` (dashboard audience mining); shortcode POSTs to `/form/submit` via `rest_url()` — endpoint NOT hardcoded · `shortcode.php` gform assets
- CSRF token refresh for cached/static pages
- 3-tier face rendering (bundle cache → gNode Tera → PHP; disk Tera fallback `templates/faces/`)
- 19D service topology registration via TopologyManager
- Non-prod visibility gating via ViewKey splash (environment-aware)
- Config distribution across constellation via `{site_id}:config:*`
- Parent-theme public contract for child themes (filter hooks)

## ::LIMITATIONS

- env default DIVERGENCE: `forms.php:291`→`'staging'` vs `detect_environment()`→`'production'`; pre-prod forms mis-gated (internal, NOT wire mismatch — COMMS fail-safe)
- Direct-XADD fallback bypasses gNode-Client batching/retry; failed XADD = logged + LOST (no retry queue)
- [gform] capture has NO fallback: ValKey unreachable → 500, nothing queued/mailed · `forms.php:165-167`
- [gform] rate-limit best-effort: INCR error swallowed, submission proceeds · `forms.php:143`
- dual-path XADD (gNode-Client | direct) has no contract guaranteeing identical output → drift risk
- env double-stamped (`message.environment` + `metadata.environment`) · `forms.php:304, 321`; only top-level drives gate, nested informational
- contact success/failure = HTML fragment + exit (implicit 200 / `status_header(500)`), not JSON · `forms.php:342-351, 413`
- gNodeClientInterface assumed, NOT runtime-validated → future gCore interface change = runtime failure
- `GTEMPLATE_FREE_TIER` silently disables all gNode comms, no operator warning on submit · `gcore-init.php:51`
- CSRF = WP nonce (action-tied), NOT distributed state → multi-server validation gap · `forms.php:428`
- rate-limit (contact) = SecurityManager injection only (`gcore-init.php:111-112`), no public per-endpoint tunables; [gform] 20/h hardcoded
- registration lookup trusts brace-literal key exists/matches, no validation · `registration.php:85`
- APCu/ValKey constellation generation counter not cross-validated → stale APCu until TTL
- comms stream retention = daemon cleanup policy only (no ValKey TTL / theme-side control); forms streams = producer XTRIM ~5000 only
- CommsManager init exposes `use_gnode` bool but NOT daemon `--allow-nonprod-send` flag → blocks non-prod real sends even when daemon permits · `comms.php:39-44`
- gNodeClient dual-assigned as keybased-client + storage provider; single interface assumed, no runtime type validation · `gcore-init.php:88-90`
- CommsMessage `type` enum DRIFT across artifacts (COMMS CONTRACT allows `custom`; `outbound_alert.yaml` omits; gNodeClient docblock lists `contact-form`); `parse_message` stores any string → degrades gracefully · `gNodeClient.php:4686`

## ::GRAPH

- DEPENDS_ON: gCore MU-plugin (gnode_client, CommsManager, TopologyManager, SecurityManager) · ValKey (@47445) · WordPress core · Operator registration config
- PROVIDES_TO: Geodineum-COMMS (XADD producer) · dashboard/analytics (forms capture streams `{site}:forms:*`) · gNode daemon (topology reg + config keys) · child themes (filter hooks) · HTTP clients (REST)
- ADHERES_TO: COMMS message contract of Geodineum-COMMS (`stream_reader.rs:215-303`) — VERIFIED field-by-field match incl. top-level scalar `environment` gate field
- ISOLATED_FROM: child themes e.g. gCube (sibling producers, independent; no direct gTemplate↔sibling calls — only shared COMMS+gNode contracts)

## ::LATENT

- "brace-literal `{site_id}:gnode:comms:{environment}` hash-tag stream key"
- "per-form capture stream `{site_id}:forms:{form_id}` — form_id sanitized, fields JSON, fp hashed never raw IP"
- "top-level scalar `environment` drives non-prod send gate, NOT `metadata.environment`"
- "graceful degradation: queueContactForm → direct XADD → wp_mail (contact only; gform = ValKey or 500)"
- "env default `staging` (forms.php) vs `production` (detect_environment) inconsistency"
- "gCore MU-plugin service locator: gnode_client / CommsManager / TopologyManager / SecurityManager"
- "9-phase autoload bootstrap, 4-tier config cache (mem/APCu/ValKey/YAML)"
- "19-dimensional capability schema topology registration"
- "FILTER_REGISTRY.md gtemplate_* child-theme public hook contract"
- "[gform] consent mandatory + 20/h/fingerprint rate limit + XTRIM ~5000 retention"
- "disk-fallback Tera templates live in templates/faces/"
