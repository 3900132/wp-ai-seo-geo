/**
 * 设置页专用 JS
 * - API 连通测试
 * - 图片 API 测试
 * - Token 用量统计
 */
(function ($) {
    'use strict';

    var cfg    = window['waisgSettings'] || {};
    var nonce  = cfg.nonce || '';
    var ajaxurl = cfg.ajaxurl || '';

    // =========================================================
    // API Key 显示 / 隐藏切换
    // =========================================================
    $('#waisg-toggle-api-key').on('click', function () {
        var $inp = $('#waisg_api_key');
        var isHidden = $inp.attr('type') === 'password';
        $inp.attr('type', isHidden ? 'text' : 'password');
        $(this).text(isHidden ? '🙈' : '👁');
    });

    // =========================================================
    // API 连通测试
    // =========================================================
    $('#waisg-test-api').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('测试中...');
        var $res = $('#waisg-test-api-result').text('').show();

        $.post(ajaxurl, {
            action:  'waisg_test_api',
            nonce:   nonce,
            api_url: $('#waisg_api_url').val(),
            api_key: $('#waisg_api_key').val(),
            model:   $('#waisg_model').val(),
        }, function (res) {
            $btn.prop('disabled', false).text('测试连接');
            var msg = (res.data && res.data.message) ? res.data.message : (res.success ? '成功' : '失败');
            $res.text(msg).css('color', res.success ? '#00a32a' : '#c00');
        }).fail(function () {
            $btn.prop('disabled', false).text('测试连接');
            $res.text('❌ 请求异常').css('color', '#c00');
        });
    });

    // =========================================================
    // 轻量模型测试
    // =========================================================
    $('#waisg-test-lightweight-api').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('测试中...');
        var $res = $('#waisg-test-lightweight-result').text('').show();

        $.post(ajaxurl, {
            action:            'waisg_test_lightweight_api',
            nonce:             nonce,
            api_url:           $('#waisg_api_url').val(),
            api_key:           $('#waisg_api_key').val(),
            lightweight_model: $('#waisg_lightweight_model').val(),
        }, function (res) {
            $btn.prop('disabled', false).text('测试轻量模型');
            var msg = (res.data && res.data.message) ? res.data.message : (res.success ? '成功' : '失败');
            $res.text(msg).css('color', res.success ? '#00a32a' : '#c00');
        }).fail(function () {
            $btn.prop('disabled', false).text('测试轻量模型');
            $res.text('❌ 请求异常').css('color', '#c00');
        });
    });

    // 轻量模型输入框有值时才显示测试行
    function toggleLightweightTestRow() {
        var hasModel = $.trim($('#waisg_lightweight_model').val()).length > 0;
        $('#waisg-lightweight-test-row').toggle(hasModel);
    }
    $('#waisg_lightweight_model').on('input change', toggleLightweightTestRow);
    toggleLightweightTestRow();

    // =========================================================
    // 图片 API 测试
    // =========================================================
    $('#waisg-test-image-api').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('测试中...');
        var $res = $('#waisg-test-image-result');
        $res.html('').show();

        $.post(ajaxurl, {
            action: 'waisg_test_image_api',
            nonce:  nonce,
        }, function (res) {
            $btn.prop('disabled', false).text('测试搜图');
            if (res.success && res.data.url) {
                $res.html('<span style="color:#00a32a;">' + (res.data.message || '✅ 成功') + '</span>'
                    + '<br><img src="' + res.data.url + '" style="max-height:80px;margin-top:6px;border-radius:4px;" />');
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : '❌ 未获取到图片';
                $res.html('<span style="color:#c00;">' + msg + '</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('测试搜图');
            $res.html('<span style="color:#c00;">❌ 请求异常</span>');
        });
    });

    // =========================================================
    // Token 统计
    // =========================================================
    function loadTokenStats() {
        $.post(ajaxurl, { action: 'waisg_get_token_stats', nonce: nonce }, function (res) {
            if (!res.success) return;
            var d = res.data;
            $('#waisg-token-total').text(d.total.toLocaleString());
            $('#waisg-token-monthly').text(d.monthly.toLocaleString());
            $('#waisg-token-month').text(d.month);
            // 分模型统计
            if (d.total_main !== undefined) $('#waisg-token-total-main').text(d.total_main.toLocaleString());
            if (d.monthly_main !== undefined) $('#waisg-token-monthly-main').text(d.monthly_main.toLocaleString());
            if (d.total_lightweight !== undefined) $('#waisg-token-total-lightweight').text(d.total_lightweight.toLocaleString());
            if (d.monthly_lightweight !== undefined) $('#waisg-token-monthly-lightweight').text(d.monthly_lightweight.toLocaleString());
        });
    }
    loadTokenStats();

    $('#waisg-reset-tokens').on('click', function () {
        if (!confirm('确定要重置全部 Token 统计数据吗？')) return;
        var $btn = $(this).prop('disabled', true);
        $.post(ajaxurl, { action: 'waisg_reset_token_stats', nonce: nonce }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                loadTokenStats();
                alert('统计已重置。');
            }
        });
    });

    // =========================================================
    // 内容结构模板 CRUD
    // =========================================================

    /** 重新渲染模板列表 tbody */
    function renderTplList(templates) {
        var $tbody = $('#waisg-tpl-tbody');
        $tbody.empty();
        if (!templates || !templates.length) {
            $tbody.append('<tr id="waisg-tpl-empty"><td colspan="3" style="color:#646970;text-align:center;padding:16px;">暂无模板，点击「新增模板」创建第一个。</td></tr>');
            return;
        }
        $.each(templates, function (i, tpl) {
            var preview = (tpl.structure || '').substring(0, 120) + ((tpl.structure || '').length > 120 ? '...' : '');
            var esc = function (s) { return $('<div>').text(s).html(); };
            $tbody.append(
                '<tr data-id="' + tpl.id + '">'
                + '<td><strong>' + esc(tpl.name) + '</strong></td>'
                + '<td><code style="white-space:pre-wrap;font-size:11px;word-break:break-all;">' + esc(preview) + '</code></td>'
                + '<td>'
                + '<button type="button" class="button button-small waisg-tpl-edit"'
                + ' data-id="' + tpl.id + '"'
                + ' data-name="' + esc(tpl.name) + '"'
                + ' data-structure="' + esc(tpl.structure || '') + '"'
                + ' data-extra="' + esc(tpl.extra || '') + '">编辑</button>'
                + '<button type="button" class="button button-small waisg-tpl-delete"'
                + ' data-id="' + tpl.id + '" style="color:#c00;margin-left:4px;">删除</button>'
                + '</td></tr>'
            );
        });
    }

    function showTplForm(id, name, structure, extra) {
        $('#waisg-tpl-id').val(id || 0);
        $('#waisg-tpl-name').val(name || '');
        $('#waisg-tpl-structure').val(structure || '');
        $('#waisg-tpl-extra').val(extra || '');
        $('#waisg-tpl-msg').text('');
        $('#waisg-tpl-form').slideDown(150);
        $('#waisg-tpl-name').focus();
    }

    function hideTplForm() {
        $('#waisg-tpl-form').slideUp(150);
        $('#waisg-tpl-id').val(0);
        $('#waisg-tpl-name, #waisg-tpl-structure, #waisg-tpl-extra').val('');
        $('#waisg-tpl-msg').text('');
    }

    $('#waisg-tpl-new').on('click', function () {
        showTplForm(0, '', '', '');
    });

    $('#waisg-tpl-cancel').on('click', hideTplForm);

    $(document).on('click', '.waisg-tpl-edit', function () {
        var $btn = $(this);
        showTplForm(
            $btn.data('id'),
            $btn.data('name'),
            $btn.attr('data-structure'),   // attr() 保留换行
            $btn.attr('data-extra')
        );
    });

    $(document).on('click', '.waisg-tpl-delete', function () {
        var id = $(this).data('id');
        if (!confirm('确定要删除这个模板吗？')) return;
        var $btn = $(this).prop('disabled', true);
        $.post(ajaxurl, { action: 'waisg_delete_template', nonce: nonce, id: id }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                renderTplList(res.data.templates);
            } else {
                alert((res.data && res.data.message) || '删除失败');
            }
        });
    });

    $('#waisg-tpl-save').on('click', function () {
        var name      = $.trim($('#waisg-tpl-name').val());
        var structure = $.trim($('#waisg-tpl-structure').val());
        var extra     = $.trim($('#waisg-tpl-extra').val());
        var id        = parseInt($('#waisg-tpl-id').val(), 10) || 0;
        var $msg      = $('#waisg-tpl-msg');

        if (!name)      { $msg.text('请输入模板名称。').css('color', '#c00'); return; }
        if (!structure) { $msg.text('请填写文章结构。').css('color', '#c00'); return; }

        var $btn = $(this).prop('disabled', true).text('保存中...');
        $msg.text('').css('color', '');

        $.post(ajaxurl, {
            action:    'waisg_save_template',
            nonce:     nonce,
            id:        id,
            name:      name,
            structure: structure,
            extra:     extra,
        }, function (res) {
            $btn.prop('disabled', false).text('保存模板');
            if (res.success) {
                $msg.text('✅ ' + res.data.message).css('color', '#00a32a');
                renderTplList(res.data.templates);
                setTimeout(hideTplForm, 800);
            } else {
                $msg.text('❌ ' + ((res.data && res.data.message) || '保存失败')).css('color', '#c00');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('保存模板');
            $msg.text('❌ 请求失败，请重试。').css('color', '#c00');
        });
    });

    // =========================================================
    // 定时优化：页面加载时刷新下次执行时间 + 立即执行按钮
    // =========================================================
    function loadCronLog() {
        $.post(ajaxurl, { action: 'waisg_get_cron_log', nonce: nonce }, function (res) {
            if (!res.success) return;
            var d = res.data;
            if ($('#waisg-cron-next-run').length) {
                $('#waisg-cron-next-run').text(d.next_run || '未计划');
            }
        });
    }
    if ($('#waisg-cron-next-run').length) {
        loadCronLog();
    }

    $('#waisg-cron-run-now').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('执行中...');
        var $res = $('#waisg-cron-run-result').text('').css('color', '#646970');
        $.post(ajaxurl, { action: 'waisg_run_cron_now', nonce: nonce }, function (res) {
            $btn.prop('disabled', false).text('立即执行一次');
            if (res.success) {
                var d   = res.data;
                var msg = '✅ 完成，本次优化 ' + d.count + ' 篇';
                if (d.note) msg += '（' + d.note + '）';
                $res.text(msg).css('color', '#00a32a');
                $('#waisg-cron-next-run').text(d.next_run || '未计划');
            } else {
                var errMsg = (res.data && res.data.message) ? res.data.message : '❌ 执行失败';
                $res.text(errMsg).css('color', '#c00');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('立即执行一次');
            $res.text('❌ 请求失败，请重试。').css('color', '#c00');
        });
    });

    // =========================================================
    // 锚点跳转：从其他页带 #card-id 进来时，平滑滚动到目标并高亮
    // （如错误日志页「保留策略可在 设置 → 十、AI 错误日志」链接）
    // =========================================================
    (function () {
        var hash = window.location.hash;
        if (!hash || hash.length < 2) return;
        var el;
        try { el = document.querySelector(hash); } catch (e) { return; }
        if (!el) return;
        setTimeout(function () {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.style.transition = 'box-shadow 0.4s ease';
            el.style.boxShadow = '0 0 0 3px #2271b1';
            setTimeout(function () { el.style.boxShadow = ''; }, 2200);
        }, 300);
    })();

})(jQuery);
