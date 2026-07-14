#!/bin/bash
#
# Geodineum Site Installation Script
# ==================================
# Installs a complete gNode-powered WordPress site with any Geodineum theme.
#
# This script handles the full deployment lifecycle:
#   1. Ecosystem detection (gNode, gNode-Client, gCore, ValKey)
#   2. WordPress installation (if not present)
#   3. Database creation (if not present)
#   4. Apache vhost configuration
#   5. SSL certificate (via certbot)
#   6. ValKey ACL user creation
#   7. gCore MU-plugin installation
#   8. Parent theme (gTemplate) + child theme installation
#   9. gNode registration
#
# Usage: sudo ./install-geodineum.sh <domain> --theme <child> [options]
#
# Examples:
#   sudo ./install-geodineum.sh geodineum.com --theme gcube --theme-path /path/to/gCube production
#   sudo ./install-geodineum.sh example.com --theme gcube --theme-path /path/to/gCube staging
#   sudo ./install-geodineum.sh example.com production  # parent theme only (standalone demo)
#
# Requirements:
#   - Must run as root (sudo)
#   - Geodineum ecosystem at /opt/geodineum/
#   - Apache2, MySQL/MariaDB, PHP 8.x installed
#   - Domain DNS pointing to this server (for SSL)
#

set -e

#######################################
# Configuration
#######################################

# Ecosystem paths - PRODUCTION ONLY
# All components should be symlinked or installed to /opt/geodineum/
GEODINEUM_ROOT="/opt/geodineum"
GCORE_PATH="${GEODINEUM_ROOT}/gCore"
GTEMPLATE_PATH="${GEODINEUM_ROOT}/gTemplate"
GNODE_PATH="${GEODINEUM_ROOT}/gNode"
GNODE_CLIENT_PATH="${GEODINEUM_ROOT}/gNode-Client"
GNODE_SCRIPTS="${GNODE_PATH}/scripts"
GNODE_PASSWORD_DIR="${GNODE_PATH}/.gnode"

# Resolved paths (validated during detection)
GCORE_SOURCE=""
GTEMPLATE_SOURCE=""
GNODE_SOURCE=""

# Theme configuration (set via arguments)
CHILD_THEME_NAME=""       # e.g., "gcube"
CHILD_THEME_PATH=""       # e.g., "/path/to/gCube"
CHILD_THEME_SOURCE=""     # validated path

# Web server
WEB_ROOT="/var/www"
WEB_USER="www-data"
WEB_GROUP="www-data"

# ValKey
VALKEY_HOST="127.0.0.1"
VALKEY_PORT="47445"
VALKEY_CLI_SECURE=""

# WP-CLI
WP_CLI=""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

#######################################
# Logging Functions
#######################################

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }
log_step()    { echo ""; echo -e "${BLUE}==>${NC} ${BOLD}$1${NC}"; }
log_detail()  { echo -e "    ${DIM}$1${NC}"; }

banner() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}           ${BOLD}Geodineum Site Installation Script${NC}                    ${CYAN}║${NC}"
    if [[ -n "$CHILD_THEME_NAME" ]]; then
        printf "${CYAN}║${NC}            gTemplate + %-38s${CYAN}║${NC}\n" "$CHILD_THEME_NAME"
    else
        echo -e "${CYAN}║${NC}            gTemplate (standalone)                              ${CYAN}║${NC}"
    fi
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

#######################################
# Utility Functions
#######################################

prompt_yes_no() {
    local prompt="$1"
    local default="${2:-y}"
    local response

    if [[ "$default" == "y" ]]; then
        read -p "$prompt [Y/n] " -n 1 -r response
    else
        read -p "$prompt [y/N] " -n 1 -r response
    fi
    echo ""

    response=${response:-$default}
    [[ "$response" =~ ^[Yy]$ ]]
}

prompt_input() {
    local prompt="$1"
    local default="$2"
    local var_name="$3"
    local response

    if [[ -n "$default" ]]; then
        read -p "$prompt [$default]: " response
        response=${response:-$default}
    else
        read -p "$prompt: " response
    fi

    eval "$var_name='$response'"
}

generate_password() {
    local base=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)
    echo "${base}Aa1!"
}

generate_db_password() {
    local hex=$(openssl rand -hex 8)
    echo "gNode_${hex}_9xZ"
}

#######################################
# Detection Functions
#######################################

detect_wpcli() {
    local candidates=(
        "$(command -v wp 2>/dev/null)"
        "/usr/local/bin/wp"
        "/usr/bin/wp"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -x "$candidate" ]]; then
            WP_CLI="$candidate"
            return 0
        fi
    done

    if command -v wp &>/dev/null; then
        WP_CLI="$(which wp)"
        return 0
    fi

    return 1
}

detect_gcore() {
    local check_path="${GCORE_PATH_ENV:-$GCORE_PATH}"

    if [[ -f "${check_path}/bootstrap.php" ]]; then
        GCORE_SOURCE="$check_path"
        return 0
    fi

    return 1
}

detect_gtemplate() {
    # Priority: 1. Script's own location (this script is in gTemplate/scripts/)
    # 2. Environment variable, 3. Production path

    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local template_root="$(dirname "$script_dir")"

    if [[ -f "${template_root}/style.css" ]] && grep -q "Theme Name.*gTemplate" "${template_root}/style.css" 2>/dev/null; then
        GTEMPLATE_SOURCE="$template_root"
        return 0
    fi

    local check_path="${GTEMPLATE_PATH_ENV:-$GTEMPLATE_PATH}"
    if [[ -f "${check_path}/style.css" ]] && grep -q "Theme Name.*gTemplate" "${check_path}/style.css" 2>/dev/null; then
        GTEMPLATE_SOURCE="$check_path"
        return 0
    fi

    return 1
}

detect_child_theme() {
    # Detect child theme from --theme-path or /opt/geodineum/<name>
    if [[ -z "$CHILD_THEME_NAME" ]]; then
        return 0  # No child theme requested, parent-only mode
    fi

    # Check explicit path first
    if [[ -n "$CHILD_THEME_PATH" ]] && [[ -f "${CHILD_THEME_PATH}/style.css" ]]; then
        if grep -q "Template:[[:space:]]*gtemplate" "${CHILD_THEME_PATH}/style.css" 2>/dev/null; then
            CHILD_THEME_SOURCE="$CHILD_THEME_PATH"
            return 0
        else
            log_warning "${CHILD_THEME_PATH}/style.css does not declare Template: gtemplate (parent theme slug)"
            return 1
        fi
    fi

    # Check /opt/geodineum/<name>
    local check_path="${GEODINEUM_ROOT}/${CHILD_THEME_NAME}"
    if [[ -f "${check_path}/style.css" ]] && grep -q "Template:[[:space:]]*gtemplate" "${check_path}/style.css" 2>/dev/null; then
        CHILD_THEME_SOURCE="$check_path"
        return 0
    fi

    # Search ~/gh/ directories
    for candidate in /home/*/gh/*; do
        if [[ -f "${candidate}/style.css" ]] && grep -q "Theme Name.*${CHILD_THEME_NAME}" "${candidate}/style.css" 2>/dev/null; then
            if grep -q "Template:[[:space:]]*gtemplate" "${candidate}/style.css" 2>/dev/null; then
                CHILD_THEME_SOURCE="$candidate"
                return 0
            fi
        fi
    done

    return 1
}

detect_gnode() {
    local check_path="${GNODE_PATH_ENV:-$GNODE_PATH}"

    if [[ -d "${check_path}/scripts" ]] && ([[ -x "${check_path}/scripts/register-site.sh" ]] || [[ -x "${check_path}/scripts/setup-site-acl.sh" ]]); then
        GNODE_SOURCE="$check_path"
        GNODE_SCRIPTS="${check_path}/scripts"
        GNODE_PASSWORD_DIR="${check_path}/.gnode"
        return 0
    fi

    return 1
}

detect_valkey() {
    # Accept either canonical unit name. valkey-server is the modern name
    # used by both the apt package and the source-build path; valkey-gnode
    # is the legacy unit name from the retired setup-valkey-smart.sh.
    # Mirrors the same compatibility shim added to validate-geodineum-config.sh
    # — keep these two in sync.
    if ! systemctl is-active --quiet valkey-server 2>/dev/null \
            && ! systemctl is-active --quiet valkey-gnode 2>/dev/null; then
        return 1
    fi

    local secure_candidates=(
        "${GNODE_SCRIPTS}/valkey-cli-secure.sh"
        "${GNODE_PATH}/scripts/valkey-cli-secure.sh"
        "/opt/geodineum/gNode/scripts/valkey-cli-secure.sh"
    )

    for candidate in "${secure_candidates[@]}"; do
        if [[ -x "$candidate" ]]; then
            VALKEY_CLI_SECURE="$candidate"
            break
        fi
    done

    if [[ -n "$VALKEY_CLI_SECURE" ]]; then
        if VALKEY_USER=gnode_daemon "$VALKEY_CLI_SECURE" PING &>/dev/null; then
            return 0
        fi
    else
        if php -r "
            \$r = new Redis();
            if (@\$r->connect('$VALKEY_HOST', $VALKEY_PORT)) {
                echo 'OK';
            }
        " 2>/dev/null | grep -q "OK"; then
            return 0
        fi
    fi

    return 1
}

detect_wp_root() {
    local domain="$1"
    local base_paths=(
        "${WEB_ROOT}/${domain}"
        "${WEB_ROOT}/$(echo $domain | cut -d. -f1)"
        "${WEB_ROOT}/$(echo $domain | sed 's/\..*//')"
    )

    for base in "${base_paths[@]}"; do
        if [[ -f "$base/wp-config.php" ]]; then
            echo "$base"
            return 0
        fi
        if [[ -f "$base/public_html/wp-config.php" ]]; then
            echo "$base/public_html"
            return 0
        fi
    done

    return 1
}

detect_database() {
    local db_name="$1"
    if mysql -e "USE ${db_name};" 2>/dev/null; then
        return 0
    fi
    return 1
}

detect_vhost() {
    local domain="$1"
    if [[ -f "/etc/apache2/sites-available/${domain}.conf" ]]; then
        return 0
    fi
    return 1
}

detect_ssl() {
    local domain="$1"
    if [[ -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]]; then
        return 0
    fi
    return 1
}

#######################################
# Installation Functions
#######################################

install_database() {
    local db_name="$1"
    local db_user="$2"
    local db_pass="$3"

    log_info "Creating database: ${db_name}"

    mysql -e "DROP USER IF EXISTS '${db_user}'@'localhost';" 2>/dev/null || true

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || {
        log_error "Failed to create database"
        return 1
    }

    mysql -e "CREATE USER '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';" || {
        log_error "Failed to create database user"
        return 1
    }

    mysql -e "GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost'; FLUSH PRIVILEGES;" || {
        log_error "Failed to grant privileges"
        return 1
    }

    log_success "Database created: ${db_name}"
}

install_wordpress() {
    local wp_root="$1"
    local domain="$2"
    local db_name="$3"
    local db_user="$4"
    local db_pass="$5"
    local admin_user="$6"
    local admin_pass="$7"
    local admin_email="$8"

    log_info "Creating WordPress directory: ${wp_root}"
    mkdir -p "$wp_root"
    chown ${WEB_USER}:${WEB_GROUP} "$wp_root"
    chmod 750 "$wp_root"

    log_info "Downloading WordPress..."
    cd "$wp_root"
    # --force lets a re-run after a partial install (where wp core
    # files landed but wp-config.php was never written) re-download
    # cleanly. detect_wp_root() keys on wp-config.php, so it reports
    # "WordPress not found" in that partial state — but `wp core download`
    # then refuses with "WordPress files seem to already be present here".
    # --force overwrites the core files (wp-admin/, wp-includes/,
    # wp-load.php, etc.) without touching wp-content/, which is safe
    # because we know no real install is using this dir yet.
    sudo -u ${WEB_USER} "$WP_CLI" core download --force --quiet || {
        log_error "Failed to download WordPress"
        return 1
    }

    log_info "Creating wp-config.php..."
    sudo -u ${WEB_USER} "$WP_CLI" config create \
        --dbname="$db_name" \
        --dbuser="$db_user" \
        --dbpass="$db_pass" \
        --dbhost="localhost" \
        --dbcharset="utf8mb4" \
        --quiet || {
        log_error "Failed to create wp-config.php"
        return 1
    }

    log_info "Installing WordPress core..."
    sudo -u ${WEB_USER} "$WP_CLI" core install \
        --url="https://${domain}" \
        --title="${domain}" \
        --admin_user="$admin_user" \
        --admin_password="$admin_pass" \
        --admin_email="$admin_email" \
        --skip-email \
        --quiet || {
        log_error "Failed to install WordPress"
        return 1
    }

    log_success "WordPress installed at ${wp_root}"

    # Baseline editor: install + activate Classic Editor and force it for all
    # posts/pages. Gutenberg wraps content in block markup/comments that breaks
    # the Geodineum-generated pages (face content, full-page plugin templates
    # like gAnalyze Findings) — the classic editor keeps the body clean so the
    # generated page renders as authored. Runs here, BEFORE the perm hardening
    # makes wp-content read-only. Non-fatal: a host without wp.org reach still
    # completes the install; re-run or install manually later.
    log_info "Installing Classic Editor (disables Gutenberg for generated pages)..."
    if sudo -u ${WEB_USER} "$WP_CLI" plugin install classic-editor --activate --quiet; then
        sudo -u ${WEB_USER} "$WP_CLI" option update classic-editor-replace classic --quiet 2>/dev/null || true
        sudo -u ${WEB_USER} "$WP_CLI" plugin install classic-widgets --activate --quiet 2>/dev/null || true
        log_success "Classic Editor active (Gutenberg disabled)"
    else
        log_warning "Classic Editor not installed (wp.org unreachable?) — run later: sudo -u ${WEB_USER} wp --path=${wp_root} plugin install classic-editor --activate"
    fi
}

install_vhost() {
    local domain="$1"
    local wp_root="$2"
    local site_id="${3:-$(echo "$domain" | sed 's/[.-]/_/g')}"
    local environment="${4:-${ENVIRONMENT:-testing}}"

    log_info "Creating Apache vhost: ${domain}"

    # open inbound port 80 (+ 443 for future certbot) BEFORE the
    # vhost is enabled. Previously ensure_http_ports_open was only called
    # inside install_ssl — operators who declined the cert prompt got an
    # apparently-working vhost that nobody could reach because the host
    # UFW (or firewalld) still default-denied. Symptom: site renders
    # fine via loopback (curl --resolve 127.0.0.1) but browsers time
    # out. install_vhost ALWAYS runs when a site is being deployed, so
    # this is the right hook.
    ensure_http_ports_open

    # SetEnv directives expose Geodineum credentials to PHP via $_ENV
    # / getenv() BEFORE wp-config.php / wp-config-geodineum.yaml is parsed.
    # gCore's MU plugin runs in the very early WP startup (before
    # mu-plugins-loaded), so values set in wp-config-geodineum.yaml arrive
    # too late — the bootstrap-loader fell through to its
    # "scan /etc/geodineum/credentials/" fallback, couldn't read the
    # admin/daemon passwords (correctly hidden from www-data), and logged
    # "ecosystem_config unavailable (bootstrap-loader: no readable ValKey
    # password under /etc/geodineum/credentials)" on every request.
    # Site rendered, but gCore's early-page-cache and other early-stage
    # gNode features no-op'd.
    #
    # With SetEnv the loader sees the explicit per-site password file
    # path immediately and skips the scan. PassEnv is added defensively
    # so the same vars flow into PHP-FPM workers via FastCGI too.
    local password_file="${GNODE_PASSWORD_DIR}/valkey_client_${site_id}.password"

    # Shared SetEnv/Directory block used by both HTTP and HTTPS vhosts
    local setenv_block="    # Geodineum environment 
    SetEnv GNODE_SITE_ID ${site_id}
    SetEnv GNODE_ENVIRONMENT ${environment}
    SetEnv VALKEY_HOST ${VALKEY_HOST}
    SetEnv VALKEY_PORT ${VALKEY_PORT}
    SetEnv VALKEY_USER gnode_client_${site_id}
    SetEnv VALKEY_PASSWORD_FILE ${password_file}
    PassEnv GNODE_SITE_ID GNODE_ENVIRONMENT VALKEY_HOST VALKEY_PORT VALKEY_USER VALKEY_PASSWORD_FILE

    <Directory ${wp_root}>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${domain}_error.log
    CustomLog \${APACHE_LOG_DIR}/${domain}_access.log combined"

    # Check for existing SSL certificate — if found, write an HTTPS
    # vhost with redirect, preserving certs from prior installs.
    local ssl_cert="/etc/letsencrypt/live/${domain}/fullchain.pem"
    local ssl_key="/etc/letsencrypt/live/${domain}/privkey.pem"

    if [[ -f "$ssl_cert" ]] && [[ -f "$ssl_key" ]]; then
        log_info "Existing SSL certificate found — writing HTTPS vhost"

        cat > "/etc/apache2/sites-available/${domain}.conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}
    ServerAlias www.${domain}
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName ${domain}
    ServerAlias www.${domain}
    DocumentRoot ${wp_root}

${setenv_block}

    SSLEngine on
    SSLCertificateFile ${ssl_cert}
    SSLCertificateKeyFile ${ssl_key}

    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
</VirtualHost>
EOF
        a2enmod ssl rewrite headers env >/dev/null 2>&1 || true
        log_success "Apache vhost enabled (HTTPS with existing cert)"
    else
        cat > "/etc/apache2/sites-available/${domain}.conf" << EOF
<VirtualHost *:80>
    ServerName ${domain}
    ServerAlias www.${domain}
    DocumentRoot ${wp_root}

${setenv_block}
</VirtualHost>
EOF
        a2enmod env >/dev/null 2>&1 || true
        log_info "Apache vhost enabled (HTTP — run certbot for HTTPS)"
    fi

    a2ensite "${domain}.conf" >/dev/null 2>&1
    systemctl reload apache2
}

#
# Open inbound HTTP/HTTPS in the host firewall (best-effort).
# Let's Encrypt's HTTP-01 challenge needs port 80 reachable from the
# public internet. Previously, the installer skipped this entirely — operators
# with default-deny firewalls got opaque certbot timeouts and were left
# to diagnose on their own.
#
# Handles three cases:
#   - UFW active: `ufw allow 80,443/tcp` (auto-open; idempotent).
#   - iptables/nftables/firewalld: warn with the canonical command to run.
#   - No host firewall detected: assume open; nothing to do.
#
# Can't manage cloud-provider security groups (AWS SG, GCP firewall
# rules) from inside the VM — that's surfaced as a post-certbot
# diagnostic if the cert request fails.
ensure_http_ports_open() {
    # UFW — most common on Ubuntu hosts
    if /usr/sbin/ufw status 2>/dev/null | grep -q "^Status: active"; then
        if ! /usr/sbin/ufw status 2>/dev/null | grep -qE '^(80|80/tcp)[[:space:]]+ALLOW'; then
            log_info "Opening ports 80 + 443 in UFW for certbot HTTP-01 challenge"
            /usr/sbin/ufw allow 80/tcp >/dev/null 2>&1 || true
            /usr/sbin/ufw allow 443/tcp >/dev/null 2>&1 || true
        fi
        return 0
    fi

    # firewalld
    if systemctl is-active --quiet firewalld 2>/dev/null; then
        log_info "firewalld is active — opening http + https services"
        /usr/bin/firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
        /usr/bin/firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
        /usr/bin/firewall-cmd --reload >/dev/null 2>&1 || true
        return 0
    fi

    # iptables/nftables: harder to introspect safely without risking duplicate
    # rules. Surface the canonical command to the operator and proceed.
    if /usr/sbin/iptables -L INPUT -n 2>/dev/null | grep -qE 'DROP|REJECT'; then
        log_warning "Default-deny iptables rules detected. If certbot times out,"
        log_info "  run: sudo iptables -I INPUT -p tcp -m multiport --dports 80,443 -j ACCEPT"
    fi
}

install_ssl() {
    local domain="$1"

    ensure_http_ports_open

    log_info "Requesting SSL certificate (certbot HTTP-01)..."
    local cert_log
    cert_log=$(mktemp)

    # don't suppress stderr — operators need to see the actual reason
    # the cert request failed (timeout, rate-limit, DNS mismatch, etc.).
    # Capture to temp file so we can also classify the failure.
    if certbot --apache \
            -d "$domain" \
            -d "www.${domain}" \
            --non-interactive \
            --agree-tos \
            --redirect \
            --email "admin@${domain}" >"$cert_log" 2>&1; then
        log_success "SSL certificate installed"
        rm -f "$cert_log"
        return 0
    fi

    # Classify the failure for actionable guidance.
    log_warning "SSL certificate request failed. Output below:"
    sed 's/^/    /' "$cert_log" | head -40

    echo ""
    if grep -qE 'Timeout during connect|Connection refused|likely firewall' "$cert_log"; then
        log_info "Diagnosis: HTTP-01 challenge could not reach this host on port 80."
        log_info "  - If your host firewall is UFW/firewalld, the installer should have opened it"
        log_info "    automatically. Verify: sudo ufw status verbose"
        log_info "  - If your VM is behind a cloud-provider firewall (AWS SG, GCP rules,"
        log_info "    Hetzner Cloud firewall), open inbound TCP 80 + 443 in their console."
        log_info "  - Test reachability from outside: curl -I http://${domain}/"
    elif grep -qE 'DNS problem|NXDOMAIN|no A/AAAA records' "$cert_log"; then
        log_info "Diagnosis: DNS for ${domain} doesn't resolve to this host yet."
        log_info "  - Check: dig +short ${domain} && curl ifconfig.me"
    elif grep -qE 'rate.?limit|too many certificates' "$cert_log"; then
        log_info "Diagnosis: Let's Encrypt rate-limit hit (5 certs per domain per week)."
        log_info "  - Try the staging environment: add --staging to certbot args"
        log_info "  - Or wait 7 days for the rate-limit window to roll over."
    else
        log_info "Inspect the full output above. Manual retry:"
        log_info "  sudo certbot --apache -d ${domain} -d www.${domain}"
    fi

    rm -f "$cert_log"
    return 1
}

install_valkey_acl() {
    local site_id="$1"
    local environment="$2"

    log_info "Creating ValKey ACL user: gnode_client_${site_id}"

    # Try register-site.sh (current) or setup-site-acl.sh (legacy)
    local acl_script=""
    for candidate in "${GNODE_SCRIPTS}/register-site.sh" "${GNODE_SCRIPTS}/setup-site-acl.sh"; do
        if [[ -x "$candidate" ]]; then
            acl_script="$candidate"
            break
        fi
    done

    if [[ -n "$acl_script" ]]; then
        "$acl_script" "$site_id" --environment "$environment" || {
            log_error "Failed to create ValKey ACL user"
            return 1
        }
        log_success "ValKey ACL user created"
    else
        log_error "register-site.sh not found at ${GNODE_SCRIPTS}"
        return 1
    fi
}

install_gcore() {
    local wp_root="$1"

    log_info "Setting up gCore MU-plugin"

    mkdir -p "${wp_root}/wp-content/mu-plugins"

    # Symlink gCore (main framework)
    if [[ -L "${wp_root}/wp-content/mu-plugins/gcore" ]]; then
        local current_target="$(readlink -f "${wp_root}/wp-content/mu-plugins/gcore")"
        if [[ "$current_target" == "$GCORE_SOURCE" ]]; then
            log_success "gCore symlink already correct (skipping)"
        else
            rm "${wp_root}/wp-content/mu-plugins/gcore"
            ln -sf "$GCORE_SOURCE" "${wp_root}/wp-content/mu-plugins/gcore"
            log_success "gCore symlink updated"
        fi
    else
        ln -sf "$GCORE_SOURCE" "${wp_root}/wp-content/mu-plugins/gcore"
        chown -h ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/mu-plugins/gcore"
        log_success "gCore symlinked"
    fi

    # Symlink gcore-mu (early page cache - loads BEFORE WordPress)
    if [[ -d "${GCORE_SOURCE}/gcore-mu" ]]; then
        if [[ -L "${wp_root}/wp-content/mu-plugins/gcore-mu" ]]; then
            local current_target="$(readlink -f "${wp_root}/wp-content/mu-plugins/gcore-mu")"
            if [[ "$current_target" == "${GCORE_SOURCE}/gcore-mu" ]]; then
                log_success "gcore-mu symlink already correct (skipping)"
            else
                rm "${wp_root}/wp-content/mu-plugins/gcore-mu"
                ln -sf "${GCORE_SOURCE}/gcore-mu" "${wp_root}/wp-content/mu-plugins/gcore-mu"
                log_success "gcore-mu symlink updated"
            fi
        else
            ln -sf "${GCORE_SOURCE}/gcore-mu" "${wp_root}/wp-content/mu-plugins/gcore-mu"
            chown -h ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/mu-plugins/gcore-mu"
            log_success "gcore-mu symlinked (early page cache enabled)"
        fi

        if [[ ! -f "${wp_root}/wp-content/mu-plugins/gcore-mu.php" ]]; then
            cat > "${wp_root}/wp-content/mu-plugins/gcore-mu.php" << 'LOADER'
<?php
/**
 * gCore MU-Plugin Loader
 * Loads gCore from /opt/geodineum/gCore/gcore-mu/
 * Includes early-page-cache.php for ~80ms speedup
 */
require_once __DIR__ . '/gcore-mu/gcore-loader.php';
LOADER
            chown ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/mu-plugins/gcore-mu.php"
            chmod 640 "${wp_root}/wp-content/mu-plugins/gcore-mu.php"
            log_success "gcore-mu.php loader created (early cache enabled)"
        else
            log_success "gcore-mu.php loader already exists (skipping)"
        fi
    else
        log_warning "gcore-mu directory not found - using basic loader (no early cache)"
        if [[ ! -f "${wp_root}/wp-content/mu-plugins/gcore-loader.php" ]]; then
            cat > "${wp_root}/wp-content/mu-plugins/gcore-loader.php" << 'LOADER'
<?php
/**
 * Plugin Name: gCore Loader
 * Description: Loads gCore framework for Geodineum integration
 * Version: 1.0.0
 */
if (!defined('GCORE_LOADED')) {
    define('GCORE_LOADED', true);
    define('GCORE_PATH', __DIR__ . '/gcore/');
    if (file_exists(GCORE_PATH . 'vendor/autoload.php')) {
        require_once GCORE_PATH . 'vendor/autoload.php';
    }
}
LOADER
            chown ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/mu-plugins/gcore-loader.php"
            chmod 640 "${wp_root}/wp-content/mu-plugins/gcore-loader.php"
            log_success "gCore basic loader created"
        fi
    fi
}

install_parent_theme() {
    local wp_root="$1"
    local theme_dir="${wp_root}/wp-content/themes/gtemplate"
    local legacy_dir="${wp_root}/wp-content/themes/gtemplate-wp"

    log_info "Setting up gTemplate parent theme"

    # Migrate legacy gtemplate-wp symlink if present
    if [[ -L "$legacy_dir" ]] || [[ -d "$legacy_dir" ]]; then
        log_info "Removing legacy gtemplate-wp theme dir (renamed to gtemplate)"
        rm -f "$legacy_dir"
    fi

    if [[ -L "$theme_dir" ]]; then
        local current_target="$(readlink -f "$theme_dir")"
        if [[ "$current_target" == "$GTEMPLATE_SOURCE" ]]; then
            log_success "gTemplate symlink already correct (skipping)"
            return 0
        else
            rm "$theme_dir"
        fi
    fi

    ln -sf "$GTEMPLATE_SOURCE" "$theme_dir"
    chown -h ${WEB_USER}:${WEB_GROUP} "$theme_dir"
    log_success "gTemplate parent theme symlinked"
}

install_child_theme() {
    local wp_root="$1"

    if [[ -z "$CHILD_THEME_NAME" ]] || [[ -z "$CHILD_THEME_SOURCE" ]]; then
        log_info "No child theme specified — using gTemplate standalone"
        return 0
    fi

    local theme_dir="${wp_root}/wp-content/themes/${CHILD_THEME_NAME}"

    log_info "Setting up ${CHILD_THEME_NAME} child theme"

    if [[ -L "$theme_dir" ]]; then
        local current_target="$(readlink -f "$theme_dir")"
        if [[ "$current_target" == "$CHILD_THEME_SOURCE" ]]; then
            log_success "${CHILD_THEME_NAME} symlink already correct (skipping)"
            return 0
        else
            rm "$theme_dir"
        fi
    fi

    ln -sf "$CHILD_THEME_SOURCE" "$theme_dir"
    chown -h ${WEB_USER}:${WEB_GROUP} "$theme_dir"
    log_success "${CHILD_THEME_NAME} child theme symlinked"
}

install_config() {
    local wp_root="$1"
    local site_id="$2"
    local domain="$3"
    local environment="$4"
    local password_file="$5"

    if [[ -f "${wp_root}/wp-config-geodineum.yaml" ]]; then
        log_success "Geodineum config already exists (skipping)"
        return 0
    fi

    log_info "Creating wp-config-geodineum.yaml"

    local viewkey=""
    if [[ "$environment" != "production" ]]; then
        viewkey=$(openssl rand -hex 16)
    fi

    # Determine active theme name for config
    local active_theme="${CHILD_THEME_NAME:-gtemplate}"

    cat > "${wp_root}/wp-config-geodineum.yaml" << CONFIG
# Geodineum Site Configuration
# Generated: $(date -Iseconds)
# Domain: ${domain}

version: 1.0.0
site_id: ${site_id}

service:
  type: wordpress-site
  tier: service
  update_mode: upsert

# gNode Topology Capabilities (16 dimensions)
capabilities:
  # Interface Identity
  protocol: http_rest
  native_format: json
  api_version: v1
  contract_stability: stable
  # Access Control
  clearance_required: public
  auth_method: session_cookie
  data_sensitivity: internal
  # Service Scope
  service_scope: client_facing
  # Functional Domain
  domain_primary: content
  domain_secondary: template
  specialization: generalist
  # Performance Profile
  throughput_tier: professional
  latency_class: responsive
  reliability_tier: high
  # Workflow Context
  pipeline_stage: deliver
  execution_priority: normal

metadata:
  type: wordpress-site
  theme: ${active_theme}
  theme_version: 1.0.0
  framework: gCore
  environment: ${environment}
  domain: https://${domain}
  gnode_mode: dual

# ValKey connection
valkey:
  host: 127.0.0.1
  port: 47445
  user: gnode_client_${site_id}
  password_file: ${password_file}

registration:
  method: smart
  check_hash_before_register: true
  sync_to_valkey: true
  valkey_config_ttl: 86400

security:
  viewkey: "${viewkey}"
  viewkey_expiry: 86400
CONFIG

    chown ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-config-geodineum.yaml"
    chmod 640 "${wp_root}/wp-config-geodineum.yaml"
    log_success "Configuration created (640)"

    if [[ -n "$viewkey" ]]; then
        log_info "Viewkey (for staging access): ${viewkey}"
    fi
}

activate_theme() {
    local wp_root="$1"
    local theme_to_activate="${CHILD_THEME_NAME:-gtemplate}"

    if [[ -z "$WP_CLI" ]]; then
        log_warning "WP-CLI not found - activate theme manually: ${theme_to_activate}"
        return 1
    fi

    cd "$wp_root"
    local current_theme=$(sudo -u ${WEB_USER} "$WP_CLI" theme list --status=active --field=name 2>/dev/null || echo "")

    if [[ "$current_theme" == "$theme_to_activate" ]]; then
        log_success "${theme_to_activate} theme already active (skipping)"
        return 0
    fi

    log_info "Activating ${theme_to_activate} theme..."
    if sudo -u ${WEB_USER} "$WP_CLI" theme activate "$theme_to_activate" 2>/dev/null; then
        log_success "${theme_to_activate} theme activated"
    else
        log_warning "Theme activation failed - activate manually in wp-admin"
    fi
}

register_gnode() {
    local wp_root="$1"
    local theme_prefix="${CHILD_THEME_NAME:-gtemplate}"

    if [[ -z "$WP_CLI" ]]; then
        log_warning "WP-CLI not found - register with gNode manually"
        return 1
    fi

    cd "$wp_root"

    # Apache's SetEnv block injects VALKEY_USER + VALKEY_PASSWORD_FILE
    # into PHP requests, but WP-CLI invocations bypass Apache entirely —
    # gCore's bootstrap-loader then falls into its glob fallback which
    # FAILS for www-data because /etc/geodineum/credentials/ is 0751
    # (traverse but not list). Result: gCore runs in degraded mode,
    # registration silently no-ops, gtemplate_registration_hash never
    # gets written. Pass the same env vars explicitly to wp-cli so CLI
    # context behaves like Apache context.
    local site_id_local
    site_id_local=$(echo "$DOMAIN" | sed 's/[.-]/_/g')
    local pwfile="${GNODE_PASSWORD_DIR}/valkey_client_${site_id_local}.password"
    local -a wp_env=(
        "VALKEY_USER=gnode_client_${site_id_local}"
        "VALKEY_PASSWORD_FILE=${pwfile}"
        "VALKEY_HOST=${VALKEY_HOST:-127.0.0.1}"
        "VALKEY_PORT=${VALKEY_PORT:-47445}"
        "GNODE_SITE_ID=${site_id_local}"
        "GNODE_ENVIRONMENT=${ENVIRONMENT:-testing}"
    )

    local registered
    registered=$(sudo -u ${WEB_USER} env "${wp_env[@]}" "$WP_CLI" option get ${theme_prefix}_registration_hash 2>/dev/null || echo "")

    if [[ -n "$registered" ]]; then
        log_success "Already registered with gNode (hash: ${registered:0:8}...)"
        return 0
    fi

    # don't suppress stderr — the previous `2>/dev/null` hid the
    # actual reason registration failed, leaving operators with a
    # bare warning and no path forward. Capture both streams to a
    # temp file so we can include the error in the log if it fails.
    log_info "Registering with gNode..."
    local reg_log
    reg_log=$(mktemp)
    local reg_rc=0

    # Try theme-specific CLI command first, then fall back to gtemplate.
    # every wp-cli invocation now carries the Geodineum env vars
    # so gCore bootstrap-loader can authenticate against ValKey from CLI.
    if sudo -u ${WEB_USER} env "${wp_env[@]}" "$WP_CLI" ${theme_prefix} register >"$reg_log" 2>&1; then
        log_success "Registered with gNode"
    elif sudo -u ${WEB_USER} env "${wp_env[@]}" "$WP_CLI" gtemplate register >"$reg_log" 2>&1; then
        log_success "Registered with gNode (via gtemplate CLI)"
    else
        reg_rc=1
        # Ensure gnode-daemon is running (it's a registration prerequisite
        # — registration writes to ValKey via the daemon's connection
        # pool) and retry once. Covers the case where the daemon was
        # restarted mid-install or hasn't finished activating yet.
        if ! systemctl is-active --quiet gnode-daemon; then
            log_info "gNode daemon not running — starting + retrying registration"
            systemctl reset-failed gnode-daemon 2>/dev/null || true
            systemctl start gnode-daemon 2>/dev/null || true
            sleep 3
            if sudo -u ${WEB_USER} env "${wp_env[@]}" "$WP_CLI" gtemplate register >"$reg_log" 2>&1; then
                log_success "Registered with gNode (after daemon restart)"
                reg_rc=0
            fi
        fi

        if [[ "$reg_rc" -ne 0 ]]; then
            log_warning "gNode registration failed — details below:"
            sed 's/^/    /' "$reg_log"
            log_info "Manual retry: sudo VALKEY_USER=gnode_client_${site_id_local} VALKEY_PASSWORD_FILE=${pwfile} -u ${WEB_USER} wp gtemplate register --path=${wp_root}"
        fi
    fi

    rm -f "$reg_log"
    return $reg_rc
}

#######################################
# Ecosystem Check
#######################################

check_ecosystem() {
    log_step "Checking Geodineum Ecosystem"

    local missing=()

    # Check root
    if [[ "$EUID" -ne 0 ]]; then
        log_error "This script must be run as root (sudo)"
        exit 1
    fi
    log_success "Running as root"

    # Check WP-CLI
    if detect_wpcli; then
        log_success "WP-CLI found: ${WP_CLI}"
    else
        log_error "WP-CLI not found"
        missing+=("wp-cli")
    fi

    # Check gCore
    if detect_gcore; then
        log_success "gCore found: ${GCORE_SOURCE}"
    else
        log_error "gCore not found"
        missing+=("gcore")
    fi

    # Check gTemplate (parent theme)
    if detect_gtemplate; then
        log_success "gTemplate found: ${GTEMPLATE_SOURCE}"
    else
        log_error "gTemplate not found"
        missing+=("gtemplate")
    fi

    # Check child theme (if specified)
    if [[ -n "$CHILD_THEME_NAME" ]]; then
        if detect_child_theme; then
            log_success "${CHILD_THEME_NAME} found: ${CHILD_THEME_SOURCE}"
        else
            log_error "${CHILD_THEME_NAME} not found"
            if [[ -n "$CHILD_THEME_PATH" ]]; then
                log_detail "Checked: ${CHILD_THEME_PATH}"
            fi
            missing+=("$CHILD_THEME_NAME")
        fi
    fi

    # Check gNode
    if detect_gnode; then
        log_success "gNode found: ${GNODE_SOURCE}"
    else
        log_error "gNode not found"
        missing+=("gnode")
    fi

    # Check ValKey — accepts both valkey-server (modern) and valkey-gnode (legacy)
    if detect_valkey; then
        log_success "ValKey is running"
    else
        log_error "ValKey not running (checked valkey-server.service + valkey-gnode.service)"
        missing+=("valkey-gnode")
    fi

    # Check services
    if systemctl is-active --quiet apache2; then
        log_success "Apache2 is running"
    else
        log_error "Apache2 not running"
        missing+=("apache2")
    fi

    if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
        log_success "MySQL/MariaDB is running"
    else
        log_error "MySQL/MariaDB not running"
        missing+=("mysql")
    fi

    # Check gNode daemon: retry briefly to absorb activating→active
    # transitions (mirrors validate-geodineum-config.sh). If still
    # inactive after retry, attempt auto-start — registration with gNode
    # in step register_gnode() needs the daemon up to write to ValKey.
    local _daemon_active="false"
    local _retry
    for _retry in 1 2 3 4 5 6; do
        if systemctl is-active --quiet gnode-daemon; then
            _daemon_active="true"
            break
        fi
        sleep 0.5
    done

    if [[ "$_daemon_active" != "true" ]]; then
        log_info "gNode daemon not running — attempting auto-start"
        systemctl reset-failed gnode-daemon 2>/dev/null || true
        if systemctl start gnode-daemon 2>/dev/null; then
            sleep 2
            if systemctl is-active --quiet gnode-daemon; then
                _daemon_active="true"
                log_success "gNode daemon started"
            fi
        fi
    fi

    if [[ "$_daemon_active" == "true" ]]; then
        log_success "gNode daemon is running"
    else
        local _state
        _state=$(systemctl is-active gnode-daemon 2>/dev/null || echo "unknown")
        log_warning "gNode daemon could not be started (state: ${_state})"
        log_info "Site registration with gNode will be skipped; site itself still works."
        log_info "Diagnose: sudo journalctl -u gnode-daemon -n 50"
    fi

    # Handle missing components
    if [[ ${#missing[@]} -gt 0 ]]; then
        echo ""
        log_warning "Missing components: ${missing[*]}"
        echo ""

        if prompt_yes_no "Attempt automatic setup of missing components?"; then
            local failed=()

            # Fix WP-CLI
            if [[ " ${missing[*]} " =~ " wp-cli " ]]; then
                log_info "Installing WP-CLI..."
                if curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
                   chmod +x wp-cli.phar && \
                   mv wp-cli.phar /usr/local/bin/wp 2>/dev/null; then
                    if detect_wpcli; then
                        log_success "WP-CLI installed: ${WP_CLI}"
                    else
                        failed+=("wp-cli")
                    fi
                else
                    failed+=("wp-cli")
                fi
            fi

            # Fix gCore
            if [[ " ${missing[*]} " =~ " gcore " ]]; then
                log_info "Searching for gCore..."
                local found=""
                for candidate in /home/*/gh/gCore; do
                    if [[ -f "${candidate}/bootstrap.php" ]]; then
                        found="$candidate"
                        break
                    fi
                done

                if [[ -n "$found" ]]; then
                    log_info "Found gCore at: ${found}"
                    if prompt_yes_no "Create symlink ${GCORE_PATH} -> ${found}?"; then
                        mkdir -p "$(dirname "${GCORE_PATH}")"
                        ln -sf "$found" "${GCORE_PATH}"
                        if detect_gcore; then
                            log_success "gCore linked: ${GCORE_SOURCE}"
                        else
                            failed+=("gcore")
                        fi
                    else
                        failed+=("gcore")
                    fi
                else
                    log_warning "gCore not found automatically"
                    failed+=("gcore")
                fi
            fi

            # Fix gTemplate
            if [[ " ${missing[*]} " =~ " gtemplate " ]]; then
                log_info "Searching for gTemplate..."
                local found=""
                for candidate in /home/*/gh/gTemplate /home/*/gh/gTemplate-wp; do
                    if [[ -f "${candidate}/style.css" ]] && grep -q "Theme Name.*gTemplate" "${candidate}/style.css" 2>/dev/null; then
                        found="$candidate"
                        break
                    fi
                done

                if [[ -n "$found" ]]; then
                    log_info "Found gTemplate at: ${found}"
                    if prompt_yes_no "Create symlink ${GTEMPLATE_PATH} -> ${found}?"; then
                        mkdir -p "$(dirname "${GTEMPLATE_PATH}")"
                        ln -sf "$found" "${GTEMPLATE_PATH}"
                        if detect_gtemplate; then
                            log_success "gTemplate linked: ${GTEMPLATE_SOURCE}"
                        else
                            failed+=("gtemplate")
                        fi
                    else
                        failed+=("gtemplate")
                    fi
                else
                    log_warning "gTemplate not found automatically"
                    failed+=("gtemplate")
                fi
            fi

            # Fix gNode
            if [[ " ${missing[*]} " =~ " gnode " ]]; then
                log_info "Searching for gNode..."
                local found=""
                for candidate in /home/*/gh/gNode /opt/geodineum/gNode /opt/gNode; do
                    if [[ -x "${candidate}/scripts/register-site.sh" ]] || [[ -x "${candidate}/scripts/setup-site-acl.sh" ]]; then
                        found="$candidate"
                        break
                    fi
                done

                if [[ -n "$found" ]]; then
                    log_info "Found gNode at: ${found}"
                    if prompt_yes_no "Create symlink ${GNODE_PATH} -> ${found}?"; then
                        mkdir -p "$(dirname "${GNODE_PATH}")"
                        ln -sf "$found" "${GNODE_PATH}"
                        if detect_gnode; then
                            log_success "gNode linked: ${GNODE_SOURCE}"
                        else
                            failed+=("gnode")
                        fi
                    else
                        failed+=("gnode")
                    fi
                else
                    log_warning "gNode not found automatically"
                    failed+=("gnode")
                fi
            fi

            # Fix ValKey service — try the modern unit name first, then
            # fall back to the legacy name. Whichever started successfully
            # is verified via detect_valkey (which accepts either).
            if [[ " ${missing[*]} " =~ " valkey-gnode " ]]; then
                log_info "Starting ValKey service..."
                local started="false"
                if systemctl start valkey-server 2>/dev/null; then
                    started="true"
                elif systemctl start valkey-gnode 2>/dev/null; then
                    started="true"
                fi
                if [[ "$started" == "true" ]]; then
                    sleep 2
                    if detect_valkey; then
                        log_success "ValKey started"
                    else
                        failed+=("valkey-gnode")
                    fi
                else
                    log_warning "Neither valkey-server.service nor valkey-gnode.service could be started"
                    failed+=("valkey-gnode")
                fi
            fi

            # Fix Apache
            if [[ " ${missing[*]} " =~ " apache2 " ]]; then
                log_info "Starting Apache2..."
                if systemctl start apache2 2>/dev/null; then
                    log_success "Apache2 started"
                else
                    failed+=("apache2")
                fi
            fi

            # Fix MySQL
            if [[ " ${missing[*]} " =~ " mysql " ]]; then
                log_info "Starting MySQL/MariaDB..."
                if systemctl start mysql 2>/dev/null || systemctl start mariadb 2>/dev/null; then
                    log_success "MySQL/MariaDB started"
                else
                    failed+=("mysql")
                fi
            fi

            echo ""
            if [[ ${#failed[@]} -gt 0 ]]; then
                log_error "Could not fix: ${failed[*]}"
                log_info "Please resolve manually and re-run."
                exit 1
            else
                log_success "All missing components resolved!"
            fi
        else
            echo ""
            log_info "Manual setup required for: ${missing[*]}"
            exit 1
        fi
    fi

    echo ""
    log_success "Ecosystem check passed"
}

#######################################
# Argument Parsing
#######################################

parse_args() {
    local positional=()

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --theme)
                CHILD_THEME_NAME="$2"
                shift 2
                ;;
            --theme-path)
                CHILD_THEME_PATH="$2"
                shift 2
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            -*)
                log_error "Unknown option: $1"
                usage
                exit 1
                ;;
            *)
                positional+=("$1")
                shift
                ;;
        esac
    done

    # Assign positional args
    DOMAIN="${positional[0]:-}"
    ENVIRONMENT="${positional[1]:-staging}"
}

usage() {
    echo "Usage: sudo $0 <domain> [--theme <name>] [--theme-path <path>] [environment]"
    echo ""
    echo "Arguments:"
    echo "  domain                  Required. The domain name (e.g., geodineum.com)"
    echo "  environment             Optional. DTAP environment (default: staging)"
    echo "                          Options: testing, staging, acceptance, production"
    echo ""
    echo "Options:"
    echo "  --theme <name>          Child theme slug (e.g., gcube)"
    echo "  --theme-path <path>     Path to child theme source (auto-detected if omitted)"
    echo "  --help, -h              Show this help message"
    echo ""
    echo "Examples:"
    echo "  # Install with gCube child theme"
    echo "  sudo $0 geodineum.com --theme gcube --theme-path /path/to/gCube production"
    echo ""
    echo "  # Install parent theme only (standalone demo)"
    echo "  sudo $0 example.com production"
    echo ""
    echo "The script auto-detects existing infrastructure and skips already-completed steps."
    echo "Safe to re-run (idempotent)."
}

#######################################
# Main Installation
#######################################

main() {
    parse_args "$@"

    banner

    if [[ -z "$DOMAIN" ]]; then
        usage
        exit 1
    fi

    # Validate environment
    case "$ENVIRONMENT" in
        testing|staging|acceptance|production) ;;
        *)
            log_error "Invalid environment: $ENVIRONMENT"
            log_info "Valid options: testing, staging, acceptance, production"
            exit 1
            ;;
    esac

    # Derive identifiers
    local site_id=$(echo "$DOMAIN" | sed 's/[.-]/_/g')
    local db_name="${site_id}_db"
    local db_user="${site_id}"
    local wp_root="${WEB_ROOT}/${DOMAIN}"
    local password_file="${GNODE_PASSWORD_DIR}/valkey_client_${site_id}.password"
    local active_theme="${CHILD_THEME_NAME:-gtemplate}"

    echo -e "  Domain:      ${BOLD}${DOMAIN}${NC}"
    echo -e "  Site ID:     ${site_id}"
    echo -e "  Environment: ${ENVIRONMENT}"
    echo -e "  Theme:       ${active_theme}"
    if [[ -n "$CHILD_THEME_NAME" ]]; then
        echo -e "  Parent:      gtemplate-wp"
    fi
    echo -e "  Web Root:    ${wp_root}"
    echo ""

    # Check ecosystem
    check_ecosystem

    # Detect existing installation
    log_step "Detecting Existing Installation"

    local need_database=true
    local need_wordpress=true
    local need_vhost=true
    local need_ssl=true
    local need_valkey_acl=true

    if detect_database "$db_name"; then
        log_success "Database exists: ${db_name}"
        need_database=false
    else
        log_info "Database not found: ${db_name} (will create)"
    fi

    if WP_ROOT_DETECTED=$(detect_wp_root "$DOMAIN"); then
        log_success "WordPress found: ${WP_ROOT_DETECTED}"
        wp_root="$WP_ROOT_DETECTED"
        need_wordpress=false
    else
        log_info "WordPress not found (will install)"
    fi

    if detect_vhost "$DOMAIN"; then
        log_success "Apache vhost exists"
        need_vhost=false
    else
        log_info "Apache vhost not found (will create)"
    fi

    if detect_ssl "$DOMAIN"; then
        log_success "SSL certificate exists"
        need_ssl=false
    else
        log_info "SSL certificate not found (will request)"
    fi

    if [[ -f "$password_file" ]]; then
        log_success "ValKey ACL user exists"
        need_valkey_acl=false
    else
        log_info "ValKey ACL user not found (will create)"
    fi

    # Collect required information
    local db_pass=""
    local admin_user="admin"
    local admin_pass=""
    local admin_email=""

    if [[ "$need_database" == "true" ]] || [[ "$need_wordpress" == "true" ]]; then
        log_step "Configuration Required"

        # db_pass MUST be set regardless of need_database state when
        # WordPress install is still pending — `wp config create
        # --dbpass=...` needs it. Three recovery paths in priority order:
        #   1. Fresh: DB doesn't exist → generate new password, create DB.
        #   2. Resume: DB exists + wp-config.php exists from a partial
        #      prior run → grep DB_PASSWORD out of wp-config.php.
        #   3. Reset: DB exists but no wp-config.php to recover from →
        #      generate a new password and ALTER USER to match. Safe
        #      because the DB user can't be in production use yet
        #      (WordPress isn't installed).
        if [[ "$need_database" == "true" ]]; then
            db_pass=$(generate_db_password)
            log_info "Generated database password"
        elif [[ "$need_wordpress" == "true" ]]; then
            # Path 2: try to recover from an existing wp-config.php first.
            if [[ -n "$WP_ROOT_DETECTED" ]] && [[ -f "${WP_ROOT_DETECTED}/wp-config.php" ]]; then
                db_pass=$(grep -E "DB_PASSWORD" "${WP_ROOT_DETECTED}/wp-config.php" 2>/dev/null \
                    | sed -nE "s/.*define[[:space:]]*\([[:space:]]*['\"]DB_PASSWORD['\"]?[[:space:]]*,[[:space:]]*['\"]([^'\"]+)['\"][[:space:]]*\).*/\1/p" \
                    | head -1)
                if [[ -n "$db_pass" ]]; then
                    log_info "Recovered DB password from existing wp-config.php"
                fi
            fi
            # Path 3: reset the DB user's password to a new random one.
            if [[ -z "$db_pass" ]]; then
                db_pass=$(generate_db_password)
                log_warning "Database '${db_name}' exists but its password is not recoverable"
                log_info "Resetting DB user '${db_user}'@localhost to a new random password"
                if ! mysql -e "ALTER USER '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}'; FLUSH PRIVILEGES;" 2>/dev/null; then
                    # User might not exist yet (DB existed but user creation failed in prior run).
                    if ! mysql -e "CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}'; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null; then
                        log_error "Could not reset/create DB user — drop the DB and re-run, or fix manually:"
                        log_info "  sudo mysql -e \"DROP DATABASE ${db_name}; DROP USER IF EXISTS '${db_user}'@'localhost';\""
                        return 1
                    fi
                fi
                log_success "DB credentials reset (user '${db_user}' now uses the new password)"
            fi
        fi

        if [[ "$need_wordpress" == "true" ]]; then
            admin_pass=$(generate_password)
            prompt_input "WordPress admin email" "admin@${DOMAIN}" admin_email
            log_info "Generated admin password"
        fi

        echo ""
        echo "  Database:     ${db_name}"
        echo "  DB User:      ${db_user}"
        echo "  DB Password:  ${db_pass:0:8}..."
        echo "  Admin User:   ${admin_user}"
        echo "  Admin Pass:   ${admin_pass:0:8}..."
        echo "  Admin Email:  ${admin_email}"
        echo ""

        if ! prompt_yes_no "Proceed with installation?"; then
            log_info "Installation cancelled"
            exit 0
        fi
    fi

    # Execute installation
    log_step "Installing Components"

    # 1. Database
    if [[ "$need_database" == "true" ]]; then
        install_database "$db_name" "$db_user" "$db_pass"
    fi

    # 2. WordPress
    if [[ "$need_wordpress" == "true" ]]; then
        install_wordpress "$wp_root" "$DOMAIN" "$db_name" "$db_user" "$db_pass" "$admin_user" "$admin_pass" "$admin_email"
    fi

    # 3. Apache vhost
    if [[ "$need_vhost" == "true" ]]; then
        install_vhost "$DOMAIN" "$wp_root" "$site_id" "$ENVIRONMENT"
    fi

    # 4. SSL
    if [[ "$need_ssl" == "true" ]]; then
        if prompt_yes_no "Request SSL certificate now?" "y"; then
            install_ssl "$DOMAIN" || true
        else
            log_info "Skipping SSL - run later: sudo certbot --apache -d ${DOMAIN} -d www.${DOMAIN}"
        fi
    fi

    # 5. ValKey ACL
    if [[ "$need_valkey_acl" == "true" ]]; then
        install_valkey_acl "$site_id" "$ENVIRONMENT"
    fi

    # 6. gCore
    install_gcore "$wp_root"

    # 7. Parent theme (always needed)
    install_parent_theme "$wp_root"

    # 8. Child theme (if specified)
    install_child_theme "$wp_root"

    # 9. Configuration
    install_config "$wp_root" "$site_id" "$DOMAIN" "$ENVIRONMENT" "$password_file"

    # 10. Activate theme
    activate_theme "$wp_root"

    # 11. Register with gNode
    register_gnode "$wp_root"

    # Harden permissions — security-first: no world-readable
    # Files: 640 (owner rw, group r), Directories: 750 (owner rwx, group rx)
    # Owner: www-data, Group: www-data (Apache/PHP-FPM identity)
    log_step "Hardening Permissions (640/750, no world access)"

    # WordPress root
    chown -R ${WEB_USER}:${WEB_GROUP} "${wp_root}" 2>/dev/null || true
    find "${wp_root}" -type d -exec chmod 750 {} \; 2>/dev/null || true
    find "${wp_root}" -type f -exec chmod 640 {} \; 2>/dev/null || true

    if [[ -f "${wp_root}/wp-config.php" ]]; then
        chmod 640 "${wp_root}/wp-config.php" 2>/dev/null || true
    fi

    if [[ -f "${wp_root}/wp-config-geodineum.yaml" ]]; then
        chmod 640 "${wp_root}/wp-config-geodineum.yaml" 2>/dev/null || true
    fi

    # .htaccess: clear +i (ecosystem harden sets it immutable) before chmod, else EPERM on re-deploy
    if [[ -f "${wp_root}/.htaccess" ]]; then
        chattr -i "${wp_root}/.htaccess" 2>/dev/null || true
        chmod 640 "${wp_root}/.htaccess" 2>/dev/null || true
    fi

    # Symlink containers — just the symlinks, not the targets
    chown -h ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/mu-plugins" 2>/dev/null || true
    chown -h ${WEB_USER}:${WEB_GROUP} "${wp_root}/wp-content/themes" 2>/dev/null || true

    # Source repos (gCore, gTemplate, child theme) — owner:group readable, no world
    for source_dir in "$GCORE_SOURCE" "$GTEMPLATE_SOURCE" "$CHILD_THEME_SOURCE"; do
        if [[ -n "$source_dir" ]] && [[ -d "$source_dir" ]]; then
            # Directories: 750
            find "$source_dir" -type d ! -path '*/.git/*' -exec chmod 750 {} \; 2>/dev/null || true
            # Files: 640
            find "$source_dir" -type f ! -path '*/.git/*' -exec chmod 640 {} \; 2>/dev/null || true
            # Ensure www-data group can read (source repos may be owned by deploy user)
            chgrp -R ${WEB_GROUP} "$source_dir" 2>/dev/null || true
        fi
    done

    # Uploads directory — needs write access for media uploads
    if [[ -d "${wp_root}/wp-content/uploads" ]]; then
        find "${wp_root}/wp-content/uploads" -type d -exec chmod 750 {} \; 2>/dev/null || true
        find "${wp_root}/wp-content/uploads" -type f -exec chmod 640 {} \; 2>/dev/null || true
    fi

    log_success "Permissions hardened (640 files, 750 dirs, no world access)"

    emit_install_summary "$site_id" "$wp_root" "$active_theme" \
        "$db_name" "$db_user" "$admin_user" "$admin_pass" "$admin_email"
}

# emit install summary — print rich detail locally AND queue a
# notification for COMMS delivery (which only fires when SMTP is
# configured). Operators always get the summary on screen, plus an
# email if COMMS is wired up.
#
# Hybrid disclosure model:
#   - Non-sensitive (URLs, usernames, viewkey, paths): inline
#   - Sensitive (DB password, ValKey passwords): paths only — the
#     credential files already exist on disk with proper perms; emailing
#     plaintext through external SMTP servers would log them indefinitely.
#   - WP admin password: inline ONCE because wp-cli stores it hashed
#     in the DB and the operator has no on-disk path to recover it.
#     Operator should change it on first login.
emit_install_summary() {
    local site_id="$1" wp_root="$2" active_theme="$3"
    local db_name="$4" db_user="$5"
    local admin_user="$6" admin_pass="$7" admin_email="$8"

    local viewkey=""
    if [[ -f "${wp_root}/wp-config-geodineum.yaml" ]]; then
        viewkey=$(grep -oE 'viewkey:[[:space:]]*"[^"]*"' "${wp_root}/wp-config-geodineum.yaml" 2>/dev/null \
            | sed -E 's/.*"([^"]*)".*/\1/' | head -1)
    fi
    local client_pw_path="${GNODE_PASSWORD_DIR}/valkey_client_${site_id}.password"

    # ─── Terminal summary ────────────────────────────────────────────
    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║${NC}              ${BOLD}Installation Complete!${NC}                              ${GREEN}║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "  Domain:       https://${DOMAIN}"
    echo "  Site ID:      ${site_id}"
    echo "  Environment:  ${ENVIRONMENT}"
    echo "  Theme:        ${active_theme}"
    echo "  WordPress:    ${wp_root}"
    echo ""
    if [[ "$need_wordpress" == "true" ]] && [[ -n "${admin_pass:-}" ]]; then
        echo -e "  ${BOLD}WordPress Admin (change password on first login):${NC}"
        echo "    URL:        https://${DOMAIN}/wp-admin/"
        echo "    Username:   ${admin_user}"
        echo "    Email:      ${admin_email}"
        echo "    Password:   ${admin_pass}"
        echo ""
    fi
    if [[ -n "$viewkey" ]]; then
        echo -e "  ${BOLD}ViewKey (staging access — bypasses environment gate):${NC}"
        echo "    https://${DOMAIN}/?viewkey=${viewkey}"
        echo ""
    fi
    echo -e "  ${BOLD}ValKey ACL (per-site client):${NC}"
    echo "    User:       gnode_client_${site_id}"
    echo "    Password:   sudo cat ${client_pw_path}"
    echo ""
    if [[ -n "${db_name:-}" ]]; then
        echo -e "  ${BOLD}Database (MariaDB):${NC}"
        echo "    Database:   ${db_name}"
        echo "    User:       ${db_user}"
        echo "    Password:   sudo grep DB_PASSWORD ${wp_root}/wp-config.php"
        echo ""
    fi
    echo -e "  ${BOLD}Config + Logs:${NC}"
    echo "    Site YAML:  ${wp_root}/wp-config-geodineum.yaml"
    echo "    Logs:       /var/log/geodineum/"
    echo "    Apache log: /var/log/apache2/${DOMAIN}_error.log"
    echo ""
    echo "  Next steps:"
    echo "    1. Visit https://${DOMAIN} to verify the site"
    [[ -n "$viewkey" ]] && \
        echo "       (use the viewkey URL above to bypass the staging gate)"
    echo "    2. Log into wp-admin, change the admin password"
    echo "    3. Configure sections in Appearance > Customize"
    if [[ -n "$CHILD_THEME_NAME" ]]; then
        echo "    4. Generate bundle: wp gtemplate bundle --path=${wp_root}"
    fi
    echo ""

    # ─── COMMS queue (best-effort) ──────────────────────────────────
    # XADD the same summary as a CommsMessage. If COMMS is configured
    # with SMTP and a recipient for this site, an email goes out; if
    # not, the message sits in the stream until configured. Either way
    # the local print above already covered the operator.
    if [[ ! -r "$client_pw_path" ]]; then
        log_info "Install summary not queued for COMMS (client password file unreadable)"
        return 0
    fi

    local stream="{${site_id}}:gnode:comms:${ENVIRONMENT}"
    local msg_id="install-summary-${site_id}-$(date +%s)"
    local timestamp
    timestamp=$(date -Iseconds 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)

    local subject="[Geodineum] Site Deployed: ${DOMAIN}"
    # Body for COMMS — same as terminal but plain text. Sensitive items
    # remain as paths, not plaintext, since this content may be relayed
    # via external SMTP whose mail logs we don't control.
    local body
    body=$(cat <<EOF
A new Geodineum site has been deployed on this host.

Domain:       https://${DOMAIN}
Site ID:      ${site_id}
Environment:  ${ENVIRONMENT}
Theme:        ${active_theme}
Web root:     ${wp_root}

WordPress Admin (change password on first login):
  URL:        https://${DOMAIN}/wp-admin/
  Username:   ${admin_user:-(unchanged)}
  Email:      ${admin_email:-(unchanged)}
  Password:   ${admin_pass:-(unchanged — already installed)}

$( [[ -n "$viewkey" ]] && printf 'ViewKey (staging access):\n  https://%s/?viewkey=%s\n\n' "${DOMAIN}" "$viewkey" )ValKey ACL (per-site client):
  User:       gnode_client_${site_id}
  Password:   sudo cat ${client_pw_path}

Database (MariaDB):
  Database:   ${db_name:-(not provisioned this run)}
  User:       ${db_user:-(not provisioned this run)}
  Password:   sudo grep DB_PASSWORD ${wp_root}/wp-config.php

Config:       ${wp_root}/wp-config-geodineum.yaml
Logs:         /var/log/geodineum/, /var/log/apache2/${DOMAIN}_error.log

(This email is queued by the Geodineum installer. If you didn't
configure SMTP in /etc/geodineum/components/geodineum-comms/
geodineum-comms.env, the message stays in the ValKey stream
\`${stream}\` until COMMS is configured.)
EOF
)

    local client_pw
    client_pw=$(/usr/bin/cat "$client_pw_path" | /usr/bin/tr -d '\n\r')

    # XADD with discrete subject/body fields (COMMS parses these as
    # fallback when 'content' field is absent). MAXLEN=1000 caps stream
    # growth. Idempotent — re-running just queues another entry.
    if valkey-cli -p "${VALKEY_PORT:-47445}" \
            --user "gnode_client_${site_id}" \
            -a "$client_pw" \
            --no-auth-warning \
            XADD "$stream" 'MAXLEN' '~' '1000' '*' \
                id "$msg_id" \
                type install_summary \
                timestamp "$timestamp" \
                priority 3 \
                subject "$subject" \
                body "$body" \
                >/dev/null 2>&1; then
        log_info "Install summary queued for COMMS (stream: ${stream})"
    else
        log_info "Install summary NOT queued — COMMS stream unreachable (continuing)"
    fi
}

main "$@"
