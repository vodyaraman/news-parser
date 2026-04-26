<?php
if (!defined('ABSPATH')) exit;

class News_Parser_Social {
    /**
     * Telegram Bot API
     */
    const TELEGRAM_API_URL = 'https://api.telegram.org/bot';
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

        $message = "*{$post->post_title}*\n\n";
        $message .= wp_trim_words(wp_strip_all_tags($post->post_content), 50);
        $message .= "\n\n?? [×èòàòü ïîëíîñòüþ](" . get_permalink($post_id) . ")";

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

        // Ïîëó÷àåì èçîáðàæåíèå
        $image_url = get_the_post_thumbnail_url($post_id, 'large');
        if (!$image_url) {
            return new WP_Error('no_image', 'No image found for Instagram post');
        }

        $caption = $post->post_title . "\n\n";
        $caption .= wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        $caption .= "\n\n?? Ïîäðîáíåå íà ñàéòå (ññûëêà â áèî)";

        $tags = get_the_tags($post_id);
        if ($tags) {
            $hashtags = array_map(function($tag) {
                return '#' . str_replace(' ', '', $tag->name);
            }, $tags);
            $caption .= "\n\n" . implode(' ', $hashtags);
        }

        return $this->create_instagram_post($image_url, $caption);
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
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => false
            ),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['ok'] ?? false;
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
                'parse_mode' => 'Markdown'
            ),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['ok'] ?? false;
    }

    /**
     * Create Instagram post
     */
    private function create_instagram_post($image_url, $caption) {
        // Instagram Graph API endpoint
        $url = "https://graph.facebook.com/v13.0/{$this->instagram_user_id}/media";

        // Ñîçäàåì Container
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
            return new WP_Error('instagram_error', 'Failed to create media container');
        }

        // Ïóáëèêóåì êîíòåéíåð
        $publish_url = "https://graph.facebook.com/v13.0/{$this->instagram_user_id}/media_publish";
        
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
        
        return !empty($publish_body['id']);
    }
}