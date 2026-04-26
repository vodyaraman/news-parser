<?php
if (!defined('ABSPATH')) exit;

// Get current status messages
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

// Get plugin options
$sources = get_option('news_sources', []);
$is_paused = get_option('news_parser_paused', false);
$enable_paraphrase = get_option('news_parser_enable_paraphrase', true);
?>

<div class="wrap news-parser-admin">
    <h1 class="wp-heading-inline"><?php _e('News Parser Settings', 'news-site-parser'); ?></h1>
    
    <?php 
    // Display notifications
    if ($status === 'saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Source added successfully!', 'news-site-parser'); ?></p>
        </div>
    <?php elseif ($status === 'settings_saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully!', 'news-site-parser'); ?></p>
        </div>
    <?php elseif ($error === 'invalid_url'): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e('Invalid URL. Make sure it is a valid WordPress REST API endpoint.', 'news-site-parser'); ?></p>
        </div>
    <?php elseif ($error === 'missing_fields'): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e('Please fill in all required fields.', 'news-site-parser'); ?></p>
        </div>
    <?php elseif ($error === 'invalid_api'): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e('Error connecting to API. Please check the URL and try again.', 'news-site-parser'); ?></p>
        </div>
    <?php endif; ?>

    <!-- Add New Source Form -->
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
            </table>

            <?php submit_button(__('Add Source', 'news-site-parser')); ?>
        </form>
    </div>
    <!-- Active Sources List -->
    <div class="card">
        <h2><?php _e('Active Sources', 'news-site-parser'); ?></h2>
        
        <table class="wp-list-table widefat fixed striped" id="parser-sources-table">
            <thead>
                <tr>
                    <th><?php _e('Source URL', 'news-site-parser'); ?></th>
                    <th><?php _e('Start Date', 'news-site-parser'); ?></th>
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

        <!-- Parser Controls -->
        <div class="parser-controls">
            <button id="start-parser" class="button button-primary">
                <?php _e('Start Parser', 'news-site-parser'); ?>
            </button>
            <button id="pause-parser" class="button" <?php echo empty($sources) ? 'disabled' : ''; ?>>
                <?php echo $is_paused ? __('Resume', 'news-site-parser') : __('Pause', 'news-site-parser'); ?>
            </button>
        </div>

        <!-- Parser Status -->
        <div class="parser-status">
            <?php if ($is_paused): ?>
                <span class="status-indicator paused"></span>
                <span class="status-text"><?php _e('Parser is paused', 'news-site-parser'); ?></span>
            <?php else: ?>
                <span class="status-indicator <?php echo get_transient('news_parser_running') ? 'running' : 'idle'; ?>"></span>
                <span class="status-text">
                    <?php echo get_transient('news_parser_running') 
                        ? __('Parser is running', 'news-site-parser')
                        : __('Parser is ready', 'news-site-parser'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>


</div>