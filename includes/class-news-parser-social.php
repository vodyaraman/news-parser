<?php
if (!defined('ABSPATH')) exit;

class News_Parser_Social {
    /**
     * Telegram Bot API
     */
    const TELEGRAM_API_URL = 'https://api.telegram.org/bot';
    const INSTAGRAM_GRAPH_API_VERSION = 'v22.0';
    private $telegram_bot_token;
    private $telegram_channel_id;
    private $instagram_access_token;
    private $instagram_user_id;

    /**
     * Initialize social networks integration
     */
    public function __construct() {
        $this->telegram_bot_token = get_option('news_parser_telegram_token', '');
        $this->telegram_channel_id = get_option('news_parser_telegram_channel', '');
        $this->instagram_access_token = get_option('news_parser_instagram_token', '');
        $this->instagram_user_id = get_option('news_parser_instagram_user_id', '');
    }

    /**
     * Post to Telegram channel
     */
    public function post_to_telegram($post_id) {
        if (empty($this->telegram_bot_token) || empty($this->telegram_channel_id)) {
            return new WP_Error('telegram_config', 'Telegram configuration is missing');
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        $title = esc_html($post->post_title);
        $excerpt_plain = wp_strip_all_tags($post->post_content);
        $excerpt = esc_html($this->trim_with_ellipsis($excerpt_plain, 50));
        $permalink = esc_url(get_permalink($post_id));

        $message = "<b>{$title}</b>\n\n";
        $message .= "{$excerpt}\n\n";
        $message .= "👉 <a href=\"{$permalink}\">Читать полностью</a>";

        $image_url = get_the_post_thumbnail_url($post_id, 'large');

        if ($image_url) {
            return $this->send_telegram_photo($image_url, $message);
        } else {
            return $this->send_telegram_message($message);
        }
    }

    /**
     * Post to Instagram
     */
    public function post_to_instagram($post_id) {
        if (empty($this->instagram_access_token) || empty($this->instagram_user_id)) {
            return new WP_Error('instagram_config', 'Instagram configuration is missing');
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Получаем изображение
        $image_url = get_the_post_thumbnail_url($post_id, 'large');
        if (!$image_url) {
            return new WP_Error('no_image', 'No image found for Instagram post');
        }

        if (stripos($image_url, 'https://') !== 0) {
            return new WP_Error('invalid_image_url', 'Instagram requires a publicly accessible HTTPS image URL');
        }

        $caption = $post->post_title . "\n\n";
        $caption .= $this->trim_with_ellipsis(wp_strip_all_tags($post->post_content), 30);
        $caption .= "\n\n👉 Подробнее на сайте (ссылка в био)";

        $tags = get_the_tags($post_id);
        if ($tags) {
            $hashtags = array_map(function($tag) {
                return '#' . str_replace(' ', '', $tag->name);
            }, $tags);
            $caption .= "\n\n" . implode(' ', $hashtags);
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($caption) > 2200) {
                $caption = mb_substr($caption, 0, 2199) . '…';
            }
        } elseif (strlen($caption) > 2200) {
            $caption = substr($caption, 0, 2199) . '…';
        }

        return $this->create_instagram_post($image_url, $caption);
    }

    private function trim_with_ellipsis($text, $words_limit) {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) <= $words_limit) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $words_limit)) . '…';
    }

    private function wait_instagram_container_ready($creation_id) {
        $status_url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=status_code,status&access_token=%s',
            self::INSTAGRAM_GRAPH_API_VERSION,
            rawurlencode($creation_id),
            rawurlencode($this->instagram_access_token)
        );

        for ($i = 0; $i < 6; $i++) {
            $status_response = wp_remote_get($status_url, array('timeout' => 20));
            if (is_wp_error($status_response)) {
                return $status_response;
            }

            $status_body = json_decode(wp_remote_retrieve_body($status_response), true);
            $status_code = strtoupper((string)($status_body['status_code'] ?? $status_body['status'] ?? ''));

            if ($status_code === 'FINISHED') {
                return true;
            }

            if (in_array($status_code, array('ERROR', 'EXPIRED'), true)) {
                $error_message = $status_body['error']['message'] ?? 'Instagram container processing failed';
                return new WP_Error('instagram_container_status', $error_message);
            }

            sleep(2);
        }

        return new WP_Error('instagram_container_timeout', 'Instagram container is not ready for publishing yet');
    }


    /**
     * Check Telegram connection and permissions
     */
    public function test_telegram_connection() {
        if (empty($this->telegram_bot_token) || empty($this->telegram_channel_id)) {
            return new WP_Error('telegram_config', 'Telegram configuration is missing');
        }

        $url = self::TELEGRAM_API_URL . $this->telegram_bot_token . '/getMe';
        $response = wp_remote_get($url, array('timeout' => 20));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            return new WP_Error('telegram_error', $body['description'] ?? 'Failed to verify Telegram bot token');
        }

        $check_chat_url = self::TELEGRAM_API_URL . $this->telegram_bot_token . '/getChat';
        $check_chat_response = wp_remote_post($check_chat_url, array(
            'body' => array('chat_id' => $this->telegram_channel_id),
            'timeout' => 20
        ));

        if (is_wp_error($check_chat_response)) {
            return $check_chat_response;
        }

        $check_chat_body = json_decode(wp_remote_retrieve_body($check_chat_response), true);
        if (empty($check_chat_body['ok'])) {
            return new WP_Error('telegram_chat_error', $check_chat_body['description'] ?? 'Failed to access Telegram channel');
        }

        return true;
    }

    /**
     * Check Instagram connection and account access
     */
    public function test_instagram_connection() {
        if (empty($this->instagram_access_token) || empty($this->instagram_user_id)) {
            return new WP_Error('instagram_config', 'Instagram configuration is missing');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=id,username&access_token=%s',
            self::INSTAGRAM_GRAPH_API_VERSION,
            rawurlencode($this->instagram_user_id),
            rawurlencode($this->instagram_access_token)
        );

        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['error'])) {
            return new WP_Error('instagram_error', $body['error']['message'] ?? 'Failed to verify Instagram credentials');
        }

        if (empty($body['id'])) {
            return new WP_Error('instagram_invalid_response', 'Invalid response while checking Instagram account');
        }

        return true;
    }

    /**
     * Send message to Telegram
     */
    private function send_telegram_message($text) {
        $url = self::TELEGRAM_API_URL . $this->telegram_bot_token . '/sendMessage';
        
        $args = array(
            'body' => array(
                'chat_id' => $this->telegram_channel_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false
            ),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            return new WP_Error('telegram_error', $body['description'] ?? 'Telegram API request failed');
        }

        return true;
    }

    /**
     * Send photo to Telegram
     */
    private function send_telegram_photo($photo_url, $caption) {
        $url = self::TELEGRAM_API_URL . $this->telegram_bot_token . '/sendPhoto';
        
        $args = array(
            'body' => array(
                'chat_id' => $this->telegram_channel_id,
                'photo' => $photo_url,
                'caption' => $caption,
                'parse_mode' => 'HTML'
            ),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            return new WP_Error('telegram_error', $body['description'] ?? 'Telegram API request failed');
        }

        return true;
    }

    /**
     * Create Instagram post
     */
    private function create_instagram_post($image_url, $caption) {
        // Instagram Graph API endpoint
        $url = "https://graph.facebook.com/" . self::INSTAGRAM_GRAPH_API_VERSION . "/{$this->instagram_user_id}/media";

        // Создаем Container
        $args = array(
            'body' => array(
                'image_url' => $image_url,
                'caption' => $caption,
                'access_token' => $this->instagram_access_token
            ),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['id'])) {
            $error_message = $body['error']['message'] ?? 'Failed to create media container';
            error_log('Instagram container error: ' . wp_json_encode($body));
            return new WP_Error('instagram_error', $error_message);
        }

        $container_status = $this->wait_instagram_container_ready($body['id']);
        if (is_wp_error($container_status)) {
            return $container_status;
        }

        // Публикуем контейнер
        $publish_url = "https://graph.facebook.com/" . self::INSTAGRAM_GRAPH_API_VERSION . "/{$this->instagram_user_id}/media_publish";
        
        $publish_args = array(
            'body' => array(
                'creation_id' => $body['id'],
                'access_token' => $this->instagram_access_token
            ),
            'timeout' => 30
        );

        $publish_response = wp_remote_post($publish_url, $publish_args);

        if (is_wp_error($publish_response)) {
            return $publish_response;
        }

        $publish_body = json_decode(wp_remote_retrieve_body($publish_response), true);
        if (empty($publish_body['id'])) {
            $error_message = $publish_body['error']['message'] ?? 'Failed to publish media container';
            error_log('Instagram publish error: ' . wp_json_encode($publish_body));
            return new WP_Error('instagram_error', $error_message);
        }

        return true;
    }
}
