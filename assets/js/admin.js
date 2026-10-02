/**
 * External Request Manager Pro admin interactions.
 */
(function($) {
    'use strict';

    const ERM = {
        busy: false,
        activeModal: null,
        returnFocus: null,
        previousOverflow: '',

        init: function() {
            $(document).on('change', '#erm-select-all', (event) => {
                $('.erm-request-checkbox').prop('checked', event.target.checked);
                this.updateSelectAll();
            });
            $(document).on('change', '.erm-request-checkbox', () => this.updateSelectAll());
            $(document).on('click', '#erm-apply-bulk-action', (event) => {
                event.preventDefault();
                const action = $('#erm-bulk-action-select').val();
                const ids = $('.erm-request-checkbox:checked').map(function() { return this.value; }).get();
                if (!action) { window.alert(ermProData.messages.selectAction); return; }
                if (!ids.length) { window.alert(ermProData.messages.selectItem); return; }
                if (action === 'delete' && !window.confirm(ermProData.messages.confirmDelete)) { return; }
                this.mutate('bulk_action', { bulk_action: action, ids: ids });
            });
            $(document).on('click', '.erm-toggle-block-btn', (event) => {
                event.preventDefault();
                this.mutate('toggle_block', { id: $(event.currentTarget).data('id') });
            });
            $(document).on('click', '.erm-remove-rate-limit-btn', (event) => {
                event.preventDefault();
                this.mutate('update_rate_limit', { id: $(event.currentTarget).data('id'), interval: 0, calls: 0 });
            });
            $(document).on('click', '.erm-delete-btn', (event) => {
                event.preventDefault();
                if (window.confirm(ermProData.messages.confirmDelete)) {
                    // Audit the original state in a single server operation.
                    this.mutate('bulk_action', { bulk_action: 'delete', ids: [$(event.currentTarget).data('id')] });
                }
            });
            $(document).on('click', '.erm-review-btn', (event) => {
                event.preventDefault();
                this.request('get_detail', { id: $(event.currentTarget).data('id') }, (data) => {
                    this.renderDetailModal(data);
                    this.openModal('#erm-detail-modal');
                });
            });
            $(document).on('click', '#erm-clear-all-btn', (event) => {
                event.preventDefault();
                this.openModal('#erm-clear-modal');
            });
            $(document).on('click', '#erm-confirm-clear-btn', (event) => {
                event.preventDefault();
                this.mutate('clear_logs', { mode: $('input[name="erm_clear_mode"]:checked').val() });
            });
            $(document).on('click', '#erm-settings-clear-except-btn, #erm-settings-clear-all-btn', (event) => {
                event.preventDefault();
                const all = event.currentTarget.id === 'erm-settings-clear-all-btn';
                $('#erm-settings-confirm-action').data('mode', all ? 'all' : 'except_blocked');
                $('#erm-settings-confirm-message').text(all ?
                    ermProData.messages.confirmClearAll : ermProData.messages.confirmClearExceptBlocked);
                this.openModal('#erm-settings-confirm-modal');
            });
            $(document).on('click', '#erm-settings-confirm-action', (event) => {
                event.preventDefault();
                this.mutate('clear_logs', { mode: $(event.currentTarget).data('mode') });
            });
            $(document).on('click', '#erm-run-db-upgrade', (event) => {
                event.preventDefault();
                $('#erm-db-upgrade-status').text(ermProData.messages.running);
                this.request('run_db_upgrade', {}, () => window.location.reload());
            });
            $(document).on('click', '.erm-modal-close, .erm-modal-close-btn', (event) => {
                event.preventDefault();
                this.closeModal();
            });
            $(document).on('click', '.erm-modal', (event) => {
                if ($(event.target).hasClass('erm-modal')) { this.closeModal(); }
            });
            $(document).on('keydown', (event) => this.handleModalKey(event));
            this.updateSelectAll();
        },

        request: function(action, data, success) {
            if (this.busy) { return; }
            this.showLoading(true);
            $.ajax({
                url: ermProData.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: $.extend({}, data, { action: 'erm_' + action, nonce: ermProData.nonce })
            }).done((response) => {
                if (!response || !response.success || !response.data) {
                    window.alert(response && response.data && response.data.message ?
                        response.data.message : ermProData.messages.error);
                    return;
                }
                if (response.data.counts) { this.updateStats(response.data.counts); }
                success(response.data);
            }).fail((xhr) => {
                const response = xhr.responseJSON;
                window.alert(response && response.data && response.data.message ?
                    response.data.message : ermProData.messages.error);
                $('#erm-db-upgrade-status').text(ermProData.messages.failed);
            }).always(() => this.showLoading(false));
        },

        mutate: function(action, data) {
            this.request(action, data, () => window.location.reload());
        },

        updateSelectAll: function() {
            const total = $('.erm-request-checkbox').length;
            const count = $('.erm-request-checkbox:checked').length;
            $('#erm-select-all').prop('checked', total > 0 && count === total)
                .prop('indeterminate', count > 0 && count < total);
        },

        updateStats: function(counts) {
            $('.erm-stat-card').first().find('.erm-stat-number').text(Number(counts.total).toLocaleString());
            $('.erm-stat-blocked .erm-stat-number').text(Number(counts.blocked).toLocaleString());
            $('.erm-stat-allowed .erm-stat-number').text(Number(counts.allowed).toLocaleString());
            Object.keys(counts).forEach((key) => {
                $('.erm-filter-tabs [data-count="' + key + '"]').text(Number(counts[key]).toLocaleString());
            });
        },

        renderDetailModal: function(data) {
            const labels = ermProData.labels;
            const body = $('#erm-detail-body').empty();
            const detail = (label, value) => {
                const row = $('<div class="erm-detail-item">');
                $('<div class="erm-detail-label">').text(label).appendTo(row);
                $('<div class="erm-detail-value">').text(value == null ? '' : String(value)).appendTo(row);
                body.append(row);
            };
            [
                ['host', data.host], ['url', data.url], ['method', data.method],
                ['source', data.source], ['count', data.count], ['status', data.status],
                ['first', data.first_request], ['last', data.last_request], ['file', data.source_file],
                ['size', data.request_size], ['code', data.response_code], ['time', data.response_time]
            ].forEach((item) => detail(labels[item[0]], item[1]));
            if (data.track_all_urls) {
                const urls = Array.isArray(data.urls_list) ? data.urls_list : [];
                detail(labels.urls, urls.length ? urls.join('\n') : labels.noUrls);
            }
            if (data.response_data != null && String(data.response_data).length) {
                const row = $('<div class="erm-detail-item">');
                $('<div class="erm-detail-label">').text(labels.body).appendTo(row);
                $('<pre class="erm-response-body">').text(String(data.response_data)).appendTo(row);
                body.append(row);
                $('<button type="button" class="button">').text(labels.download).appendTo(body)
                    .on('click', () => this.downloadResponse(data));
            }
            $('#erm-detail-actions').show();
            $('#erm-rate-interval').val(data.rate_limit_interval);
            $('#erm-rate-calls').val(data.rate_limit_calls || 1);
            $('#erm-modal-toggle-block').text(data.is_blocked ? labels.unblock : labels.block)
                .off('click').on('click', () => this.mutate('toggle_block', { id: data.id }));
            $('#erm-save-rate-limit').off('click').on('click', () => {
                const interval = String($('#erm-rate-interval').val() || '0');
                const calls = String($('#erm-rate-calls').val() || '1');
                if (!/^\d+$/.test(interval) || !/^\d+$/.test(calls) ||
                    Number(interval) > 31536000 || Number(calls) < 1 || Number(calls) > 100000) {
                    window.alert(ermProData.messages.invalidRateLimit);
                    return;
                }
                this.mutate('update_rate_limit', { id: data.id, interval: interval, calls: calls });
            });
            $('#erm-modal-delete').off('click').on('click', () => {
                if (window.confirm(ermProData.messages.confirmDelete)) {
                    this.mutate('bulk_action', { bulk_action: 'delete', ids: [data.id] });
                }
            });
        },

        downloadResponse: function(data) {
            const blob = new Blob([String(data.response_data)], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = String(data.host || 'response').replace(/[^a-z0-9]/gi, '_') + '_' + data.id + '.txt';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        },

        openModal: function(selector) {
            if (this.activeModal) { this.closeModal(); }
            this.returnFocus = document.activeElement;
            this.previousOverflow = document.body.style.overflow;
            this.activeModal = $(selector).removeClass('hidden').attr('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            // A request may still be completing while its dialog opens.
            this.activeModal.find('.erm-modal-content').first().trigger('focus');
        },

        closeModal: function() {
            if (!this.activeModal) { return; }
            this.activeModal.addClass('hidden').attr('aria-hidden', 'true');
            document.body.style.overflow = this.previousOverflow;
            this.activeModal = null;
            if (this.returnFocus && document.contains(this.returnFocus)) { this.returnFocus.focus(); }
        },

        handleModalKey: function(event) {
            if (!this.activeModal) { return; }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeModal();
            } else if (event.key === 'Tab') {
                const controls = this.activeModal.find('button, input, select, textarea, a[href], [tabindex="0"]')
                    .filter(':visible').filter(':enabled');
                const first = controls.first()[0];
                const last = controls.last()[0];
                if (!first) { event.preventDefault(); return; }
                if (event.shiftKey && (document.activeElement === first || !controls.is(document.activeElement))) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !controls.is(document.activeElement))) {
                    event.preventDefault();
                    first.focus();
                }
            }
        },

        showLoading: function(show) {
            this.busy = show;
            $('.erm-pro-wrap, .erm-modal').attr('aria-busy', show ? 'true' : 'false');
            const buttons = $('.erm-pro-wrap button, .erm-modal button').not('.erm-modal-close, .erm-modal-close-btn');
            if (show) {
                buttons.each(function() {
                    $(this).data('erm-was-disabled', this.disabled);
                    this.disabled = true;
                });
            } else {
                buttons.each(function() {
                    this.disabled = Boolean($(this).data('erm-was-disabled'));
                });
            }
        },

        escapeHtml: function(value) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(value == null ? '' : value).replace(/[&<>"']/g, (character) => map[character]);
        }
    };

    $(function() { ERM.init(); });
})(jQuery);
