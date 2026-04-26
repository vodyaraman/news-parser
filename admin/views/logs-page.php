<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Parser Logs', 'news-site-parser'); ?></h1>
    
    <button id="clear-logs" class="page-title-action">
        <?php _e('Clear Logs', 'news-site-parser'); ?>
    </button>

    <?php
    // Get current page number
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;
    $offset = ($page - 1) * $per_page;

    // Get logs from database
    global $wpdb;
    $table_name = $wpdb->prefix . 'news_parser_logs';

    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_items / $per_page);

    // Get logs with pagination
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );
    ?>

    <?php if (empty($logs)): ?>
        <p><?php _e('No logs found.', 'news-site-parser'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Time', 'news-site-parser'); ?></th>
                    <th><?php _e('Source', 'news-site-parser'); ?></th>
                    <th><?php _e('Type', 'news-site-parser'); ?></th>
                    <th><?php _e('Message', 'news-site-parser'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html(
                            date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($log->created_at)
                            )
                        ); ?></td>
                        <td><?php echo esc_html($log->source_url); ?></td>
                        <td>
                            <span class="log-type log-type-<?php echo esc_attr($log->type); ?>">
                                <?php echo esc_html($log->type); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->message); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Add pagination
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $total_pages,
            'current' => $page
        ));
        echo '</div>';
        echo '</div>';
        ?>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('#clear-logs').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to clear all logs?', 'news-site-parser'); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'clear_logs',
                    nonce: '<?php echo wp_create_nonce('news_parser_ajax_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data);
                    }
                }
            });
        }
    });
});
</script>