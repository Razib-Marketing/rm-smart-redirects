/**
 * RMSmart Redirects - AJAX Frontend
 * Handles all AJAX interactions with loading states
 */

(function ($) {
    'use strict';

    const RMSmart = {
        /**
         * Smart Check: Inspect Source URL on Blur
         */
        checkSourceSlug: function (e) {
            const $input = $(e.target);
            const url = $input.val();
            const $forceCheckbox = $('#rmsmart-is-forced');
            const $warning = $('#rmsmart-slug-warning');

            // Remove existing warning if any
            if ($('#rmsmart-slug-warning').length) {
                $('#rmsmart-slug-warning').remove();
            }

            if (!url) return;

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_check_slug',
                    nonce: rmsmartAjax.nonce,
                    source_url: url,
                    target_url: url
                },
                success: function (response) {
                    if (response.success && response.data.exists) {
                        // Create Warning Element
                        const warningHtml = `
                            <div id="rmsmart-slug-warning" class="notice notice-warning inline" style="margin:10px 0 0 0; padding:8px 12px; border-left-color:#f0ad4e;">
                                <p style="margin:0; font-size:13px;">
                                    <strong>⚠️ Page Exists:</strong> A published page already exists at this URL. 
                                    <br>To redirect it effectively, you must enable <strong>Force Redirect ⚡</strong>.
                                </p>
                            </div>
                            </div>
                        `;
                        // Robust selector: Direct parent since input is inside a div
                        $input.parent().append(warningHtml);

                        // Highlight the Force Checkbox
                        $forceCheckbox.parent().css({
                            'transition': 'all 0.3s',
                            'color': '#d63638',
                            'font-weight': 'bold'
                        });

                        // Shake animation for attention
                        setTimeout(() => $forceCheckbox.parent().css('color', ''), 2000);
                    }
                }
            });
        },

        init: function () {
            // Only run on plugin page
            if (!$('#rmsmart-redirect-form').length && !$('.rmsmart-wrap').length) return;

            this.bindEvents();
        },

        bindEvents: function () {
            // Add/Edit Redirect Form
            $(document).on('submit', '#rmsmart-redirect-form', this.handleSaveRedirect.bind(this));

            // Delete Redirect
            $(document).on('click', '.rmsmart-delete-redirect', this.handleDeleteRedirect.bind(this));

            // Bulk Delete
            $(document).on('click', '#doaction, #doaction2', this.handleBulkAction.bind(this));

            // Accept Pending
            $(document).on('click', '.rmsmart-accept-pending', this.handleAcceptPending.bind(this));

            // Discard Pending
            $(document).on('click', '.rmsmart-discard-pending', this.handleDiscardPending.bind(this));

            // Delete 404 Log
            $(document).on('click', '.rmsmart-delete-404', this.handleDelete404.bind(this));

            // Test Redirect (Simulation Mode)
            $(document).on('click', '#rmsmart-test-btn', this.handleTestRedirect.bind(this));

            // Smart Slug Check (Debounced on Input)
            $(document).on('input', 'input[name="source_url"], input[name="target_url"]', this.debounce(this.checkSourceSlug.bind(this), 500));
        },

        /**
         * Helper: Debounce Function
         */
        debounce: function (func, wait) {
            let timeout;
            return function (...args) {
                const context = this;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        },

        /**
         * Smart Check: Inspect Source URL (Only if Target is also set)
         */
        checkSourceSlug: function () {
            const $form = $('#rmsmart-redirect-form');
            const $sourceInput = $form.find('input[name="source_url"]');
            const $targetInput = $form.find('input[name="target_url"]');

            const sourceUrl = $sourceInput.val();
            const targetUrl = $targetInput.val();

            const $forceCheckbox = $('#rmsmart-is-forced');

            // 1. Remove existing warning
            if ($('#rmsmart-slug-warning').length) {
                $('#rmsmart-slug-warning').remove();
            }

            // 2. REQUIRE BOTH FIELDS
            if (!sourceUrl || !targetUrl) return;

            // 3. AJAX Check for Dual-Sided Verification
            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_check_slug',
                    nonce: rmsmartAjax.nonce,
                    source_url: sourceUrl,
                    target_url: targetUrl
                },
                success: function (response) {
                    if (response.success && response.data.exists) {
                        // --- CONFLICT DETECTED ---

                        // 1. Show Warning
                        const warningHtml = `
                            <div id="rmsmart-slug-warning" class="notice notice-warning inline" style="margin:10px 0 0 0; padding:8px 12px; border-left-color:#f0ad4e;">
                                <p style="margin:0; font-size:13px;">
                                    <strong>⚠️ Page Exists:</strong> A published page already exists at this URL. 
                                    <br>To redirect it effectively, you must enable <strong>Force Redirect ⚡</strong>.
                                </p>
                            </div>
                            </div>
                        `;
                        // FIX: Append AFTER the form row (full width)
                        $('.rmsmart-form-row').after(warningHtml);

                        // 2. Show Force Checkbox Wrapper
                        $('#rmsmart-force-wrapper').fadeIn();

                        // 3. DISABLE Submit Button (Until Forced)
                        const $submitBtn = $form.find('button[type="submit"]');
                        if (!$forceCheckbox.is(':checked')) {
                            $submitBtn.prop('disabled', true).css('opacity', '0.6').attr('title', 'Please enable Force Redirect to proceed.');
                        }

                        // 4. Attach Listener to Force Checkbox to Re-enable Button
                        $forceCheckbox.off('change.rmsmart').on('change.rmsmart', function () {
                            if ($(this).is(':checked')) {
                                $submitBtn.prop('disabled', false).css('opacity', '1').removeAttr('title');
                            } else {
                                $submitBtn.prop('disabled', true).css('opacity', '0.6').attr('title', 'Please enable Force Redirect to proceed.');
                            }
                        });

                        // Shake animation
                        setTimeout(() => $('#rmsmart-force-wrapper').css('color', '#d63638'), 100);
                        setTimeout(() => $('#rmsmart-force-wrapper').css('color', ''), 2000);

                    } else {
                        // No conflict? Reset UI completely
                        $('#rmsmart-force-wrapper').hide();
                        $('#rmsmart-slug-warning').remove();
                        const $submitBtn = $form.find('button[type="submit"]');
                        $submitBtn.prop('disabled', false).css('opacity', '1').removeAttr('title');
                        $forceCheckbox.prop('checked', false);
                    }
                }
            });
        },

        /**
         * Handle Add/Edit Redirect Form Submission
         */
        handleSaveRedirect: function (e) {
            e.preventDefault();

            const $form = $(e.target);
            const $button = $form.find('button[type="submit"]');
            const buttonText = $button.text();
            const isEdit = $form.find('input[name="redirect_id"]').val() != '0';

            // Show loading state
            $button.prop('disabled', true).html('<span class="rmsmart-spinner"></span> Saving...');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_save_redirect',
                    nonce: rmsmartAjax.nonce,
                    id: $form.find('input[name="redirect_id"]').val(),
                    source_url: $form.find('input[name="source_url"]').val(),
                    target_url: $form.find('input[name="target_url"]').val(),
                    redirect_type: $form.find('select[name="redirect_type"]').val(),
                    is_forced: $form.find('input[name="is_forced"]').is(':checked') ? 1 : 0
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        RMSmart.updateStats();

                        // Clear form inputs for BOTH add and edit
                        $form.find('input[name="source_url"]').val('');
                        $form.find('input[name="target_url"]').val('');
                        $form.find('select[name="redirect_type"]').val('301');
                        $form.find('input[name="redirect_id"]').val('0');
                        $form.find('input[name="is_forced"]').prop('checked', false); // Clear force checkbox
                        $('#rmsmart-slug-warning').remove(); // Clear warning
                        $('#rmsmart-force-wrapper').hide(); // Hide force wrapper

                        if (!isEdit) {
                            // For ADD: Clear URL parameters to prevent re-filling
                            const cleanUrl = window.location.pathname + '?page=rmsmart-redirects&tab=manager';
                            window.history.replaceState({}, '', cleanUrl);

                            // Reload after short delay to show new redirect in table
                            setTimeout(function () {
                                window.location.reload();
                            }, 1000);
                        } else {
                            // For EDIT: Clear URL param and reload to show changes
                            const cleanUrl = window.location.pathname + '?page=rmsmart-redirects&tab=manager';
                            window.history.replaceState({}, '', cleanUrl);

                            setTimeout(function () {
                                window.location.reload();
                            }, 800);
                        }
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $button.prop('disabled', false).text(buttonText);
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred. Please try again.', 'error');
                    $button.prop('disabled', false).text(buttonText);
                }
            });

            return false;
        },

        /**
         * Handle Delete Single Redirect
         */
        handleDeleteRedirect: function (e) {
            e.preventDefault();
            // ... (rest of the file unchanged)
            if (!confirm('Delete this redirect?')) return false;

            const $link = $(e.target).closest('a');
            const id = $link.data('id');
            const $row = $link.closest('tr');

            // Show loading overlay on row
            $row.css('opacity', '0.5').find('a').css('pointer-events', 'none');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_delete_redirect',
                    nonce: rmsmartAjax.nonce,
                    id: id
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        $row.fadeOut(400, function () { $(this).remove(); });
                        RMSmart.updateStats();
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred. Please try again.', 'error');
                    $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                }
            });

            return false;
        },

        // ... (rest of the functions: handleBulkAction, handleAcceptPending, handleDiscardPending, handleTestRedirect, updateStats, handleDelete404, performBulkDelete, showToast)

        /**
         * Handle Bulk Actions
         */
        handleBulkAction: function (e) {
            const $btn = $(e.target);
            const whichSelect = $btn.attr('id') === 'doaction' ? 'action' : 'action2';
            const action = $('select[name="' + whichSelect + '"]').val();

            // Handle Redirect Bulk Delete
            if (action === 'bulk-delete') {
                e.preventDefault();
                const $checkboxes = $('input[name="bulk-delete[]"]:checked');
                this.performBulkDelete($checkboxes, 'rmsmart_bulk_delete', $btn);
                return false;
            }

            // Handle 404 Log Bulk Delete
            if (action === 'bulk-delete-404') {
                e.preventDefault();
                const $checkboxes = $('input[name="bulk-delete-404[]"]:checked');
                this.performBulkDelete($checkboxes, 'rmsmart_bulk_delete_404', $btn);
                return false;
            }

            return true; // Let normal form submission handle other actions
        },

        /**
         * Handle Accept Pending
         */
        handleAcceptPending: function (e) {
            e.preventDefault();

            const $link = $(e.target).closest('a');
            const id = $link.data('id');
            const $row = $link.closest('tr');

            $row.css('opacity', '0.5').find('a').css('pointer-events', 'none');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_accept_pending',
                    nonce: rmsmartAjax.nonce,
                    id: id
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        $row.fadeOut(400, function () { $(this).remove(); });
                        RMSmart.updateStats();
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred.', 'error');
                    $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                }
            });

            return false;
        },

        /**
         * Handle Discard Pending
         */
        handleDiscardPending: function (e) {
            e.preventDefault();

            const $link = $(e.target).closest('a');
            const id = $link.data('id');
            const $row = $link.closest('tr');

            if (!confirm('Discard this pending redirect?')) return false;

            $row.css('opacity', '0.5').find('a').css('pointer-events', 'none');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_discard_pending',
                    nonce: rmsmartAjax.nonce,
                    id: id
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        $row.fadeOut(400, function () { $(this).remove(); });
                        RMSmart.updateStats();
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred.', 'error');
                    $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                }
            });

            return false;
        },

        /**
         * Handle Test Redirect (Simulation Mode)
         */
        handleTestRedirect: function (e) {
            e.preventDefault();

            const $btn = $('#rmsmart-test-btn');
            const $startLabel = $btn.text();
            const url = $('#rmsmart-test-url').val();
            const $result = $('#rmsmart-test-result');

            if (!url) {
                alert('Please enter a URL to test.');
                return;
            }

            // Loading state
            $btn.prop('disabled', true).text('Testing...');
            $result.hide().removeClass('notice-success notice-error').html('');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_test_redirect',
                    nonce: rmsmartAjax.nonce,
                    url: url
                },
                success: function (response) {
                    $result.show();

                    if (response.success && response.data.found) {
                        // Clear input on success
                        $('#rmsmart-test-url').val('');

                        const match = response.data.match;
                        $result.css({
                            'border-left': '4px solid #46b450',
                            'background': '#f0f9eb'
                        }).html(
                            '<h4 style="margin:0 0 5px 0; color:#23702e;">✅ Redirect Found!</h4>' +
                            '<p style="margin:0;">Target: <strong>' + match.target + '</strong></p>' +
                            '<p style="margin:5px 0 0 0; font-size:12px; color:#666;">' +
                            'Type: ' + match.type + ' | Source: ' + match.source + '</p>'
                        );
                    } else {
                        $result.css({
                            'border-left': '4px solid #d63638',
                            'background': '#fbeaea'
                        }).html(
                            '<h4 style="margin:0 0 5px 0; color:#d63638;">❌ No Redirect Found</h4>' +
                            '<p style="margin:0;">This URL would return a <strong>404 Error</strong>.</p>'
                        );
                    }
                    $btn.prop('disabled', false).text($startLabel);
                },
                error: function () {
                    alert('Error testing redirect.');
                    $btn.prop('disabled', false).text($startLabel);
                }
            });
        },

        /**
         * Update Stats Cards in Real-time
         */
        updateStats: function () {
            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_get_stats',
                    nonce: rmsmartAjax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        const data = response.data;
                        $('.rmsmart-stat-card.green .count').text(data.active || 0);
                        $('.rmsmart-stat-card.orange .count').text(data.pending || 0);
                        $('.rmsmart-stat-card.blue .count').text(data.hits || 0);
                    }
                }
            });
        },

        /**
         * Handle Delete 404 Log
         */
        handleDelete404: function (e) {
            e.preventDefault();

            if (!confirm('Delete this log entry?')) return false;

            const $link = $(e.target).closest('a');
            const id = $link.data('id');
            const $row = $link.closest('tr');

            $row.css('opacity', '0.5').find('a').css('pointer-events', 'none');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rmsmart_delete_404',
                    nonce: rmsmartAjax.nonce,
                    id: id
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        $row.fadeOut(400, function () { $(this).remove(); });
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred.', 'error');
                    $row.css('opacity', '1').find('a').css('pointer-events', 'auto');
                }
            });

            return false;
        },

        /**
         * Helper: Perform Bulk Delete
         */
        performBulkDelete: function ($checkboxes, ajaxAction, $btn) {
            if ($checkboxes.length === 0) {
                alert('Please select items to delete.');
                return false;
            }

            if (!confirm('Delete ' + $checkboxes.length + ' selected item(s)?')) {
                return false;
            }

            const ids = $checkboxes.map(function () { return $(this).val(); }).get();
            $btn.prop('disabled', true).val('Deleting...');

            $.ajax({
                url: rmsmartAjax.ajax_url,
                type: 'POST',
                data: {
                    action: ajaxAction,
                    nonce: rmsmartAjax.nonce,
                    ids: ids
                },
                success: function (response) {
                    if (response.success) {
                        RMSmart.showToast(response.data.message, 'success');
                        setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    } else {
                        RMSmart.showToast(response.data.message, 'error');
                        $btn.prop('disabled', false).val('Apply');
                    }
                },
                error: function () {
                    RMSmart.showToast('An error occurred. Please try again.', 'error');
                    $btn.prop('disabled', false).val('Apply');
                }
            });
        },

        /**
         * Show Toast Notification
         */
        showToast: function (message, type) {
            const toast = $('<div class="rmsmart-toast rmsmart-toast-' + type + '">' + message + '</div>');
            $('body').append(toast);

            setTimeout(function () {
                toast.addClass('rmsmart-toast-show');
            }, 100);

            setTimeout(function () {
                toast.removeClass('rmsmart-toast-show');
                setTimeout(function () { toast.remove(); }, 300);
            }, 3000);
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        RMSmart.init();
    });

})(jQuery);
