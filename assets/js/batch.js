/**
 * 批量优化页面 JS
 * 优化结果存入暂存区（不直接覆盖文章），用户审阅后手动应用
 */
(function ($) {
    'use strict';

    var cfg      = window['waisgCfg'] || {};
    var ajaxurl  = cfg.ajaxurl  || '';
    var nonce    = cfg.nonce    || '';
    var interval = cfg.interval || 3000;

    var queue      = [];
    var processing = false;
    var stopped    = false;
    var doneCount  = 0;
    var totalCount = 0;
    var failedIds  = []; // 失败项ID列表（用于"仅重试失败项"）
    var successIds = []; // 已成功（已暂存）项ID列表

    // =========================================================
    // 分页状态
    // =========================================================
    var allPosts    = [];
    var currentPage = 1;
    var perPage     = 20;

    function getPerPage() {
        return parseInt($('#waisg-batch-per-page').val(), 10) || 20;
    }

    function totalPages() {
        return Math.max(1, Math.ceil(allPosts.length / perPage));
    }

    /** 将所有行渲染到 tbody */
    function buildAllRows(posts) {
        var rows = '';
        posts.forEach(function (p) {
            rows += '<tr id="waisg-batch-row-' + p.id + '" class="waisg-batch-row" data-page="1">'
                + '<td><input type="checkbox" class="waisg-batch-check" value="' + p.id + '" checked /></td>'
                + '<td>' + p.id + '</td>'
                + '<td>' + $('<div>').text(p.title).html() + '</td>'
                + '<td><span class="waisg-batch-status" id="waisg-batch-status-' + p.id + '">等待</span></td>'
                + '<td id="waisg-batch-score-' + p.id + '" style="font-size:11px;color:#999;">—</td>'
                + '<td id="waisg-batch-op-' + p.id + '" style="text-align:center;"></td>'
                + '</tr>';
        });
        $('#waisg-batch-tbody').html(rows || '<tr><td colspan="6">没有找到符合条件的文章。</td></tr>');
    }

    function assignPages() {
        perPage = getPerPage();
        $('#waisg-batch-tbody tr.waisg-batch-row').each(function (i) {
            $(this).attr('data-page', Math.floor(i / perPage) + 1);
        });
    }

    function showPage(page) {
        perPage = getPerPage();
        var tp = totalPages();
        currentPage = Math.min(Math.max(1, page), tp);

        $('#waisg-batch-tbody tr').each(function () {
            // 主行按页显示
            if ($(this).hasClass('waisg-batch-row')) {
                var visible = parseInt($(this).attr('data-page'), 10) === currentPage;
                $(this).toggle(visible);
                // 详情行跟随主行
                var $detail = $(this).next('.waisg-batch-detail-row');
                if ($detail.length) {
                    $detail.toggle(visible && $detail.data('expanded') === true);
                }
            }
        });

        var info = '第 ' + currentPage + ' / ' + tp + ' 页（共 ' + allPosts.length + ' 篇）';
        $('#waisg-batch-page-info').text(info);
        $('#waisg-batch-first, #waisg-batch-prev').prop('disabled', currentPage <= 1);
        $('#waisg-batch-next, #waisg-batch-last').prop('disabled', currentPage >= tp);

        var allChecked = true;
        $('#waisg-batch-tbody tr.waisg-batch-row:visible .waisg-batch-check').each(function () {
            if (!$(this).prop('checked')) allChecked = false;
        });
        $('#waisg-batch-select-all, #waisg-batch-check-all').prop('checked', allChecked);
        $('#waisg-batch-pagination').toggle(tp > 1);
    }

    // =========================================================
    // 分页控件事件
    // =========================================================
    $('#waisg-batch-first').on('click', function () { showPage(1); });
    $('#waisg-batch-prev' ).on('click', function () { showPage(currentPage - 1); });
    $('#waisg-batch-next' ).on('click', function () { showPage(currentPage + 1); });
    $('#waisg-batch-last' ).on('click', function () { showPage(totalPages()); });

    // =========================================================
    // 获取文章列表
    // =========================================================
    $('#waisg-batch-load').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('获取中...');
        $.post(ajaxurl, {
            action:      'waisg_batch_get_posts',
            nonce:       nonce,
            post_type:   $('#waisg-batch-post-type').val(),
            status:      $('#waisg-batch-status').val(),
            cat_id:      $('#waisg-batch-cat').val(),
            limit:       $('#waisg-batch-limit').val(),
            exclude_ids: $('#waisg-batch-exclude').val(),
        }, function (res) {
            $btn.prop('disabled', false).text('获取文章列表');
            if (!res.success) { alert('获取失败：' + (res.data && res.data.message)); return; }

            allPosts    = res.data.posts;
            currentPage = 1;
            perPage     = getPerPage();

            buildAllRows(allPosts);
            assignPages();
            showPage(1);

            $('#waisg-batch-total').text('（共 ' + allPosts.length + ' 篇）');
            $('#waisg-batch-list-wrap').show();
            $('#waisg-batch-select-all-pages').prop('checked', false);
        }).fail(function () {
            $btn.prop('disabled', false).text('获取文章列表');
            alert('请求失败，请刷新重试。');
        });
    });

    // 全选当前页
    $('#waisg-batch-select-all, #waisg-batch-check-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('#waisg-batch-tbody tr.waisg-batch-row:visible .waisg-batch-check').prop('checked', checked);
        $('#waisg-batch-select-all, #waisg-batch-check-all').prop('checked', checked);
        if (!checked) $('#waisg-batch-select-all-pages').prop('checked', false);
    });

    // 全选所有页
    $('#waisg-batch-select-all-pages').on('change', function () {
        var checked = $(this).prop('checked');
        $('.waisg-batch-check').prop('checked', checked);
        $('#waisg-batch-select-all, #waisg-batch-check-all').prop('checked', checked);
    });

    // =========================================================
    // 开始批量优化
    // =========================================================
    $('#waisg-batch-start').on('click', function () {
        var ids = [];
        $('.waisg-batch-check:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
        if (!ids.length) { alert('请至少选择一篇文章。'); return; }

        // 过滤掉已经成功的项（除非用户取消勾选并重新勾选了）
        var skipped = 0;
        ids = ids.filter(function(id){
            if (successIds.indexOf(id) >= 0) { skipped++; return false; }
            return true;
        });
        if (!ids.length) {
            alert('所选文章都已优化完成，请点击"批量应用"或先取消已成功项再重新勾选。');
            return;
        }

        // 重置失败列表（要重新统计本轮失败的）
        failedIds = [];

        queue      = ids.slice();
        processing = true;
        stopped    = false;
        doneCount  = 0;
        totalCount = ids.length;

        saveBatchState(); // 保存初始状态到 localStorage

        $('#waisg-batch-log').html('');
        $('#waisg-batch-progress-wrap').show();
        // 不再隐藏 action-bar，保留已成功项的批量应用入口
        $('#waisg-batch-retry-failed').hide();
        $('#waisg-batch-start').hide();
        $('#waisg-batch-stop').show();
        updateProgress(0, '开始批量优化，共 ' + totalCount + ' 篇' + (skipped ? '（跳过 ' + skipped + ' 篇已优化项）' : '') + '...');

        processNext();
    });

    // =========================================================
    // 仅重试失败项
    // =========================================================
    $('#waisg-batch-retry-failed').on('click', function () {
        if (!failedIds.length) { alert('没有失败的记录可重试。'); return; }
        if (!confirm('确定仅对 ' + failedIds.length + ' 篇失败的文章重试？')) return;

        queue      = failedIds.slice();
        var retryIds = failedIds.slice(); // 备份本次重试的ID
        failedIds  = []; // 清空失败列表，重新统计
        processing = true;
        stopped    = false;
        doneCount  = 0;
        totalCount = queue.length;

        // 重置这些行的状态显示
        retryIds.forEach(function(id){
            setRowStatus(id, '等待重试...', 'warn');
        });

        saveBatchState();
        $('#waisg-batch-progress-wrap').show();
        $('#waisg-batch-retry-failed').hide();
        $('#waisg-batch-start').hide();
        $('#waisg-batch-stop').show();
        updateProgress(0, '开始重试失败项，共 ' + totalCount + ' 篇...');

        processNext();
    });

    $('#waisg-batch-stop').on('click', function () {
        stopped    = true;
        processing = false;
        $('#waisg-batch-stop').hide();
        $('#waisg-batch-start').show();
        addLog('⏹ 用户手动停止。', 'warn');
    });

    // =========================================================
    // 逐篇处理
    // =========================================================
    function processNext() {
        if (stopped || !queue.length) {
            processing = false;
            $('#waisg-batch-stop').hide();
            $('#waisg-batch-start').show().text('▶ 继续优化未优化项');
            // 根据本轮结果决定显示什么按钮
            if (failedIds.length > 0) {
                $('#waisg-batch-failed-count').text(failedIds.length);
                $('#waisg-batch-retry-failed').show();
            }
            // 只要存在已成功的暂存项（带 history_id 的详情行），就显示批量应用栏
            if ($('.bd-apply[data-history-id]').length > 0) {
                $('#waisg-batch-action-bar').show();
            }
            addLog('🏁 本轮完成。成功 ' + doneCount + ' 篇' + (failedIds.length ? '，失败 ' + failedIds.length + ' 篇' : '') + '。', 'success');
            clearBatchState(); // 任务完成，清除 localStorage
            return;
        }

        var postId = queue.shift();
        var skipHumanize = $('#waisg-batch-skip-humanize').is(':checked');
        var seoOnly = $('#waisg-batch-seo-only').is(':checked');
        var phaseHint = seoOnly ? '仅SEO' : (skipHumanize ? '优化中（跳过润色）' : '优化+润色');
        setRowStatus(postId, phaseHint + '...', 'processing');
        updateProgress(
            Math.round((doneCount / totalCount) * 100),
            '正在处理 ID:' + postId + '（' + (doneCount + 1) + '/' + totalCount + '）— ' + phaseHint
        );

        // 自动翻页到正在处理的行
        var $row = $('#waisg-batch-row-' + postId);
        if ($row.length) {
            var rowPage = parseInt($row.attr('data-page'), 10);
            if (rowPage !== currentPage) showPage(rowPage);
        }

        $.post(ajaxurl, {
            action:         'waisg_batch_optimize_one',
            nonce:          nonce,
            post_id:        postId,
            template_id:    parseInt($('#waisg-batch-template').val(), 10) || 0,
            seo_only:       $('#waisg-batch-seo-only').is(':checked') ? 1 : 0,
            skip_humanize:  $('#waisg-batch-skip-humanize').is(':checked') ? 1 : 0,
            auto_fix_seo:   $('#waisg-batch-auto-fix-seo').is(':checked') ? 1 : 0,
            model_override: $('#waisg-batch-model-override').val() || '',
        }, function (res) {
            doneCount++;
            saveBatchState(); // 每完成一篇，更新 localStorage
            if (res.success) {
                var d = res.data;
                setRowStatus(postId, '✅ 待确认', 'success');
                addLog('✅ [' + postId + '] ' + $('<div>').text(d.title).html() + ' — 优化完成，等待确认');
                // 更新标题单元格为优化后标题
                var $titleCell = $('#waisg-batch-row-' + postId).find('td:nth-child(3)');
                $titleCell.html($('<span>').text(d.title));
                // 渲染操作按钮 + 插入详情行
                renderOptimizeResult(postId, d);
                // 更新主行 SEO 评分（紧凑版）
                renderRowScore(postId, d.optimized);
                // 记录到成功列表
                if (successIds.indexOf(postId) < 0) successIds.push(postId);
                // 如果之前在失败列表中，移除
                var fi = failedIds.indexOf(postId);
                if (fi >= 0) failedIds.splice(fi, 1);
                $('#waisg-batch-action-bar').show();
            } else {
                setRowStatus(postId, '❌ 失败', 'error');
                addLog('❌ [' + postId + '] ' + (res.data && res.data.message), 'error');
                // 记录失败
                if (failedIds.indexOf(postId) < 0) failedIds.push(postId);
            }

            updateProgress(Math.round((doneCount / totalCount) * 100));

            if (!stopped && queue.length) {
                setTimeout(processNext, interval);
            } else {
                processNext();
            }
        }).fail(function () {
            doneCount++;
            setRowStatus(postId, '❌ 请求失败', 'error');
            addLog('❌ [' + postId + '] 请求失败（网络或超时）', 'error');
            if (failedIds.indexOf(postId) < 0) failedIds.push(postId);
            updateProgress(Math.round((doneCount / totalCount) * 100));
            if (!stopped && queue.length) { setTimeout(processNext, interval); } else { processNext(); }
        });
    }

    // =========================================================
    // 构建详情行（原文 vs 优化后对比 + 保存控件）
    // =========================================================
    function renderOptimizeResult(postId, d) {
        // 操作按钮
        $('#waisg-batch-op-' + postId).html(
            '<button type="button" class="waisg-batch-expand button button-small" data-post-id="' + postId + '">查看 & 应用</button>'
        );

        // 如果详情行还不存在则插入
        if (!$('#waisg-batch-detail-' + postId).length) {
            var orig = d.original || {};
            var opt  = d.optimized || {};

            var statusOptions = '<option value="draft">草稿</option>'
                + '<option value="publish">立即发布</option>'
                + '<option value="future">定时发布</option>';

            var detailHtml = '<tr id="waisg-batch-detail-' + postId + '" class="waisg-batch-detail-row" data-post-id="' + postId + '" style="display:none;">'
                + '<td colspan="5" style="padding:0;">'
                + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:2px solid #2271b1;">'
                // 原文（只读）
                + '<div style="padding:16px;background:#fffbe6;border-right:1px solid #ddd;">'
                + '<h4 style="margin:0 0 10px;color:#666;">📄 原文（只读）</h4>'
                + origField('标题',    esc(orig.post_title))
                + origField('摘要',    esc(orig.post_excerpt))
                + origField('正文预览', '<div style="max-height:120px;overflow:auto;font-size:12px;white-space:pre-wrap;">' + esc(orig.post_content) + '</div>')
                + origField('SEO标题', esc(orig.seo_title))
                + origField('SEO描述', esc(orig.seo_desc))
                + origField('SEO关键词', esc(orig.seo_kw))
                + '</div>'
                // 优化后（可编辑）
                + '<div style="padding:16px;background:#f0f9ff;">'
                + '<h4 style="margin:0 0 10px;color:#2271b1;">✨ AI 优化后（可编辑）</h4>'
                + '<table style="width:100%;">'
                + editField('标题',    '<input type="text" class="bd-title large-text" value="' + esc(opt.post_title) + '" />')
                + editField('摘要',    '<textarea class="bd-excerpt large-text" rows="2">' + esc(opt.post_excerpt) + '</textarea>')
                + editField('正文',    '<textarea class="bd-content large-text" rows="8">' + esc(opt.post_content) + '</textarea>')
                + editField('SEO标题', '<input type="text" class="bd-seo-title large-text" value="' + esc(opt.seo_title) + '" />')
                + editField('SEO描述', '<textarea class="bd-seo-desc large-text" rows="2">' + esc(opt.seo_desc) + '</textarea>')
                + editField('SEO关键词', '<input type="text" class="bd-seo-kw large-text" value="' + esc(opt.seo_kw) + '" />')
                + editField('SEO 评分', '<div class="bd-seo-score-wrap">' + calcSeoScoreHtml(opt.post_title, opt.seo_title, opt.seo_desc, opt.seo_kw) + '</div>')
                + editField('应用为',
                    '<select class="bd-status">' + statusOptions + '</select>'
                    + '<input type="datetime-local" class="bd-date" style="display:none;margin:0 6px;" />'
                    + '<button type="button" class="bd-apply button button-primary" data-history-id="' + d.history_id + '" data-post-id="' + postId + '" style="margin-left:6px;">应用到文章</button>'
                    + '<span class="bd-msg" style="font-size:12px;margin-left:8px;display:none;"></span>'
                )
                + '</table>'
                + '</div>'
                + '</div>'
                + '</td>'
                + '</tr>';

            $('#waisg-batch-row-' + postId).after(detailHtml);
        }
    }

    function origField(label, valHtml) {
        return '<div style="margin-bottom:8px;">'
            + '<strong style="font-size:12px;color:#666;">' + label + '：</strong>'
            + '<div style="font-size:13px;color:#333;">' + (valHtml || '—') + '</div>'
            + '</div>';
    }

    function editField(label, inputHtml) {
        return '<tr>'
            + '<th style="width:90px;padding:5px 8px 5px 0;vertical-align:top;font-size:13px;white-space:nowrap;">' + label + '</th>'
            + '<td style="padding:3px 0;">' + inputHtml + '</td>'
            + '</tr>';
    }

    function esc(str) {
        return $('<div>').text(String(str || '')).html();
    }

    // =========================================================
    // 主行紧凑版 SEO 评分（不展开就能看到）
    // =========================================================
    function renderRowScore(postId, opt) {
        if (!opt) return;
        var info = calcSeoScoreData(opt.post_title, opt.seo_title, opt.seo_desc, opt.seo_kw);
        // 简化版：总分 + 5个圆点（绿/黄/红/灰）
        var dots = '';
        var fieldMap = {
            titleLen: { tip: '标题长度', field: 'title' },
            seoTLen:  { tip: 'SEO标题',  field: 'seo_title' },
            seoDLen:  { tip: 'SEO描述',  field: 'seo_description' },
            kwInT:    { tip: '关键词↑标题', field: 'title' },
            kwInD:    { tip: '关键词↑描述', field: 'seo_description' },
        };
        $.each(info.grades, function(k, v){
            var color = v === 'green' ? '#00a32a' : v === 'yellow' ? '#dba617' : v === 'red' ? '#d63638' : '#c3c4c7';
            var meta = fieldMap[k] || { tip: k, field: '' };
            dots += '<span class="waisg-row-dot" title="' + meta.tip + '：' + v +
                    '（点击让 AI 重新优化）" data-grade="' + v + '" data-field="' + meta.field +
                    '" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' +
                    color + ';margin-right:3px;cursor:' + (v === 'green' || v === 'gray' ? 'default' : 'pointer') + ';"></span>';
        });
        var totalColor = info.total >= 80 ? '#00a32a' : info.total >= 50 ? '#dba617' : '#d63638';
        var html = '<div style="display:flex;align-items:center;gap:6px;">'
                 + '<strong style="color:' + totalColor + ';font-size:13px;">' + info.total + '</strong>'
                 + '<span style="font-size:11px;color:#646970;">/100</span>'
                 + '<span style="margin-left:4px;">' + dots + '</span>'
                 + '</div>';
        $('#waisg-batch-score-' + postId).html(html);
    }

    /** 评分数据（与 calcSeoScoreHtml 共用核心逻辑） */
    function calcSeoScoreData(title, seoT, seoD, kw) {
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
        var grades = {
            titleLen: grade(tl,  20, 60,  15, 80),
            seoTLen:  grade(stl, 30, 60,  20, 80),
            seoDLen:  grade(sdl, 120, 160, 100, 200),
            kwInT: kwLc ? ((title || '').toLowerCase().indexOf(kwLc) >= 0 ? 'green' : 'red') : 'gray',
            kwInD: kwLc ? ((seoD  || '').toLowerCase().indexOf(kwLc) >= 0 ? 'green' : 'red') : 'gray',
        };
        var total = 0;
        $.each(grades, function (k, v) { if (v === 'green') total += 20; else if (v === 'yellow') total += 10; });
        return { grades: grades, total: total };
    }

    // 点击主行评分圆点 → 触发对应字段的 AI 重新优化
    $(document).on('click', '.waisg-row-dot', function(){
        var grade = $(this).data('grade');
        if (grade === 'green' || grade === 'gray') return;
        var field = $(this).data('field');
        if (!field) return;
        var $row = $(this).closest('tr.waisg-batch-row');
        var postId = parseInt($row.find('.waisg-batch-check').val(), 10);
        var $detail = $('#waisg-batch-detail-' + postId);
        if (!$detail.length) return;
        // 复用详情行的修复逻辑
        var $scoreItem = $detail.find('.bd-seo-score-wrap .waisg-score-item[data-field="' + field + '"]').first();
        if ($scoreItem.length) {
            $scoreItem.trigger('click');
        }
    });


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
    // 展开/收起详情行
    // =========================================================
    $(document).on('click', '.waisg-batch-expand', function () {
        var postId  = $(this).data('post-id');
        var $detail = $('#waisg-batch-detail-' + postId);
        if (!$detail.length) return;
        var willExpand = !($detail.data('expanded') === true);
        $detail.data('expanded', willExpand).toggle(willExpand);
        $(this).text(willExpand ? '收起' : '查看 & 应用');
    });

    // =========================================================
    // 详情行：SEO 字段变化时实时更新评分（同步主行）
    // =========================================================
    $(document).on('input change', '.bd-title, .bd-seo-title, .bd-seo-desc, .bd-seo-kw', function () {
        var $detail = $(this).closest('.waisg-batch-detail-row');
        var postId  = parseInt($detail.data('post-id'), 10);
        var title   = $detail.find('.bd-title').val();
        var seoT    = $detail.find('.bd-seo-title').val();
        var seoD    = $detail.find('.bd-seo-desc').val();
        var seoKw   = $detail.find('.bd-seo-kw').val();
        $detail.find('.bd-seo-score-wrap').html(calcSeoScoreHtml(title, seoT, seoD, seoKw));
        // 同步更新主行紧凑版评分
        renderRowScore(postId, {
            post_title: title, seo_title: seoT, seo_desc: seoD, seo_kw: seoKw
        });
    });

    // =========================================================
    // 详情行：定时发布联动
    // =========================================================
    $(document).on('change', '.bd-status', function () {
        $(this).closest('td').find('.bd-date').toggle($(this).val() === 'future');
    });

    // =========================================================
    // 详情行：单篇「应用到文章」
    // =========================================================
    $(document).on('click', '.bd-apply', function () {
        var historyId = $(this).data('history-id');
        var postId    = $(this).data('post-id');
        var $detail   = $(this).closest('.waisg-batch-detail-row');
        var target    = $detail.find('.bd-status').val();
        var postDate  = $detail.find('.bd-date').val();
        var $msg      = $detail.find('.bd-msg');

        if (target === 'future' && !postDate) { alert('请选择定时发布时间。'); return; }

        $(this).prop('disabled', true).text('应用中...');
        $msg.hide();

        $.post(ajaxurl, {
            action:        'waisg_save_staged_to_wp',
            nonce:         nonce,
            history_id:    historyId,
            post_title:    $detail.find('.bd-title').val(),
            post_content:  $detail.find('.bd-content').val(),
            post_excerpt:  $detail.find('.bd-excerpt').val(),
            seo_title:     $detail.find('.bd-seo-title').val(),
            seo_desc:      $detail.find('.bd-seo-desc').val(),
            seo_kw:        $detail.find('.bd-seo-kw').val(),
            target_status: target,
            post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
        }, function (res) {
            $detail.find('.bd-apply').prop('disabled', false).text('应用到文章');
            if (res.success) {
                var d = res.data;
                var labels = { draft: '草稿', publish: '已发布', future: '已定时' };
                $msg.html('✅ ' + (labels[d.status] || d.status)
                    + ' &nbsp;<a href="' + d.edit_url + '" target="_blank">编辑文章</a>'
                ).css('color', '#0a6').show();
                setRowStatus(postId, '✅ 已应用', 'success');
                $('#waisg-batch-row-' + postId + ' .waisg-batch-check').prop('disabled', true).prop('checked', false);
            } else {
                $msg.text('❌ ' + (res.data && res.data.message ? res.data.message : '应用失败')).css('color', '#c00').show();
            }
        }).fail(function () {
            $detail.find('.bd-apply').prop('disabled', false).text('应用到文章');
            $detail.find('.bd-msg').text('❌ 请求失败').css('color', '#c00').show();
        });
    });

    // =========================================================
    // 批量应用操作栏
    // =========================================================
    $('#waisg-batch-action-status').on('change', function () {
        $('#waisg-batch-action-date').toggle($(this).val() === 'future');
    });

    $('#waisg-batch-action-apply').on('click', function () {
        // 收集所有已优化且未应用的行（带 history_id 的详情行）
        var items = [];
        $('.waisg-batch-check:checked').each(function () {
            var postId    = parseInt($(this).val(), 10);
            var $detail   = $('#waisg-batch-detail-' + postId);
            var historyId = $detail.find('.bd-apply').data('history-id');
            if (historyId) items.push({ postId: postId, historyId: historyId });
        });
        if (!items.length) { alert('请先勾选已优化完成的文章。'); return; }

        var target   = $('#waisg-batch-action-status').val();
        var postDate = $('#waisg-batch-action-date').val();
        if (target === 'future' && !postDate) { alert('请选择定时发布时间。'); return; }

        var $btn    = $(this).prop('disabled', true).text('应用中...');
        var $result = $('#waisg-batch-action-result').hide();
        var done = 0, fail = 0;
        var pending = items.slice();

        function applyOne() {
            if (!pending.length) {
                $btn.prop('disabled', false).text('批量应用');
                var txt = '✅ ' + done + ' 篇已应用' + (fail ? '，' + fail + ' 篇失败' : '');
                $result.text(txt).css('color', fail ? '#c00' : '#0a6').show();
                return;
            }
            var item    = pending.shift();
            var $detail = $('#waisg-batch-detail-' + item.postId);

            $.post(ajaxurl, {
                action:        'waisg_save_staged_to_wp',
                nonce:         nonce,
                history_id:    item.historyId,
                post_title:    $detail.find('.bd-title').val(),
                post_content:  $detail.find('.bd-content').val(),
                post_excerpt:  $detail.find('.bd-excerpt').val(),
                seo_title:     $detail.find('.bd-seo-title').val(),
                seo_desc:      $detail.find('.bd-seo-desc').val(),
                seo_kw:        $detail.find('.bd-seo-kw').val(),
                target_status: target,
                post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
            }, function (res) {
                if (res.success) {
                    done++;
                    setRowStatus(item.postId, '✅ 已应用', 'success');
                    $('#waisg-batch-row-' + item.postId + ' .waisg-batch-check').prop('disabled', true).prop('checked', false);
                } else { fail++; }
                applyOne();
            }).fail(function () { fail++; applyOne(); });
        }
        applyOne();
    });

    // =========================================================
    // 辅助函数
    // =========================================================
    function updateProgress(pct, text) {
        $('#waisg-batch-progress-bar').css('width', pct + '%').text(pct + '%');
        if (text) $('#waisg-batch-progress-text').text(text);
    }

    function setRowStatus(postId, text, type) {
        $('#waisg-batch-status-' + postId)
            .text(text)
            .removeClass('waisg-batch-s-processing waisg-batch-s-success waisg-batch-s-error waisg-batch-s-warn')
            .addClass('waisg-batch-s-' + (type || ''));
    }

    function addLog(msg, type) {
        var color = type === 'error' ? '#c00' : (type === 'success' ? '#0a6' : (type === 'warn' ? '#996' : '#333'));
        var $log = $('#waisg-batch-log');
        $log.append('<div style="color:' + color + ';">[' + new Date().toLocaleTimeString() + '] ' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    // =========================================================
    // localStorage 任务持久化
    // =========================================================
    var LS_BATCH_KEY = 'waisg_batch_queue';

    function saveBatchState() {
        try {
            // 只保存 ID 列表，不保存完整 post 对象，避免超出 localStorage 5MB 上限
            localStorage.setItem(LS_BATCH_KEY, JSON.stringify({
                timestamp:  Date.now(),
                post_ids:   allPosts.map(function (p) { return p.id; }),
                remaining:  queue.slice(),
                done:       doneCount,
                total:      totalCount,
                seo_only:   $('#waisg-batch-seo-only').is(':checked'),
            }));
        } catch (e) {}
    }

    function clearBatchState() {
        try { localStorage.removeItem(LS_BATCH_KEY); } catch (e) {}
    }

    function checkBatchRestore() {
        try {
            var saved = JSON.parse(localStorage.getItem(LS_BATCH_KEY) || 'null');
            if (!saved || !saved.remaining || !saved.remaining.length) { clearBatchState(); return; }
            if (Date.now() - saved.timestamp > 24 * 3600 * 1000) { clearBatchState(); return; }

            var $bar = $('<div id="waisg-batch-restore-bar" style="background:#fff8dc;border:1px solid #f0ad00;padding:10px 14px;border-radius:4px;margin-bottom:12px;font-size:13px;">'
                + '⚠️ 发现上次未完成的批量优化任务（已完成 ' + saved.done + '/' + saved.total + ' 篇，剩余 '
                + saved.remaining.length + ' 篇）。'
                + ' <button type="button" id="waisg-restore-batch-btn" class="button button-primary button-small" style="margin:0 8px;">继续执行</button>'
                + ' <button type="button" id="waisg-discard-batch-btn" class="button button-small">放弃</button>'
                + '</div>');
            $('.wrap h1').first().after($bar);

            $('#waisg-restore-batch-btn').on('click', function () {
                $bar.remove();
                // 从 post_ids 重建 allPosts（兼容旧格式 posts 字段）
                var ids = saved.post_ids || (saved.posts || []).map(function (p) { return p.id; });
                allPosts   = ids.map(function (id) { return { id: id, title: 'ID: ' + id }; });
                doneCount  = saved.done;
                totalCount = saved.total;
                queue      = saved.remaining.slice();
                if (saved.seo_only) $('#waisg-batch-seo-only').prop('checked', true);
                currentPage = 1;
                perPage     = getPerPage();
                buildAllRows(allPosts);
                assignPages();
                showPage(1);
                $('#waisg-batch-total').text('（共 ' + allPosts.length + ' 篇）');
                $('#waisg-batch-list-wrap').show();
                processing = true;
                stopped    = false;
                $('#waisg-batch-progress-wrap').show();
                $('#waisg-batch-action-bar').hide();
                $('#waisg-batch-start').hide();
                $('#waisg-batch-stop').show();
                updateProgress(Math.round((doneCount / totalCount) * 100), '恢复未完成任务，剩余 ' + queue.length + ' 篇...');
                processNext();
            });
            $('#waisg-discard-batch-btn').on('click', function () {
                $bar.remove();
                clearBatchState();
            });
        } catch (e) {}
    }

    // =========================================================
    // 批量详情行：SEO 评分指示灯点击 → 自动修复该字段
    // 含连续无效保护：连续 2 次未改进则停止自动重试，防止无限消耗 Token
    // =========================================================
    var batchRetryStats = {}; // { 'postId-field': { count: 0, lastGrade: '' } }

    $(document).on('click', '.bd-seo-score-wrap .waisg-score-item', function () {
        var $span = $(this);
        if ($span.hasClass('waisg-score-gray') || $span.hasClass('waisg-score-green')) return;
        var field   = $span.data('field');
        var $detail = $span.closest('.waisg-batch-detail-row');
        var postId  = parseInt($detail.data('post-id'), 10);
        if (!field || !postId) return;

        // 当前评分等级
        var currentGrade = $span.hasClass('waisg-score-red') ? 'red'
                         : $span.hasClass('waisg-score-yellow') ? 'yellow'
                         : 'green';
        var statKey = postId + '-' + field;
        var stat = batchRetryStats[statKey] || { count: 0, lastGrade: '' };
        if (stat.lastGrade && stat.lastGrade === currentGrade) {
            stat.count++;
        }
        if (stat.count >= 2) {
            if (!confirm(
                '此字段已连续 ' + stat.count + ' 次优化但评分未改善（' + currentGrade + '）。\n' +
                '继续重试可能持续消耗 Token 而没有效果。\n\n' +
                '建议：在详情行中手动调整字段内容。\n\n' +
                '是否仍要继续 AI 重试？'
            )) {
                return;
            }
            stat.count = 0;
        }

        $span.css('opacity', '0.5').css('cursor', 'wait');
        $.post(ajaxurl, {
            action:            'waisg_optimize_single',
            nonce:             nonce,
            post_id:           postId,
            field:             field,
            current_title:     $detail.find('.bd-title').val(),
            current_excerpt:   $detail.find('.bd-excerpt').val(),
            current_seo_title: $detail.find('.bd-seo-title').val(),
            current_seo_desc:  $detail.find('.bd-seo-desc').val(),
            current_seo_kw:    $detail.find('.bd-seo-kw').val(),
            current_value:     getBdFieldValue($detail, field),
        }, function (res) {
            $span.css('opacity', '').css('cursor', '');
            if (!res.success) {
                alert('修复失败：' + (res.data && res.data.message ? res.data.message : '未知错误'));
                return;
            }

            var oldValue = getBdFieldValue($detail, field);
            var newValue = res.data.value;
            if (oldValue === newValue) {
                alert('AI 返回内容与原文一致，未做修改。建议手动调整字段内容。');
                stat.count++;
                stat.lastGrade = currentGrade;
                batchRetryStats[statKey] = stat;
                return;
            }

            setBdFieldValue($detail, res.data.field, res.data.value);
            $detail.find('.bd-seo-score-wrap').html(calcSeoScoreHtml(
                $detail.find('.bd-title').val(),
                $detail.find('.bd-seo-title').val(),
                $detail.find('.bd-seo-desc').val(),
                $detail.find('.bd-seo-kw').val()
            ));
            // 同步主行评分
            renderRowScore(postId, {
                post_title: $detail.find('.bd-title').val(),
                seo_title:  $detail.find('.bd-seo-title').val(),
                seo_desc:   $detail.find('.bd-seo-desc').val(),
                seo_kw:     $detail.find('.bd-seo-kw').val(),
            });

            // 检查评分是否改善
            var $newSpan = $detail.find('.bd-seo-score-wrap .waisg-score-item[data-field="' + field + '"]').first();
            var newGrade = $newSpan.hasClass('waisg-score-red') ? 'red'
                         : $newSpan.hasClass('waisg-score-yellow') ? 'yellow'
                         : $newSpan.hasClass('waisg-score-green') ? 'green'
                         : 'gray';
            if (newGrade === currentGrade && currentGrade !== 'green') {
                stat.count++;
                stat.lastGrade = currentGrade;
            } else {
                stat.count = 0;
                stat.lastGrade = newGrade;
            }
            batchRetryStats[statKey] = stat;
        }).fail(function () {
            $span.css('opacity', '').css('cursor', '');
            alert('请求失败，请重试。');
        });
    });

    function getBdFieldValue($d, field) {
        var m = { title: 'bd-title', seo_title: 'bd-seo-title', seo_description: 'bd-seo-desc', seo_keywords: 'bd-seo-kw', excerpt: 'bd-excerpt', content: 'bd-content' };
        return m[field] ? $d.find('.' + m[field]).val() : '';
    }

    function setBdFieldValue($d, field, value) {
        var m = { title: 'bd-title', seo_title: 'bd-seo-title', seo_description: 'bd-seo-desc', seo_keywords: 'bd-seo-kw', excerpt: 'bd-excerpt', content: 'bd-content' };
        if (m[field]) $d.find('.' + m[field]).val(value);
    }

    checkBatchRestore();

})(jQuery);
