/**
 * AI 文章生成前端逻辑
 * 生成结果存入暂存区（不直接写 WordPress），用户在结果列表中编辑后手动保存
 */
(function ($) {
    'use strict';

    var cfg     = window['waisgGen'] || {};
    var ajaxurl = cfg.ajaxurl || '';
    var nonce   = cfg.nonce   || '';

    var isRunning  = false;
    var shouldStop = false;

    // =========================================================
    // 结果列表分页（只统计主行 .gen-result-row，不含展开的详情行）
    // =========================================================
    var GEN_PER_PAGE   = 20;
    var genCurrentPage = 1;

    function genMainRows() {
        return $('#gen-result-body .gen-result-row');
    }

    function genTotalPages() {
        return Math.max(1, Math.ceil(genMainRows().length / GEN_PER_PAGE));
    }

    function genShowPage(page) {
        var tp = genTotalPages();
        genCurrentPage = Math.max(1, Math.min(page, tp));

        genMainRows().each(function (i) {
            var rowPage = Math.floor(i / GEN_PER_PAGE) + 1;
            var visible = rowPage === genCurrentPage;
            $(this).toggle(visible);
            // 展开的详情行跟随主行
            var $detail = $(this).next('.gen-detail-row');
            if ($detail.length) {
                $detail.toggle(visible && $detail.data('expanded') === true);
            }
        });

        var total = genMainRows().length;
        $('#gen-result-page-info').text('第 ' + genCurrentPage + ' / ' + tp + ' 页（共 ' + total + ' 条）');
        $('#gen-result-first, #gen-result-prev').prop('disabled', genCurrentPage <= 1);
        $('#gen-result-next, #gen-result-last').prop('disabled', genCurrentPage >= tp);
        $('#gen-result-pagination').toggle(tp > 1);
    }

    /** 新增主行后，自动跳到最后一页 */
    function genAfterAppend() {
        genShowPage(genTotalPages());
    }

    $('#gen-result-first').on('click', function () { genShowPage(1); });
    $('#gen-result-prev' ).on('click', function () { genShowPage(genCurrentPage - 1); });
    $('#gen-result-next' ).on('click', function () { genShowPage(genCurrentPage + 1); });
    $('#gen-result-last' ).on('click', function () { genShowPage(genTotalPages()); });

    // =========================================================
    // Tab 切换
    // =========================================================
    $('.gen-tab-btn').on('click', function () {
        var tab = $(this).data('tab');
        $('.gen-tab-btn').each(function () {
            var active = $(this).data('tab') === tab;
            $(this).css({
                'border-color': active ? '#c3c4c7 #c3c4c7 #fff' : 'transparent',
                'background':   active ? '#fff' : 'transparent',
                'color':        active ? '#1d2327' : '#646970',
            });
        });
        $('#gen-tab-manual, #gen-tab-import, #gen-tab-rewrite').hide();
        $('#gen-tab-' + tab).show();
    });

    // =========================================================
    // 工具函数
    // =========================================================
    function log(msg) {
        var $log = $('#gen-log');
        $log.append('[' + new Date().toLocaleTimeString() + '] ' + msg + '\n');
        $log.scrollTop($log[0].scrollHeight);
    }

    function setProgress(current, total) {
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        $('#gen-progress-bar').css('width', pct + '%');
        $('#gen-progress-text').text('进度：' + current + ' / ' + total + ' 篇（' + pct + '%）');
    }

    function getCommonParams(tabSelector) {
        var $ctx = $(tabSelector);
        return {
            length:         parseInt($ctx.find('.gen-length-input').val(), 10) || 0,
            post_type:      $ctx.find('.gen-post-type-input').val() || 'post',
            category_id:    parseInt($ctx.find('.gen-category-input').val(), 10) || 0,
            language:       $ctx.find('.gen-language-input').val() || 'zh-CN',
            template_id:    parseInt($ctx.find('.gen-template-input').val(), 10) || 0,
            model_override: $ctx.find('.gen-model-override-input').val() || '',
        };
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // =========================================================
    // SEO 评分（生成器详情行用）
    // =========================================================
    function calcSeoScoreHtml(title, seoT, seoD, kw) {
        var tl  = (title || '').length;
        var stl = (seoT  || '').length;
        var sdl = (seoD  || '').length;
        var kwLc = ((kw || '').split(',')[0] || '').trim().toLowerCase();

        function grade(len, g1, g2, y1, y2) {
            if (len === 0) return 'gray';
            if (len >= g1 && len <= g2) return 'green';
            if (len >= y1 && len <= y2) return 'yellow';
            return 'red';
        }
        var s = {
            titleLen: grade(tl,  20, 60,  15, 80),
            seoTLen:  grade(stl, 30, 60,  20, 80),
            seoDLen:  grade(sdl, 120, 160, 100, 200),
            kwInT: kwLc ? ((title || '').toLowerCase().indexOf(kwLc) >= 0 ? 'green' : 'red') : 'gray',
            kwInD: kwLc ? ((seoD  || '').toLowerCase().indexOf(kwLc) >= 0 ? 'green' : 'red') : 'gray',
        };
        var total = 0;
        $.each(s, function (k, v) { if (v === 'green') total += 20; else if (v === 'yellow') total += 10; });
        var totalColor = total >= 80 ? '#00a32a' : total >= 50 ? '#996b00' : '#d63638';
        var tips = { titleLen: '标题长度(20~60)', seoTLen: 'SEO标题(30~60)', seoDLen: 'SEO描述(120~160)', kwInT: '关键词↑标题', kwInD: '关键词↑描述' };
        var fieldMap = { titleLen: 'title', seoTLen: 'seo_title', seoDLen: 'seo_description', kwInT: 'title', kwInD: 'seo_description' };
        var html = '<div class="waisg-seo-score" style="flex-wrap:wrap;">'
            + '<span class="waisg-score-label">SEO 评分：</span>'
            + '<strong style="font-size:15px;font-weight:700;color:' + totalColor + ';">' + total + '</strong>'
            + '<span style="color:#646970;"> / 100</span>&nbsp;';
        $.each(s, function (k, v) {
            html += '<span class="waisg-score-item waisg-score-' + v + '" title="' + tips[k] + '" data-field="' + fieldMap[k] + '">' + tips[k] + '</span> ';
        });
        html += '</div>';
        return html;
    }

    // =========================================================
    // 构建主行 + 详情行 HTML
    // =========================================================

    /** 详情行（可编辑字段 + 保存控件） */
    function buildDetailRow(historyId, d) {
        var statusOptions = '<option value="draft">草稿</option>'
            + '<option value="publish">立即发布</option>'
            + '<option value="future">定时发布</option>';

        return '<tr class="gen-detail-row" data-history-id="' + historyId + '" style="display:none;">'
            + '<td colspan="6" style="padding:16px 24px;background:#fafafa;border-top:2px solid #2271b1;">'
            + '<table style="width:100%;border-collapse:collapse;">'
            + detailField('标题',    '<input type="text" class="gd-title large-text" value="' + escHtml(d.post_title) + '" />')
            + detailField('摘要',    '<textarea class="gd-excerpt large-text" rows="2">' + escHtml(d.post_excerpt) + '</textarea>')
            + detailField('正文（HTML）', '<textarea class="gd-content large-text" rows="10">' + escHtml(d.post_content) + '</textarea>')
            + detailField('SEO 标题',    '<input type="text" class="gd-seo-title large-text" value="' + escHtml(d.seo_title) + '" />')
            + detailField('SEO 描述',    '<textarea class="gd-seo-desc large-text" rows="2">' + escHtml(d.seo_desc) + '</textarea>')
            + detailField('SEO 关键词',  '<input type="text" class="gd-seo-kw large-text" value="' + escHtml(d.seo_kw) + '" />')
            + detailField('SEO 评分', '<div class="gen-seo-score-wrap">' + calcSeoScoreHtml(d.post_title, d.seo_title, d.seo_desc, d.seo_kw) + '</div>')
            + detailField('保存为',
                '<select class="gd-status">' + statusOptions + '</select>'
                + '<input type="datetime-local" class="gd-date" style="display:none;margin:0 6px;" />'
                + '<button type="button" class="gd-save button button-primary" data-history-id="' + historyId + '" style="margin-left:6px;">保存到 WordPress</button>'
                + '<span class="gd-msg" style="font-size:12px;margin-left:8px;display:none;"></span>'
            )
            + '</table>'
            + '</td>'
            + '</tr>';
    }

    function detailField(label, inputHtml) {
        return '<tr>'
            + '<th style="width:110px;padding:6px 10px 6px 0;vertical-align:top;font-weight:600;font-size:13px;white-space:nowrap;">' + label + '</th>'
            + '<td style="padding:4px 0;">' + inputHtml + '</td>'
            + '</tr>';
    }

    // =========================================================
    // 插入/更新结果行
    // =========================================================

    /**
     * 新增或更新结果主行
     * status: 'pending' | 'success' | 'error' | 'saved'
     */
    function upsertResultRow(historyId, index, keyword, title, status, detailData) {
        var $table = $('#gen-result-table');
        var $body  = $('#gen-result-body');
        if ($table.is(':hidden')) $table.show();

        var statusHtml;
        if (status === 'success') {
            statusHtml = '<span style="color:#00a32a;font-size:12px;">✅ 已生成</span>';
        } else if (status === 'saved') {
            statusHtml = '<span style="color:#2271b1;font-size:12px;">📝 已保存</span>';
        } else if (status === 'error') {
            statusHtml = '<span style="color:#c00;font-size:12px;">❌ 失败</span>';
        } else {
            statusHtml = '<span style="color:#f0a500;font-size:12px;">⏳ 生成中</span>';
        }

        var $row = $body.find('.gen-result-row[data-index="' + index + '"]');

        if ($row.length) {
            // 更新已有主行
            $row.find('.col-title').text(title || '（生成中...）');
            $row.find('.col-status').html(statusHtml);
            if (status === 'success' && historyId) {
                $row.find('.col-cb input').prop('disabled', false).val(historyId);
                $row.attr('data-history-id', historyId);
                // 更新展开按钮状态
                var $btn = $row.find('.gen-expand-btn');
                $btn.prop('disabled', false).text('编辑/保存');
                // 找紧跟的详情行（初建时 data-history-id 是 index，需替换整行）
                var $detail = $row.next('.gen-detail-row');
                if ($detail.length && detailData) {
                    $detail.replaceWith(buildDetailRow(historyId, detailData));
                }
            }
        } else {
            // 新建主行
            var cbHtml = (status === 'success' && historyId)
                ? '<input type="checkbox" class="gen-result-check" value="' + historyId + '" />'
                : '<input type="checkbox" class="gen-result-check" disabled />';

            var expandBtn = (status === 'success' && historyId)
                ? '<button type="button" class="gen-expand-btn button button-small" data-index="' + index + '">编辑/保存</button>'
                : '<button type="button" class="gen-expand-btn button button-small" disabled>编辑/保存</button>';

            var mainRow = '<tr class="gen-result-row" data-index="' + index + '" data-history-id="' + (historyId || '') + '">'
                + '<td class="col-cb">' + cbHtml + '</td>'
                + '<td>' + index + '</td>'
                + '<td style="color:#646970;font-size:12px;">' + escHtml(keyword || '') + '</td>'
                + '<td class="col-title">' + escHtml(title || '（生成中...）') + '</td>'
                + '<td class="col-status" style="text-align:center;">' + statusHtml + '</td>'
                + '<td style="text-align:center;">' + expandBtn + '</td>'
                + '</tr>';

            // 详情行（预建，初始隐藏）
            var detailRow = (status === 'success' && historyId && detailData)
                ? buildDetailRow(historyId, detailData)
                : '<tr class="gen-detail-row" data-history-id="' + (historyId || index) + '" style="display:none;"><td colspan="6"></td></tr>';

            $body.append(mainRow + detailRow);
        }

        if (status === 'success') {
            $('#gen-batch-action-bar').show();
        }
        genAfterAppend();
    }


    // =========================================================
    // 展开/收起详情行
    // =========================================================
    $(document).on('click', '.gen-expand-btn', function () {
        var index = $(this).data('index');
        var $mainRow = $(this).closest('.gen-result-row');
        var historyId = $mainRow.data('history-id');
        var $detail = $('#gen-result-body .gen-detail-row[data-history-id="' + historyId + '"]');
        if (!$detail.length) return;

        var willExpand = !($detail.data('expanded') === true);
        $detail.data('expanded', willExpand).toggle(willExpand);
        $(this).text(willExpand ? '收起' : '编辑/保存');
    });

    // =========================================================
    // 详情行：SEO 字段变化时实时更新评分
    // =========================================================
    $(document).on('input change', '.gd-title, .gd-seo-title, .gd-seo-desc, .gd-seo-kw', function () {
        var $detail = $(this).closest('.gen-detail-row');
        $detail.find('.gen-seo-score-wrap').html(calcSeoScoreHtml(
            $detail.find('.gd-title').val(),
            $detail.find('.gd-seo-title').val(),
            $detail.find('.gd-seo-desc').val(),
            $detail.find('.gd-seo-kw').val()
        ));
    });

    // =========================================================
    // 详情行：定时发布联动
    // =========================================================
    $(document).on('change', '.gd-status', function () {
        $(this).closest('td').find('.gd-date').toggle($(this).val() === 'future');
    });

    // =========================================================
    // 详情行：单篇「保存到 WordPress」
    // =========================================================
    $(document).on('click', '.gd-save', function () {
        var historyId = $(this).data('history-id');
        var $detail   = $(this).closest('.gen-detail-row');
        var target    = $detail.find('.gd-status').val();
        var postDate  = $detail.find('.gd-date').val();
        var $msg      = $detail.find('.gd-msg');

        if (target === 'future' && !postDate) {
            alert('请选择定时发布时间。');
            return;
        }

        $(this).prop('disabled', true).text('保存中...');
        $msg.hide();

        $.post(ajaxurl, {
            action:        'waisg_save_staged_to_wp',
            nonce:         nonce,
            history_id:    historyId,
            post_title:    $detail.find('.gd-title').val(),
            post_content:  $detail.find('.gd-content').val(),
            post_excerpt:  $detail.find('.gd-excerpt').val(),
            seo_title:     $detail.find('.gd-seo-title').val(),
            seo_desc:      $detail.find('.gd-seo-desc').val(),
            seo_kw:        $detail.find('.gd-seo-kw').val(),
            target_status: target,
            post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
        }, function (res) {
            var $btn = $detail.find('.gd-save');
            $btn.prop('disabled', false).text('保存到 WordPress');
            if (res.success) {
                var d = res.data;
                var labels = { draft: '草稿', publish: '已发布', future: '已定时' };
                $msg.html('✅ ' + (labels[d.status] || d.status)
                    + ' &nbsp;<a href="' + d.edit_url + '" target="_blank">编辑</a>'
                    + (d.view_url ? ' &nbsp;<a href="' + d.view_url + '" target="_blank">查看</a>' : '')
                ).css('color', '#0a6').show();
                // 更新主行状态
                var $mainRow = $('#gen-result-body .gen-result-row[data-history-id="' + historyId + '"]');
                $mainRow.find('.col-status').html('<span style="color:#2271b1;font-size:12px;">📝 已保存</span>');
                $mainRow.find('.gen-expand-btn').text('查看详情');
            } else {
                $msg.text('❌ ' + (res.data && res.data.message ? res.data.message : '保存失败')).css('color', '#c00').show();
            }
        }).fail(function () {
            $detail.find('.gd-save').prop('disabled', false).text('保存到 WordPress');
            $detail.find('.gd-msg').text('❌ 请求失败').css('color', '#c00').show();
        });
    });

    // =========================================================
    // 完成回调
    // =========================================================
    function onFinish(msg) {
        isRunning  = false;
        shouldStop = false;
        log('🎉 ' + msg);
        $('#gen-progress-text').text(msg);
        $('#gen-start-manual, #gen-start-import').prop('disabled', false);
        $('.gen-stop-btn').hide().prop('disabled', false).text('⏹ 停止');
    }

    // =========================================================
    // 停止按钮
    // =========================================================
    $(document).on('click', '.gen-stop-btn', function () {
        shouldStop = true;
        log('⏹ 用户请求停止，等待当前篇完成后停止...');
        $(this).prop('disabled', true).text('停止中...');
    });

    // =========================================================
    // 模式一：手动输入
    // =========================================================
    $('#gen-start-manual').on('click', function () {
        if (isRunning) return;

        var keywords    = $.trim($('#gen-keywords').val());
        var topic       = $.trim($('#gen-topic').val());
        var description = $.trim($('#gen-description').val());
        var count       = parseInt($('#gen-count').val(), 10) || 1;

        if (!keywords) { alert('关键词为必填项，请输入关键词！'); $('#gen-keywords').focus(); return; }
        if (count < 1 || count > 50) { alert('生成篇数请填写 1~50 之间的数字。'); return; }

        var common = getCommonParams('#gen-tab-manual');
        isRunning  = true;
        shouldStop = false;

        $('#gen-progress-wrap').show();
        $('#gen-result-body').empty();
        $('#gen-result-table').hide();
        $('#gen-batch-action-bar').hide();
        $('#gen-result-pagination').hide();
        genCurrentPage = 1;
        $('#gen-log').text('');
        $('#gen-start-manual').prop('disabled', true);
        $('#gen-tab-manual .gen-stop-btn').show();
        setProgress(0, count);
        log('手动模式：生成 ' + count + ' 篇  关键词：' + keywords);

        var tasks = [];
        for (var i = 0; i < count; i++) {
            tasks.push({ index: i + 1, keyword: keywords, topic: topic || keywords, keywords: keywords, description: description });
        }
        runQueue(tasks, count, common);
    });

    // =========================================================
    // 模式二：批量导入关键词
    // =========================================================
    var importedKeywords = [];

    function updateSummaryLabel() {
        var total = importedKeywords.reduce(function (s, item) { return s + (parseInt(item.count, 10) || 1); }, 0);
        $('#gen-kw-count-label').text('已导入 ' + importedKeywords.length + ' 个关键词，合计生成 ' + total + ' 篇');
    }

    $('#gen-import-btn').on('click', function () {
        var raw = $.trim($('#gen-import-text').val());
        if (!raw) { alert('请先粘贴关键词（每行一个）。'); return; }
        var lines = raw.split(/\n+/).map(function (s) { return $.trim(s); }).filter(Boolean);
        if (!lines.length) { alert('未能识别到有效关键词。'); return; }
        importedKeywords = lines.map(function (kw) { return { kw: kw, count: 1 }; });
        renderKeywordTable();
        $('#gen-kw-list-wrap').show();
        $('#gen-import-extra').show();
        $('#gen-start-import').show();
    });

    function renderKeywordTable() {
        var $body = $('#gen-kw-body');
        $body.empty();
        $.each(importedKeywords, function (i, item) {
            $body.append('<tr>'
                + '<td style="color:#646970;">' + (i + 1) + '</td>'
                + '<td>' + escHtml(item.kw) + '</td>'
                + '<td style="text-align:center;"><input type="number" class="gen-kw-count small-text" data-idx="' + i + '" value="' + (item.count || 1) + '" min="1" max="20" style="width:65px;text-align:center;" /> 篇</td>'
                + '<td><button type="button" class="button button-small gen-kw-del" data-idx="' + i + '" style="color:#c00;">删除</button></td>'
                + '</tr>');
        });
        updateSummaryLabel();
    }

    $(document).on('change input', '.gen-kw-count', function () {
        var idx = parseInt($(this).data('idx'), 10);
        var val = Math.max(1, Math.min(20, parseInt($(this).val(), 10) || 1));
        $(this).val(val);
        if (importedKeywords[idx] !== undefined) importedKeywords[idx].count = val;
        updateSummaryLabel();
    });

    $(document).on('click', '.gen-kw-del', function () {
        importedKeywords.splice(parseInt($(this).data('idx'), 10), 1);
        renderKeywordTable();
        if (!importedKeywords.length) { $('#gen-kw-list-wrap, #gen-import-extra, #gen-start-import').hide(); }
    });

    $(document).on('click', '.gen-kw-set-count', function () {
        var n = parseInt($(this).data('count'), 10) || 1;
        $.each(importedKeywords, function (i, item) { item.count = n; });
        renderKeywordTable();
    });

    $('#gen-kw-clear').on('click', function () {
        if (!confirm('确定要清空关键词列表吗？')) return;
        importedKeywords = [];
        $('#gen-kw-list-wrap, #gen-import-extra, #gen-start-import').hide();
        $('#gen-import-text').val('');
    });

    $('#gen-start-import').on('click', function () {
        if (isRunning) return;
        if (!importedKeywords.length) { alert('关键词列表为空，请先导入关键词。'); return; }

        $('#gen-kw-body .gen-kw-count').each(function () {
            var idx = parseInt($(this).data('idx'), 10);
            if (importedKeywords[idx] !== undefined) importedKeywords[idx].count = Math.max(1, parseInt($(this).val(), 10) || 1);
        });

        var topicPrefix = $.trim($('#gen-import-topic-prefix').val());
        var description = $.trim($('#gen-import-description').val());
        var common      = getCommonParams('#gen-tab-import');
        var tasks = [], serial = 0;

        $.each(importedKeywords, function (i, item) {
            for (var j = 0; j < Math.max(1, parseInt(item.count, 10) || 1); j++) {
                serial++;
                tasks.push({
                    index:       serial,
                    keyword:     item.kw,
                    topic:       topicPrefix ? (topicPrefix + item.kw) : item.kw,
                    keywords:    item.kw,
                    description: description,
                });
            }
        });

        var total = tasks.length;
        isRunning = true; shouldStop = false;

        $('#gen-progress-wrap').show();
        $('#gen-result-body').empty();
        $('#gen-result-table').hide();
        $('#gen-batch-action-bar').hide();
        $('#gen-result-pagination').hide();
        genCurrentPage = 1;
        $('#gen-log').text('');
        $('#gen-start-import').prop('disabled', true);
        $('#gen-tab-import .gen-stop-btn').show();
        setProgress(0, total);
        log('导入模式：' + importedKeywords.length + ' 个关键词，合计 ' + total + ' 篇');

        runQueue(tasks, total, common);
    });

    // =========================================================
    // 生成队列（不再调 wp_insert_post，结果存暂存区）
    // =========================================================
    function runQueue(tasks, total, common) {
        // 保存任务到 localStorage
        try {
            localStorage.setItem('waisg_gen_queue', JSON.stringify({
                timestamp: Date.now(),
                tasks:     tasks,
                common:    common,
                done:      0,
                total:     total,
            }));
        } catch (e) {}

        function next(idx) {
            if (shouldStop) {
                try { localStorage.removeItem('waisg_gen_queue'); } catch (e) {}
                onFinish('已停止（已完成 ' + idx + ' / ' + total + ' 篇）'); return;
            }
            if (idx >= tasks.length) {
                try { localStorage.removeItem('waisg_gen_queue'); } catch (e) {}
                onFinish('全部生成完成！共 ' + total + ' 篇，请在下方审阅并选择保存。'); return;
            }

            var task = tasks[idx];
            log('🤖 第 ' + task.index + '/' + total + ' 篇 ——「' + task.keyword + '」');
            upsertResultRow(0, task.index, task.keyword, '', 'pending', null);

            $.ajax({
                url:  ajaxurl,
                type: 'POST',
                data: $.extend({}, common, {
                    action:      'waisg_gen_article',
                    nonce:       nonce,
                    topic:       task.topic,
                    keywords:    task.keywords,
                    description: task.description,
                    length:      common.length,
                    index:       task.index,
                    total:       total,
                }),
                success: function (res) {
                    setProgress(task.index, total);
                    // 更新 localStorage 进度
                    try {
                        var saved = JSON.parse(localStorage.getItem('waisg_gen_queue') || 'null');
                        if (saved) { saved.done = task.index; localStorage.setItem('waisg_gen_queue', JSON.stringify(saved)); }
                    } catch (e) {}
                    if (res.success) {
                        var d = res.data;
                        log('✅ 第 ' + task.index + ' 篇成功：' + d.post_title);
                        upsertResultRow(d.history_id, task.index, task.keyword, d.post_title, 'success', d);
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : '未知错误';
                        log('❌ 第 ' + task.index + ' 篇失败：' + msg);
                        upsertResultRow(0, task.index, task.keyword, '生成失败', 'error', null);
                    }
                    setTimeout(function () { next(idx + 1); }, 600);
                },
                error: function (xhr) {
                    log('❌ 第 ' + task.index + ' 篇请求异常 HTTP ' + xhr.status);
                    upsertResultRow(0, task.index, task.keyword, '请求异常', 'error', null);
                    setProgress(task.index, total);
                    setTimeout(function () { next(idx + 1); }, 1200);
                },
                timeout: 180000,
            });
        }
        next(0);
    }

    // =========================================================
    // 文章类型切换：隐藏/显示分类行
    // =========================================================
    $(document).on('change', '.gen-post-type-input', function () {
        $(this).closest('table').find('.gen-category-row').toggle($(this).val() === 'post');
    });
    $('.gen-post-type-input').each(function () {
        if ($(this).val() !== 'post') $(this).closest('table').find('.gen-category-row').hide();
    });

    // =========================================================
    // 结果表全选（用 history_id 作为 checkbox value）
    // =========================================================
    $('#gen-result-check-all').on('change', function () {
        $('.gen-result-check:not(:disabled)').prop('checked', $(this).prop('checked'));
    });

    // =========================================================
    // 批量保存到 WordPress
    // =========================================================
    $('#gen-batch-status').on('change', function () {
        $('#gen-batch-post-date').toggle($(this).val() === 'future');
    });

    $('#gen-batch-apply').on('click', function () {
        var historyIds = [];
        $('.gen-result-check:checked:not(:disabled)').each(function () {
            historyIds.push($(this).val());
        });
        if (!historyIds.length) { alert('请先勾选文章。'); return; }

        var target   = $('#gen-batch-status').val();
        var postDate = $('#gen-batch-post-date').val();
        if (target === 'future' && !postDate) { alert('请选择定时发布时间。'); return; }

        var $btn    = $(this).prop('disabled', true).text('保存中...');
        var $result = $('#gen-batch-result').hide();
        var done = 0, fail = 0;
        var pending = historyIds.slice();

        function saveOne() {
            if (!pending.length) {
                $btn.prop('disabled', false).text('批量保存');
                var txt = '✅ ' + done + ' 篇已保存' + (fail ? '，' + fail + ' 篇失败' : '');
                $result.text(txt).css('color', fail ? '#c00' : '#0a6').show();
                return;
            }
            var hid = pending.shift();
            var $detail = $('#gen-result-body .gen-detail-row[data-history-id="' + hid + '"]');

            $.post(ajaxurl, {
                action:        'waisg_save_staged_to_wp',
                nonce:         nonce,
                history_id:    hid,
                post_title:    $detail.find('.gd-title').val()     || '',
                post_content:  $detail.find('.gd-content').val()   || '',
                post_excerpt:  $detail.find('.gd-excerpt').val()   || '',
                seo_title:     $detail.find('.gd-seo-title').val() || '',
                seo_desc:      $detail.find('.gd-seo-desc').val()  || '',
                seo_kw:        $detail.find('.gd-seo-kw').val()    || '',
                target_status: target,
                post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
            }, function (res) {
                if (res.success) {
                    done++;
                    // 更新对应主行状态
                    var $mainRow = $('#gen-result-body .gen-result-row[data-history-id="' + hid + '"]');
                    $mainRow.find('.col-status').html('<span style="color:#2271b1;font-size:12px;">📝 已保存</span>');
                    $mainRow.find('.gen-result-check').prop('checked', false).prop('disabled', true);
                } else { fail++; }
                saveOne();
            }).fail(function () { fail++; saveOne(); });
        }
        saveOne();
    });

    // =========================================================
    // 改写/伪原创 Tab
    // =========================================================
    $('#gen-start-rewrite').on('click', function () {
        var content = $('#gen-rewrite-content').val().trim();
        if (!content) {
            alert('请粘贴原文内容。');
            return;
        }

        var $btn     = $(this).prop('disabled', true).text('改写中...');
        var kw       = $('#gen-rewrite-keywords').val().trim();
        var label    = kw || '改写文章';

        $('#gen-progress-wrap').show();
        setProgress(0, 1);
        $('#gen-result-table').show();
        upsertResultRow(null, 1, label, '（改写中...）', 'pending', null);
        genAfterAppend();
        log('✂️ 开始改写...');

        $.ajax({
            url:     ajaxurl,
            type:    'POST',
            timeout: 180000,
            data: {
                action:         'waisg_rewrite_article',
                nonce:          nonce,
                content:        content,
                keywords:       kw,
                description:    $('#gen-rewrite-description').val().trim(),
                language:       $('#gen-rewrite-language').val() || 'zh-CN',
                post_type:      $('#gen-rewrite-post-type').val() || 'post',
                category_id:    parseInt($('#gen-rewrite-category').val(), 10) || 0,
                template_id:    parseInt($('#gen-rewrite-template').val(), 10) || 0,
                model_override: $('#gen-tab-rewrite .gen-model-override-input').val() || '',
                index:          1,
            },
            success: function (res) {
                $btn.prop('disabled', false).text('✂️ 开始改写文章');
                setProgress(1, 1);
                if (res.success) {
                    var d = res.data;
                    log('✅ 改写完成：' + d.title);
                    upsertResultRow(d.history_id, 1, label, d.title, 'success', d.detail);
                } else {
                    var msg = (res.data && res.data.message) ? res.data.message : '未知错误';
                    log('❌ 改写失败：' + msg);
                    upsertResultRow(null, 1, label, '改写失败', 'error', null);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text('✂️ 开始改写文章');
                setProgress(1, 1);
                log('❌ 请求异常 HTTP ' + xhr.status);
                upsertResultRow(null, 1, label, '请求异常', 'error', null);
            },
        });
    });

    // =========================================================
    // localStorage 任务恢复（生成器）
    // =========================================================
    (function checkGenRestore() {
        try {
            var saved = JSON.parse(localStorage.getItem('waisg_gen_queue') || 'null');
            if (!saved || !saved.tasks || !saved.tasks.length) return;
            if (Date.now() - saved.timestamp > 24 * 3600 * 1000) { localStorage.removeItem('waisg_gen_queue'); return; }
            if (saved.done >= saved.total) { localStorage.removeItem('waisg_gen_queue'); return; }

            var remaining = saved.total - saved.done;
            var $bar = $('<div id="waisg-gen-restore-bar" style="background:#fff8dc;border:1px solid #f0ad00;padding:10px 14px;border-radius:4px;margin-bottom:12px;font-size:13px;">'
                + '⚠️ 发现上次未完成的文章生成任务（已完成 ' + saved.done + '/' + saved.total + ' 篇，剩余 ' + remaining + ' 篇）。'
                + ' <button type="button" id="waisg-gen-restore-btn" class="button button-primary button-small" style="margin:0 8px;">继续生成</button>'
                + ' <button type="button" id="waisg-gen-discard-btn" class="button button-small">放弃</button>'
                + '</div>');
            $('.wrap h1').first().after($bar);

            $('#waisg-gen-restore-btn').on('click', function () {
                $bar.remove();
                var tasks  = saved.tasks.slice(saved.done);
                var common = saved.common || {};
                var total  = saved.total;
                isRunning  = true;
                shouldStop = false;
                $('#gen-progress-wrap').show();
                $('#gen-result-body').empty();
                $('#gen-result-table').hide();
                $('#gen-batch-action-bar').hide();
                $('#gen-result-pagination').hide();
                genCurrentPage = 1;
                $('#gen-log').text('');
                $('#gen-start-manual, #gen-start-import').prop('disabled', true);
                $('.gen-stop-btn').show();
                setProgress(saved.done, total);
                log('恢复任务：从第 ' + (saved.done + 1) + ' 篇开始，共剩余 ' + tasks.length + ' 篇');
                runQueue(tasks, total, common);
            });
            $('#waisg-gen-discard-btn').on('click', function () {
                $bar.remove();
                localStorage.removeItem('waisg_gen_queue');
            });
        } catch (e) {}
    })();

    // =========================================================
    // 生成器详情行：SEO 评分指示灯点击 → 聚焦对应输入框（无 post_id，不调 AI）
    // =========================================================
    $(document).on('click', '.gen-seo-score-wrap .waisg-score-item', function () {
        if ($(this).hasClass('waisg-score-gray') || $(this).hasClass('waisg-score-green')) return;
        var field   = $(this).data('field');
        var $detail = $(this).closest('.gen-detail-row');
        if (!field) return;
        var classMap = { title: 'gd-title', seo_title: 'gd-seo-title', seo_description: 'gd-seo-desc', seo_keywords: 'gd-seo-kw', excerpt: 'gd-excerpt', content: 'gd-content' };
        var cls = classMap[field];
        if (cls) {
            var $input = $detail.find('.' + cls);
            $input.focus();
            $input[0] && $input[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

})(jQuery);
