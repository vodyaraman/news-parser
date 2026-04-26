jQuery(document).ready(function($) {
    // DOM элементы
    const elements = {
        startButton: $('#start-parser'),
        pauseButton: $('#pause-parser'),
        sourcesTable: $('#parser-sources-table'),
        statusIndicator: $('.parser-status .status-indicator'),
        statusText: $('.parser-status .status-text'),
        deleteButtons: $('.delete-source'),
        resetButtons: $('.reset-source'),
        clearLogsButton: $('#clear-logs'),
        statsContainer: $('.parser-stats'),
        testTelegramButton: $('.test-telegram'),
        testInstagramButton: $('.test-instagram'),
        testChatGptButton: $('.test-chatgpt')
    };

    let statusUpdateInterval = null;

    // Инициализация
    function initAdmin() {
        bindEventHandlers();
        initStatusUpdates();
        initDatePicker();
        console.log('News Parser Admin initialized');
    }

    // Инициализация datepicker
    function initDatePicker() {
        if ($('#news_parser_start_date').length) {
            const now = new Date();
            $('#news_parser_start_date').val(now.toISOString().slice(0, 16));
        }
    }

    // Привязка обработчиков событий
    function bindEventHandlers() {
        elements.startButton.on('click', handleStartParser);
        elements.pauseButton.on('click', handlePauseResumeParser);
        $(document).on('click', '.delete-source', handleDeleteSource);
        $(document).on('click', '.reset-source', handleResetSource);
        elements.clearLogsButton.on('click', handleClearLogs);
        $('.news-parser-form').on('submit', handleFormSubmission);

        // Социальные сети
        elements.testTelegramButton.on('click', handleTestTelegram);
        elements.testInstagramButton.on('click', handleTestInstagram);
        elements.testChatGptButton.on('click', handleTestChatGpt);
    }

    // Обновление статуса парсера
    function initStatusUpdates() {
        if (statusUpdateInterval) {
            clearInterval(statusUpdateInterval);
        }
        updateParserStatus();
        statusUpdateInterval = setInterval(updateParserStatus, 5000);
    }

    function showNotice(type, message) {
        const notice = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss notice</span>
                </button>
            </div>
        `);

        $('.wrap > h1').after(notice);
        
        setTimeout(() => {
            notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Обновление статистики
    function updateStatistics(stats) {
        if (!stats) return;

        const statsHtml = `
            <div class="stat-item">
                <span>Total Sources: </span>
                <span class="stat-value total-sources">${stats.total_sources}</span>
            </div>
            <div class="stat-item">
                <span>Active Sources: </span>
                <span class="stat-value active-sources">${stats.active_sources}</span>
            </div>
            <div class="stat-item">
                <span>Total Posts: </span>
                <span class="stat-value total-posts">${stats.total_posts}</span>
            </div>
        `;

        elements.statsContainer.html(statsHtml);
    }

// Обработка парсера
    function handleStartParser(e) {
        e.preventDefault();
        
        elements.startButton.prop('disabled', true);
        elements.statusIndicator.addClass('running');
        elements.statusText.text('Starting parser...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'start_parser',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', 'Parser started successfully');
                    updateParserStatus();
                    elements.startButton.prop('disabled', true);
                    elements.pauseButton.prop('disabled', false).text('Pause');
                    
                    if (response.data && response.data.stats) {
                        updateStatistics(response.data.stats);
                    }
                } else {
                    showNotice('error', response.data || 'Error starting parser');
                    elements.startButton.prop('disabled', false);
                    elements.statusIndicator.removeClass('running');
                    elements.statusText.text('Parser is ready');
                }
            },
            error: function() {
                showNotice('error', 'Error occurred while starting parser');
                elements.startButton.prop('disabled', false);
                elements.statusIndicator.removeClass('running');
                elements.statusText.text('Parser is ready');
            }
        });
    }

    function handlePauseResumeParser(e) {
        e.preventDefault();
        
        const isPaused = elements.pauseButton.text().trim() === 'Resume';
        const action = isPaused ? 'resume_parser' : 'pause_parser';
        
        elements.pauseButton.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: action,
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const newButtonText = isPaused ? 'Pause' : 'Resume';
                    elements.pauseButton
                        .text(newButtonText)
                        .prop('disabled', false);
                    
                    showNotice('success', response.data);
                    updateParserStatus(); // Обновляем статус
                } else {
                    showNotice('error', response.data || 'Error changing parser state');
                    elements.pauseButton.prop('disabled', false);
                }
            },
            error: function() {
                showNotice('error', 'Error occurred');
                elements.pauseButton.prop('disabled', false);
            }
        });
    }

    function handleTestChatGpt(e) {
        e.preventDefault();
        const button = $(this);
        const spinner = button.next('.spinner');
        const result = spinner.next('.test-result');

        button.prop('disabled', true);
        spinner.addClass('is-active');
        result.removeClass('test-success test-error').empty();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'test_chatgpt',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    result.addClass('test-success').text(response.data);
                } else {
                    result.addClass('test-error').text(response.data || 'Test failed');
                }
            },
            error: function() {
                result.addClass('test-error').text('Connection error');
            },
            complete: function() {
                button.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    }

    // Обработка логов
    function handleClearLogs(e) {
        e.preventDefault();
        
        if (!confirm(newsParserAjax.strings.confirm_clear_logs)) {
            return;
        }

        const button = $(this);
        button.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_logs',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showNotice('error', response.data);
                    button.prop('disabled', false);
                }
            },
            error: function() {
                showNotice('error', 'Error clearing logs');
                button.prop('disabled', false);
            }
        });
    }

    // Социальные сети
    function handleTestTelegram(e) {
        e.preventDefault();
        const button = $(this);
        const spinner = button.next('.spinner');
        const result = spinner.next('.test-result');

        button.prop('disabled', true);
        spinner.addClass('is-active');
        result.removeClass('test-success test-error').empty();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'test_telegram',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    result.addClass('test-success').text(response.data);
                } else {
                    result.addClass('test-error').text(response.data);
                }
            },
            error: function() {
                result.addClass('test-error').text('Connection failed');
            },
            complete: function() {
                button.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    }

    function handleTestInstagram(e) {
        e.preventDefault();
        const button = $(this);
        const spinner = button.next('.spinner');
        const result = spinner.next('.test-result');

        button.prop('disabled', true);
        spinner.addClass('is-active');
        result.removeClass('test-success test-error').empty();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'test_instagram',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    result.addClass('test-success').text(response.data);
                } else {
                    result.addClass('test-error').text(response.data);
                }
            },
            error: function() {
                result.addClass('test-error').text('Connection failed');
            },
            complete: function() {
                button.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    }

// Обработка источников
    function handleDeleteSource(e) {
        e.preventDefault();
        
        const button = $(this);
        const sourceUrl = button.data('url');
        
        if (!confirm(newsParserAjax.strings.confirm_delete)) {
            return;
        }

        button.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_news_source',
                source_url: sourceUrl,
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    button.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        if (elements.sourcesTable.find('tbody tr').length === 0) {
                            elements.sourcesTable.find('tbody').append(
                                '<tr><td colspan="7">No sources added.</td></tr>'
                            );
                        }
                    });
                    showNotice('success', 'Source deleted successfully');
                    updateParserStatus();
                } else {
                    showNotice('error', response.data);
                    button.prop('disabled', false);
                }
            },
            error: function() {
                showNotice('error', 'Error occurred while deleting source');
                button.prop('disabled', false);
            }
        });
    }

    function handleResetSource(e) {
        e.preventDefault();
        
        const button = $(this);
        const sourceUrl = button.data('url');
        
        if (!confirm(newsParserAjax.strings.confirm_reset)) {
            return;
        }

        button.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'reset_news_source',
                source_url: sourceUrl,
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', 'Source reset successfully');
                    updateParserStatus();
                } else {
                    showNotice('error', response.data);
                }
                button.prop('disabled', false);
            },
            error: function() {
                showNotice('error', 'Error occurred while resetting source');
                button.prop('disabled', false);
            }
        });
    }

    // Обновление статуса парсера
    function updateParserStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_parser_status',
                nonce: newsParserAjax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    const data = response.data;
                    console.log('Parser status:', data); // Для отладки

                    // Обновляем состояние кнопок
                    elements.startButton.prop('disabled', data.is_running || data.is_paused);
                    elements.pauseButton
                        .prop('disabled', false) // Всегда активна
                        .text(data.is_paused ? 'Resume' : 'Pause');

                    // Обновляем индикатор статуса
                    elements.statusIndicator.removeClass('running paused idle');
                    elements.statusText.empty();

                    if (data.is_paused) {
                        elements.statusIndicator.addClass('paused');
                        elements.statusText.text('Parser is paused');
                    } else if (data.is_running) {
                        elements.statusIndicator.addClass('running');
                        elements.statusText.text('Parser is running');
                    } else {
                        elements.statusIndicator.addClass('idle');
                        elements.statusText.text('Parser is ready');
                    }

                    // Обновляем статусы источников
                    if (data.statuses) {
                        data.statuses.forEach(function(source) {
                            const row = elements.sourcesTable.find(`tr[data-url="${source.url}"]`);
                            if (row.length) {
                                row.find('.status').html(`
                                    <span class="status-badge status-${source.status}">
                                        ${getStatusText(source.status)}
                                    </span>
                                `);
                                row.find('.page').text(source.page || '1');
                                row.find('.posts-count').text(source.posts_count || '0');
                                row.find('.last-processed').text(source.last_processed || 'Never');
                            }
                        });
                    }

                    // Обновляем статистику
                    if (data.stats) {
                        updateStatistics(data.stats);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Status update error:', error);
            }
        });
    }

    function updateStatusDisplay(data) {
        if (!data || !data.statuses) return;

        // Обновление статусов источников
        data.statuses.forEach(function(source) {
            const row = elements.sourcesTable.find(`tr[data-url="${source.url}"]`);
            if (row.length) {
                row.find('.status').html(`
                    <span class="status-badge status-${source.status}">
                        ${getStatusText(source.status)}
                    </span>
                `);

                row.find('.page').text(source.page || '1');
                row.find('.posts-count').text(source.posts_count || '0');
                row.find('.last-processed').text(source.last_processed || 'Never');
            }
        });

        // Обновление состояния кнопок
        const isPaused = data.is_paused;
        const isRunning = data.is_running;

        elements.startButton.prop('disabled', isRunning || isPaused);
        elements.pauseButton
            .prop('disabled', !isRunning && !isPaused)
            .text(isPaused ? 'Resume' : 'Pause');

        // Обновление индикатора статуса
        elements.statusIndicator.removeClass('running paused idle');
        if (isPaused) {
            elements.statusIndicator.addClass('paused');
            elements.statusText.text('Parser is paused');
        } else if (isRunning) {
            elements.statusIndicator.addClass('running');
            elements.statusText.text('Parser is running');
        } else {
            elements.statusIndicator.addClass('idle');
            elements.statusText.text('Parser is ready');
        }
    }

    function updateStatusIndicator(isRunning, isPaused) {
        elements.statusIndicator.removeClass('running paused idle');
        
        if (isPaused) {
            elements.statusIndicator.addClass('paused');
            elements.statusText.text('Parser is paused');
        } else if (isRunning) {
            elements.statusIndicator.addClass('running');
            elements.statusText.text('Parser is running');
        } else {
            elements.statusIndicator.addClass('idle');
            elements.statusText.text('Parser is ready');
        }
    }

    // Вспомогательные функции
    function getStatusText(status) {
        const statuses = {
            'not_started': 'Not Started',
            'in_progress': 'In Progress',
            'waiting': 'Waiting',
            'completed': 'Completed',
            'error': 'Error',
            'monitoring': 'Monitoring'
        };
        return statuses[status] || status;
    }

    // Обработка формы
    function handleFormSubmission(e) {
        const form = $(this);
        if (!form.valid()) {
            e.preventDefault();
            return;
        }
        form.find('button[type="submit"]').prop('disabled', true);
    }

    // Очистка при выходе
    $(window).on('unload', function() {
        if (statusUpdateInterval) {
            clearInterval(statusUpdateInterval);
        }
    });

    // Инициализация
    initAdmin();

    // Экспорт функций
    window.newsParserAdmin = {
        updateParserStatus,
        showNotice,
        updateStatistics
    };
});