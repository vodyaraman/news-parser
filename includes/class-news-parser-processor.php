<?php
if (!defined('ABSPATH')) exit;

class News_Parser_Processor
{
    /**
     * Main plugin instance
     * @var News_Site_Parser
     */
    private $main;

    /**
     * Default batch size for processing
     * @var int
     */
    private $batch_size = 10;

    /**
     * Initialize the processor
     */
    public function __construct($main)
    {
        $this->main = $main;
        $this->batch_size = get_option('news_parser_batch_size', 10);
    }

    /**
     * Process a single source
     */
    public function process_source($source_url, &$source_data)
    {
        try {
            $page = get_option("news_parser_{$source_url}_page", 1);

            // Получаем дату начала
            $start_date = new DateTime($source_data['start_date']);
            $start_date->setTime(0, 0, 0);
            $formatted_start = $start_date->format('Y-m-d\TH:i:s');

            // Формируем URL с параметрами
            $url_with_params = add_query_arg([
                'per_page' => $this->batch_size,
                'page' => $page,
                '_embed' => 'wp:featuredmedia,wp:term',
                'after' => $formatted_start,
                'orderby' => 'date',
                'order' => 'asc'
            ], $source_url);

            $this->log("Проверка новых постов с {$formatted_start}, страница {$page}", 'info', $source_url);

            $response = wp_remote_get($url_with_params, [
                'timeout' => 60,
                'sslverify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
                ],
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception("Invalid response code: " . $response_code);
            }

            $total_posts = wp_remote_retrieve_header($response, 'X-WP-Total');
            $total_pages = wp_remote_retrieve_header($response, 'X-WP-TotalPages');

            $this->log("Найдено постов: {$total_posts}, страниц: {$total_pages}");

            $posts = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($posts)) {
                // Нет новых постов, планируем следующую проверку через короткий интервал
                $source_data['status'] = 'monitoring';
                $source_data['last_check'] = current_time('mysql');
                wp_schedule_single_event(time() + 300, 'news_parser_cron_job'); // Проверка каждые 5 минут
                $this->log("Нет новых постов. Следующая проверка через 5 минут.");
                return true;
            }

            $processed_count = 0;
            $found_new = false;

            foreach ($posts as $post) {
                // Проверяем, был ли этот пост уже обработан
                global $wpdb;
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'original_post_guid' 
                AND meta_value = %s",
                    $post['guid']['rendered']
                ));

                if (!$exists) {
                    // Найден новый пост
                    $found_new = true;
                    if ($this->process_post($post, $source_url, $source_data['post_type'])) {
                        $processed_count++;
                    }
                }
            }

            // Обновляем статистику
            $current_count = (int)get_option("news_parser_{$source_url}_posts_count", 0);
            $new_count = $current_count + $processed_count;
            update_option("news_parser_{$source_url}_posts_count", $new_count);
            update_option("news_parser_{$source_url}_last_processed", current_time('mysql'));

            if ($found_new) {
                $this->log("Обработано новых постов: {$processed_count}");
            }

            // Проверяем следующую страницу если есть новые посты
            if ($page < $total_pages && $found_new) {
                update_option("news_parser_{$source_url}_page", $page + 1);
                $source_data['status'] = 'in_progress';
                wp_schedule_single_event(time() + 5, 'news_parser_cron_job');
                $this->log("Переход к следующей странице: " . ($page + 1));
            } else {
                // Сбрасываем страницу и продолжаем мониторинг
                update_option("news_parser_{$source_url}_page", 1);
                $source_data['status'] = 'monitoring';
                $source_data['last_check'] = current_time('mysql');
                wp_schedule_single_event(time() + 300, 'news_parser_cron_job'); // Проверка каждые 5 минут
                $this->log("Проверка завершена. Следующая проверка через 5 минут.");
            }

            return true;

        } catch (Exception $e) {
            $this->log("Ошибка: " . $e->getMessage(), 'error');
            // В случае ошибки планируем следующую попытку
            wp_schedule_single_event(time() + 300, 'news_parser_cron_job');
            return false;
        }
    }

    /**
     * Process a single post
     */
    private function process_post($post, $source_url, $post_type)
    {
        try {
            // Проверяем наличие ID и контента
            if (empty($post['id'])) {
                $this->log("Пропущен пост без ID", 'error');
                return false;
            }

            // Проверка на дубликат
            global $wpdb;
            $duplicate_check = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id 
             WHERE pm1.meta_key = 'original_post_guid' 
             AND pm1.meta_value = %s 
             AND p.post_type = %s
             LIMIT 1",
                $post['guid']['rendered'], $post_type
            ));

            if ($duplicate_check) {
                $this->log("Пост {$post['id']} уже существует, пропускаем");
                return false;
            }

            // Сохраняем оригинальные данные
            $original_title = $post['title']['rendered'];
            $original_content = $post['content']['rendered'];
            $original_excerpt = !empty($post['excerpt']['rendered']) ? $post['excerpt']['rendered'] : '';

            // Подготавливаем данные поста для парафраза
            $post_title = wp_strip_all_tags($original_title);
            $post_content = $original_content;
            $post_excerpt = wp_strip_all_tags($original_excerpt);

            // Удаляем внутренние ссылки донора
            $post_content = $this->remove_source_links($post_content, $source_url);

            // Парафраз если включен
            if (get_option('news_parser_enable_paraphrase', true)) {
                $this->log("Парафраз заголовка: " . $post_title);
                $paraphrased_title = $this->paraphrase_text($post_title);
                if ($paraphrased_title) {
                    $post_title = $paraphrased_title;
                    $this->log("Заголовок после парафраза: " . $post_title);
                } else {
                    $this->log("Ошибка парафраза заголовка", 'warning');
                }

                $this->log("Начало парафраза контента");
                $paraphrased_content = $this->paraphrase_text($post_content);
                if ($paraphrased_content) {
                    $post_content = $paraphrased_content;
                    $this->log("Контент успешно парафразирован");
                } else {
                    $this->log("Ошибка парафраза контента", 'warning');
                }

                if (!empty($post_excerpt)) {
                    $paraphrased_excerpt = $this->paraphrase_text($post_excerpt);
                    if ($paraphrased_excerpt) {
                        $post_excerpt = $paraphrased_excerpt;
                        $this->log("Отрывок успешно парафразирован");
                    }
                }
            }

            // Создаем пост
            $post_data = array(
                'post_title' => $post_title,
                'post_content' => $post_content,
                'post_excerpt' => $post_excerpt,
                'post_status' => 'publish',
                'post_author' => get_option('news_parser_default_author', 1),
                'post_date' => $post['date'],
                'post_date_gmt' => $post['date_gmt'],
                'post_type' => $post_type,
            );

            $post_id = wp_insert_post($post_data, true);

            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }

            // Сохраняем все оригинальные данные в мета-поля
            update_post_meta($post_id, 'original_post_guid', $post['guid']['rendered']);
            update_post_meta($post_id, 'original_url', $post['link']);
            update_post_meta($post_id, 'source_url', $source_url);
            update_post_meta($post_id, 'original_title', $original_title);
            update_post_meta($post_id, 'original_content', $original_content);
            update_post_meta($post_id, 'original_excerpt', $original_excerpt);

            // Получаем маппинг категорий
            $category_mapping = get_option('news_parser_category_mapping', array());
            $default_category = get_option('default_category');

            // Обрабатываем и сохраняем оригинальные категории и теги
            if (!empty($post['_embedded']['wp:term'])) {
                $original_terms = array();
                foreach ($post['_embedded']['wp:term'] as $terms) {
                    foreach ($terms as $term) {
                        // Сохраняем оригинальный термин
                        $original_terms[$term['taxonomy']][] = array(
                            'id' => $term['id'],
                            'name' => $term['name'],
                            'slug' => $term['slug'],
                            'taxonomy' => $term['taxonomy'],
                            'description' => isset($term['description']) ? $term['description'] : ''
                        );

                        // Обрабатываем категории с маппингом
                        if ($post_type === 'post' && $term['taxonomy'] === 'category') {
                            if (isset($category_mapping[$term['name']])) {
                                // Если есть маппинг для этой категории
                                $target_category_id = $category_mapping[$term['name']];
                                wp_set_post_categories($post_id, array($target_category_id), true);
                                $this->log("Категория '{$term['name']}' замаппирована в ID: $target_category_id");
                            } else {
                                // Если маппинга нет, используем категорию по умолчанию
                                if ($default_category) {
                                    wp_set_post_categories($post_id, array($default_category), true);
                                    $this->log("Использована категория по умолчанию для '{$term['name']}'");
                                } else {
                                    $this->log("Пропущена категория '{$term['name']}' - нет маппинга", 'warning');
                                }
                            }
                        } // Обрабатываем теги
                        elseif ($post_type === 'post' && $term['taxonomy'] === 'post_tag') {
                            wp_set_post_tags($post_id, $term['name'], true);
                            $this->log("Добавлен тег: " . $term['name']);
                        }
                    }
                }
                // Сохраняем оригинальные термины
                update_post_meta($post_id, 'original_terms', json_encode($original_terms));
            }

            // Обрабатываем главное изображение
            if (!empty($post['_embedded']['wp:featuredmedia'][0])) {
                $this->process_featured_image($post_id, $post['_embedded']['wp:featuredmedia'][0]);
            }

            $processed_content = $this->process_content_images($post_id, $post_content);
            $processed_content = $this->process_content_videos($post_id, $processed_content);
            if ($processed_content !== $post_content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $processed_content
                ));
            }

            $this->log("Успешно создан пост ID: {$post_id} (оригинальный ID: {$post['id']})");
            return true;

        } catch (Exception $e) {
            $this->log("Ошибка обработки поста {$post['id']}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Загрузка главного изображения поста
     */
    private function process_featured_image($post_id, $media_data)
    {
        try {
            $image_url = '';

            // Пытаемся получить URL изображения
            if (!empty($media_data['source_url'])) {
                $image_url = $media_data['source_url'];
            } elseif (!empty($media_data['guid']['rendered'])) {
                $image_url = $media_data['guid']['rendered'];
            }

            if (!empty($image_url)) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                // Загружаем файл
                $tmp = download_url($image_url);
                if (is_wp_error($tmp)) {
                    throw new Exception($tmp->get_error_message());
                }

                $file_array = array(
                    'name' => explode('?', basename($image_url), 2)[0],
                    'tmp_name' => $tmp
                );

                // Загружаем изображение
                $image_id = media_handle_sideload($file_array, $post_id);

                // Очистка
                @unlink($tmp);

                if (is_wp_error($image_id)) {
                    throw new Exception($image_id->get_error_message());
                }

                // Устанавливаем как миниатюру
                set_post_thumbnail($post_id, $image_id);

                // Устанавливаем alt-текст если есть
                if (!empty($media_data['alt_text'])) {
                    update_post_meta($image_id, '_wp_attachment_image_alt', $media_data['alt_text']);
                }

                $this->log("Главное изображение успешно загружено для поста ID: $post_id");
            }
        } catch (Exception $e) {
            $this->log("Ошибка загрузки главного изображения для поста $post_id: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Загружает изображения локально и обновляет ссылки в контенте
     *
     * @param int $post_id ID поста
     * @param string $content Содержимое поста
     * @return string Обработанный контент
     */
    private function process_content_images($post_id, $content)
    {
        $content = preg_replace_callback(
            '/<img[^>]+src=([\'"])?(http[s]?:\/\/[^"\'>]+)\1[^>]*>/i',
            function ($matches) use ($post_id) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $img_url = $matches[2];            // Значение src

                // Загружаем изображение
                $tmp = download_url($img_url);

                if (is_wp_error($tmp)) {
                    $this->log("Ошибка загрузки изображения: " . $tmp->get_error_message(), 'error');
                    return '';
                }

                // Получаем информацию о файле
                $file_array = array(
                    'name' => explode('?', basename($img_url), 2)[0],
                    'tmp_name' => $tmp
                );

                // Добавляем файл в медиабиблиотеку
                $id = media_handle_sideload($file_array, $post_id);

                if (is_wp_error($id)) {
                    @unlink($tmp);
                    $this->log("Ошибка сохранения изображения: " . $id->get_error_message() . ' - ' . print_r($file_array, true) . ' ' . $img_url, 'error');
                    return '';
                }

                add_post_meta($post_id, '_uploaded_images', $id);

                return wp_get_attachment_image($id, 'full');
            },
            $content
        );

        return $content;
    }

    private function process_content_videos($post_id, $content)
    {
        preg_match_all('/<video[^>]+src=([\'"])?((http[s]?:\/\/[^"\']+))/i', $content, $matches);

        if (!empty($matches[2])) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            foreach ($matches[2] as $img_url) {
                try {
                    // Загружаем изображение
                    $tmp = download_url($img_url);

                    if (is_wp_error($tmp)) {
                        $this->log("Ошибка загрузки видео: " . $tmp->get_error_message(), 'error');
                        continue;
                    }

                    // Получаем информацию о файле
                    $file_array = array(
                        'name' => basename($img_url),
                        'tmp_name' => $tmp
                    );

                    // Добавляем файл в медиабиблиотеку
                    $id = media_handle_sideload($file_array, $post_id);

                    if (is_wp_error($id)) {
                        @unlink($tmp);
                        $this->log("Ошибка сохранения видео: " . $id->get_error_message(), 'error');
                        continue;
                    }

                    // Получаем URL нового изображения
                    $new_url = wp_get_attachment_url($id);
                    if ($new_url) {
                        // Заменяем старый URL на новый в контенте
                        $content = str_replace($img_url, $new_url, $content);

                        // Добавляем атрибуты для оптимизации
                        //$content = str_replace('<img', '<img loading="lazy" decoding="async"', $content);

                        $this->log("Видео успешно загружено и заменено: " . $new_url);
                    }

                    // Добавляем изображение в галерею поста
                    add_post_meta($post_id, '_uploaded_videos', $id);

                } catch (Exception $e) {
                    $this->log("Ошибка обработки видео: " . $e->getMessage(), 'error');
                    if (isset($tmp) && file_exists($tmp)) {
                        @unlink($tmp);
                    }
                }
            }
        }

        return $content;
    }

    private function remove_source_links($content, $source_url)
    {
        if (empty($content) || empty($source_url)) {
            return $content;
        }

        // Получаем домен источника
        $source_domain = parse_url($source_url, PHP_URL_HOST);
        if (!$source_domain) {
            return $content;
        }

        return preg_replace_callback('~<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~i', function ($matches) use ($source_domain) {
            $href = $matches[1];
            $linkText = $matches[2];

            // Приводим ссылку к нижнему регистру и обрезаем пробелы
            $hrefLower = strtolower(trim($href));

            // Проверка: ссылка ведёт на удаляемый домен
            if (stripos($hrefLower, $source_domain) !== false) {
                return $linkText;
            }

            // Проверка: ссылка относительная
            if (!preg_match('~^(https?:)?//~', $hrefLower)) {
                return $linkText;
            }

            return $matches[0]; // оставить ссылку без изменений
        }, $content);
    }


    /**
     * Paraphrase text using ChatGPT API
     */
    public function paraphrase_text($text)
    {
        if (empty($text)) {
            return false;
        }

        $api_key = get_option('news_parser_chatgpt_key', '');
        if (empty($api_key)) {
            $this->log("ChatGPT API Key not set", 'error');
            return false;
        }

        $model = get_option('news_parser_chatgpt_model', 'gpt-4');
        $url = 'https://api.openai.com/v1/chat/completions';

        $prompt = "Rephrase the following text, preserving its meaning and HTML formatting. Make the text unique: \n\n" . $text;

        $data = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => 'You are a professional rewriter. Rephrase the text, preserving HTML tags and structure.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.7,
            'max_tokens' => 4000
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            $this->log("ChatGPT API Error: " . $response->get_error_message(), 'error');
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body['choices'][0]['message']['content'])) {
            $this->log("ChatGPT API Error: Invalid response - " . print_r($body, true), 'error');
            return false;
        }

        return $body['choices'][0]['message']['content'];
    }


    /**
     * Log message to main instance
     */
    private function log($message, $type = 'info', $source_url = '')
    {
        if ($this->main) {
            $this->main->log($message, $type, $source_url);
        }
    }
}