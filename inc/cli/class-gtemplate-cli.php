<?php
/**
 * WP-CLI commands for gTemplate
 *
 * Usage:
 *   wp <prefix> register                 # Smart registration (check first)
 *   wp <prefix> register --force         # Force re-registration
 *   wp <prefix> status                   # Show registration status
 *   wp <prefix> config                   # Show registration config
 *
 * The command name is derived dynamically from gtemplate_get_theme_prefix().
 *
 * @package gTemplate
 * @version 1.0.0
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * gTemplate site registration and management commands
 */
class GtemplateCLI
{
    /**
     * Register site with gNode topology
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force re-registration even if already registered
     *
     * ## EXAMPLES
     *
     *     # Smart registration (only if changed)
     *     wp <prefix> register
     *
     *     # Force re-registration
     *     wp <prefix> register --force
     *
     * @when after_wp_load
     */
    public function register($args, $assoc_args)
    {
        $force = isset($assoc_args['force']);

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Site Registration ===');
        WP_CLI::line('');

        // Load registration config
        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        WP_CLI::line('Config loaded: registration.yaml');
        WP_CLI::line('Site ID: ' . ($config['site_id'] ?? 'unknown'));
        WP_CLI::line('');

        // Check current status
        $status = \gTemplate\get_registration_status();

        if ($status['registered'] && !$force) {
            WP_CLI::line('Status: Already registered');
            WP_CLI::line('  Hash: ' . substr($status['hash'], 0, 16) . '...');
            WP_CLI::line('  Registered at: ' . ($status['registered_at'] ?? 'unknown'));
            WP_CLI::line('');
            WP_CLI::line('Checking for config changes...');
        }

        // Perform registration
        WP_CLI::line($force ? 'Force registering...' : 'Registering...');

        $success = \gTemplate\smart_register_site($force);

        WP_CLI::line('');

        if ($success) {
            $new_status = \gTemplate\get_registration_status();
            WP_CLI::success('Registration completed successfully!');
            WP_CLI::line('  Hash: ' . substr($new_status['hash'], 0, 16) . '...');
            WP_CLI::line('  Method: ' . ($new_status['method'] ?? 'unknown'));
            WP_CLI::line('  Registered at: ' . ($new_status['registered_at'] ?? 'unknown'));
        } else {
            WP_CLI::error('Registration failed! Check error log for details.');
        }

        WP_CLI::line('');
    }

    /**
     * Show registration status
     *
     * ## EXAMPLES
     *
     *     wp <prefix> status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args)
    {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Registration Status ===');
        WP_CLI::line('');

        $status = \gTemplate\get_registration_status();

        if ($status['registered']) {
            WP_CLI::line('Status: Registered');
            WP_CLI::line('Hash: ' . ($status['hash'] ?? 'unknown'));
            WP_CLI::line('Registered at: ' . ($status['registered_at'] ?? 'unknown'));
            WP_CLI::line('Method: ' . ($status['method'] ?? 'unknown'));
        } else {
            WP_CLI::line('Status: Not registered');
            WP_CLI::line('');
            WP_CLI::line('Run: wp ' . $prefix . ' register');
        }

        WP_CLI::line('');
    }

    /**
     * Show registration configuration
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp <prefix> config
     *     wp <prefix> config --format=json
     *
     * @when after_wp_load
     */
    public function config($args, $assoc_args)
    {
        $format = $assoc_args['format'] ?? 'table';

        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($config, JSON_PRETTY_PRINT));
            return;
        }

        if ($format === 'yaml') {
            WP_CLI::line(yaml_emit($config));
            return;
        }

        // Table format
        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Registration Config ===');
        WP_CLI::line('');

        WP_CLI::line('Basic Information:');
        WP_CLI::line('  Version: ' . ($config['version'] ?? 'unknown'));
        WP_CLI::line('  Site ID: ' . ($config['site_id'] ?? 'unknown'));
        WP_CLI::line('  Type: ' . ($config['service']['type'] ?? 'unknown'));
        WP_CLI::line('  Environment: ' . ($config['metadata']['environment'] ?? 'unknown'));
        WP_CLI::line('  Domain: ' . ($config['metadata']['domain'] ?? 'unknown'));
        WP_CLI::line('');

        WP_CLI::line('Capabilities:');
        foreach ($config['capabilities'] ?? [] as $cap => $value) {
            WP_CLI::line(sprintf('  %-25s %.2f', $cap, $value));
        }
        WP_CLI::line('');

        WP_CLI::line('ValKey:');
        WP_CLI::line('  User: ' . ($config['valkey']['user'] ?? 'unknown'));
        WP_CLI::line('  Password file: ' . ($config['valkey']['password_file'] ?? 'unknown'));
        WP_CLI::line('');

        WP_CLI::line('Managers:');
        foreach ($config['managers'] ?? [] as $manager => $file) {
            WP_CLI::line(sprintf('  %-15s %s', $manager, $file));
        }
        WP_CLI::line('');
    }

    /**
     * Sync configuration to ValKey
     *
     * ## EXAMPLES
     *
     *     wp <prefix> sync-config
     *
     * @when after_wp_load
     */
    public function sync_config($args, $assoc_args)
    {
        WP_CLI::line('');
        WP_CLI::line('=== Syncing Config to ValKey ===');
        WP_CLI::line('');

        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        WP_CLI::line('Syncing configuration...');

        $success = \gTemplate\sync_config_to_valkey($config);

        if ($success) {
            WP_CLI::success('Config synced successfully!');
            WP_CLI::line('  Keys: {' . $config['site_id'] . '}:config:*');
        } else {
            WP_CLI::error('Config sync failed! Check error log.');
        }

        WP_CLI::line('');
    }

    /**
     * Get a runtime config value from ValKey
     *
     * ## OPTIONS
     *
     * <category>
     * : Config category (ratelimit, cache, security, features)
     *
     * <key>
     * : Config key
     *
     * [--default=<value>]
     * : Default value if not found
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-get ratelimit api_limit
     *
     * @when after_wp_load
     * @subcommand runtime-get
     */
    public function runtime_get($args, $assoc_args) {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        if (count($args) < 2) {
            WP_CLI::error('Usage: wp ' . $prefix . ' runtime-get <category> <key>');
            return;
        }
        if (!function_exists('gtemplate_config_get')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $value = gtemplate_config_get($args[0], $args[1], $assoc_args['default'] ?? null);
        WP_CLI::line($value ?? '(null)');
    }

    /**
     * Set a runtime config value in ValKey
     *
     * ## OPTIONS
     *
     * <category>
     * : Config category
     *
     * <key>
     * : Config key
     *
     * <value>
     * : Value to set
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-set ratelimit api_limit 200
     *
     * @when after_wp_load
     * @subcommand runtime-set
     */
    public function runtime_set($args, $assoc_args) {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        if (count($args) < 3) {
            WP_CLI::error('Usage: wp ' . $prefix . ' runtime-set <category> <key> <value>');
            return;
        }
        if (!function_exists('gtemplate_config_set')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $success = gtemplate_config_set($args[0], $args[1], $args[2]);
        if ($success) {
            WP_CLI::success("Set {$args[0]}.{$args[1]} = {$args[2]}");
        } else {
            WP_CLI::error("Failed to set {$args[0]}.{$args[1]}");
        }
    }

    /**
     * List runtime config values
     *
     * ## OPTIONS
     *
     * [<category>]
     * : Optional category to list
     *
     * [--format=<format>]
     * : Output format (table, json)
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-list
     *     wp <prefix> runtime-list ratelimit
     *
     * @when after_wp_load
     * @subcommand runtime-list
     */
    public function runtime_list($args, $assoc_args) {
        if (!function_exists('gtemplate_config_get_all')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $format = $assoc_args['format'] ?? 'table';
        $category = $args[0] ?? null;
        $categories = $category ? [$category] : ['ratelimit', 'cache', 'security', 'features'];
        $all = [];
        foreach ($categories as $cat) {
            $all[$cat] = gtemplate_config_get_all($cat);
        }
        if ($format === 'json') {
            WP_CLI::line(json_encode($all, JSON_PRETTY_PRINT));
            return;
        }
        WP_CLI::line('');
        foreach ($all as $cat => $values) {
            WP_CLI::line("[{$cat}]");
            foreach ($values as $key => $value) {
                WP_CLI::line(sprintf('  %-25s %s', $key, $value));
            }
            WP_CLI::line('');
        }
    }

    // =========================================================================
    // AIO (AI Optimization) Commands
    // =========================================================================

    /**
     * Generate AI metadata for a post
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to generate AI metadata for
     *
     * [--force]
     * : Force regeneration even if cached
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-generate 123
     *     wp <prefix> aio-generate 123 --force
     *
     * @when after_wp_load
     * @subcommand aio-generate
     */
    public function aio_generate($args, $assoc_args)
    {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a post ID');
            return;
        }

        $postId = (int) $args[0];
        $post = get_post($postId);

        if (!$post) {
            WP_CLI::error("Post {$postId} not found");
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('=== AIO: Generate AI Metadata ===');
        WP_CLI::line('');
        WP_CLI::line("Post: {$post->post_title} (ID: {$postId})");
        WP_CLI::line('');

        // Check if AIO is enabled
        if (!function_exists('gtemplate_aio_is_enabled') || !gtemplate_aio_is_enabled()) {
            WP_CLI::warning('AIO is not enabled. Check InferenceManager and Ollama status.');
            WP_CLI::line('');
            WP_CLI::line('To enable AIO:');
            WP_CLI::line('  1. Ensure Ollama is running: ollama serve');
            WP_CLI::line('  2. Ensure a model is available: ollama pull llama3');
            WP_CLI::line('  3. Check SEOManager config: enable_geo = true');
            WP_CLI::line('');
            return;
        }

        $force = isset($assoc_args['force']);
        WP_CLI::line($force ? 'Forcing regeneration...' : 'Generating AI metadata...');
        WP_CLI::line('');

        $startTime = microtime(true);

        $result = gtemplate_generate_ai_meta_for_post($postId, ['force_regenerate' => $force]);

        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($result && ($result['success'] ?? false)) {
            WP_CLI::success("AI metadata generated in {$elapsed}ms");
            WP_CLI::line('');
            WP_CLI::line('Results:');
            WP_CLI::line('  FAQ pairs: ' . count($result['faq'] ?? []));
            WP_CLI::line('  Entities: ' . count($result['entities'] ?? []));
            WP_CLI::line('  TL;DR: ' . (empty($result['tldr']) ? 'No' : 'Yes (' . strlen($result['tldr']) . ' chars)'));
            WP_CLI::line('  SPR: ' . (empty($result['spr']) ? 'No' : 'Yes (' . strlen($result['spr']) . ' chars)'));
            WP_CLI::line('  Model: ' . ($result['model'] ?? 'unknown'));
            WP_CLI::line('');

            // Show TL;DR preview
            if (!empty($result['tldr'])) {
                WP_CLI::line('TL;DR Preview:');
                WP_CLI::line('  ' . substr($result['tldr'], 0, 200) . (strlen($result['tldr']) > 200 ? '...' : ''));
                WP_CLI::line('');
            }

            // Show FAQ preview
            if (!empty($result['faq'])) {
                WP_CLI::line('FAQ Preview (first 2):');
                foreach (array_slice($result['faq'], 0, 2) as $faq) {
                    WP_CLI::line('  Q: ' . ($faq['q'] ?? ''));
                    WP_CLI::line('  A: ' . substr($faq['a'] ?? '', 0, 100) . '...');
                    WP_CLI::line('');
                }
            }
        } else {
            WP_CLI::error('Generation failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Regenerate AI metadata for all posts
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Post type to process (default: post,page)
     *
     * [--limit=<num>]
     * : Maximum posts to process (default: 50)
     *
     * [--force]
     * : Force regeneration even if cached
     *
     * [--dry-run]
     * : Show what would be processed without generating
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-regenerate-all
     *     wp <prefix> aio-regenerate-all --limit=10 --force
     *     wp <prefix> aio-regenerate-all --post-type=post --dry-run
     *
     * @when after_wp_load
     * @subcommand aio-regenerate-all
     */
    public function aio_regenerate_all($args, $assoc_args)
    {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        WP_CLI::line('');
        WP_CLI::line('=== AIO: Regenerate All AI Metadata ===');
        WP_CLI::line('');

        // Check if AIO is enabled
        if (!function_exists('gtemplate_aio_is_enabled') || !gtemplate_aio_is_enabled()) {
            WP_CLI::error('AIO is not enabled. Run: wp ' . $prefix . ' aio-status');
            return;
        }

        $postTypes = explode(',', $assoc_args['post-type'] ?? 'post,page');
        $limit = (int) ($assoc_args['limit'] ?? 50);
        $force = isset($assoc_args['force']);
        $dryRun = isset($assoc_args['dry-run']);

        // Get posts to process
        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC'
        ]);

        WP_CLI::line('Found ' . count($posts) . ' posts to process');
        WP_CLI::line('Post types: ' . implode(', ', $postTypes));
        WP_CLI::line('Force: ' . ($force ? 'Yes' : 'No'));
        WP_CLI::line('');

        if ($dryRun) {
            WP_CLI::line('DRY RUN - Posts that would be processed:');
            foreach ($posts as $post) {
                $hasMeta = get_post_meta($post->ID, 'gtemplate_ai_meta', true) ? 'Yes' : 'No';
                WP_CLI::line("  [{$post->ID}] {$post->post_title} (AI meta: {$hasMeta})");
            }
            WP_CLI::line('');
            WP_CLI::line('Run without --dry-run to process.');
            return;
        }

        $success = 0;
        $failed = 0;
        $skipped = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Generating AI metadata', count($posts));

        foreach ($posts as $post) {
            // Skip if has meta and not forcing
            if (!$force && get_post_meta($post->ID, 'gtemplate_ai_meta', true)) {
                $skipped++;
                $progress->tick();
                continue;
            }

            // Skip short content
            if (strlen(wp_strip_all_tags($post->post_content)) < 100) {
                $skipped++;
                $progress->tick();
                continue;
            }

            $result = gtemplate_generate_ai_meta_for_post($post->ID, ['force_regenerate' => $force]);

            if ($result && ($result['success'] ?? false)) {
                $success++;
            } else {
                $failed++;
            }

            $progress->tick();

            // Small delay to not overwhelm Ollama
            usleep(100000); // 100ms
        }

        $progress->finish();

        WP_CLI::line('');
        WP_CLI::success("Completed! Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");
        WP_CLI::line('');
    }

    /**
     * Show AIO status
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-status
     *
     * @when after_wp_load
     * @subcommand aio-status
     */
    public function aio_status($args, $assoc_args)
    {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        WP_CLI::line('');
        WP_CLI::line('=== AIO Status ===');
        WP_CLI::line('');

        // Check AIO enabled
        $aioEnabled = function_exists('gtemplate_aio_is_enabled') && gtemplate_aio_is_enabled();
        WP_CLI::line('AIO Enabled: ' . ($aioEnabled ? 'Yes' : 'No'));

        // Check SEOManager
        $seo = function_exists('gtemplate_get_seo_manager') ? gtemplate_get_seo_manager() : null;
        WP_CLI::line('SEOManager: ' . ($seo ? 'Available' : 'Not available'));

        if ($seo && method_exists($seo, 'isGeoEnabled')) {
            WP_CLI::line('SEOManager AIO: ' . ($seo->isGeoEnabled() ? 'Enabled' : 'Disabled'));
        }

        if ($seo && method_exists($seo, 'getStatistics')) {
            $stats = $seo->getStatistics();
            WP_CLI::line('');
            WP_CLI::line('Statistics:');
            WP_CLI::line('  AI meta generated: ' . ($stats['ai_meta_generated'] ?? 0));
            WP_CLI::line('  FAQ pairs: ' . ($stats['faq_pairs_generated'] ?? 0));
            WP_CLI::line('  Entities extracted: ' . ($stats['entities_extracted'] ?? 0));
            WP_CLI::line('  SPR compressions: ' . ($stats['spr_compressions'] ?? 0));
            WP_CLI::line('  Wikidata lookups: ' . ($stats['wikidata_lookups'] ?? 0));
        }

        // Count posts with AI meta
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'gtemplate_ai_meta'"
        );
        WP_CLI::line('');
        WP_CLI::line('Posts with AI meta: ' . $count);

        // Show llms.txt URLs
        WP_CLI::line('');
        WP_CLI::line('Endpoints:');
        WP_CLI::line('  llms.txt: ' . home_url('/llms.txt'));
        WP_CLI::line('  llms-full.txt: ' . home_url('/llms-full.txt'));
        WP_CLI::line('  LLM Context API: ' . rest_url($prefix . '/v1/llm-context'));

        WP_CLI::line('');
    }

    /**
     * Show AI metadata for a post
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID
     *
     * [--format=<format>]
     * : Output format (table, json)
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-show 123
     *     wp <prefix> aio-show 123 --format=json
     *
     * @when after_wp_load
     * @subcommand aio-show
     */
    public function aio_show($args, $assoc_args)
    {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        if (empty($args[0])) {
            WP_CLI::error('Please provide a post ID');
            return;
        }

        $postId = (int) $args[0];
        $format = $assoc_args['format'] ?? 'table';

        $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);

        if (!$aiMeta || !is_array($aiMeta)) {
            WP_CLI::error("No AI metadata found for post {$postId}. Run: wp {$prefix} aio-generate {$postId}");
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($aiMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        // Table format
        WP_CLI::line('');
        WP_CLI::line("=== AI Metadata for Post {$postId} ===");
        WP_CLI::line('');

        WP_CLI::line('Generated: ' . date('Y-m-d H:i:s', $aiMeta['generated_at'] ?? 0));
        WP_CLI::line('Model: ' . ($aiMeta['model'] ?? 'unknown'));
        WP_CLI::line('');

        if (!empty($aiMeta['tldr'])) {
            WP_CLI::line('TL;DR:');
            WP_CLI::line('  ' . $aiMeta['tldr']);
            WP_CLI::line('');
        }

        if (!empty($aiMeta['description'])) {
            WP_CLI::line('AI Description:');
            WP_CLI::line('  ' . $aiMeta['description']);
            WP_CLI::line('');
        }

        if (!empty($aiMeta['spr'])) {
            WP_CLI::line('SPR (Sparse Priming Representation):');
            WP_CLI::line('  ' . $aiMeta['spr']);
            WP_CLI::line('');
        }

        if (!empty($aiMeta['faq'])) {
            WP_CLI::line('FAQ Pairs (' . count($aiMeta['faq']) . '):');
            foreach ($aiMeta['faq'] as $i => $faq) {
                WP_CLI::line('  ' . ($i + 1) . '. Q: ' . ($faq['q'] ?? ''));
                WP_CLI::line('     A: ' . ($faq['a'] ?? ''));
            }
            WP_CLI::line('');
        }

        if (!empty($aiMeta['entities'])) {
            WP_CLI::line('Entities (' . count($aiMeta['entities']) . '):');
            foreach ($aiMeta['entities'] as $entity) {
                $wiki = !empty($entity['wikidata_id']) ? " ({$entity['wikidata_id']})" : '';
                WP_CLI::line('  - ' . ($entity['name'] ?? '') . ' [' . ($entity['type'] ?? '') . ']' . $wiki);
            }
            WP_CLI::line('');
        }
    }

    /**
     * Clear AI metadata for a post or all posts
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : Specific post ID to clear (omit for all)
     *
     * [--all]
     * : Clear all posts
     *
     * [--yes]
     * : Skip confirmation
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-clear 123
     *     wp <prefix> aio-clear --all --yes
     *
     * @when after_wp_load
     * @subcommand aio-clear
     */
    public function aio_clear($args, $assoc_args)
    {
        $postId = !empty($args[0]) ? (int) $args[0] : null;
        $all = isset($assoc_args['all']);

        if (!$postId && !$all) {
            WP_CLI::error('Please provide a post ID or use --all');
            return;
        }

        if ($postId) {
            delete_post_meta($postId, 'gtemplate_ai_meta');
            delete_post_meta($postId, 'gtemplate_content_hash');
            WP_CLI::success("Cleared AI metadata for post {$postId}");
            return;
        }

        // Clear all
        if (!isset($assoc_args['yes'])) {
            WP_CLI::confirm('Are you sure you want to clear ALL AI metadata?');
        }

        global $wpdb;
        $count = $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('gtemplate_ai_meta', 'gtemplate_content_hash')"
        );

        WP_CLI::success("Cleared AI metadata from {$count} rows");
    }

    /**
     * Preview llms.txt output
     *
     * ## OPTIONS
     *
     * [--full]
     * : Show llms-full.txt instead
     *
     * ## EXAMPLES
     *
     *     wp <prefix> aio-llms-preview
     *     wp <prefix> aio-llms-preview --full
     *
     * @when after_wp_load
     * @subcommand aio-llms-preview
     */
    public function aio_llms_preview($args, $assoc_args)
    {
        $seo = function_exists('gtemplate_get_seo_manager') ? gtemplate_get_seo_manager() : null;
        $full = isset($assoc_args['full']);

        $siteConfig = function_exists('gtemplate_get_llms_site_config') ? gtemplate_get_llms_site_config() : [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url()
        ];

        $pages = function_exists('gtemplate_get_llms_pages') ? gtemplate_get_llms_pages() : [];

        WP_CLI::line('');
        WP_CLI::line($full ? '=== llms-full.txt Preview ===' : '=== llms.txt Preview ===');
        WP_CLI::line('');

        if ($seo && method_exists($seo, 'generateLLMsTxt')) {
            if ($full) {
                $pagesWithContent = function_exists('gtemplate_get_llms_pages_with_content')
                    ? gtemplate_get_llms_pages_with_content()
                    : $pages;
                $content = $seo->generateLLMsFullTxt($siteConfig, $pagesWithContent);
            } else {
                $content = $seo->generateLLMsTxt($siteConfig, $pages);
            }
            WP_CLI::line($content);
        } else {
            WP_CLI::warning('SEOManager not available, showing basic preview');
            WP_CLI::line("# " . ($siteConfig['name'] ?? 'Site'));
            WP_CLI::line('');
            WP_CLI::line("> " . ($siteConfig['description'] ?? ''));
            WP_CLI::line('');
            WP_CLI::line('## Pages');
            foreach ($pages as $page) {
                WP_CLI::line("- [{$page['title']}]({$page['url']})");
            }
        }

        WP_CLI::line('');
    }

    /**
     * Show or generate viewkey for environment gate
     *
     * ## OPTIONS
     *
     * [--regenerate]
     * : Generate a new viewkey (overwrites existing)
     *
     * [--copy]
     * : Output just the viewkey (for scripting/copying)
     *
     * ## EXAMPLES
     *
     *     wp <prefix> viewkey
     *     wp <prefix> viewkey --copy
     *     wp <prefix> viewkey --regenerate
     *
     * @when after_wp_load
     */
    public function viewkey($args, $assoc_args)
    {
        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load site configuration');
            return;
        }

        $environment = $config['metadata']['environment'] ?? 'production';
        $viewkey = $config['security']['viewkey'] ?? '';
        $site_id = $config['site_id'] ?? 'unknown';
        $copyOnly = isset($assoc_args['copy']);

        // Regenerate if requested
        if (isset($assoc_args['regenerate'])) {
            $viewkey = bin2hex(random_bytes(16)); // 32 hex chars

            // Find config file location
            $config_file = ABSPATH . 'wp-config-geodineum.yaml';
            if (!file_exists($config_file)) {
                $config_file = get_template_directory() . '/registration.local.yaml';
            }

            $file_updated = false;
            if (file_exists($config_file) && is_writable($config_file)) {
                $content = file_get_contents($config_file);

                // Update or add viewkey
                if (preg_match('/^(\s*viewkey:\s*)["\']?[^"\'\n]*["\']?\s*$/m', $content)) {
                    $content = preg_replace(
                        '/^(\s*viewkey:\s*)["\']?[^"\'\n]*["\']?\s*$/m',
                        '$1"' . $viewkey . '"',
                        $content
                    );
                } else {
                    // Add to security section
                    if (preg_match('/^security:\s*$/m', $content)) {
                        $content = preg_replace(
                            '/^(security:\s*)$/m',
                            "$1\n  viewkey: \"$viewkey\"",
                            $content
                        );
                    }
                }

                file_put_contents($config_file, $content);
                $file_updated = true;
                WP_CLI::success('Viewkey regenerated in config file!');
            } else {
                WP_CLI::warning("Cannot write to config file. Add this manually:");
                WP_CLI::line("  viewkey: \"$viewkey\"");
            }

            // Always sync to ValKey (updates the cached config)
            $config['security'] = $config['security'] ?? [];
            $config['security']['viewkey'] = $viewkey;
            if (\gTemplate\sync_config_to_valkey($config)) {
                WP_CLI::success('Viewkey synced to ValKey cache!');
            } else {
                WP_CLI::warning('Failed to sync viewkey to ValKey');
            }
        }

        // Output
        if ($copyOnly) {
            if (empty($viewkey)) {
                WP_CLI::error('No viewkey configured');
            } else {
                WP_CLI::line($viewkey);
            }
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Environment Gate ===');
        WP_CLI::line('');
        WP_CLI::line('Site ID:     ' . $site_id);
        WP_CLI::line('Environment: ' . $environment);
        WP_CLI::line('');

        if ($environment === 'production') {
            WP_CLI::line('Status: Environment gate is INACTIVE (production)');
            WP_CLI::line('');
            WP_CLI::line('The environment gate only activates for non-production environments.');
            WP_CLI::line('Change metadata.environment in your config to enable it.');
        } else {
            $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

            WP_CLI::line('Status: Environment gate is ACTIVE');
            WP_CLI::line('');

            if (empty($viewkey)) {
                WP_CLI::warning('No viewkey configured!');
                WP_CLI::line('');
                WP_CLI::line('Anonymous visitors will see the gate screen but cannot enter a viewkey.');
                WP_CLI::line('Generate one with: wp ' . $prefix . ' viewkey --regenerate');
            } else {
                WP_CLI::line('Viewkey: ' . $viewkey);
                WP_CLI::line('');
                WP_CLI::line('Share this viewkey with clients to preview the site without WordPress login.');
                WP_CLI::line('');
                WP_CLI::line('Preview URL: ' . home_url('/?viewkey=' . $viewkey));
            }
        }

        WP_CLI::line('');
    }

    /**
     * Show current DTAP environment and detection logic
     *
     * ## EXAMPLES
     *
     *     wp <prefix> environment
     *
     * @when after_wp_load
     */
    public function environment($args, $assoc_args)
    {
        WP_CLI::line('');
        WP_CLI::line('=== gTemplate DTAP Environment ===');
        WP_CLI::line('');

        // Load config
        $config = \gTemplate\load_registration_config();
        $config_env = $config['metadata']['environment'] ?? null;

        // WordPress environment
        $wp_env = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'not defined';

        // Auto-detect from domain
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $detected_env = 'production'; // default

        if (strpos($domain, 'test') !== false || strpos($domain, 'dev') !== false || strpos($domain, 'local') !== false) {
            $detected_env = 'testing';
        } elseif (strpos($domain, 'staging') !== false) {
            $detected_env = 'staging';
        } elseif (strpos($domain, 'accept') !== false || strpos($domain, 'uat') !== false) {
            $detected_env = 'acceptance';
        }

        // Determine effective environment
        $effective_env = $config_env ?? ($wp_env !== 'not defined' ? $wp_env : $detected_env);

        // Map WP environment types to DTAP
        if ($effective_env === 'development' || $effective_env === 'local') {
            $effective_env = 'testing';
        }

        WP_CLI::line('Domain:              ' . $domain);
        WP_CLI::line('');
        WP_CLI::line('Detection Sources:');
        WP_CLI::line('  Config file:       ' . ($config_env ?? '(not set)'));
        WP_CLI::line('  WP_ENVIRONMENT:    ' . $wp_env);
        WP_CLI::line('  Domain detection:  ' . $detected_env);
        WP_CLI::line('');
        WP_CLI::line('Effective Environment: ' . strtoupper($effective_env));
        WP_CLI::line('');

        // Show DTAP info
        $dtap_info = [
            'testing' => 'Development/feature testing - Environment gate ACTIVE',
            'staging' => 'Pre-release validation - Environment gate ACTIVE',
            'acceptance' => 'UAT/client approval - Environment gate ACTIVE',
            'production' => 'Live production site - Environment gate INACTIVE',
        ];

        WP_CLI::line('DTAP Environments:');
        foreach ($dtap_info as $env => $desc) {
            $marker = ($env === $effective_env) ? ' <- CURRENT' : '';
            WP_CLI::line(sprintf('  %-12s %s%s', strtoupper($env), $desc, $marker));
        }

        WP_CLI::line('');

        // gNode stream info
        WP_CLI::line('gNode Stream Namespace: {' . $effective_env . '}:gnode:unified:default');
        WP_CLI::line('');
    }
}

// Register CLI command using the theme prefix dynamically
$gtemplate_cli_command = function_exists('gtemplate_get_theme_prefix')
    ? gtemplate_get_theme_prefix()
    : 'gtemplate';
WP_CLI::add_command($gtemplate_cli_command, 'GtemplateCLI');
