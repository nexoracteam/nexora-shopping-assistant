(function ($) {
    'use strict';

    // Translated strings come from wp_localize_script(); English is the fallback.
    function t(key, fallback) {
        var config = window.ConvoCartAdminConfig || {};
        return (config.strings && config.strings[key]) || fallback;
    }

    function initColorPickers() {
        if ($.fn.wpColorPicker) { $('.convocart-color-picker').wpColorPicker(); }
    }

    function initFormSubmit() {
        $('.convocart-admin form').on('submit', function () {
            var form = $(this);
            var submit = $(this).find('button[type="submit"], input[type="submit"]');
            if (form.data('submitting') === 1) {
                return false;
            }
            if (submit.length && form.data('confirm') === 1 && !window.confirm('Continue?')) {
                return false;
            }
            form.data('submitting', 1).attr('aria-busy', 'true');
            if (submit.length) {
                submit.prop('disabled', true).attr('data-loading', '1');
            }
        });
    }

    function initSyncForm() {
        $('.convocart-sync-form').on('submit', function () {
            var btn = $(this).find('button[type="submit"], input[type="submit"]');
            if (btn.length) {
                btn.val(t('syncing', 'Syncing\u2026')).prop('disabled', true).attr('data-loading', '1');
            }
        });
    }

    function initProviderTest() {
        $('.convocart-test-provider').on('click', function () {
            var button = $(this);
            var result = button.parent().find('.convocart-test-result');
            var config = window.ConvoCartAdminConfig || {};
            var field = $('.convocart-model-field[data-provider="' + button.data('provider') + '"]');
            if (field.data('dirty')) { result.text(t('saveBeforeTest', ' Save the model selection before testing.')); return; }
            button.prop('disabled', true);
            result.text(t('testing', 'Testing\u2026'));
            $.ajax({
                url: config.restBase + '/admin/providers/' + encodeURIComponent(button.data('provider')) + '/test',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                }
            }).done(function (body) {
                var ok = !!(body && (body.connected || body.ok));
                result.text(' ' + (body.message || (ok ? t('verified', 'Shopping response verified.') : t('notConnected', 'Not connected.'))) + (body.model ? t('modelLabel', ' Model: ') + body.model : '') + (body.code ? ' [' + body.code + ']' : ''));
            }).fail(function (xhr) {
                var msg = t('requestFailed', 'Request failed.');
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                result.text(' ' + msg);
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    }

    function renderModels(field, body) {
        var select = field.find('.convocart-model-select');
        var status = field.find('.convocart-model-status');
        var custom = field.find('.convocart-model-custom');
        var selected = field.data('customDirty') ? custom.val() : (select.val() || select.data('selected') || '');
        selected = String(selected || '').trim();
        if (field.data('provider') === 'gemini') { selected = selected.replace(/^models\//i, ''); }
        var models = (body.models || []).filter(function (model) { return !model.disabled && model.status !== 'retired' && model.status !== 'unsupported'; });
        field.data('availableIds', models.map(function (model) { return model.id; }));
        select.empty();
        $('<option />').val('').text(models.length ? t('chooseModel', 'Choose an available model') : t('noModels', 'No verified available models')).prop('disabled', true).appendTo(select);
        models.forEach(function (model) {
            $('<option />').val(model.id).text(model.label + (model.badge ? ' - ' + model.badge : '')).prop('selected', model.id === selected).appendTo(select);
        });
        var available = field.data('availableIds').indexOf(selected) !== -1;
        select.val(available ? selected : '').prop('disabled', !models.length).prop('required', !!models.length);
        if (!field.data('customDirty')) { custom.val(available ? selected : ''); }
        setModelValidity(custom, available || !field.data('customDirty'));
        var message = body.message || t('modelsLoaded', 'Models loaded.');
        if (body.fetched_at) { message += t('lastSynced', ' Last synchronized: ') + new Date(body.fetched_at * 1000).toLocaleString() + '.'; }
        if (body.selected_missing && body.selected_status) { message += t('savedModel', ' Saved model: ') + body.selected_status.message; }
        if (field.data('customDirty') && !available) { message += t('typedNotListed', ' The typed ID is not in the available list.'); }
        status.text(message);
    }

    function setModelValidity(input, valid) {
        if (input[0] && input[0].setCustomValidity) { input[0].setCustomValidity(valid ? '' : t('chooseFromList', 'Choose a model from the synchronized available list.')); }
        input.attr('aria-invalid', valid ? 'false' : 'true');
    }

    function loadProviderModels(field, refresh) {
        var config = window.ConvoCartAdminConfig || {};
        var provider = field.data('provider');
        var button = field.find('.convocart-refresh-models');
        var status = field.find('.convocart-model-status');
        var sequence = (field.data('sequence') || 0) + 1;
        field.data('sequence', sequence);
        button.prop('disabled', true);
        status.text(t('loadingModels', 'Loading models...'));
        $.ajax({
            url: config.restBase + '/admin/providers/' + encodeURIComponent(provider) + '/models' + (refresh ? '?refresh=1' : ''),
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce);
            }
        }).done(function (body) {
            if (field.data('sequence') !== sequence) { return; }
            renderModels(field, body || {});
        }).fail(function (xhr) {
            if (field.data('sequence') !== sequence) { return; }
            var msg = t('couldNotLoad', 'Could not load models.');
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            status.text(msg);
        }).always(function () {
            if (field.data('sequence') === sequence) { button.prop('disabled', false); }
        });
    }

    function initProviderModels() {
        $('.convocart-model-field').each(function () {
            loadProviderModels($(this), false);
        });
        $('.convocart-refresh-models').on('click', function () {
            loadProviderModels($(this).closest('.convocart-model-field'), true);
        });
        $('.convocart-model-custom').on('input change', function () {
            var input = $(this);
            var field = input.closest('.convocart-model-field');
            var value = String(input.val() || '').trim();
            if (field.data('provider') === 'gemini') { value = value.replace(/^models\//i, ''); }
            field.data('dirty', true).data('customDirty', true);
            var available = (field.data('availableIds') || []).indexOf(value) !== -1;
            field.find('.convocart-model-select').val(available ? value : '');
            setModelValidity(input, available);
        });
        $('.convocart-model-custom').on('invalid', function () { $(this).closest('details').prop('open', true); });
        $('.convocart-model-select').on('change', function () {
            var select = $(this);
            var field = select.closest('.convocart-model-field');
            field.data('dirty', true).data('customDirty', false);
            var input = field.find('.convocart-model-custom').val(select.val());
            setModelValidity(input, true);
        });
        // Keep an open provider screen current without overwriting unsaved input.
        window.setInterval(function () {
            if (!document.hidden) { $('.convocart-model-field').each(function () { loadProviderModels($(this), false); }); }
        }, 300000);
    }

    function initMediaPickers() {
        if (!window.wp || !window.wp.media) {
            return;
        }
        $('[data-convocart-media-picker]').each(function () {
            var picker = $(this);
            var input = picker.find('[data-convocart-media-input]');
            var preview = picker.find('[data-convocart-media-preview] img');
            var chooseBtn = picker.find('[data-convocart-media-choose]');
            var removeBtn = picker.find('[data-convocart-media-remove]');
            var frame = null;

            chooseBtn.on('click', function (event) {
                event.preventDefault();
                if (frame) {
                    frame.open();
                    return;
                }
                frame = wp.media({
                    title: t('mediaTitle', 'Select or upload an image'),
                    library: { type: 'image' },
                    button: { text: t('mediaButton', 'Use this image') },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = attachment.url;
                    if (attachment.sizes && attachment.sizes.thumbnail) {
                        url = attachment.sizes.thumbnail.url;
                    }
                    input.val(attachment.id);
                    preview.attr('src', url).show();
                    removeBtn.show();
                });
                frame.open();
            });

            removeBtn.on('click', function (event) {
                event.preventDefault();
                input.val('0');
                preview.attr('src', '').hide();
                removeBtn.hide();
            });
        });
    }

    $(document).ready(function () {
        initColorPickers();
        initFormSubmit();
        initSyncForm();
        initProviderTest();
        initProviderModels();
        initMediaPickers();
    });
}(jQuery));
