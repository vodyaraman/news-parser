<?php
/*
Plugin Name: News Site Parser
Description: Parsing news from other WordPress sites with SpinnerChief API integration
Version: 1.7.2
Author: Ulugbek Yuldoshev
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: news-site-parser
Domain Path: /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NEWS_PARSER_VERSION', '1.7.0');
define('NEWS_PARSER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NEWS_PARSER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NEWS_PARSER_PLUGIN_FILE', __FILE__);
define('SPINNERCHIEF_API_KEY', '48443a44bc174a538086657b3c708b6');
define('SPINNERCHIEF_DEV_KEY', 'api2d6f4cc5c81c43d3a');

class News_Site_Parser {
    /**
     * Admin class instance
     * @var News_Parser_Admin
     */
    private $admin;

    /**
     * Processor class instance
     * @var News_Parser_Processor
     */
    private $processor;

    /**
     * The single instance of the class
     * @var News_Site_Parser
     */
    protected static $_instance = null;

    /**
     * Initialize the plugin
     */
    public function __construct() {
        $this->init_hooks();
        $this->include_files();
        $this->init_components();

        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Plugin activation/deactivation
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));

        // Plugin initialization
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
        add_action('init', array($this, 'init'));

        // Cron job hooks
        add_action('news_parser_cron_job', array($this, 'process_news_sources'));
        
        // Add cleanup schedule
        if (!wp_next_scheduled('news_parser_cleanup')) {
            wp_schedule_event(time(), 'daily', 'news_parser_cleanup');
        }
        add_action('news_parser_cleanup', array($this, 'cleanup_old_logs'));
    }

    /**
     * Include required files
     */
    private function include_files() {
        require_once NEWS_PARSER_PLUGIN_DIR . 'includes/class-news-parser-admin.php';
        require_once NEWS_PARSER_PLUGIN_DIR . 'includes/class-news-parser-processor.php';
        require_once NEWS_PARSER_PLUGIN_DIR . 'includes/class-news-parser-social.php';
        
        if (defined('WP_CLI') && WP_CLI) {
            require_once NEWS_PARSER_PLUGIN_DIR . 'includes/class-news-parser-cli.php';
        }
    }

    /**
     * Initialize components
     */
    private function init_components() {
        $this->admin = new News_Parser_Admin($this);
        $this->processor = new News_Parser_Processor($this);
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'news-site-parser')
        );
        return $schedules;
    }

    /**
     * Plugin activation
     */
    public function activate_plugin() {
        // Clear any existing scheduled tasks
        wp_clear_scheduled_hook('news_parser_cron_job');
        wp_clear_scheduled_hook('news_parser_cleanup');
        
        // Schedule new tasks
        if (!wp_next_scheduled('news_parser_cron_job')) {
            wp_schedule_event(time(), 'every_minute', 'news_parser_cron_job');
        }
        if (!wp_next_scheduled('news_parser_cleanup')) {
            wp_schedule_event(time(), 'daily', 'news_parser_cleanup');
        }

        // Set default options
        update_option('news_parser_paused', false);
        update_option('news_parser_enable_paraphrase', true);
        update_option('news_parser_batch_size', 5);
        update_option('news_parser_max_errors', 3);
        delete_transient('news_parser_running');

        // Create logs table
        $this->create_logs_table();
    }

    /**
     * Create logs table
     */
    private function create_logs_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_url varchar(255) NOT NULL,
            message text NOT NULL,
            type varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_url (source_url),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get parser statistics
     */
    public function get_statistics() {
        $sources = get_option('news_sources', array());
        $stats = array(
            'total_sources' => 0,
            'active_sources' => 0,
            'completed_sources' => 0,
            'error_sources' => 0,
            'total_posts' => 0,
            'is_running' => $this->is_running(),
            'is_paused' => $this->is_paused(),
            'last_update' => current_time('mysql')
        );

        foreach ($sources as $url => $source_data) {
            // Увеличиваем общее количество источников
            $stats['total_sources']++;

            // Подсчитываем статусы источников
            switch ($source_data['status']) {
                case 'in_progress':
                case 'monitoring':
                    $stats['active_sources']++;
                    break;
                case 'completed':
                    $stats['completed_sources']++;
                    break;
                case 'error':
                    $stats['error_sources']++;
                    break;
            }

            // Добавляем количество постов для этого источника
            $posts_count = intval(get_option("news_parser_{$url}_posts_count", 0));
            $stats['total_posts'] += $posts_count;
        }

        // Добавляем дополнительную статистику
        $stats['success_rate'] = $stats['total_sources'] > 0 
            ? round(($stats['completed_sources'] / $stats['total_sources']) * 100, 2)
            : 0;

        $stats['average_posts_per_source'] = $stats['total_sources'] > 0 
            ? round($stats['total_posts'] / $stats['total_sources'], 2)
            : 0;

        return $stats;
    }

    /**
     * Plugin deactivation
     */
    public function deactivate_plugin() {
        wp_clear_scheduled_hook('news_parser_cron_job');
        wp_clear_scheduled_hook('news_parser_cleanup');
        delete_transient('news_parser_running');
    }

    /**
     * Load plugin text domain
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'news-site-parser',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Initialize plugin
     */
    public function init() {
        if (get_option('news_parser_version') !== NEWS_PARSER_VERSION) {
            $this->update_plugin();
        }
    }

    /**
     * Update plugin
     */
    private function update_plugin() {
        update_option('news_parser_version', NEWS_PARSER_VERSION);
    }

    /**
     * Process news sources
     */
    public function process_news_sources() {
        // Если парсер на паузе, пропускаем
        if (get_option('news_parser_paused', false)) {
            $this->log("Parser is paused");
            delete_transient('news_parser_running');
            return;
        }

        // Проверяем флаг выполнения
        if (get_transient('news_parser_running')) {
            $this->log("Another parser instance is running");
            return;
        }

        $this->log("Starting parser...");

        // Устанавливаем флаг выполнения
        set_transient('news_parser_running', true, 3600);

        try {
            $sources = get_option('news_sources', array());
            if (!is_array($sources)) {
                throw new Exception("Sources not found");
            }

            foreach ($sources as $source_url => &$source_data) {
                if ($source_data['status'] === 'completed'/* || $source_data['status'] === 'error'*/) {
                    continue;
                }

                $this->log("Processing source: $source_url");
                
                // Обрабатываем источник
                $result = $this->processor->process_source($source_url, $source_data);
                
                if (!$result) {
                    $source_data['error_count'] = isset($source_data['error_count']) ? $source_data['error_count'] + 1 : 1;
                    if ($source_data['error_count'] >= 3) {
                        $source_data['status'] = 'error';
                    }
                }

                // Сохраняем обновленные данные после каждого источника
                update_option('news_sources', $sources);

                // Удаляем флаг выполнения после обработки каждого источника
                delete_transient('news_parser_running');
            }

        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage(), 'error');
        }

        // Удаляем флаг выполнения
        delete_transient('news_parser_running');
    }

    /**
     * Cleanup old logs
     */
    public function cleanup_old_logs($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Log a message
     */
    public function log($message, $type = 'info', $source_url = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';

        $wpdb->insert(
            $table_name,
            array(
                'source_url' => $source_url,
                'message' => $message,
                'type' => $type,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[News Parser] $message");
        }

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::line("$type: $message ($source_url)");
        }
    }

    /**
     * Get admin instance
     */
    public function get_admin() {
        return $this->admin;
    }

    /**
     * Get processor instance
     */
    public function get_processor() {
        return $this->processor;
    }

    /**
     * Get plugin version
     */
    public function get_version() {
        return NEWS_PARSER_VERSION;
    }

    /**
     * Check if parser is running
     */
    public function is_running() {
        return (bool)get_transient('news_parser_running');
    }

    /**
     * Check if parser is paused
     */
    public function is_paused() {
        return get_option('news_parser_paused', false);
    }

    /**
     * Force unlock parser
     */
    public function force_unlock() {
        delete_transient('news_parser_running');
        $this->log("Parser forcefully unlocked");
    }

    /**
     * Main plugin instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Delete all plugin data
     */
    public static function delete_plugin_data() {
        global $wpdb;

        // Delete options
        $options_to_delete = array(
            'news_parser_version',
            'news_parser_paused',
            'news_sources',
            'news_parser_enable_paraphrase',
            'news_parser_batch_size',
            'news_parser_max_errors'
        );

        foreach ($options_to_delete as $option) {
            delete_option($option);
        }
        
        // Delete all parser-related options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'news_parser_%'");

        // Delete logs table
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}news_parser_logs");

        // Clear scheduled tasks
        wp_clear_scheduled_hook('news_parser_cron_job');
        wp_clear_scheduled_hook('news_parser_cleanup');
        delete_transient('news_parser_running');
    }
}

// Initialize plugin
function run_news_site_parser() {
    return News_Site_Parser::instance();
}

// Start the plugin
run_news_site_parser();

add_action('add_meta_boxes', function () {
    add_meta_box(
        'original_guid_metabox',          // ID мета-бокса
        'Original GUID',                  // Название
        'render_original_guid_metabox',   // Callback
        'post',                           // Где выводить (post type)
        'side',                           // Расположение
        'default'                         // Приоритет
    );
});

function render_original_guid_metabox($post) {
    $original_guid = get_post_meta($post->ID, 'original_post_guid', true);

    if (!empty($original_guid)) {
        // Экранируем атрибуты для безопасности
        $url = esc_url($original_guid);
        echo '<a href="' . $url . '" target="_blank" rel="noreferrer">' . esc_html($url) . '</a>';
    } else {
        echo '<em>No original GUID found.</em>';
    }
}