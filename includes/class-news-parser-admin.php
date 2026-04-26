<?php
if (!defined('ABSPATH')) {
    exit;
}

class News_Parser_Admin {
    /**
     * The main plugin instance
     * @var News_Site_Parser
     */
    private $main;

    /**
     * Initialize the admin class
     * @param News_Site_Parser $main Main plugin instance
     */
    public function __construct($main) {
        $this->main = $main;
        $this->init_hooks();

        // Добавляем хук для метабокса
        add_action('add_meta_boxes', array($this, 'add_parser_meta_box'));
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Admin menu and pages
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX handlers
        add_action('wp_ajax_get_parser_status', array($this, 'ajax_get_parser_status'));
        add_action('wp_ajax_start_parser', array($this, 'ajax_start_parser'));
        add_action('wp_ajax_pause_parser', array($this, 'ajax_pause_parser'));
        add_action('wp_ajax_resume_parser', array($this, 'ajax_resume_parser'));
        add_action('wp_ajax_delete_news_source', array($this, 'ajax_delete_news_source'));
        add_action('wp_ajax_reset_news_source', array($this, 'ajax_reset_news_source'));
        add_action('wp_ajax_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_test_spinnerchief', array($this, 'ajax_test_spinnerchief'));
        add_action('wp_ajax_restore_original_content', array($this, 'ajax_restore_original_content'));
        add_action('wp_ajax_reparaphrase_content', array($this, 'ajax_reparaphrase_content'));
        add_action('wp_ajax_update_category_mapping', array($this, 'ajax_update_category_mapping'));
        add_action('wp_ajax_test_chatgpt', array($this, 'ajax_test_chatgpt'));
        add_action('admin_post_save_chatgpt_settings', array($this, 'handle_save_chatgpt_settings'));

        // Admin post handlers
        add_action('admin_post_save_news_sources', array($this, 'handle_save_news_sources'));
        add_action('admin_post_save_parser_settings', array($this, 'handle_save_parser_settings'));

        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            __('News Parser Settings', 'news-site-parser'),
            __('News Parser', 'news-site-parser'),
            'manage_options',
            'news-parser-settings',
            array($this, 'render_settings_page'),
            'dashicons-rss',
            100
        );

        add_submenu_page(
            'news-parser-settings',
            __('Category Mapping', 'news-site-parser'),
            __('Category Mapping', 'news-site-parser'),
            'manage_options',
            'news-parser-category-mapping',
            array($this, 'render_category_mapping_page')
        );

        add_submenu_page(
            'news-parser-settings',
            __('Social Networks', 'news-site-parser'),
            __('Social Networks', 'news-site-parser'),
            'manage_options',
            'news-parser-social',
            array($this, 'render_social_settings_page')
        );

        add_submenu_page(
            'news-parser-settings',
            __('Parser Logs', 'news-site-parser'),
            __('Logs', 'news-site-parser'),
            'manage_options',
            'news-parser-logs',
            array($this, 'render_logs_page')
        );
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array(
            'toplevel_page_news-parser-settings',
            'news-parser_page_news-parser-logs',
            'news-parser_page_news-parser-category-mapping'
        ))) {
            return;
        }

        // Отображаем уведомления об успехе
        if (isset($_GET['status'])) {
            $message = '';
            $type = 'success';

            switch ($_GET['status']) {
                case 'saved':
                    $message = __('News source added successfully.', 'news-site-parser');
                    break;
                case 'settings_saved':
                    $message = __('Parser settings saved successfully.', 'news-site-parser');
                    break;
                case 'source_deleted':
                    $message = __('Source deleted successfully.', 'news-site-parser');
                    break;
                case 'source_reset':
                    $message = __('Source reset successfully.', 'news-site-parser');
                    break;
                case 'categories_updated':
                    $message = __('Categories updated successfully.', 'news-site-parser');
                    break;
                case 'mapping_saved':
                    $message = __('Category mapping saved successfully.', 'news-site-parser');
                    break;
            }

            if ($message) {
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr($type),
                    esc_html($message)
                );
            }
        }

        // Отображаем уведомления об ошибках
        if (isset($_GET['error'])) {
            $message = '';
            
            switch ($_GET['error']) {
                case 'invalid_url':
                    $message = __('Invalid URL. Please ensure it is a valid WordPress REST API endpoint.', 'news-site-parser');
                    break;
                case 'missing_fields':
                    $message = __('Please fill in all required fields.', 'news-site-parser');
                    break;
                case 'invalid_date':
                    $message = __('Invalid date format.', 'news-site-parser');
                    break;
                case 'invalid_api':
                    $message = __('Error connecting to API. Please check the URL and try again.', 'news-site-parser');
                    break;
                case 'permission_denied':
                    $message = __('You do not have sufficient permissions to perform this action.', 'news-site-parser');
                    break;
                case 'invalid_nonce':
                    $message = __('Security check failed. Please try again.', 'news-site-parser');
                    break;
            }

            if ($message) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html($message)
                );
            }
        }

        // Отображаем системные уведомления
        if (get_transient('news_parser_system_notice')) {
            $notice = get_transient('news_parser_system_notice');
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
            delete_transient('news_parser_system_notice');
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Получаем текущие статусы и настройки
        $sources = get_option('news_sources', array());
        $is_paused = get_option('news_parser_paused', false);
        $enable_paraphrase = get_option('news_parser_enable_paraphrase', true);
        $batch_size = get_option('news_parser_batch_size', 10);
        $stats = $this->main->get_statistics();
        ?>

        <div class="wrap news-parser-admin">
            <h1 class="wp-heading-inline"><?php _e('News Parser Settings', 'news-site-parser'); ?></h1>
            
            <!-- Общая статистика -->
            <div class="card parser-stats">
                <h2><?php _e('Parser Statistics', 'news-site-parser'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Sources:', 'news-site-parser'); ?></span>
                        <span class="stat-value"><?php echo esc_html($stats['total_sources']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Active Sources:', 'news-site-parser'); ?></span>
                        <span class="stat-value"><?php echo esc_html($stats['active_sources']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Posts:', 'news-site-parser'); ?></span>
                        <span class="stat-value"><?php echo esc_html($stats['total_posts']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Форма добавления нового источника -->
            <div class="card">
                <h2><?php _e('Add New Source', 'news-site-parser'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="news-parser-form">
                    <input type="hidden" name="action" value="save_news_sources">
                    <?php wp_nonce_field('save_news_sources', 'news_parser_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="news_source_url"><?php _e('Source URL', 'news-site-parser'); ?></label>
                            </th>
                            <td>
                                <input type="url" 
                                    name="news_source_url" 
                                    id="news_source_url" 
                                    class="regular-text" 
                                    placeholder="https://example.com/wp-json/wp/v2/posts"
                                    required>
                                <p class="description">
                                    <?php _e('Enter WordPress REST API endpoint URL (usually ends with /wp-json/wp/v2/posts)', 'news-site-parser'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="news_parser_start_date"><?php _e('Start Date', 'news-site-parser'); ?></label>
                            </th>
                            <td>
                                <input type="datetime-local" 
                                    name="news_parser_start_date" 
                                    id="news_parser_start_date" 
                                    required>
                                <p class="description">
                                    <?php _e('Posts published after this date will be imported', 'news-site-parser'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="news_parser_post_type"><?php _e('Post Type', 'news-site-parser'); ?></label>
                            </th>
                            <td>
                                <input
                                        name="news_parser_post_type"
                                        id="news_parser_post_type"
                                        value="post"
                                        required>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Add Source', 'news-site-parser')); ?>
                </form>
            </div>

            <!-- Список активных источников -->
            <div class="card">
                <h2><?php _e('Active Sources', 'news-site-parser'); ?></h2>
                
                <table class="wp-list-table widefat fixed striped" id="parser-sources-table">
                    <thead>
                        <tr>
                            <th><?php _e('Source URL', 'news-site-parser'); ?></th>
                            <th><?php _e('Start Date', 'news-site-parser'); ?></th>
                            <th><?php _e('Post Type', 'news-site-parser'); ?></th>
                            <th><?php _e('Status', 'news-site-parser'); ?></th>
                            <th><?php _e('Current Page', 'news-site-parser'); ?></th>
                            <th><?php _e('Posts Imported', 'news-site-parser'); ?></th>
                            <th><?php _e('Last Processed', 'news-site-parser'); ?></th>
                            <th><?php _e('Actions', 'news-site-parser'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sources)): ?>
                            <tr>
                                <td colspan="7"><?php _e('No sources added.', 'news-site-parser'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sources as $url => $data): ?>
                                <tr data-url="<?php echo esc_attr($url); ?>">
                                    <td><?php echo esc_html($url); ?></td>
                                    <td class="start-date">
                                        <?php echo esc_html(date_i18n(
                                            get_option('date_format') . ' ' . get_option('time_format'),
                                            strtotime($data['start_date'])
                                        )); ?>
                                    </td>
                                    <td><?=esc_html($data['post_type'])?></td>
                                    <td class="status">
                                        <span class="status-badge status-<?php echo esc_attr($data['status']); ?>">
                                            <?php 
                                            $statuses = array(
                                                'not_started' => __('Not Started', 'news-site-parser'),
                                                'in_progress' => __('In Progress', 'news-site-parser'),
                                                'waiting' => __('Waiting', 'news-site-parser'),
                                                'completed' => __('Completed', 'news-site-parser'),
                                                'error' => __('Error', 'news-site-parser')
                                            );
                                            echo isset($statuses[$data['status']]) ? $statuses[$data['status']] : $data['status'];
                                            ?>
                                        </span>
                                    </td>
                                    <td class="page"><?php echo get_option("news_parser_{$url}_page", 1); ?></td>
                                    <td class="posts-count">
                                        <?php echo get_option("news_parser_{$url}_posts_count", 0); ?>
                                    </td>
                                    <td class="last-processed">
                                        <?php 
                                        echo !empty($data['last_processed']) 
                                            ? date_i18n(
                                                get_option('date_format') . ' ' . get_option('time_format'), 
                                                strtotime($data['last_processed'])
                                            )
                                            : __('Never', 'news-site-parser');
                                        ?>
                                    </td>
                                    <td class="actions">
                                        <button class="button reset-source" 
                                                data-url="<?php echo esc_attr($url); ?>">
                                            <?php _e('Reset', 'news-site-parser'); ?>
                                        </button>
                                        <button class="button delete-source" 
                                                data-url="<?php echo esc_attr($url); ?>">
                                            <?php _e('Delete', 'news-site-parser'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Контроль парсера -->
                <div class="parser-controls">
                    <button id="start-parser" class="button button-primary">
                        <?php _e('Start Parser', 'news-site-parser'); ?>
                    </button>
                    <button id="pause-parser" class="button" <?php echo empty($sources) ? 'disabled' : ''; ?>>
                        <?php echo $is_paused ? __('Resume', 'news-site-parser') : __('Pause', 'news-site-parser'); ?>
                    </button>
                </div>

                <!-- Статус парсера -->
                <div class="parser-status">
                    <span class="status-indicator <?php echo $is_paused ? 'paused' : ($this->main->is_running() ? 'running' : 'idle'); ?>"></span>
                    <span class="status-text">
                        <?php 
                        if ($is_paused) {
                            _e('Parser is paused', 'news-site-parser');
                        } elseif ($this->main->is_running()) {
                            _e('Parser is running', 'news-site-parser');
                        } else {
                            _e('Parser is ready', 'news-site-parser');
                        }
                        ?>
                    </span>
                </div>
            </div>

            <!-- Общие настройки -->
            <div class="card">
                <h2><?php _e('General Settings', 'news-site-parser'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="save_parser_settings">
                    <?php wp_nonce_field('save_parser_settings', 'parser_settings_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <?php _e('Batch Size', 'news-site-parser'); ?>
                            </th>
                            <td>
                                <input type="number" 
                                    name="news_parser_batch_size" 
                                    value="<?php echo esc_attr($batch_size); ?>" 
                                    min="1" max="100">
                                <p class="description">
                                    <?php _e('Number of posts to process in each batch', 'news-site-parser'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php _e('Enable Paraphrasing', 'news-site-parser'); ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                        name="news_parser_enable_paraphrase" 
                                        value="1" 
                                        <?php checked($enable_paraphrase); ?>>
                                    <?php _e('Use AI to paraphrase imported content', 'news-site-parser'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', 'news-site-parser')); ?>
                </form>
            </div>

            <!-- ChatGPT Settings -->
            <div class="card">
                <h2><?php _e('ChatGPT Settings', 'news-site-parser'); ?></h2>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('save_chatgpt_settings', 'chatgpt_settings_nonce'); ?>
                    <input type="hidden" name="action" value="save_chatgpt_settings">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="chatgpt_api_key"><?php _e('API Key', 'news-site-parser'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                    name="chatgpt_api_key" 
                                    id="chatgpt_api_key"
                                    value="<?php echo esc_attr(get_option('news_parser_chatgpt_key', '')); ?>" 
                                    class="regular-text"
                                    autocomplete="off">
                                <p class="description">
                                    <?php _e('Enter your ChatGPT API Key', 'news-site-parser'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="chatgpt_model"><?php _e('Model', 'news-site-parser'); ?></label>
                            </th>
                            <td>
                                <select name="chatgpt_model" id="chatgpt_model">
                                    <option value="gpt-4" <?php selected(get_option('news_parser_chatgpt_model', 'gpt-4'), 'gpt-4'); ?>>
                                        GPT-4
                                    </option>
                                    <option value="gpt-3.5-turbo" <?php selected(get_option('news_parser_chatgpt_model', 'gpt-4'), 'gpt-3.5-turbo'); ?>>
                                        GPT-3.5 Turbo
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Select ChatGPT model to use', 'news-site-parser'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php _e('Test Connection', 'news-site-parser'); ?>
                            </th>
                            <td>
                                <button type="button" class="button test-chatgpt">
                                    <?php _e('Test ChatGPT Connection', 'news-site-parser'); ?>
                                </button>
                                <span class="spinner"></span>
                                <span class="test-result"></span>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save ChatGPT Settings', 'news-site-parser')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Clean up old logs keeping only last 1500 entries
     */
    private function cleanup_old_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';
        
        // Оставляем только последние 1500 записей
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$table_name} 
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table_name}
                    ORDER BY created_at DESC
                    LIMIT %d
                ) temp
            )",
            1500
        ));
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Автоматическая очистка старых логов
        $this->cleanup_old_logs();

        // Получаем параметры
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';

        // Подготавливаем условия WHERE
        $where_conditions = array('1=1');
        $where_params = array();

        if (!empty($_GET['log_type'])) {
            $where_conditions[] = 'type = %s';
            $where_params[] = sanitize_text_field($_GET['log_type']);
        }

        if (!empty($_GET['source_url'])) {
            $where_conditions[] = 'source_url = %s';
            $where_params[] = sanitize_text_field($_GET['source_url']);
        }

        if (!empty($_GET['date_from'])) {
            $where_conditions[] = 'created_at >= %s';
            $where_params[] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where_conditions[] = 'created_at <= %s';
            $where_params[] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Получаем общее количество записей
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}",
            $where_params
        );
        $total_items = $wpdb->get_var($query);

        // Получаем логи
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE {$where_clause}
            ORDER BY created_at DESC 
            LIMIT %d OFFSET %d",
            array_merge($where_params, array($per_page, $offset))
        );
        $logs = $wpdb->get_results($query);

        // Получаем уникальные типы и источники для фильтров
        $log_types = $wpdb->get_col("SELECT DISTINCT type FROM {$table_name}");
        $sources = $wpdb->get_col("SELECT DISTINCT source_url FROM {$table_name} WHERE source_url != ''");
        ?>
        <div class="wrap">
            <div class="logs-header">
    <h1 class="wp-heading-inline">
        <?php _e('Parser Logs', 'news-site-parser'); ?>
        <span class="log-count">(<?php echo number_format($total_items); ?> <?php _e('entries', 'news-site-parser'); ?>)</span>
    </h1>
    
    <button id="clear-logs" class="page-title-action">
        <?php _e('Clear Logs', 'news-site-parser'); ?>
    </button>
</div>

<div class="logs-filter">
    <input type="hidden" name="page" value="news-parser-logs">
    
    <select name="log_type">
        <option value=""><?php _e('All Types', 'news-site-parser'); ?></option>
        <?php foreach ($log_types as $type): ?>
            <option value="<?php echo esc_attr($type); ?>"
                <?php selected(isset($_GET['log_type']) ? $_GET['log_type'] : '', $type); ?>>
                <?php echo esc_html(ucfirst($type)); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!empty($sources)): ?>
    <select name="source_url">
        <option value=""><?php _e('All Sources', 'news-site-parser'); ?></option>
        <?php foreach ($sources as $source): ?>
            <option value="<?php echo esc_attr($source); ?>"
                <?php selected(isset($_GET['source_url']) ? $_GET['source_url'] : '', $source); ?>>
                <?php echo esc_html(parse_url($source, PHP_URL_HOST)); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <input type="date" name="date_from" 
           value="<?php echo isset($_GET['date_from']) ? esc_attr($_GET['date_from']) : ''; ?>"
           placeholder="<?php _e('From', 'news-site-parser'); ?>">
           
    <input type="date" name="date_to"
           value="<?php echo isset($_GET['date_to']) ? esc_attr($_GET['date_to']) : ''; ?>"
           placeholder="<?php _e('To', 'news-site-parser'); ?>">

    <?php submit_button(__('Filter', 'news-site-parser'), 'secondary', 'filter', false); ?>
</div>

            <?php if (empty($logs)): ?>
                <p><?php _e('No logs found.', 'news-site-parser'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Time', 'news-site-parser'); ?></th>
                            <th><?php _e('Type', 'news-site-parser'); ?></th>
                            <th><?php _e('Source', 'news-site-parser'); ?></th>
                            <th><?php _e('Message', 'news-site-parser'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    strtotime($log->created_at)
                                )); ?></td>
                                <td>
                                    <span class="log-type log-type-<?php echo esc_attr($log->type); ?>">
                                        <?php echo esc_html(ucfirst($log->type)); ?>
                                    </span>
                                </td>
                                <td><?php echo $log->source_url ? esc_html(parse_url($log->source_url, PHP_URL_HOST)) : ''; ?></td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                $total_pages = ceil($total_items / $per_page);
                echo '<div class="tablenav bottom">';
                echo '<div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $page,
                    'add_args' => array_filter(array(
                        'log_type' => isset($_GET['log_type']) ? $_GET['log_type'] : null,
                        'source_url' => isset($_GET['source_url']) ? $_GET['source_url'] : null,
                        'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : null,
                        'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : null
                    ))
                ));
                echo '</div>';
                echo '</div>';
                ?>
            <?php endif; ?>
        </div>

        <style>
        .log-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        .log-type-info { background: #e5f5fa; color: #0286c2; }
        .log-type-error { background: #fae7e7; color: #dc3232; }
        .log-type-warning { background: #fff8e5; color: #dba617; }
        .log-type-success { background: #ecf7ed; color: #46b450; }
        .log-count {
            color: #666;
            font-size: 13px;
            font-weight: normal;
        }
        .logs-filter {
            margin: 8px 0;
        }
        .logs-filter select,
        .logs-filter input[type="date"] {
            margin-right: 10px;
        }
        </style>
        <?php
    }

    /**
     * Add parser meta box to post editor
     */
    public function add_parser_meta_box() {
        add_meta_box(
            'news_parser_controls',
            __('News Parser Info', 'news-site-parser'),
            array($this, 'render_parser_meta_box'),
            'post', // todo more post types ??
            'side',
            'high'
        );
    }


    /**
     * Get imported categories with statistics
     * @param bool $with_stats Whether to include post counts and additional stats
     * @return array Categories data
     */
    /**
 * Get imported categories with statistics
 */
private function get_imported_categories($with_stats = false) {
    global $wpdb;
    
    $categories = array();
    
    // Получаем все записи original_terms из мета-полей с одним запросом
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.meta_value, p.ID 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = %s 
        AND p.post_status = 'publish'
        ORDER BY p.post_date DESC",
        'original_terms'
    ));

    foreach ($results as $row) {
        $terms = json_decode($row->meta_value, true);
        if (!empty($terms['category'])) {
            foreach ($terms['category'] as $term) {
                $term_name = $term['name'];
                
                if ($with_stats) {
                    if (!isset($categories[$term_name])) {
                        $categories[$term_name] = array(
                            'slug' => $term['slug'],
                            'count' => 1,
                            'term_id' => $term['id'],
                            'description' => isset($term['description']) ? $term['description'] : '',
                            'posts' => array($row->ID)
                        );
                    } else {
                        $categories[$term_name]['count']++;
                        $categories[$term_name]['posts'][] = $row->ID;
                    }
                } else {
                    if (!isset($categories[$term_name])) {
                        $categories[$term_name] = array(
                            'slug' => $term['slug'],
                            'term_id' => $term['id']
                        );
                    }
                }
            }
        }
    }

    // Получаем текущий маппинг для статистики
    if ($with_stats) {
        $current_mapping = get_option('news_parser_category_mapping', array());
        foreach ($categories as $name => &$data) {
            $data['is_mapped'] = isset($current_mapping[$name]);
            $data['mapped_to'] = isset($current_mapping[$name]) ? 
                get_cat_name($current_mapping[$name]) : null;
            $data['mapped_to_id'] = isset($current_mapping[$name]) ? 
                $current_mapping[$name] : null;
        }
    }

    return $categories;
}

    /**
     * Sanitize batch size
     * Made public for WordPress settings API
     * 
     * @param mixed $value Value to sanitize
     * @return int Sanitized value
     */
    public function sanitize_batch_size($value) {
        $value = absint($value);
        return max(1, min(100, $value));
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Batch size
        register_setting('news_parser_settings', 'news_parser_batch_size', array(
            'type' => 'integer',
            'default' => 10,
            'sanitize_callback' => array($this, 'sanitize_batch_size')
        ));

        // Error logging
        register_setting('news_parser_settings', 'news_parser_error_logging', array(
            'type' => 'boolean',
            'default' => true
        ));

        // Paraphrase settings
        register_setting('news_parser_settings', 'news_parser_enable_paraphrase', array(
            'type' => 'boolean',
            'default' => true
        ));

        // ChatGPT settings
        register_setting('news_parser_settings', 'news_parser_chatgpt_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));

        register_setting('news_parser_settings', 'news_parser_chatgpt_model', array(
            'type' => 'string',
            'default' => 'gpt-4',
            'sanitize_callback' => 'sanitize_text_field'
        ));

        // Category settings
        register_setting('news_parser_settings', 'news_parser_category_mapping', array(
            'type' => 'array',
            'default' => array()
        ));
    }

    /**
     * Sanitize category mapping
     */
    public function sanitize_category_mapping($mapping) {
        if (!is_array($mapping)) {
            return array();
        }

        $sanitized = array();
        foreach ($mapping as $source => $target) {
            $sanitized[sanitize_text_field($source)] = absint($target);
        }

        return $sanitized;
    }
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array(
            'toplevel_page_news-parser-settings',
            'news-parser_page_news-parser-logs',
            'news-parser_page_news-parser-category-mapping'
        ))) {
            return;
        }

        wp_enqueue_style(
            'news-parser-admin',
            NEWS_PARSER_PLUGIN_URL . 'css/admin.css',
            array(),
            NEWS_PARSER_VERSION
        );

        wp_enqueue_script(
            'news-parser-admin',
            NEWS_PARSER_PLUGIN_URL . 'js/admin.js',
            array('jquery'),
            NEWS_PARSER_VERSION,
            true
        );

        wp_localize_script('news-parser-admin', 'newsParserAjax', array(
            'nonce' => wp_create_nonce('news_parser_ajax_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this source?', 'news-site-parser'),
                'confirm_reset' => __('Are you sure you want to reset this source?', 'news-site-parser'),
                'confirm_clear_logs' => __('Are you sure you want to clear all logs?', 'news-site-parser'),
                'confirm_reparaphrase' => __('Are you sure you want to reparaphrase this content?', 'news-site-parser'),
                'confirm_restore' => __('Are you sure you want to restore original content?', 'news-site-parser'),
                'confirm_bulk_update' => __('Are you sure you want to update all posts? This might take a while.', 'news-site-parser')
            ),
            'ajax_url' => admin_url('admin-ajax.php')
        ));
    }

    /**
     * AJAX: Update category mapping
     */
    public function ajax_update_category_mapping() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        $mapping = isset($_POST['mapping']) ? $_POST['mapping'] : array();
        $sanitized_mapping = $this->sanitize_category_mapping($mapping);
        
        update_option('news_parser_category_mapping', $sanitized_mapping);

        // Обновляем категории для существующих постов если запрошено
        if (isset($_POST['update_existing']) && $_POST['update_existing']) {
            $updated = $this->bulk_update_post_categories();
            wp_send_json_success(array(
                'message' => sprintf(__('Mapping saved and %d posts updated.', 'news-site-parser'), $updated),
                'updated_posts' => $updated
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Category mapping saved successfully.', 'news-site-parser')
            ));
        }
    }

    /**
     * AJAX: Get parser status
     */
    public function ajax_get_parser_status() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $sources = get_option('news_sources', array());
        $statuses = array();
        $is_paused = (bool)get_option('news_parser_paused', false);
        $is_running = (bool)get_transient('news_parser_running');

        foreach ($sources as $url => $data) {
            $statuses[] = array(
                'url' => $url,
                'status' => $data['status'],
                'page' => get_option("news_parser_{$url}_page", 1),
                'posts_count' => get_option("news_parser_{$url}_posts_count", 0),
                'last_processed' => isset($data['last_processed']) ? 
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), 
                    strtotime($data['last_processed'])) : 'Never'
            );
        }

        wp_send_json_success(array(
            'statuses' => $statuses,
            'is_paused' => $is_paused,
            'is_running' => $is_running,
            'stats' => $this->main->get_statistics()
        ));
    }

    /**
     * Get category mapping statistics
     */
    private function get_category_mapping_stats() {
    $mapping = get_option('news_parser_category_mapping', array());
    $imported_cats = $this->get_imported_categories(true);
    
    $unmapped_count = 0;
    $mapped_count = 0;
    $posts_count = 0;

    foreach ($imported_cats as $cat) {
        $posts_count += $cat['count'];
        if (isset($cat['is_mapped']) && $cat['is_mapped']) {
            $mapped_count++;
        } else {
            $unmapped_count++;
        }
    }

    return array(
        'total_imported' => count($imported_cats),
        'mapped' => $mapped_count,
        'unmapped' => $unmapped_count,
        'total_posts' => $posts_count,
        'categories' => $imported_cats
    );
}


    /**
     * Count posts that need category update
     */
    private function count_posts_needing_category_update() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = 'original_terms'
            AND p.post_status = 'publish'
        ");
        
        return (int)$count;
    }

    /**
     * Create missing categories automatically
     */
    private function create_missing_categories() {
        $imported_cats = $this->get_imported_categories();
        $current_mapping = get_option('news_parser_category_mapping', array());
        $created = 0;
        $skipped = 0;
        $errors = array();
        
        foreach ($imported_cats as $cat_name => $cat_data) {
            if (!isset($current_mapping[$cat_name])) {
                $new_cat = wp_insert_term(
                    $cat_name, 
                    'category', 
                    array('slug' => sanitize_title($cat_data['slug']))
                );

                if (is_wp_error($new_cat)) {
                    $errors[] = sprintf(
                        __('Error creating category "%s": %s', 'news-site-parser'),
                        $cat_name,
                        $new_cat->get_error_message()
                    );
                    $skipped++;
                } else {
                    $current_mapping[$cat_name] = $new_cat['term_id'];
                    $created++;
                }
            }
        }
        
        if ($created > 0) {
            update_option('news_parser_category_mapping', $current_mapping);
        }
        
        return array(
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }

    /**
 * Save category mapping
 */
private function save_category_mapping($mapping, $update_posts = false) {
    if (!is_array($mapping)) {
        return false;
    }

    $sanitized_mapping = array();
    foreach ($mapping as $source => $target) {
        if (!empty($target)) {
            $sanitized_mapping[sanitize_text_field($source)] = absint($target);
        }
    }

    update_option('news_parser_category_mapping', $sanitized_mapping);

    if ($update_posts) {
        $result = $this->bulk_update_post_categories();
        return $result;
    }

    return true;
}
    /**
     * Bulk update post categories based on mapping
     */
    private function bulk_update_post_categories() {
    global $wpdb;
    
    $mapping = get_option('news_parser_category_mapping', array());
    $updated = 0;
    $errors = array();
    $processed = array();

    // Получаем импортированные категории с ID постов
    $categories = $this->get_imported_categories(true);
    
    foreach ($categories as $source_cat => $cat_data) {
        if (isset($mapping[$source_cat]) && !empty($cat_data['posts'])) {
            $target_cat_id = $mapping[$source_cat];
            
            foreach ($cat_data['posts'] as $post_id) {
                if (!in_array($post_id, $processed)) {
                    $current_cats = wp_get_post_categories($post_id);
                    if (!in_array($target_cat_id, $current_cats)) {
                        $current_cats[] = $target_cat_id;
                        $result = wp_set_post_categories($post_id, $current_cats);
                        
                        if (!is_wp_error($result)) {
                            $updated++;
                            $processed[] = $post_id;
                            update_post_meta($post_id, '_category_mapping_updated', current_time('mysql'));
                        } else {
                            $errors[] = sprintf(
                                'Failed to update categories for post ID: %d - %s',
                                $post_id,
                                $result->get_error_message()
                            );
                        }
                    }
                }
            }
        }
    }

    return array(
        'updated' => $updated,
        'errors' => $errors,
        'processed' => count($processed)
    );
}

    /**
     * Get source post categories for metabox
     */
    private function get_post_source_categories($post_id) {
        $original_terms = get_post_meta($post_id, 'original_terms', true);
        if (empty($original_terms)) {
            return array();
        }

        $terms = json_decode($original_terms, true);
        if (empty($terms['category'])) {
            return array();
        }

        return $terms['category'];
    }

    /**
     * Render post metabox
     */
    public function render_parser_meta_box($post) {
        // Проверяем, является ли пост импортированным
        $original_content = get_post_meta($post->ID, 'original_content', true);
        if (!$original_content) {
            echo '<p>' . __('This post was not imported by News Parser.', 'news-site-parser') . '</p>';
            return;
        }

        wp_nonce_field('news_parser_meta_box', 'news_parser_meta_box_nonce');

        // Получаем информацию об источнике
        $source_url = get_post_meta($post->ID, 'source_url', true);
        $original_url = get_post_meta($post->ID, 'original_url', true);
        $import_date = get_post_meta($post->ID, '_import_date', true);
        $source_categories = $this->get_post_source_categories($post->ID);
        ?>

        <div class="news-parser-meta">
            <div class="meta-section">
                <h4><?php _e('Source Information', 'news-site-parser'); ?></h4>
                <p>
                    <strong><?php _e('Source Site:', 'news-site-parser'); ?></strong>
                    <?php echo esc_html(parse_url($source_url, PHP_URL_HOST)); ?>
                </p>
                <?php if ($original_url): ?>
                <p>
                    <strong><?php _e('Original URL:', 'news-site-parser'); ?></strong>
                    <a href="<?php echo esc_url($original_url); ?>" target="_blank">
                        <?php _e('View Original', 'news-site-parser'); ?>
                    </a>
                </p>
                <?php endif; ?>
                <?php if ($import_date): ?>
                <p>
                    <strong><?php _e('Imported:', 'news-site-parser'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import_date))); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if (!empty($source_categories)): ?>
            <div class="meta-section">
                <h4><?php _e('Original Categories', 'news-site-parser'); ?></h4>
                <ul>
                    <?php foreach ($source_categories as $cat): ?>
                        <li><?php echo esc_html($cat['name']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="meta-section">
                <h4><?php _e('Actions', 'news-site-parser'); ?></h4>
                <button type="button" class="button restore-original" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php _e('Restore Original Content', 'news-site-parser'); ?>
                </button>
                <button type="button" class="button reparaphrase-content" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php _e('Reparaphrase Content', 'news-site-parser'); ?>
                </button>
                <button type="button" class="button update-categories" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php _e('Update Categories', 'news-site-parser'); ?>
                </button>
                <div class="parser-status"></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Restore original content
     */
    public function ajax_restore_original_content() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID', 'news-site-parser'));
        }
        
        $original_content = get_post_meta($post_id, 'original_content', true);
        $original_title = get_post_meta($post_id, 'original_title', true);
        
        if (!$original_content || !$original_title) {
            wp_send_json_error(__('Original content not found', 'news-site-parser'));
        }
        
        $updated = wp_update_post(array(
            'ID' => $post_id,
            'post_title' => wp_strip_all_tags($original_title),
            'post_content' => $original_content
        ), true);
        
        if (is_wp_error($updated)) {
            wp_send_json_error($updated->get_error_message());
        }
        
        wp_send_json_success(__('Content restored to original', 'news-site-parser'));
    }
    /**
     * Render category mapping page with enhanced functionality
     */
    public function render_category_mapping_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Проверяем и обрабатываем действия
        $message = '';
        $message_type = 'info';

        if (isset($_POST['bulk_update_categories']) && check_admin_referer('bulk_update_categories')) {
            $result = $this->bulk_update_post_categories();
            $message = sprintf(
                __('Updated %d posts. %s', 'news-site-parser'),
                $result['updated'],
                !empty($result['errors']) ? ' Errors: ' . implode(', ', $result['errors']) : ''
            );
            $message_type = !empty($result['errors']) ? 'warning' : 'success';
        }

        if (isset($_POST['create_missing_categories']) && check_admin_referer('create_missing_categories')) {
            $result = $this->create_missing_categories();
            $message = sprintf(
                __('Created %d categories, skipped %d. %s', 'news-site-parser'),
                $result['created'],
                $result['skipped'],
                !empty($result['errors']) ? ' Errors: ' . implode(', ', $result['errors']) : ''
            );
            $message_type = !empty($result['errors']) ? 'warning' : 'success';
        }

        // Получаем данные для отображения
        $current_mapping = get_option('news_parser_category_mapping', array());
        $site_categories = get_categories(array('hide_empty' => false));
        $imported_categories = $this->get_imported_categories(true);
        $mapping_stats = $this->get_category_mapping_stats();

        // Отображаем сообщение если есть
        if ($message) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($message_type),
                esc_html($message)
            );
        }
        ?>

        <div class="wrap news-parser-admin">
            <h1 class="wp-heading-inline"><?php _e('Category Mapping', 'news-site-parser'); ?></h1>

            <!-- Статистика -->
            <div class="category-mapping-stats card">
                <h2><?php _e('Mapping Statistics', 'news-site-parser'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Total Categories:', 'news-site-parser'); ?></span>
                        <span class="stat-value"><?php echo esc_html($mapping_stats['total_imported']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Mapped:', 'news-site-parser'); ?></span>
                        <span class="stat-value success"><?php echo esc_html($mapping_stats['mapped']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Unmapped:', 'news-site-parser'); ?></span>
                        <span class="stat-value warning"><?php echo esc_html($mapping_stats['unmapped']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Posts to Update:', 'news-site-parser'); ?></span>
                        <span class="stat-value">
                            <?php echo isset($mapping_stats['posts_to_update']) ? esc_html($mapping_stats['posts_to_update']) : __('N/A', 'news-site-parser'); ?>
                        </span>
                    </div>

                </div>
            </div>

            <!-- Действия -->
            <div class="category-mapping-actions card">
                <h2><?php _e('Bulk Actions', 'news-site-parser'); ?></h2>
                <div class="action-buttons">
                    <form method="post" class="inline-form">
                        <?php wp_nonce_field('create_missing_categories'); ?>
                        <button type="submit" name="create_missing_categories" class="button">
                            <?php _e('Create Missing Categories', 'news-site-parser'); ?>
                        </button>
                    </form>

                    <form method="post" class="inline-form">
                        <?php wp_nonce_field('bulk_update_categories'); ?>
                        <button type="submit" name="bulk_update_categories" class="button">
                            <?php _e('Update All Posts Categories', 'news-site-parser'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Таблица маппинга -->
            <form method="post" action="" class="category-mapping-form card">
                <?php wp_nonce_field('save_category_mapping'); ?>
                
                <h2><?php _e('Category Mapping', 'news-site-parser'); ?></h2>
                
                <table class="wp-list-table widefat fixed striped category-mapping-table">
                    <thead>
                        <tr>
                            <th class="column-source"><?php _e('Source Category', 'news-site-parser'); ?></th>
                            <th class="column-count"><?php _e('Posts', 'news-site-parser'); ?></th>
                            <th class="column-target"><?php _e('Target Category', 'news-site-parser'); ?></th>
                            <th class="column-status"><?php _e('Status', 'news-site-parser'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($imported_categories)): ?>
                            <tr>
                                <td colspan="4"><?php _e('No categories found in imported posts.', 'news-site-parser'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($imported_categories as $source_cat => $cat_data): ?>
                                <tr>
                                    <td class="column-source"><?php echo esc_html($source_cat); ?></td>
                                    <td class="column-count"><?php echo esc_html($cat_data['count']); ?></td>
                                    <td class="column-target">
                                        <select name="category_mapping[<?php echo esc_attr($source_cat); ?>]" 
                                                class="category-select" 
                                                data-source="<?php echo esc_attr($source_cat); ?>">
                                            <option value=""><?php _e('-- Select Category --', 'news-site-parser'); ?></option>
                                            <?php foreach ($site_categories as $site_cat): ?>
                                                <option value="<?php echo esc_attr($site_cat->term_id); ?>"
                                                    <?php selected(isset($current_mapping[$source_cat]) ? $current_mapping[$source_cat] : '', $site_cat->term_id); ?>>
                                                    <?php echo esc_html($site_cat->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="column-status">
                                        <?php if (isset($current_mapping[$source_cat])): ?>
                                            <span class="status-badge mapped">
                                                <?php _e('Mapped', 'news-site-parser'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge unmapped">
                                                <?php _e('Not Mapped', 'news-site-parser'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="mapping-actions">
                    <label class="update-posts-checkbox">
                        <input type="checkbox" name="update_existing_posts" value="1">
                        <?php _e('Update existing posts with new mapping', 'news-site-parser'); ?>
                    </label>
                    
                    <?php submit_button(__('Save Mapping', 'news-site-parser'), 'primary', 'save_category_mapping'); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle saving news sources
     */
    public function handle_save_news_sources() {
        if (!current_user_can('manage_options') || !check_admin_referer('save_news_sources', 'news_parser_nonce')) {
            wp_die(__('You do not have permission to perform this action.', 'news-site-parser'));
        }

        $url = isset($_POST['news_source_url']) ? esc_url_raw($_POST['news_source_url']) : '';
        $start_date = isset($_POST['news_parser_start_date']) ? sanitize_text_field($_POST['news_parser_start_date']) : '';

        if (empty($url) || empty($start_date)) {
            wp_redirect(add_query_arg('error', 'missing_fields', admin_url('admin.php?page=news-parser-settings')));
            exit;
        }

        // Форматируем URL правильно
        $url = rtrim($url, '/');
        if (strpos($url, '/wp-json/wp/v2/posts') === false) {
            $url .= '/wp-json/wp/v2/posts';
        }

        // Форматируем дату
        try {
            $date = new DateTime($start_date);
            $formatted_date = $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            wp_redirect(add_query_arg('error', 'invalid_date', admin_url('admin.php?page=news-parser-settings')));
            exit;
        }

        // Проверяем API конечной точки
        $test_url = add_query_arg('per_page', '1', $url);
        $response = wp_remote_get($test_url, array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'User-Agent' => 'curl/7.81.0',
            ],
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_redirect(add_query_arg('error', 'invalid_api', admin_url('admin.php?page=news-parser-settings')));
            exit;
        }

        // Получаем текущие источники
        $sources = get_option('news_sources', array());
        if (!is_array($sources)) {
            $sources = array();
        }

        // Добавляем новый источник
        $sources[$url] = array(
            'status' => 'not_started',
            'start_date' => $formatted_date,
            'last_processed' => null,
            'error_count' => 0,
            'post_type' => sanitize_text_field($_POST['news_parser_post_type'] ?? 'post'),
        );

        // Сохраняем источники
        update_option('news_sources', $sources);

        // Инициализируем опции источника
        update_option("news_parser_{$url}_page", 1);
        update_option("news_parser_{$url}_posts_count", 0);
        update_option("news_parser_{$url}_last_processed", null);

        // Логируем добавление источника
        $this->log("Added new source: $url, Start date: $formatted_date");

        // Перенаправляем на страницу настроек с сообщением об успехе
        wp_redirect(add_query_arg('status', 'saved', admin_url('admin.php?page=news-parser-settings')));
        exit;
    }

    /**
 * Handle saving parser settings
 */
public function handle_save_parser_settings() {
    if (!current_user_can('manage_options') || !check_admin_referer('save_parser_settings', 'parser_settings_nonce')) {
        wp_die(__('You do not have permission to perform this action.', 'news-site-parser'));
    }

    // Batch size settings
    $batch_size = isset($_POST['news_parser_batch_size']) ? absint($_POST['news_parser_batch_size']) : 10;
    $batch_size = max(1, min(100, $batch_size));
    update_option('news_parser_batch_size', $batch_size);

    // Paraphrase settings
    $enable_paraphrase = isset($_POST['news_parser_enable_paraphrase']);
    update_option('news_parser_enable_paraphrase', $enable_paraphrase);

    // ChatGPT settings
    if (isset($_POST['chatgpt_api_key'])) {
        $api_key = sanitize_text_field($_POST['chatgpt_api_key']);
        if (!empty($api_key)) {
            update_option('news_parser_chatgpt_key', $api_key);
        }
        error_log('Saving ChatGPT API key: ' . substr($api_key, 0, 5) . '...');
        update_option('news_parser_chatgpt_key', $api_key);
    }

    if (isset($_POST['chatgpt_model'])) {
        $model = sanitize_text_field($_POST['chatgpt_model']);
        if (in_array($model, array('gpt-4', 'gpt-3.5-turbo'))) {
            update_option('news_parser_chatgpt_model', $model);
        }
    }

    // Default author settings
    if (isset($_POST['default_author'])) {
        $author_id = absint($_POST['default_author']);
        if ($author_id > 0) {
            update_option('news_parser_default_author', $author_id);
        }
    }

    // Error logging settings
    $error_logging = isset($_POST['news_parser_error_logging']);
    update_option('news_parser_error_logging', $error_logging);

    // Auto cleanup settings
    if (isset($_POST['logs_retention_days'])) {
        $retention_days = absint($_POST['logs_retention_days']);
        if ($retention_days > 0) {
            update_option('news_parser_logs_retention_days', $retention_days);
        }
    }

    // Custom timeout settings
    if (isset($_POST['request_timeout'])) {
        $timeout = absint($_POST['request_timeout']);
        if ($timeout >= 30 && $timeout <= 300) {
            update_option('news_parser_request_timeout', $timeout);
        }
    }

    // Custom User Agent
    if (isset($_POST['custom_user_agent'])) {
        $user_agent = sanitize_text_field($_POST['custom_user_agent']);
        update_option('news_parser_custom_user_agent', $user_agent);
    }

    // Image processing settings
    $process_images = isset($_POST['news_parser_process_images']);
    update_option('news_parser_process_images', $process_images);

    if (isset($_POST['image_max_width'])) {
        $max_width = absint($_POST['image_max_width']);
        if ($max_width > 0) {
            update_option('news_parser_image_max_width', $max_width);
        }
    }

    // Category settings
    $default_category = isset($_POST['default_category']) ? absint($_POST['default_category']) : 0;
    update_option('news_parser_default_category', $default_category);

    // Logging settings
    if (isset($_POST['log_level'])) {
        $log_level = sanitize_text_field($_POST['log_level']);
        if (in_array($log_level, array('debug', 'info', 'warning', 'error'))) {
            update_option('news_parser_log_level', $log_level);
        }
    }

    // Custom fields to import
    if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
        $custom_fields = array_map('sanitize_text_field', $_POST['custom_fields']);
        update_option('news_parser_custom_fields', $custom_fields);
    }

    // Логируем обновление настроек
    $this->log('Parser settings updated');

    // Добавляем сообщение об успехе
    add_settings_error(
        'news_parser_messages',
        'settings_updated',
        __('Settings saved successfully.', 'news-site-parser'),
        'updated'
    );

    // Перенаправляем на страницу настроек с сообщением об успехе
    wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
    exit;
}

    /**
     * AJAX: Pause and resume parser
     */
    public function ajax_pause_parser() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        update_option('news_parser_paused', true);
        delete_transient('news_parser_running');
        
        wp_send_json_success(__('Parser paused successfully', 'news-site-parser'));
    }

    public function ajax_resume_parser() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        update_option('news_parser_paused', false);
        wp_schedule_single_event(time(), 'news_parser_cron_job');
        
        wp_send_json_success(__('Parser resumed successfully', 'news-site-parser'));
    }

    /**
     * AJAX: Delete news source
     */
    public function ajax_delete_news_source() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : '';
        if (empty($source_url)) {
            wp_send_json_error(__('Invalid source URL', 'news-site-parser'));
        }

        $sources = get_option('news_sources', array());
        if (!isset($sources[$source_url])) {
            wp_send_json_error(__('Source not found', 'news-site-parser'));
        }

        // Удаляем источник
        unset($sources[$source_url]);
        update_option('news_sources', $sources);

        // Удаляем связанные опции
        delete_option("news_parser_{$source_url}_page");
        delete_option("news_parser_{$source_url}_posts_count");
        delete_option("news_parser_{$source_url}_skips");
        delete_option("news_parser_{$source_url}_last_processed");

        // Логируем действие
        $this->log("Source deleted: $source_url", 'info');

        wp_send_json_success(array(
            'message' => __('Source deleted successfully', 'news-site-parser'),
            'stats' => $this->main->get_statistics()
        ));
    }

    /**
     * AJAX: Reset news source
     */
    public function ajax_reset_news_source() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : '';
        if (empty($source_url)) {
            wp_send_json_error(__('Invalid source URL', 'news-site-parser'));
        }

        $sources = get_option('news_sources', array());
        if (!isset($sources[$source_url])) {
            wp_send_json_error(__('Source not found', 'news-site-parser'));
        }

        // Сбрасываем статус источника
        $sources[$source_url]['status'] = 'not_started';
        $sources[$source_url]['error_count'] = 0;
        update_option('news_sources', $sources);

        // Сбрасываем связанные опции
        update_option("news_parser_{$source_url}_page", 1);
        update_option("news_parser_{$source_url}_posts_count", 0);
        delete_option("news_parser_{$source_url}_skips");
        delete_option("news_parser_{$source_url}_last_processed");

        // Логируем действие
        $this->log("Source reset: $source_url", 'info');

        wp_send_json_success(array(
            'message' => __('Source reset successfully', 'news-site-parser'),
            'stats' => $this->main->get_statistics()
        ));
    }

    /**
     * AJAX: Start parser
     */
    public function ajax_start_parser() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        // Очищаем флаг паузы
        update_option('news_parser_paused', false);
        
        // Устанавливаем флаг запуска
        set_transient('news_parser_running', true, 3600);
        
        // Запускаем парсер немедленно
        wp_schedule_single_event(time(), 'news_parser_cron_job');

        wp_send_json_success(array(
            'message' => __('Parser started successfully', 'news-site-parser'),
            'stats' => $this->main->get_statistics()
        ));
    }

    /**
     * Handle AJAX clear logs request
     */
    public function ajax_clear_logs() {
        check_ajax_referer('news_parser_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'news-site-parser'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'news_parser_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            wp_send_json_success(__('Logs cleared successfully', 'news-site-parser'));
        } else {
            wp_send_json_error(__('Error clearing logs', 'news-site-parser'));
        }
    }

    public function ajax_test_chatgpt() {
    check_ajax_referer('news_parser_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'news-site-parser'));
    }

    $api_key = get_option('news_parser_chatgpt_key', '');
    // Добавляем логирование
    error_log('Testing ChatGPT connection. API key exists: ' . (!empty($api_key) ? 'yes' : 'no'));

    if (empty($api_key)) {
        wp_send_json_error(__('API key is not set', 'news-site-parser'));
        return;
    }

    // Тестовый запрос к API
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array(
            'model' => get_option('news_parser_chatgpt_model', 'gpt-4'),
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Test connection'
                )
            ),
            'max_tokens' => 10
        )),
        'timeout' => 15
    ));

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body) || isset($body['error'])) {
        wp_send_json_error($body['error']['message'] ?? 'Invalid response from API');
    }

    wp_send_json_success(__('ChatGPT connection successful!', 'news-site-parser'));
}

    /**
 * Add social networks settings page
 */
public function add_social_settings_page() {
    add_submenu_page(
        'news-parser-settings',
        __('Social Networks', 'news-site-parser'),
        __('Social Networks', 'news-site-parser'),
        'manage_options',
        'news-parser-social',
        array($this, 'render_social_settings_page')
    );
}

/**
 * Render social networks settings page
 */
public function render_social_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Сохраняем настройки
    if (isset($_POST['save_social_settings']) && check_admin_referer('save_social_settings')) {
        $this->save_social_settings();
    }

    // Получаем текущие настройки
    $telegram_token = get_option('news_parser_telegram_token', '');
    $telegram_channel = get_option('news_parser_telegram_channel', '');
    $instagram_token = get_option('news_parser_instagram_token', '');
    $instagram_user_id = get_option('news_parser_instagram_user_id', '');
    $auto_posting = get_option('news_parser_auto_posting', array());

    ?>
    <div class="wrap">
        <h1><?php _e('Social Networks Settings', 'news-site-parser'); ?></h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved successfully!', 'news-site-parser'); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('save_social_settings'); ?>
            
            <!-- Telegram Settings -->
            <div class="card">
                <h2><span class="dashicons dashicons-telegram"></span> <?php _e('Telegram Settings', 'news-site-parser'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="telegram_token"><?php _e('Bot Token', 'news-site-parser'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="telegram_token" name="telegram_token" 
                                   class="regular-text" value="<?php echo esc_attr($telegram_token); ?>">
                            <p class="description">
                                <?php _e('Enter your Telegram Bot Token from @BotFather', 'news-site-parser'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="telegram_channel"><?php _e('Channel ID', 'news-site-parser'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="telegram_channel" name="telegram_channel" 
                                   class="regular-text" value="<?php echo esc_attr($telegram_channel); ?>">
                            <p class="description">
                                <?php _e('Enter your Telegram Channel ID (e.g., @yourchannel)', 'news-site-parser'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Test Connection', 'news-site-parser'); ?>
                        </th>
                        <td>
                            <button type="button" class="button test-telegram" 
                                    <?php echo empty($telegram_token) || empty($telegram_channel) ? 'disabled' : ''; ?>>
                                <?php _e('Test Telegram Connection', 'news-site-parser'); ?>
                            </button>
                            <span class="spinner"></span>
                            <span class="test-result"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Instagram Settings -->
            <div class="card">
                <h2><span class="dashicons dashicons-instagram"></span> <?php _e('Instagram Settings', 'news-site-parser'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="instagram_token"><?php _e('Access Token', 'news-site-parser'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="instagram_token" name="instagram_token" 
                                   class="regular-text" value="<?php echo esc_attr($instagram_token); ?>">
                            <p class="description">
                                <?php _e('Enter your Instagram Graph API Access Token', 'news-site-parser'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="instagram_user_id"><?php _e('User ID', 'news-site-parser'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="instagram_user_id" name="instagram_user_id" 
                                   class="regular-text" value="<?php echo esc_attr($instagram_user_id); ?>">
                            <p class="description">
                                <?php _e('Enter your Instagram Business Account ID', 'news-site-parser'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Test Connection', 'news-site-parser'); ?>
                        </th>
                        <td>
                            <button type="button" class="button test-instagram" 
                                    <?php echo empty($instagram_token) || empty($instagram_user_id) ? 'disabled' : ''; ?>>
                                <?php _e('Test Instagram Connection', 'news-site-parser'); ?>
                            </button>
                            <span class="spinner"></span>
                            <span class="test-result"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Auto Posting Settings -->
            <div class="card">
                <h2><?php _e('Auto Posting Settings', 'news-site-parser'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php _e('Enable Auto Posting', 'news-site-parser'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_posting[]" value="telegram" 
                                       <?php checked(in_array('telegram', $auto_posting)); ?>>
                                <?php _e('Post to Telegram', 'news-site-parser'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="auto_posting[]" value="instagram" 
                                       <?php checked(in_array('instagram', $auto_posting)); ?>>
                                <?php _e('Post to Instagram', 'news-site-parser'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Post Status', 'news-site-parser'); ?>
                        </th>
                        <td>
                            <div class="social-status">
                                <div class="status-item">
                                    <span class="label"><?php _e('Telegram:', 'news-site-parser'); ?></span>
                                    <span class="count"><?php echo get_option('news_parser_telegram_posts_count', 0); ?></span>
                                    <?php _e('posts sent', 'news-site-parser'); ?>
                                </div>
                                <div class="status-item">
                                    <span class="label"><?php _e('Instagram:', 'news-site-parser'); ?></span>
                                    <span class="count"><?php echo get_option('news_parser_instagram_posts_count', 0); ?></span>
                                    <?php _e('posts published', 'news-site-parser'); ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <input type="submit" name="save_social_settings" class="button-primary" 
                       value="<?php _e('Save Settings', 'news-site-parser'); ?>">
            </p>
        </form>
    </div>

    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .card h2 {
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .test-result {
            margin-left: 10px;
            display: inline-block;
        }
        .test-success {
            color: #46b450;
        }
        .test-error {
            color: #dc3232;
        }
        .social-status {
            display: flex;
            gap: 20px;
        }
        .status-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
        }
        .status-item .count {
            font-weight: bold;
            color: #2271b1;
        }
    </style>
    <?php
}

/**
 * Save ChatGPT settings
 */
public function handle_save_chatgpt_settings() {
    if (!current_user_can('manage_options') || !check_admin_referer('save_chatgpt_settings', 'chatgpt_settings_nonce')) {
        wp_die(__('You do not have permission to perform this action.', 'news-site-parser'));
    }

    // Save API key
    if (isset($_POST['chatgpt_api_key'])) {
        $api_key = sanitize_text_field($_POST['chatgpt_api_key']);
        update_option('news_parser_chatgpt_key', $api_key);
    }

    // Save model
    if (isset($_POST['chatgpt_model'])) {
        $model = sanitize_text_field($_POST['chatgpt_model']);
        if (in_array($model, array('gpt-4', 'gpt-3.5-turbo'))) {
            update_option('news_parser_chatgpt_model', $model);
        }
    }

    // Добавляем сообщение об успехе
    add_settings_error(
        'news_parser_messages',
        'settings_updated',
        __('ChatGPT settings saved successfully.', 'news-site-parser'),
        'updated'
    );

    // Перенаправляем обратно на страницу настроек
    wp_redirect(add_query_arg(
        array(
            'page' => 'news-parser-settings',
            'settings-updated' => 'true'
        ), 
        admin_url('admin.php')
    ));
    exit;
}

/**
 * Save social network settings
 */
private function save_social_settings() {
    // Telegram settings
    if (isset($_POST['telegram_token'])) {
        update_option('news_parser_telegram_token', sanitize_text_field($_POST['telegram_token']));
    }
    if (isset($_POST['telegram_channel'])) {
        update_option('news_parser_telegram_channel', sanitize_text_field($_POST['telegram_channel']));
    }

    // Instagram settings
    if (isset($_POST['instagram_token'])) {
        update_option('news_parser_instagram_token', sanitize_text_field($_POST['instagram_token']));
    }
    if (isset($_POST['instagram_user_id'])) {
        update_option('news_parser_instagram_user_id', sanitize_text_field($_POST['instagram_user_id']));
    }

    // Auto posting settings
    $auto_posting = isset($_POST['auto_posting']) ? (array)$_POST['auto_posting'] : array();
    update_option('news_parser_auto_posting', $auto_posting);

    // Add message
    add_settings_error(
        'news_parser_messages',
        'settings_updated',
        __('Settings saved successfully.', 'news-site-parser'),
        'updated'
    );

    // Redirect back
    wp_redirect(add_query_arg('settings-updated', 'true'));
    exit;
}

/**
 * Test Telegram connection
 */
public function ajax_test_telegram() {
    check_ajax_referer('news_parser_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'news-site-parser'));
    }

    $social = new News_Parser_Social();
    $result = $social->test_telegram_connection();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(__('Telegram connection successful!', 'news-site-parser'));
}

/**
 * Test Instagram connection
 */
public function ajax_test_instagram() {
    check_ajax_referer('news_parser_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'news-site-parser'));
    }

    $social = new News_Parser_Social();
    $result = $social->test_instagram_connection();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(__('Instagram connection successful!', 'news-site-parser'));
}

/**
 * Handle social network errors
 */
private function handle_social_error($error, $network) {
    if (is_wp_error($error)) {
        $message = $error->get_error_message();
    } else {
        $message = __('Unknown error occurred', 'news-site-parser');
    }

    $this->log(sprintf(
        'Error posting to %s: %s',
        $network,
        $message
    ), 'error');

    return false;
}

    /**
     * Log a message
     */
    private function log($message, $type = 'info', $source_url = '') {
        if ($this->main) {
            $this->main->log($message, $type, $source_url);
        }
    }

    
}