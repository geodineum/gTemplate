<?php
declare(strict_types=1);
/**
 * gTemplate WP-CLI AI optimization commands (aio_*).
 *
 * Extracted from inc/cli/class-gtemplate-cli.php in Commit 1.10.d
 *. Composed via PHP trait — UX preserved verbatim.
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GtemplateCLI_AIO
{
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
}
