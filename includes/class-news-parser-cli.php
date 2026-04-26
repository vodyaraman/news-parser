<?php
if (!defined('WP_CLI') || !WP_CLI) return;

require_once NEWS_PARSER_PLUGIN_DIR . 'includes/class-news-parser-cli.php';

class News_Parser_CLI
{

    /**
     * Запустить парсер
     *
     * ## EXAMPLES
     *      wp parser start
     */
    public function start()
    {
        WP_CLI::line('Starting parser...');
        update_option('news_parser_paused', false);
        do_action('news_parser_cron_job');
        WP_CLI::success('Parser started');
    }

    /**
     * Остановить парсер
     *
     * ## EXAMPLES
     *      wp parser stop
     */
    public function stop()
    {
        WP_CLI::line('Stopping parser...');
        update_option('news_parser_paused', true);
        delete_transient('news_parser_running');
        WP_CLI::success('Parser stopped');
    }

    /**
     * Получить статус парсера
     *
     * ## EXAMPLES
     *      wp parser status
     */
    public function status()
    {
        $sources = get_option('news_sources', array());
        $is_paused = get_option('news_parser_paused', false);
        $is_running = get_transient('news_parser_running');

        WP_CLI::line('Parser status:');
        WP_CLI::line('Running: ' . ($is_running ? 'Yes' : 'No'));
        WP_CLI::line('Paused: ' . ($is_paused ? 'Yes' : 'No'));
        WP_CLI::line("\nSources:");

        foreach ($sources as $url => $data) {
            WP_CLI::line("- {$url}");
            WP_CLI::line("  Status: {$data['status']}");
            WP_CLI::line("  Posts: " . get_option("news_parser_{$url}_posts_count", 0));
            WP_CLI::line("  Last processed: " . ($data['last_processed'] ?? 'Never'));
            WP_CLI::line("");
        }
    }

    /**
     * Очистить логи
     *
     * ## EXAMPLES
     *      wp parser clear-logs
     */
    public function clear_logs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';

        $wpdb->query("TRUNCATE TABLE $table_name");
        WP_CLI::success('Logs cleared');
    }

    /**
     * Добавить новый источник
     *
     * ## OPTIONS
     *
     * <url>
     * : URL WordPress сайта для парсинга
     *
     * [--date=<date>]
     * : Дата начала парсинга (YYYY-MM-DD)
     *
     * ## EXAMPLES
     *      wp parser add-source https://example.com --date=2024-01-01
     */
    public function add_source($args, $assoc_args)
    {
        $url = $args[0];
        $date = isset($assoc_args['date']) ? $assoc_args['date'] : date('Y-m-d');

        $sources = get_option('news_sources', array());

        $sources[$url] = array(
            'status' => 'not_started',
            'start_date' => $date . ' 00:00:00',
            'last_processed' => null,
            'error_count' => 0,
            'post_type' => $assoc_args['post_type'] ?? 'post',
        );

        update_option('news_sources', $sources);
        WP_CLI::success("Source added: {$url}");
    }
}

WP_CLI::add_command('parser', 'News_Parser_CLI');