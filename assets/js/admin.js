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

    });

})(jQuery);
