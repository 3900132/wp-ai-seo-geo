/**
 * 编辑页 AI 功能 JS
 * 注意：SEO 字段通过隐藏表单字段随文章一起提交，由 save_post 钩子处理备份+计数+保存
 */
(function ($) {
    'use strict';

    var cfg     = window['waisgCfg'] || {};
    var ajaxurl = cfg.ajaxurl || '';
    var nonce   = cfg.nonce   || '';
    var postId  = cfg.post_id || 0;

    // 读取「使用模型」选择（main / lightweight / 空）；主元框优先，回退侧边栏
    function getModelOverride() {
        var el = document.getElementById('waisg-model-override') ||
                 document.getElementById('waisg-model-override-side');
        return el ? el.value : '';
    }

    // 读取「跳过二次润色」勾选（勾选=1，否则=0）
    function getSkipHumanize() {
        var el = document.getElementById('waisg-skip-humanize');
        return (el && el.checked) ? 1 : 0;
    }

    // 主元框与侧边栏两个模型下拉联动同步：改其一，另一个跟随
    $(document).on('change', '.waisg-model-override', function () {
        $('.waisg-model-override').val($(this).val());
    });

    // =========================================================
    // 工具函数
    // =========================================================

    function showStatus(msg, type) {
        var $s = $('#waisg-status');
        $s.stop(true, true)
          .removeClass('waisg-status-success waisg-status-error waisg-status-loading')
          .addClass('waisg-status-' + (type || 'loading'))
          .text(msg)   // 用 .text() 而非 .html()，防止后端返回的 AI 原始文本/报错内容触发 XSS
          .show();
        // 不再自动消失，用户操作时再隐藏
    }

    // 用户点击非功能按钮或修改字段时隐藏状态提示（排除 loading 状态，避免清掉进行中的提示）
    $('#waisg-box').on('click', 'button', function () {
        var $s = $('#waisg-status');
        if ($s.hasClass('waisg-status-loading')) return;
        if ($(this).attr('id') !== 'waisg-btn-save-seo') {
            $s.fadeOut(300);
        }
    });

    $('#waisg-seo-title, #waisg-seo-desc, #waisg-seo-kw').on('input', function () {
        var $s = $('#waisg-status');
        if ($s.hasClass('waisg-status-loading')) return;
        $s.fadeOut(300);
    });

    function setLoading(bool) {
        $('#waisg-box .button').prop('disabled', bool);
    }

    /** 判断当前是否为 Gutenberg 块编辑器（DOM 级检测，最可靠） */
    function isGutenberg() {
        return !!document.querySelector('.block-editor');
    }

    /** 获取编辑器内容 */
    function getEditorContent() {
        if (!isGutenberg()) {
            // 经典编辑器：可视模式从 TinyMCE 读，代码模式从 textarea 读
            if (typeof tinymce !== 'undefined' && tinymce.get('content') && !tinymce.get('content').isHidden()) {
                return tinymce.get('content').getContent();
            }
            var ta = document.getElementById('content');
            return ta ? ta.value : '';
        }
        // Gutenberg
        if (window.wp && wp.data && wp.data.select('core/editor')) {
            return wp.data.select('core/editor').getEditedPostContent() || '';
        }
        return '';
    }

    /** 设置编辑器内容 */
    function setEditorContent(html) {
        console.log('📝 尝试设置编辑器内容，长度：', html.length);

        if (!isGutenberg()) {
            console.log('🔄 检测到经典编辑器');
            // 始终更新 textarea（代码模式直接可见）
            var ta = document.getElementById('content');
            if (ta) {
                ta.value = html;
            }
            // 始终同步 TinyMCE 内部状态（不管当前是可视还是代码模式）
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                try {
                    tinymce.get('content').setContent(html);
                } catch (e) {}
            }
            console.log('✓ 经典编辑器已更新');
            return;
        }

        // Gutenberg
        if (window.wp && wp.data && wp.data.dispatch && wp.blocks) {
            try {
                var blocks = wp.blocks.parse(html);
                wp.data.dispatch('core/block-editor').resetBlocks(blocks);
                console.log('✓ Gutenberg 编辑器已更新');
                return;
            } catch (e) {
                console.error('❌ Gutenberg 更新失败：', e);
            }
        }
        console.error('❌ 编辑器内容未更新');
    }

    function getTitle() {
        var el = document.getElementById('title') || document.getElementById('post_title') || document.querySelector('[name="post_title"]');
        return el ? el.value : '';
    }

    function setTitle(val) {
        console.log('📝 更新标题：', val);
        var el = document.getElementById('title') || document.getElementById('post_title') || document.querySelector('[name="post_title"]');
        if (el) {
            el.value = val;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function getExcerpt() {
        var el = document.getElementById('excerpt');
        return el ? el.value : '';
    }

    function setExcerpt(val) {
        console.log('📝 更新摘要：', val);
        var el = document.getElementById('excerpt');
        if (el) {
            el.value = val;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // =========================================================
    // 隐藏字段同步（SEO 字段 → 随文章表单提交给 save_post）
    // =========================================================

    /** 将可见 SEO 输入框的值同步到隐藏字段 */
    function syncHiddenFields() {
        var seoTitle = document.getElementById('waisg-seo-title');
        var seoDesc = document.getElementById('waisg-seo-desc');
        var seoKw = document.getElementById('waisg-seo-kw');
        var seoTitleH = document.getElementById('waisg-seo-title-h');
        var seoDescH = document.getElementById('waisg-seo-desc-h');
        var seoKwH = document.getElementById('waisg-seo-kw-h');

        if (seoTitle && seoTitleH) seoTitleH.value = seoTitle.value;
        if (seoDesc && seoDescH) seoDescH.value = seoDesc.value;
        if (seoKw && seoKwH) seoKwH.value = seoKw.value;
    }

    /** 标记"有 AI 内容待保存" */
    function markAiPending() {
        var el = document.getElementById('waisg-ai-pending');
        if (el) el.value = '1';
    }

    /** 将 AI 结果填入编辑器 */
    function fillResult(data) {
        console.log('📥 开始填入 AI 结果...');
        var emptyFields = [];

        if (data.title)           { setTitle(data.title); } else emptyFields.push('标题');
        if (data.content)         { setEditorContent(data.content); } else emptyFields.push('正文');
        if (data.excerpt)         { setExcerpt(data.excerpt); } else emptyFields.push('摘要');

        if (data.seo_title) {
            var seoTitleEl = document.getElementById('waisg-seo-title');
            if (seoTitleEl) seoTitleEl.value = data.seo_title;
        } else {
            emptyFields.push('SEO标题');
        }

        if (data.seo_description) {
            var seoDescEl = document.getElementById('waisg-seo-desc');
            if (seoDescEl) seoDescEl.value = data.seo_description;
        } else {
            emptyFields.push('SEO描述');
        }

        if (data.seo_keywords) {
            var seoKwEl = document.getElementById('waisg-seo-kw');
            if (seoKwEl) seoKwEl.value = data.seo_keywords;
        } else {
            emptyFields.push('SEO关键词');
        }

        syncHiddenFields();
        markAiPending();
        updateCharCount();
        waisgLastResult = data;

        var stagingBar = document.getElementById('waisg-staging-bar');
        if (stagingBar) stagingBar.style.display = 'block';

        calcSeoScore();
        if (data.seo_keywords) loadLinkSuggestions(data.seo_keywords);

        console.log('✅ AI 结果已填入编辑器');

        // 如果有字段为空，显示警告
        if (emptyFields.length > 0) {
            console.warn('⚠️ 以下字段 AI 返回为空：' + emptyFields.join('、'));
        }
    }

    /** 字符计数 */
    function updateCharCount() {
        var seoTitleEl = document.getElementById('waisg-seo-title');
        var seoDescEl = document.getElementById('waisg-seo-desc');
        var seoTitleCountEl = document.getElementById('waisg-seo-title-count');
        var seoDescCountEl = document.getElementById('waisg-seo-desc-count');

        var t = seoTitleEl ? (seoTitleEl.value || '').length : 0;
        var d = seoDescEl ? (seoDescEl.value || '').length : 0;

        if (seoTitleCountEl) {
            seoTitleCountEl.textContent = t + '/60';
            seoTitleCountEl.style.color = t > 60 ? '#c00' : '#646970';
        }
        if (seoDescCountEl) {
            seoDescCountEl.textContent = d + '/160';
            seoDescCountEl.style.color = d > 160 ? '#c00' : '#646970';
        }
    }

    // SEO 输入框变化时同步到隐藏字段
    var seoTitleEl = document.getElementById('waisg-seo-title');
    var seoDescEl = document.getElementById('waisg-seo-desc');
    var seoKwEl = document.getElementById('waisg-seo-kw');

    if (seoTitleEl) {
        seoTitleEl.addEventListener('input', function() {
            syncHiddenFields();
            updateCharCount();
            calcSeoScore();
        });
    }
    if (seoDescEl) {
        seoDescEl.addEventListener('input', function() {
            syncHiddenFields();
            updateCharCount();
            calcSeoScore();
        });
    }
    if (seoKwEl) {
        seoKwEl.addEventListener('input', function() {
            syncHiddenFields();
            updateCharCount();
            calcSeoScore();
        });
    }

    updateCharCount();
    syncHiddenFields(); // 初始化同步
    calcSeoScore();     // 初始化评分（如已有内容）

    // =========================================================
    // SEO 评分
    // =========================================================
    var waisgLastResult = null;

    function calcSeoScore() {
        var title = getTitle();
        var seoT  = $('#waisg-seo-title').val() || '';
        var seoD  = $('#waisg-seo-desc').val()  || '';
        var kw    = ($('#waisg-seo-kw').val() || '').replace(/[，、；;｜|]/g, ',').split(',')[0].trim().toLowerCase();

        // 所有字段均为空时隐藏面板（避免新文章显示全灰指示灯）
        if (!title && !seoT && !seoD && !kw) {
            $('#waisg-seo-score').hide();
            return;
        }

        function grade(len, g1, g2, y1, y2) {
            if (len >= g1 && len <= g2) return 'green';
            if (len >= y1 && len <= y2) return 'yellow';
            return 'red';
        }

        var tl  = title.length;
        var stl = seoT.length;
        var sdl = seoD.length;

        var s = {
            titleLen : tl  > 0 ? grade(tl,  20, 60,  15, 80)   : 'gray',
            seoTLen  : stl > 0 ? grade(stl, 30, 60,  20, 80)   : 'gray',
            seoDLen  : sdl > 0 ? grade(sdl, 120, 160, 100, 200): 'gray',
            kwInT    : kw ? (title.toLowerCase().indexOf(kw) >= 0 ? 'green' : 'red') : 'gray',
            kwInD    : kw ? (seoD.toLowerCase().indexOf(kw)  >= 0 ? 'green' : 'red') : 'gray',
        };

        var total = 0;
        $.each(s, function (k, v) { if (v === 'green') total += 20; else if (v === 'yellow') total += 10; });

        $('#waisg-score-title-len').attr('class', 'waisg-score-item waisg-score-' + s.titleLen);
        $('#waisg-score-seo-t-len').attr('class', 'waisg-score-item waisg-score-' + s.seoTLen);
        $('#waisg-score-seo-d-len').attr('class', 'waisg-score-item waisg-score-' + s.seoDLen);
        $('#waisg-score-kw-in-t').attr('class',   'waisg-score-item waisg-score-' + s.kwInT);
        $('#waisg-score-kw-in-d').attr('class',   'waisg-score-item waisg-score-' + s.kwInD);

        $('#waisg-score-total').text(total)
            .css('color', total >= 80 ? '#00a32a' : total >= 50 ? '#996b00' : '#d63638');
        $('#waisg-seo-score').show();
    }

    // =========================================================
    // 暂存到待处理
    // =========================================================
    $('#waisg-btn-stage').on('click', function () {
        if (!postId) { alert('请先保存文章。'); return; }
        var $btn = $(this).prop('disabled', true).text('暂存中...');
        $.post(ajaxurl, {
            action:       'waisg_stage_from_editor',
            nonce:        nonce,
            post_id:      postId,
            post_title:   getTitle(),
            post_content: getEditorContent(),
            post_excerpt: getExcerpt(),
            seo_title:    $('#waisg-seo-title').val(),
            seo_desc:     $('#waisg-seo-desc').val(),
            seo_kw:       $('#waisg-seo-kw').val(),
        }, function (res) {
            $btn.prop('disabled', false).text('📥 暂存到待处理');
            if (res.success) {
                showStatus('✅ 已暂存到「待处理」！可在「优化历史」中查看和应用。', 'success');
                $('#waisg-staging-bar').hide();
            } else {
                showStatus('❌ ' + ((res.data && res.data.message) || '暂存失败'), 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('📥 暂存到待处理');
            showStatus('❌ 请求失败，请重试。', 'error');
        });
    });

    $('#waisg-btn-dismiss-stage').on('click', function () {
        $('#waisg-staging-bar').hide();
    });

    // =========================================================
    // 内链建议
    // =========================================================
    function loadLinkSuggestions(keywords) {
        if (!keywords || !postId) return;
        $.post(ajaxurl, {
            action:   'waisg_suggest_links',
            nonce:    nonce,
            post_id:  postId,
            keywords: keywords
        }, function (res) {
            if (!res.success || !res.data.links || !res.data.links.length) return;
            // 防御性转义：标题/URL 虽来自站内数据，仍转义防止意外注入
            function escAttr(s) { return $('<div/>').text(s == null ? '' : String(s)).html().replace(/"/g, '&quot;'); }
            function escHtml(s) { return $('<div/>').text(s == null ? '' : String(s)).html(); }
            // 协议白名单：只允许 http/https，防止 javascript: 等协议注入
            function safeUrl(u) { return /^https?:\/\//i.test(String(u)) ? u : '#'; }
            var html = '<div class="waisg-link-panel"><span class="waisg-link-panel-title">🔗 内链建议：</span>';
            $.each(res.data.links, function (i, lnk) {
                var safeUrl = escAttr(safeUrl(lnk.url));
                var safeTitle = escHtml(lnk.title);
                var aHtml = '<a href="' + safeUrl + '">' + safeTitle + '</a>';
                html += '<span class="waisg-link-item">'
                    + '<a href="' + safeUrl + '" target="_blank">' + safeTitle + '</a> '
                    + '<button type="button" class="button-link waisg-link-copy" data-code="' + aHtml.replace(/"/g, '&quot;') + '">复制</button>'
                    + '</span> ';
            });
            html += '</div>';
            $('#waisg-link-suggestions').html(html).show();
        });
    }

    $(document).on('click', '.waisg-link-copy', function () {
        var code = $(this).attr('data-code');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code).then(function () { alert('已复制到剪贴板！'); });
        } else {
            var $ta = $('<textarea>').val(code).appendTo('body');
            $ta[0].select();
            document.execCommand('copy');
            $ta.remove();
            alert('已复制到剪贴板！');
        }
    });

    // =========================================================
    // AI 生成文章
    // =========================================================
    $('#waisg-btn-generate').on('click', function () {
        var topic    = $('#waisg-gen-topic').val().trim();
        var keywords = $('#waisg-gen-keywords').val().trim();
        var length   = parseInt($('#waisg-gen-length').val(), 10) || 0;

        if (!topic) {
            alert('请输入文章主题/要求。');
            return;
        }
        setLoading(true);
        showStatus('🤖 AI 正在生成文章，请稍候（可能需要 10~30 秒）...', 'loading');

        $.post(ajaxurl, {
            action:      'waisg_generate_article',
            nonce:       nonce,
            topic:       topic,
            keywords:    keywords,
            length:      length,
            template_id: parseInt($('#waisg-opt-template').val(), 10) || 0,
            model_override: getModelOverride(),
            skip_humanize:  getSkipHumanize(),
        }, function (res) {
            setLoading(false);
            if (!res.success) {
                showStatus('❌ ' + res.data.message, 'error');
                return;
            }
            fillResult(res.data);
            showStatus('✅ 文章已生成并填入编辑器。请检查内容后，点击「发布/更新」保存，届时将自动记录优化次数。', 'success');
        }).fail(function () {
            setLoading(false);
            showStatus('❌ 请求失败，请检查网络或 API 配置后重试。', 'error');
        });
    });

    // =========================================================
    // 一键优化全部
    // =========================================================
    $('#waisg-btn-optimize-all').on('click', function () {
        console.log('🔘 点击了「一键优化全部」按钮');

        if (!postId) {
            console.warn('⚠️ 文章 ID 为空，postId=', postId);
            alert('请先保存文章（点击「发布」）再进行优化。');
            return;
        }

        console.log('✓ 文章 ID 有效：', postId);

        if (!confirm('确定要一键优化全部字段吗？\n优化结果将填入编辑器，不会立即保存。\n请检查内容后手动点击「更新」保存。')) {
            console.log('用户取消了操作');
            return;
        }

        console.log('📤 正在发送优化请求...');
        setLoading(true);
        showStatus('🚀 AI 正在优化全部字段，请稍候...', 'loading');

        $.post(ajaxurl, {
            action:      'waisg_optimize_all',
            nonce:       nonce,
            post_id:     postId,
            template_id: parseInt($('#waisg-opt-template').val(), 10) || 0,
            model_override: getModelOverride(),
            skip_humanize:  getSkipHumanize(),
        }, function (res) {
            console.log('✅ 收到服务器响应：', res);
            setLoading(false);

            if (!res.success) {
                console.error('❌ 优化失败：', res.data);
                showStatus('❌ ' + res.data.message, 'error');
                return;
            }

            console.log('📝 AI 返回的优化结果：', res.data);
            fillResult(res.data);
            showStatus('✅ 全部字段已优化并填入编辑器。请检查后点击「更新」保存，将自动备份旧版本并记录优化次数。', 'success');
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.error('❌ AJAX 请求失败：', { textStatus, errorThrown, response: jqXHR.responseText });
            setLoading(false);
            showStatus('❌ 请求失败：' + textStatus, 'error');
        });
    });

    // =========================================================
    // 仅优化 SEO 字段（不修改正文，节省 Token）
    // =========================================================
    $('#waisg-btn-optimize-seo-only').on('click', function () {
        if (!postId) {
            alert('请先保存文章（点击「发布」）再进行优化。');
            return;
        }
        if (!confirm('确定要仅优化 SEO 字段吗？\n不会修改正文内容，Token 消耗更少。')) return;

        setLoading(true);
        showStatus('🎯 AI 正在优化 SEO 字段，请稍候...', 'loading');

        $.post(ajaxurl, {
            action:      'waisg_optimize_seo_only',
            nonce:       nonce,
            post_id:     postId,
            template_id: parseInt($('#waisg-opt-template').val(), 10) || 0,
            model_override: getModelOverride(),
        }, function (res) {
            setLoading(false);
            if (!res.success) {
                showStatus('❌ ' + res.data.message, 'error');
                return;
            }
            fillResult(res.data);
            showStatus('✅ SEO 字段已优化并填入编辑器。正文内容未修改。请检查后点击「更新」保存。', 'success');
        }).fail(function () {
            setLoading(false);
            showStatus('❌ 请求失败，请重试。', 'error');
        });
    });

    // =========================================================
    // 单字段优化
    // =========================================================
    $(document).on('click', '.waisg-btn-single', function () {
        if (!postId) {
            alert('请先保存文章再进行优化。');
            return;
        }
        var field = $(this).data('field');
        var label = $(this).text();

        doOptimizeSingle(field, label, false);
    });

    /**
     * 单字段优化的核心逻辑（普通按钮 + SEO 评分点击 + need_confirm 重试共用）
     * @param field  字段名
     * @param label  字段中文名（用于状态提示）
     * @param force  是否强制优化（跳过达标预检）
     */
    function doOptimizeSingle(field, label, force) {
        setLoading(true);
        showStatus('🔧 正在优化【' + label + '】...', 'loading');

        $.post(ajaxurl, {
            action:              'waisg_optimize_single',
            nonce:               nonce,
            post_id:             postId,
            field:               field,
            current_title:       getTitle(),
            current_excerpt:     getExcerpt(),
            current_seo_title:   $('#waisg-seo-title').val(),
            current_seo_desc:    $('#waisg-seo-desc').val(),
            current_seo_kw:      $('#waisg-seo-kw').val(),
            current_value:       getCurrentFieldValue(field),
            model_override:      getModelOverride(),
            skip_humanize:       getSkipHumanize(),
            force:               force ? 1 : 0
        }, function (res) {
            setLoading(false);
            if (!res.success) {
                showStatus('❌ ' + res.data.message, 'error');
                return;
            }
            // 达标确认：后端检测到字段已达标，提示用户是否仍要消耗 Token 重新优化
            if (res.data.need_confirm) {
                if (confirm(res.data.message)) {
                    doOptimizeSingle(field, label, true);
                }
                return;
            }
            applySingleResult(res.data.field, res.data.value);
            syncHiddenFields();
            markAiPending();
            calcSeoScore();
            // 显示暂存到待处理提示条（与一键优化全部一致，每次优化都可暂存）
            var stagingBar = document.getElementById('waisg-staging-bar');
            if (stagingBar) stagingBar.style.display = 'block';
            showStatus('✅ 【' + label + '】已优化，请检查后手动点击「更新」保存。', 'success');
        }).fail(function () {
            setLoading(false);
            showStatus('❌ 请求失败，请重试。', 'error');
        });
    }

    function getCurrentFieldValue(field) {
        switch (field) {
            case 'title':           return getTitle();
            case 'content':         return getEditorContent();
            case 'excerpt':         return getExcerpt();
            case 'seo_title':       return $('#waisg-seo-title').val();
            case 'seo_description': return $('#waisg-seo-desc').val();
            case 'seo_keywords':    return $('#waisg-seo-kw').val();
        }
        return '';
    }

    function applySingleResult(field, value) {
        switch (field) {
            case 'title':           setTitle(value);          break;
            case 'content':         setEditorContent(value);  break;
            case 'excerpt':         setExcerpt(value);        break;
            case 'seo_title':       $('#waisg-seo-title').val(value); break;
            case 'seo_description': $('#waisg-seo-desc').val(value);  break;
            case 'seo_keywords':    $('#waisg-seo-kw').val(value);    break;
        }
        updateCharCount();
    }

    // =========================================================
    // 「保存 SEO 字段」按钮（立即通过 AJAX 写入数据库，不需要更新文章）
    // =========================================================
    $('#waisg-btn-save-seo').on('click', function () {
        if (!postId) {
            alert('请先保存文章。');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('保存中...');
        var seoTitle = $('#waisg-seo-title').val();
        var seoDesc = $('#waisg-seo-desc').val();
        var seoKw = $('#waisg-seo-kw').val();

        console.log('📤 正在保存 SEO 字段：', { seoTitle, seoDesc, seoKw });

        $.post(ajaxurl, {
            action:    'waisg_save_result',
            nonce:     nonce,
            post_id:   postId,
            save_type: 'seo',
            seo_title: seoTitle,
            seo_desc:  seoDesc,
            seo_kw:    seoKw
        }, function (res) {
            console.log('✅ 保存响应：', res);

            if (res.success) {
                showStatus('✅ ' + (res.data && res.data.message ? res.data.message : 'SEO 字段已保存！'), 'success');
                // 同步更新页面上 SEO 插件的表单字段
                if (res.data && res.data.data) {
                    var keys = res.data.data;
                    if (keys.title_key && seoTitle) {
                        $('[name="' + keys.title_key + '"]').val(seoTitle);
                    }
                    if (keys.desc_key && seoDesc) {
                        $('[name="' + keys.desc_key + '"]').val(seoDesc);
                    }
                    if (keys.kw_key && seoKw) {
                        $('[name="' + keys.kw_key + '"]').val(seoKw);
                    }
                }
                $btn.prop('disabled', false).text('💾 保存 SEO 字段到数据库');
            } else {
                $btn.prop('disabled', false).text('💾 保存 SEO 字段到数据库');
                var errorMsg = (res.data && res.data.message) || '保存失败，请重试';
                console.error('❌ 保存错误：', res.data);
                showStatus('❌ ' + errorMsg, 'error');
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).text('💾 保存 SEO 字段到数据库');
            console.error('❌ AJAX 请求失败：', { textStatus, errorThrown });
            showStatus('❌ 请求失败：' + textStatus, 'error');
        });
    });

    // =========================================================
    // Gutenberg 保存完成后实时刷新「AI 优化次数」显示
    // Gutenberg 保存后不刷新页面，需要通过 subscribe 监听保存状态变化
    // =========================================================
    if (window.wp && wp.data && wp.data.subscribe && postId) {
        var wasSaving = false;
        wp.data.subscribe(function () {
            var isSaving = wp.data.select('core/editor') &&
                           wp.data.select('core/editor').isSavingPost();
            // 从"保存中"变为"已完成"时触发
            if (wasSaving && !isSaving) {
                // 延迟 500ms 确保 save_post 钩子已执行完毕
                setTimeout(function () {
                    $.post(ajaxurl, {
                        action:  'waisg_get_opt_count',
                        nonce:   nonce,
                        post_id: postId
                    }, function (res) {
                        if (res.success) {
                            $('#waisg-opt-count').text(res.data.count);
                            // 重置 AI 待保存标记（已经保存完成）
                            $('#waisg-ai-pending').val('0');
                        }
                    });
                }, 500);
            }
            wasSaving = isSaving;
        });
    }

    // =========================================================
    // SEO 评分指示灯点击 → 自动修复该字段
    // 含连续无效保护：连续 2 次未改进评分则停止自动重试，防止无限消耗 Token
    // =========================================================
    var waisgRetryStats = {}; // { field: { count: 0, lastGrade: '' } }

    $(document).on('click', '#waisg-seo-score .waisg-score-item', function () {
        if ($(this).hasClass('waisg-score-gray')) return;
        if (!postId) { alert('请先保存文章再进行优化。'); return; }
        var field = $(this).data('field');
        if (!field) return;
        var $item = $(this);
        var label = $item.text();

        // 当前评分等级（red / yellow / green）
        var currentGrade = $item.hasClass('waisg-score-red') ? 'red'
                         : $item.hasClass('waisg-score-yellow') ? 'yellow'
                         : 'green';

        // 连续无效保护：上次优化后等级未改进则计数 +1，2 次后阻止并提示
        var stat = waisgRetryStats[field] || { count: 0, lastGrade: '' };
        if (stat.lastGrade && stat.lastGrade === currentGrade) {
            stat.count++;
        }
        if (stat.count >= 2) {
            if (!confirm(
                '【' + label + '】已连续 ' + stat.count + ' 次优化但评分未改善（' + currentGrade + '）。\n' +
                '继续重试可能持续消耗 Token 而没有效果。\n\n' +
                '建议：手动编辑此字段以满足长度/关键词要求。\n\n' +
                '是否仍要继续 AI 重试？'
            )) {
                return;
            }
            // 用户坚持继续则重置计数
            stat.count = 0;
        }

        // 优化执行（抽成函数，支持 force 绕过达标预检 + need_confirm 确认）
        function runScoreOptimize(force) {
            setLoading(true);
            showStatus('🔄 正在优化【' + label + '】... ' + (stat.count > 0 ? '（已重试 ' + stat.count + ' 次）' : ''), 'loading');
            $.post(ajaxurl, {
                action:            'waisg_optimize_single',
                nonce:             nonce,
                post_id:           postId,
                field:             field,
                current_title:     getTitle(),
                current_excerpt:   getExcerpt(),
                current_seo_title: $('#waisg-seo-title').val(),
                current_seo_desc:  $('#waisg-seo-desc').val(),
                current_seo_kw:    $('#waisg-seo-kw').val(),
                current_value:     getCurrentFieldValue(field),
                model_override:    getModelOverride(),
                force:             force ? 1 : 0,
            }, function (res) {
                setLoading(false);
                if (!res.success) { showStatus('❌ ' + res.data.message, 'error'); return; }

                // 达标确认：字段已达标时后端返回 need_confirm，让用户决定是否强制优化
                if (res.data.need_confirm) {
                    if (confirm(res.data.message)) {
                        runScoreOptimize(true);  // 带 force=1 重新请求
                    }
                    return;
                }

                var oldValue = getCurrentFieldValue(field);
                var newValue = res.data.value;

                // 内容完全相同则提示用户（节省继续点击的冲动）
                if (oldValue === newValue) {
                    showStatus('⚠️ 【' + label + '】AI 返回内容与原文一致，未做修改。建议手动调整。', 'error');
                    stat.count++;
                    stat.lastGrade = currentGrade;
                    waisgRetryStats[field] = stat;
                    return;
                }

                applySingleResult(res.data.field, res.data.value);
                syncHiddenFields();
                markAiPending();
                calcSeoScore();
                // 显示暂存提示条（与一键优化全部一致，单字段优化结果也应可暂存）
                var stagingBar = document.getElementById('waisg-staging-bar');
                if (stagingBar) stagingBar.style.display = 'block';

                // 检查评分是否改善（取当前字段对应评分项的等级）
                var newGrade = waisgGetFieldGrade(field);
                if (newGrade === currentGrade && currentGrade !== 'green') {
                    // 未改善：计数 +1
                    stat.count++;
                    stat.lastGrade = currentGrade;
                    showStatus('⚠️ 【' + label + '】已优化但评分仍为 ' + currentGrade + '。可再次点击重试，或手动调整。', 'error');
                } else {
                    // 改善了：重置计数
                    stat.count = 0;
                    stat.lastGrade = newGrade;
                    showStatus('✅ 【' + label + '】已优化，请检查后点击「更新」保存。', 'success');
                }
                waisgRetryStats[field] = stat;
            }).fail(function () {
                setLoading(false);
                showStatus('❌ 请求失败，请重试。', 'error');
            });
        }
        runScoreOptimize(false);
    });

    /** 获取字段对应评分项的等级 */
    function waisgGetFieldGrade(field) {
        var mapping = {
            'title': ['#waisg-score-title-len', '#waisg-score-kw-in-t'],
            'seo_title': ['#waisg-score-seo-t-len'],
            'seo_description': ['#waisg-score-seo-d-len', '#waisg-score-kw-in-d']
        };
        var selectors = mapping[field] || [];
        // 取最差的等级（red > yellow > green > gray）
        var worst = 'green';
        for (var i = 0; i < selectors.length; i++) {
            var $el = $(selectors[i]);
            if ($el.hasClass('waisg-score-red')) return 'red';
            if ($el.hasClass('waisg-score-yellow')) worst = 'yellow';
        }
        return worst;
    }

})(jQuery);
