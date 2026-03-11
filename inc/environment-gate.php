<?php
/**
 * Environment Gate - Viewkey-based access control for non-production environments
 *
 * When environment is NOT production:
 * - Anonymous visitors see "Under Development" screen with viewkey login
 * - WordPress logged-in users see the site + environment badge
 * - Visitors with valid viewkey cookie see the site + environment badge
 *
 * Viewkey is stored in wp-config-geodineum.yaml:
 *   security:
 *     viewkey: "your-secret-viewkey-here"
 *     viewkey_expiry: 86400  # Cookie expiry in seconds (default: 24 hours)
 *
 * @package gTemplate
 * @since 1.1.0
 */

namespace gTemplate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Environment Gate class
 */
class EnvironmentGate
{
    /** @var string Current environment */
    private string $environment;

    /** @var array Site configuration */
    private array $config;

    /** @var string Cookie name for viewkey */
    private const COOKIE_NAME = 'gtemplate_viewkey';

    /** @var int Default cookie expiry (24 hours) */
    private const DEFAULT_EXPIRY = 86400;

    /**
     * Initialize the environment gate
     */
    public function __construct()
    {
        $this->config = load_registration_config() ?? [];
        $this->environment = $this->detectEnvironment();
    }

    /**
     * Detect DTAP environment from multiple sources
     *
     * Priority:
     * 1. Config file (metadata.environment)
     * 2. WP_ENVIRONMENT_TYPE constant
     * 3. Domain-based auto-detection
     * 4. Default to 'production' (safest)
     */
    private function detectEnvironment(): string
    {
        // 1. Check config file first (highest priority)
        if (!empty($this->config['metadata']['environment'])) {
            return $this->normalizeEnvironment($this->config['metadata']['environment']);
        }

        // 2. Check WordPress environment constant
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return $this->normalizeEnvironment(WP_ENVIRONMENT_TYPE);
        }

        // 3. Auto-detect from domain
        $domain = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST) ?? '';

        if (strpos($domain, 'test') !== false || strpos($domain, 'dev') !== false || strpos($domain, 'local') !== false) {
            return 'testing';
        }
        if (strpos($domain, 'staging') !== false) {
            return 'staging';
        }
        if (strpos($domain, 'accept') !== false || strpos($domain, 'uat') !== false) {
            return 'acceptance';
        }

        // 4. Default to production (safest - no gate)
        return 'production';
    }

    /**
     * Normalize environment names to DTAP standard
     */
    private function normalizeEnvironment(string $env): string
    {
        $env = strtolower(trim($env));

        // Map WordPress environment types to DTAP
        $mapping = [
            'development' => 'testing',
            'local' => 'testing',
            'dev' => 'testing',
            'test' => 'testing',
            'stage' => 'staging',
            'uat' => 'acceptance',
            'accept' => 'acceptance',
            'live' => 'production',
            'prod' => 'production',
        ];

        return $mapping[$env] ?? $env;
    }

    /**
     * Check if gate should be active
     */
    public function isActive(): bool
    {
        return $this->environment !== 'production';
    }

    /**
     * Check if current visitor has access
     */
    public function hasAccess(): bool
    {
        // Production = always accessible
        if (!$this->isActive()) {
            return true;
        }

        // WordPress logged-in users always have access
        if (is_user_logged_in()) {
            return true;
        }

        // Check for valid viewkey cookie
        if ($this->hasValidViewkeyCookie()) {
            return true;
        }

        return false;
    }

    /**
     * Get the configured viewkey
     */
    public function getViewkey(): ?string
    {
        return $this->config['security']['viewkey'] ?? null;
    }

    /**
     * Check if viewkey is configured
     */
    public function hasViewkey(): bool
    {
        $viewkey = $this->getViewkey();
        return !empty($viewkey);
    }

    /**
     * Validate a viewkey
     */
    public function validateViewkey(string $input): bool
    {
        $viewkey = $this->getViewkey();
        if (empty($viewkey)) {
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($viewkey, $input);
    }

    /**
     * Check if visitor has valid viewkey cookie
     */
    public function hasValidViewkeyCookie(): bool
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        $cookie_value = $_COOKIE[self::COOKIE_NAME];
        $viewkey = $this->getViewkey();

        if (empty($viewkey)) {
            return false;
        }

        // Cookie stores hashed viewkey for security
        $expected_hash = $this->hashViewkey($viewkey);
        return hash_equals($expected_hash, $cookie_value);
    }

    /**
     * Set viewkey cookie
     */
    public function setViewkeyCookie(): void
    {
        $viewkey = $this->getViewkey();
        if (empty($viewkey)) {
            return;
        }

        $expiry = $this->config['security']['viewkey_expiry'] ?? self::DEFAULT_EXPIRY;
        $hash = $this->hashViewkey($viewkey);

        setcookie(
            self::COOKIE_NAME,
            $hash,
            [
                'expires' => time() + $expiry,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );

        // Also set in $_COOKIE for immediate access
        $_COOKIE[self::COOKIE_NAME] = $hash;
    }

    /**
     * Clear viewkey cookie
     */
    public function clearViewkeyCookie(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Hash viewkey for cookie storage
     */
    private function hashViewkey(string $viewkey): string
    {
        // Use site URL as salt for per-site uniqueness
        $salt = parse_url(get_site_url(), PHP_URL_HOST) ?? 'gtemplate';
        return hash('sha256', $viewkey . $salt);
    }

    /**
     * Get environment display name
     */
    public function getEnvironmentLabel(): string
    {
        $labels = [
            'testing' => 'Development',
            'staging' => 'Staging',
            'acceptance' => 'UAT',
            'production' => 'Live'
        ];

        return $labels[$this->environment] ?? ucfirst($this->environment);
    }

    /**
     * Get environment badge color
     */
    public function getEnvironmentColor(): string
    {
        $colors = [
            'testing' => '#e74c3c',     // Red
            'staging' => '#f39c12',     // Orange
            'acceptance' => '#3498db',  // Blue
            'production' => '#27ae60'   // Green
        ];

        return $colors[$this->environment] ?? '#95a5a6';
    }

    /**
     * Get current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }
}

/**
 * Handle viewkey form submission
 */
function handle_viewkey_submission(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!isset($_POST['gtemplate_viewkey_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['gtemplate_viewkey_nonce'], 'gtemplate_viewkey_action')) {
        return;
    }

    $gate = new EnvironmentGate();

    // Handle logout
    if (isset($_POST['gtemplate_viewkey_logout'])) {
        $gate->clearViewkeyCookie();
        wp_redirect(home_url());
        exit;
    }

    // Handle login
    if (isset($_POST['gtemplate_viewkey_input'])) {
        $input = sanitize_text_field($_POST['gtemplate_viewkey_input']);

        if ($gate->validateViewkey($input)) {
            $gate->setViewkeyCookie();

            // Redirect to requested page or home
            $redirect = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : home_url();
            wp_redirect($redirect);
            exit;
        } else {
            // Invalid viewkey - will show error on gate screen
            $GLOBALS['gtemplate_viewkey_error'] = true;
        }
    }
}

/**
 * Render the environment gate screen
 */
function render_gate_screen(): void
{
    $gate = new EnvironmentGate();
    $error = $GLOBALS['gtemplate_viewkey_error'] ?? false;
    $site_name = get_bloginfo('name');
    $env_label = $gate->getEnvironmentLabel();
    $env_color = $gate->getEnvironmentColor();
    $has_viewkey = $gate->hasViewkey();

    // Get site config for branding
    $config = load_registration_config() ?? [];
    $logo_url = $config['branding']['logo_url'] ?? '';

    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($site_name); ?> - <?php echo esc_html($env_label); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .gate-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }

        .gate-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }

        .gate-logo img {
            max-width: 60px;
            max-height: 60px;
            border-radius: 10px;
        }

        .gate-badge {
            display: inline-block;
            background: <?php echo esc_attr($env_color); ?>;
            color: #fff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
        }

        .gate-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .gate-subtitle {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .gate-form {
            margin-top: 20px;
        }

        .gate-input-group {
            position: relative;
            margin-bottom: 15px;
        }

        .gate-input {
            width: 100%;
            padding: 14px 20px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            color: #fff;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease;
        }

        .gate-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .gate-input:focus {
            border-color: <?php echo esc_attr($env_color); ?>;
            background: rgba(255, 255, 255, 0.15);
        }

        .gate-button {
            width: 100%;
            padding: 14px 20px;
            background: <?php echo esc_attr($env_color); ?>;
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .gate-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .gate-error {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid rgba(231, 76, 60, 0.4);
            color: #e74c3c;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .gate-divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            color: rgba(255, 255, 255, 0.3);
            font-size: 12px;
        }

        .gate-divider::before,
        .gate-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        .gate-divider span {
            padding: 0 15px;
        }

        .gate-wp-login {
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .gate-wp-login:hover {
            color: #fff;
        }

        .gate-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            color: rgba(255, 255, 255, 0.3);
        }

        .gate-no-viewkey {
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="gate-container">
        <div class="gate-logo">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>">
            <?php else: ?>
                &#x1f6a7;
            <?php endif; ?>
        </div>

        <div class="gate-badge"><?php echo esc_html($env_label); ?></div>

        <h1 class="gate-title"><?php echo esc_html($site_name); ?></h1>
        <p class="gate-subtitle">
            This site is currently under development.<br>
            <?php if ($has_viewkey): ?>
                Enter your viewkey to preview the site.
            <?php else: ?>
                Please log in to continue.
            <?php endif; ?>
        </p>

        <?php if ($has_viewkey): ?>
            <form class="gate-form" method="post" action="">
                <?php wp_nonce_field('gtemplate_viewkey_action', 'gtemplate_viewkey_nonce'); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">

                <?php if ($error): ?>
                    <div class="gate-error">
                        Invalid viewkey. Please try again.
                    </div>
                <?php endif; ?>

                <div class="gate-input-group">
                    <input
                        type="password"
                        name="gtemplate_viewkey_input"
                        class="gate-input"
                        placeholder="Enter viewkey"
                        autocomplete="off"
                        autofocus
                    >
                </div>

                <button type="submit" class="gate-button">
                    View Site
                </button>
            </form>

            <div class="gate-divider"><span>or</span></div>
        <?php else: ?>
            <p class="gate-no-viewkey">
                No viewkey configured for this site.
            </p>
            <div class="gate-divider"><span>admin access</span></div>
        <?php endif; ?>

        <a href="<?php echo esc_url(wp_login_url(home_url())); ?>" class="gate-wp-login">
            WordPress Admin Login &rarr;
        </a>

        <div class="gate-footer">
            Powered by gTemplate &bull; Geodinium Stack
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * Render environment badge for authenticated users
 */
function render_environment_badge(): void
{
    $gate = new EnvironmentGate();

    if (!$gate->isActive()) {
        return;
    }

    $env_label = $gate->getEnvironmentLabel();
    $env_color = $gate->getEnvironmentColor();
    $is_viewkey_user = $gate->hasValidViewkeyCookie() && !is_user_logged_in();

    ?>
    <div id="gtemplate-env-badge" style="
        position: fixed;
        top: 10px;
        right: 10px;
        background: <?php echo esc_attr($env_color); ?>;
        color: #fff;
        padding: 8px 16px;
        border-radius: 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        z-index: 999999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        gap: 10px;
    ">
        <span><?php echo esc_html($env_label); ?></span>
        <?php if ($is_viewkey_user): ?>
            <form method="post" action="" style="display: inline; margin: 0;">
                <?php wp_nonce_field('gtemplate_viewkey_action', 'gtemplate_viewkey_nonce'); ?>
                <button type="submit" name="gtemplate_viewkey_logout" value="1" style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: #fff;
                    padding: 4px 10px;
                    border-radius: 10px;
                    font-size: 10px;
                    cursor: pointer;
                ">Exit Preview</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Initialize environment gate
 *
 * Runs on 'init' hook (priority 1) because:
 * - is_user_logged_in() requires user session (initialized on 'init')
 * - is_admin() needs to be determined first
 * - viewkey form submission must happen before any output
 */
function init_environment_gate(): void
{
    // Skip for admin, AJAX, REST API, and cron
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    // Skip for REST API requests
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    // Skip for wp-login.php and wp-register.php
    global $pagenow;
    if (in_array($pagenow, ['wp-login.php', 'wp-register.php'], true)) {
        return;
    }

    // Handle form submission early (before output)
    handle_viewkey_submission();

    $gate = new EnvironmentGate();

    // If gate is active and user doesn't have access, show gate screen
    if ($gate->isActive() && !$gate->hasAccess()) {
        // Hook early to show gate screen
        add_action('template_redirect', __NAMESPACE__ . '\\render_gate_screen', 1);
        return;
    }

    // If user has access to non-production, show environment badge
    if ($gate->isActive() && $gate->hasAccess()) {
        add_action('wp_footer', __NAMESPACE__ . '\\render_environment_badge');
    }
}

// Initialize on 'init' hook with high priority (after user session is loaded)
// User authentication happens during 'init', so is_user_logged_in() works correctly
add_action('init', __NAMESPACE__ . '\\init_environment_gate', 1);
