/**
 * ErrorVault Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // Verify token button
        $('#verify-token').on('click', function() {
            var $button = $(this);
            var $result = $('#verify-result');
            var endpoint = $('#api_endpoint').val();
            var token = $('#api_token').val();

            if (!endpoint || !token) {
                $result.removeClass('success').addClass('error')
                    .text('Please enter both endpoint and token.');
                return;
            }

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.verifying);

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_verify_token',
                    nonce: errorvaultAdmin.nonce,
                    endpoint: endpoint,
                    token: token
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text(errorvaultAdmin.strings.verified + ' - Site: ' + response.data.site_name);
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text(errorvaultAdmin.strings.failed + ': ' + response.error);
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text(errorvaultAdmin.strings.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Send test error button
        $('#send-test-error').on('click', function() {
            var $button = $(this);
            var $result = $('#test-result');

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.testing);

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_test_error',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text(errorvaultAdmin.strings.testSuccess);
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text(errorvaultAdmin.strings.testFailed);
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text(errorvaultAdmin.strings.testFailed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Test connection button
        $('#test-connection').on('click', function() {
            var $button = $(this);
            var $result = $('#test-result');

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> Testing connection...');

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_test_connection',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text('✓ Connection successful!');
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text('✗ ' + (response.data && response.data.message ? response.data.message : 'Connection failed'));
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text('✗ Connection failed');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Send test health report button
        $('#send-test-health').on('click', function() {
            var $button = $(this);
            var $result = $('#test-result');

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.testingHealth);

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_test_health',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text(errorvaultAdmin.strings.testHealthSuccess);
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text(response.data || errorvaultAdmin.strings.testHealthFailed);
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text(errorvaultAdmin.strings.testHealthFailed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Clear failure log button
        $('#clear-failure-log').on('click', function() {
            var $button = $(this);

            if (!confirm('Are you sure you want to clear the connection failure log?')) {
                return;
            }

            $button.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_clear_failures',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to clear failure log');
                        $button.prop('disabled', false).text('Clear Failure Log');
                    }
                },
                error: function() {
                    alert('Failed to clear failure log');
                    $button.prop('disabled', false).text('Clear Failure Log');
                }
            });
        });

        // Trigger backup poll button
        $('#trigger-backup-poll').on('click', function() {
            var $button = $(this);
            var $result = $('#backup-result');

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.triggeringBackup);

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_trigger_backup_poll',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text(errorvaultAdmin.strings.backupTriggered);
                        // Auto-refresh logs after 2 seconds
                        setTimeout(function() {
                            $('#view-backup-logs').trigger('click');
                        }, 2000);
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text(errorvaultAdmin.strings.backupFailed + ': ' + response.data);
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text(errorvaultAdmin.strings.backupFailed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // View backup logs button
        $('#view-backup-logs').on('click', function() {
            var $button = $(this);
            var $container = $('#backup-logs-container');
            var $content = $('#backup-logs-content');

            if ($container.is(':visible')) {
                $container.slideUp();
                $button.text('View Recent Logs');
                return;
            }

            $button.prop('disabled', true);
            $content.html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.loadingLogs);
            $container.slideDown();

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_get_backup_logs',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $content.text(response.data.logs);
                        $button.text('Hide Logs');
                    } else {
                        $content.text('Failed to load logs: ' + response.data);
                    }
                },
                error: function() {
                    $content.text('Failed to load logs');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Clear backup logs button
        $('#clear-backup-logs').on('click', function() {
            var $button = $(this);
            var $result = $('#backup-result');

            if (!confirm('Are you sure you want to clear the backup logs?')) {
                return;
            }

            $button.prop('disabled', true);
            $result.removeClass('success error').html('<span class="errorvault-spinner"></span> ' + errorvaultAdmin.strings.clearingLogs);

            $.ajax({
                url: errorvaultAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'errorvault_clear_backup_logs',
                    nonce: errorvaultAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success')
                            .text(errorvaultAdmin.strings.logsCleared);
                        // Clear the logs display if visible
                        if ($('#backup-logs-container').is(':visible')) {
                            $('#backup-logs-content').text('No backup logs found. Logs will appear here after the first backup operation.');
                        }
                    } else {
                        $result.removeClass('success').addClass('error')
                            .text('Failed to clear logs: ' + response.data);
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error')
                        .text('Failed to clear logs');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

    });

})(jQuery);
