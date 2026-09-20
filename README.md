# WP AI SEO + GEO 智能优化 — 完整文档

> 版本 2.0.13 · 作者 [ivye](https://www.3520.net) · 支持 WordPress 5.0+

---

## 目录

### 用户使用手册
1. [安装与激活](#1-安装与激活)
2. [基本设置](#2-基本设置)
3. [单篇文章优化（编辑页）](#3-单篇文章优化编辑页)
4. [SEO 评分面板](#4-seo-评分面板)
5. [批量优化](#5-批量优化)
6. [AI 文章生成](#6-ai-文章生成)
7. [优化历史](#7-优化历史)
8. [文章列表 AI 状态列](#8-文章列表-ai-状态列)
9. [定时自动优化](#9-定时自动优化)
10. [核心工作流程](#10-核心工作流程)
11. [任务中断恢复](#11-任务中断恢复)
12. [常见问题](#12-常见问题)

### 开发者文档
13. [插件结构](#13-插件结构)
14. [核心类说明](#14-核心类说明)
15. [AJAX 接口列表](#15-ajax-接口列表)
16. [数据库结构](#16-数据库结构)
17. [主要 WordPress Hook](#17-主要-wordpress-hook)
18. [扩展开发指南](#18-扩展开发指南)
19. [更新日志](#19-更新日志)

---

# 用户使用手册

## 1. 安装与激活

1. 将插件文件夹 `wp-ai-seo-geo` 上传到 `/wp-content/plugins/`
2. 进入 WordPress 后台 → **插件** → 启用 **WP AI SEO + GEO 智能优化**
3. 启用后左侧菜单出现 **🤖 AI SEO+GEO** 菜单组，首先进入 **基本设置** 完成 API 配置

---

## 2. 基本设置

路径：**AI SEO+GEO → 基本设置**

### 一、AI 大模型接口配置（必填）

| 字段 | 说明 |
|------|------|
| API 地址（Base URL） | 兼容 OpenAI 格式的接口地址，例如 `https://api.openai.com/v1`、DeepSeek、文心等 |
| API Key | 对应平台的密钥 |
| 模型名称 | 例如 `gpt-4o`、`deepseek-chat`、`ernie-4.0` |
| 轻量模型名称 | 可选，用于 SEO 字段优化等轻量任务，如 `gpt-4o-mini`、`deepseek-chat`，可节省约 90% 费用。留空则统一使用主模型 |
| 请求超时 | 建议 60~120 秒，生成长文需要更多时间 |
| 温度 | 0.1~0.7，越低越稳定，推荐 **0.3**；需要多样性可调至 0.5~0.7 |
| 最大 Tokens | 控制 AI 单次最大返回长度，推荐 4096；生成长文可设 8192 |
| 配图搜词 Tokens | （v1.9.9 新增）配图时把节级中文标题/关键词翻译成英文搜图词的 `max_tokens` 预算，默认 200。**推理模型（如 deepseek-v4-flash / o1 / R1）思考会吃配额，建议设 800 以上**，否则返空触发重试更慢；普通模型 200 即可 |

**连通测试**：填写好 API 信息后，点击「测试连接」按钮，插件会用当前填写的配置发送一条极短请求，即时验证接口是否可用，**无需提前保存设置**。如配置了轻量模型，还会显示「测试轻量模型」按钮，可单独验证轻量模型的连通性。

> 💡 **推理模型自动适配**（v1.9.1 新增）：测试连接时插件会检测 API 返回中是否含推理内容（`reasoning_content` 字段），据此自动识别推理模型（如 DeepSeek-R1 / o1 / o3 / 龙猫 / QwQ 等）并存入实测名单。后续优化时，名单中的模型会自动追加 `max_tokens` 推理预算，并在偶发"思考耗尽配额"时兜底重试，避免报错"AI 仅返回推理内容，未返回最终答案"。测试成功后推理模型会显示「检测到推理模型，已自动适配 token 预算」提示。普通模型从名单移除，行为不受影响。**建议配置新模型后先点一次「测试连接」完成探测。**

### Token 用量统计

API 配置区底部展示累计 Token 消耗量：
- **本月消耗**：当月所有 AI 调用累计消耗的 Token 数
- **历史累计**：插件安装以来所有调用的总 Token 数
- 点击「重置统计」可将计数清零（不影响 AI 功能本身）

### 二、系统提示词（角色 Prompt）

相当于告诉 AI "你是谁、写作风格是什么"。每次调用 AI 时都会先发送这段内容。

- **留空**：使用内置默认提示词（适合大多数场景）
- **自定义**：设置行业角色、语言风格、禁止事项等

**支持变量**（会自动替换为当前文章内容）：

| 变量 | 替换为 |
|------|--------|
| `[标题]` | 文章标题 |
| `[内容]` | 文章正文 |
| `[关键词]` | SEO 关键词 |
| `[摘要]` | 文章摘要 |
| `[描述]` | SEO 描述 |

> ⚠️ **Token 警告**：
> - `[内容]` 会插入完整正文，导致正文在系统提示词与用户提示词中**各发送一次（Token 翻倍）**，长文章尤其消耗大；
> - 任何变量都会让系统提示词每次内容不同，**破坏 OpenAI/Claude 的 prompt caching 命中率**（批量优化场景下损失最明显）；
> - **建议**：留空或写不含变量的纯静态角色描述，插件已自动将文章信息正确传递给 AI。

示例：
```
你是家居装修领域的资深编辑，写作风格简洁亲切，面向普通家庭用户。
严禁出现价格数字，严禁推荐具体品牌。
```

### 三、支持的文章类型

勾选需要开启 AI 功能的文章类型。勾选后，该类型编辑页才显示 AI 操作面板。

### 四、SEO 字段名配置

插件自动兼容主流 SEO 插件，**一般无需修改**：

| SEO 插件 | 标题字段 | 描述字段 | 关键词字段 |
|----------|----------|----------|------------|
| Yoast SEO | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw` |
| RankMath | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` |
| AIOSEO | `_aioseo_title` | `_aioseo_description` | `_aioseo_keywords` |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_analysis_target_kw` |

如使用其他 SEO 插件，在此手动填写对应的 meta key 名称。可在数据库 `wp_postmeta` 表中查找文章对应的 meta_key 来确认。

### 五、批量优化设置

- **请求间隔（秒）**：批量优化时**每"批"完成后**的等待时间，防止 API 限速。单线程时即每篇之间的间隔；并发时即每 N 篇完成后的间隔。推荐 3~5 秒
- **批量并发数**（v1.9.0 新增）：批量优化 / 批量生成 / 批量改写时**同时**处理的文章数，1-5 篇可选，默认 2 篇
  - **1**：串行，最稳但最慢（旧版行为）
  - **2-3**：推荐，速度提升约 2-3 倍，API 限速友好
  - **4-5**：最快，但容易触发 API 429 限速，建议主模型 API 额度高时再用
  - ⚠️ 浏览器对同域名最多 6 路并发，超过 5 可能反而变慢；同时受 AI 平台 RPM/TPM 上限制约
- **批量任务模型**（v1.6.0 新增）：选择批量处理（批量优化、批量生成、批量改写、定时优化）使用的 AI 模型
  - **主模型**：使用基本设置中的主模型，质量更高但费用较高（默认值）
  - **轻量模型**：使用轻量模型（需先在基本设置中配置），可大幅降低 API 费用
  - 此设置为全局默认值，在批量优化页面和 AI 文章生成页面上也有独立的「使用模型」下拉框，可临时覆盖全局设置
  - 优先级：**操作页面选择 > 全局设置默认值**
- **清理无用标签**（v2.0.11 新增）：勾选后自动剥除 AI 生成/优化正文中的 div/p/span/font/section 等无语义包装标签。闭合标签转为空行保留段落结构（WordPress 前台会依据空行自动重建段落，显示效果不变），h2/h3、列表、表格、图片、链接、pre/code 代码块等语义内容原样保留。可避免块编辑器全篇落入「经典块」后残留无效布局标签导致前台样式失控。默认开启；同时所有正文 Prompt 会禁止 AI 输出这类标签（从源头减少）
- **降低 AI 痕迹**：勾选后启用内容润色功能。所有 AI 生成/优化的正文内容会经过二次润色，打散 AI 写作痕迹，降低被 AI 检测工具识别的概率。会额外消耗一次 API 调用，使用的模型由下方「润色模型」决定，默认关闭
- **润色模型**（v1.9.0 新增）：控制「降低 AI 痕迹」这一步使用哪个模型
  - **轻量模型**：默认值，最省 Token、速度最快（需先在基本设置中配置轻量模型）
  - **主模型**：润色质量最高，但单篇耗时约翻倍
  - **跟随主任务模型**：当前任务用什么模型生成/优化正文，就用同一模型润色（最灵活；批量/编辑页按操作页的「使用模型」选择，定时任务按「批量任务模型」设置）
- **润色提示词**（v1.5.0 新增）：自定义润色阶段的 user prompt，控制 AI 如何改写正文使其更像人类写作。必须包含 `[正文]` 占位符（运行时替换为待润色 HTML），留空则使用内置默认提示词。支持"查看内置默认"和"恢复为默认"
- **AI 高频词替换词库**（v1.5.0 新增）：自定义 AI 高频词及其替换规则
  - 格式：每行一条 `原词|替换词`，替换词留空 = 删除该词
  - `#` 开头为注释行，空行忽略
  - 留空文本框 = 使用内置默认词库（60+ 条规则）
  - 可单独关闭此功能（取消勾选"启用 AI 高频词自动替换"）
  - 提供"查看/恢复默认词库"折叠面板

### 六、图片配置

AI 生成文章时自动插入配图，支持三种来源：

**Pexels（免费）**
1. 前往 [pexels.com/api](https://www.pexels.com/api/) 注册并申请 API Key
2. 选择来源 **Pexels**，填入 API Key，点击「测试搜图」验证

**Unsplash（免费）**
1. 前往 [unsplash.com/developers](https://unsplash.com/developers) 注册并创建 Application
2. 选择来源 **Unsplash**，填入 **Access Key**
   - ⚠️ 是 **Access Key**，不是 Application ID，也不是 Secret Key
   - 位置：开发者后台 → Your Application → Keys → Access Key

**AI 大模型生成图片**
- 适用于支持 OpenAI `/v1/images/generations` 格式的图片接口
- 需填写：图片生成 API 地址、API Key、模型名称（如 `dall-e-3`）、图片尺寸

### 七、定时自动优化

详见 [第 9 节：定时自动优化](#9-定时自动优化)。

### 八、Schema 结构化数据与 Canonical（v1.5.0 新增）

自动在页面 `<head>` 中输出 JSON-LD 结构化数据和 Canonical URL 标签。

| 设置项 | 说明 |
|--------|------|
| FAQPage Schema | 自动提取正文 FAQ 区块（`<h3>`问题 + 后续段落答案），生成 FAQPage JSON-LD。配合 AI 优化生成的 FAQ 结构使用 |
| 全站基础 Schema | 输出 WebSite + BreadcrumbList + Article/WebPage。完整替代主题 functions.php 中的手动 Schema 代码 |
| Schema 类型开关 | WebSite / BreadcrumbList / Article 三个类型可独立启用/关闭 |
| 默认图 URL | Article Schema 图片四层兜底：特色图 → 正文第一张图 → 站点 Logo → 此默认图。留空且前三层无图时 image 不输出 |
| Canonical URL | 自动为所有页面添加 `<link rel="canonical">` 标签，防止重复 URL 导致搜索引擎权重分散 |

**Schema 注意事项**：
- 启用全站基础 Schema 后，需先移除主题中原有的 Schema 输出代码，避免重复
- Publisher logo 尺寸固定 600×600
- FAQ 提取失败时静默跳过，不阻断页面渲染
- 默认图 URL 未设置时，image 字段留空不输出无效链接

**Canonical URL 说明**：
- 覆盖所有页面类型：单篇文章/页面、首页、分类/标签归档、文章类型归档、作者页、日期归档
- 分页页面（`/page/2/`）的 canonical 自动指向第一页，集中权重
- 开启后自动移除 WordPress 默认的 `rel_canonical` 输出，避免重复
- 如果已使用 Yoast / RankMath / AIOSEO 等 SEO 插件，它们通常已输出 canonical，**请勿重复开启**

### 九、内容结构模板

预定义文章结构规范，AI 生成/优化时选择模板后，按预设结构组织内容。

**创建模板**：点击「＋ 新增模板」

| 字段 | 说明 |
|------|------|
| 模板名称 | 便于识别的名称，如"产品介绍标准模板" |
| 文章结构 | 每行一个章节或段落要求，支持 Markdown 标题格式 |
| 附加要求 | 可选，如"每章节不少于 200 字；语气专业正式" |

**`{关键词}` 占位符**：在结构中使用 `{关键词}`，生成时会自动替换为实际关键词。

模板示例：
```
## {关键词}简介
介绍{关键词}的基本概念和背景

## {关键词}的核心优势
### 优势一
### 优势二

## 实际使用场景

## 选购指南

## 常见问题解答

## 总结
```

---

## 3. 单篇文章优化（编辑页）

进入任意文章或页面的编辑页，在编辑器下方找到 **🤖 AI SEO + GEO 智能优化** 面板。

> 完整兼容 **Gutenberg（块编辑器）** 和 **经典编辑器**（包括可视模式和文本/代码模式）。AI 优化结果会自动填入当前使用的编辑器。

### 使用模型（v1.6.3 新增）

面板顶部和右侧边栏「🤖 AI 快捷操作」区各有一个「🧠 使用模型」下拉框，两处实时联动同步。选择后，本页所有 AI 操作（AI 生成文章、一键优化全部、仅优化 SEO、单字段优化、点击 SEO 评分指示灯修复）都使用所选模型：

- **主模型**：质量更高，费用较高
- **轻量模型**：大幅降低 API 费用（需先在「基本设置」中配置轻量模型名称）

> 默认选中轻量模型（如已配置），未配置轻量模型时仅显示主模型选项。

### AI 生成文章（从零创作）

在面板第一区域：
- **主题/要求**：文章的核心主题或具体写作要求
- **关键词**：AI 会在文章中重点体现的关键词，逗号分隔
- **字数要求**：0 = 不限；填写具体数字控制文章长度

点击「✨ AI 生成文章」，AI 生成结果会直接填入编辑器各字段。

### 一键优化全部字段

点击「⚡ 一键优化全部」：
- AI 同时优化：标题、正文、摘要、SEO 标题、SEO 描述、SEO 关键词
- 结果填入编辑器，**不会自动保存**，检查后手动点击「更新」保存
- 每次保存时插件自动备份当前版本，可在「优化历史」中回滚

### 仅优化 SEO 字段

点击「🎯 仅优化 SEO」：
- 只优化 SEO 标题、SEO 描述、SEO 关键词、文章标题和摘要，**不修改正文内容**
- Token 消耗仅为全量优化的约 30%，适合正文质量已满意但 SEO 字段需要优化的场景
- 优先使用轻量模型（如已配置）进一步降低成本

### 单字段优化

在「单独优化」区域点击对应按钮（标题 / 正文 / 摘要 / SEO标题 / SEO描述 / SEO关键词），只优化该字段。适合对某项单独调整的场景。

### SEO 字段编辑区

**直接编辑**：SEO 标题、SEO 描述、SEO 关键词可直接在输入框中手动修改，字符计数实时更新：
- SEO 标题：右侧显示 `N/60`，超过 60 字符时数字变红
- SEO 描述：右侧显示 `N/160`，超过 160 字符时数字变红

**保存 SEO 字段**：点击「💾 保存 SEO 字段到数据库」可立即将 SEO 字段写入数据库，**无需更新文章主体**。保存成功后页面不会刷新，同时会自动同步更新页面上 SEO 插件的表单字段。

> ⚠️ 只有在「基本设置 → SEO 字段名配置」中明确配置了字段名的项才会被保存。未配置的字段会被跳过并显示提示信息。

### 暂存到待处理

优化完成后，面板出现一个提示条：
```
优化完成，是否要暂存到「待处理」？
[📥 暂存到待处理]  [关闭]
```

点击「暂存到待处理」后，当前编辑器中所有字段（含你手动修改过的内容）会以快照形式保存到「优化历史 → 待处理」，**不影响文章当前发布状态**。适合"先存起来，稍后再决定是否应用"的场景。

### 内链建议

AI 优化完成后，系统会自动根据 SEO 关键词搜索站内相关文章，在 SEO 字段区下方显示建议内链：
- 点击文章标题可在新标签打开查看
- 点击「复制」可将 `<a href="...">标题</a>` 代码复制到剪贴板，直接粘贴到正文中使用

---

## 4. SEO 评分面板

SEO 字段区下方（字段有内容时自动显示）有一个实时 SEO 评分面板，由 **5 个颜色指示灯** 和一个 **总分** 组成。

### 指示灯颜色含义

| 颜色 | 含义 |
|------|------|
| 🟢 绿色 | 该项达标，符合 SEO 最佳实践 |
| 🟡 黄色 | 该项勉强可接受，建议优化 |
| 🔴 红色 | 该项不达标，需要修改 |
| ⚪ 灰色 | 该字段为空，暂无数据 |

### 5 个评分项详细说明

| 指示灯 | 绿色标准 | 黄色标准 | 红色标准 |
|--------|---------|---------|---------|
| **标题** | 20~60 字符 | 15~19 或 61~80 字符 | <15 或 >80 字符 |
| **SEO 标题** | 30~60 字符 | 20~29 或 61~80 字符 | <20 或 >80 字符 |
| **SEO 描述** | 120~160 字符 | 100~119 或 161~200 字符 | <100 或 >200 字符 |
| **关键词↑标题** | 关键词出现在文章标题中 | — | 关键词未出现在标题中 |
| **关键词↑描述** | 关键词出现在 SEO 描述中 | — | 关键词未出现在 SEO 描述中 |

> **字符数说明**：所有计算基于字符数（中文每字算 1 个字符），与字节数不同。

### 总分计算

- 每个绿色指示灯：+20 分
- 每个黄色指示灯：+10 分
- 红色/灰色指示灯：+0 分
- 满分 100 分，**80 分及以上**为优秀（绿色显示），**50~79 分**为良好（橙色），**50 分以下**需改进（红色）

### 点击指示灯优化

点击任意**非灰色**指示灯，插件会自动调用 AI 重新优化对应字段：

- 点击「SEO 标题」→ AI 重写 SEO 标题，确保长度合适且含关键词
- 点击「SEO 描述」→ AI 重写 SEO 描述，确保长度合适且含关键词
- 点击「标题」→ AI 重写文章标题，确保长度合适
- 点击「关键词↑标题」→ AI 重写文章标题，确保包含关键词
- 点击「关键词↑描述」→ AI 重写 SEO 描述，确保包含关键词

优化完成后对应指示灯自动更新颜色，总分重新计算。灰色（空字段）不支持点击，请先填写内容。鼠标悬停在可点击的指示灯上时会显示手型光标和下划线提示。

> 💡 **批量优化详情行**同样有 SEO 评分面板，也支持点击指示灯自动修复该行对应字段。

---

## 5. 批量优化

路径：**AI SEO+GEO → 批量优化**

适合对大量已有文章进行统一 AI 优化。所有优化结果先存入暂存区，由你审阅后决定是否应用，不会直接覆盖文章。

### 步骤一：筛选文章

| 筛选项 | 说明 |
|--------|------|
| 文章类型 | 选择要优化的文章类型 |
| 文章状态 | 已发布 / 草稿 / 待审 / 全部 |
| 分类 | 仅文章类型有效，按分类筛选 |
| 每次最多获取 | 从数据库读取的上限，建议不超过 200，最高 500 |
| 每页显示 | 分页显示条数 |
| 排除文章 ID | 不想优化的文章 ID，多个用英文逗号分隔 |

点击「获取文章列表」加载文章。

### 步骤二：选择文章

- 勾选单篇 / 全选当前页 / 全选所有页
- 可选：选择「使用模型」（v1.6.0 新增）：主模型或轻量模型，默认值跟随全局设置，可临时切换
- 可选：勾选「仅优化 SEO 字段」复选框，只优化 SEO 相关字段而不修改正文，大幅节省 Token
- 可选：勾选「跳过二次润色」（v1.5.0 新增），批量场景下不执行 humanize，每篇少 1-3 次 API 调用
- 可选：「SEO 评分保障」默认开启（v1.5.0 新增），优化后自动用 PHP 修正不达标字段至 ≥ 90 分，零额外 Token
- 点击「▶ 开始批量优化」

### 步骤三：查看优化结果

每篇完成后：
- **主行 SEO 评分**（v1.5.0 新增）：不用展开详情就能在列表主行看到 SEO 总分（0~100）和 5 个彩色圆点指示灯（绿/黄/红/灰），点击红/黄圆点可直接触发 AI 修复该字段
- 点击「查看 & 应用」展开详情：
  - **左侧（黄色背景）**：原始内容（只读）
  - **右侧（蓝色背景）**：AI 优化后内容（可编辑）
  - **SEO 评分面板**：显示优化后内容的 SEO 得分，非灰色指示灯可点击让 AI 重新优化

### 步骤四：应用到 WordPress

- **逐篇应用**：在详情中选择状态（存为草稿 / 保持待审 / 立即发布 / 定时发布）→ 点击「应用到文章」
- **批量应用**：勾选多篇 → 底部「批量应用到 WordPress」→ 选状态（选项同上）→ 「批量应用」

> 💡 优化「待审」文章时选择「保持待审」（v2.0.10 新增），应用后文章仍为待审状态，继续走人工审核流程；选择其他状态则会直接改变文章状态

### 重新开始与重试（v1.5.0 改进）

- **保留已成功项**：重新点击"继续优化"时，自动跳过已优化成功的文章，不会重复消耗 Token
- **仅重试失败项**：优化完成后如有失败的文章，会出现独立的「🔄 仅重试失败项」按钮，只对失败的文章重新优化
- **批量应用始终可用**：只要有任何已优化成功的文章，底部批量应用栏始终可见可用

> ⚠️ 未应用的结果自动保存在「优化历史 → 待处理」，下次可继续处理

---

## 6. AI 文章生成

路径：**AI SEO+GEO → AI 文章生成**

从零生成全新文章，支持三种模式。

### 通用设置（三种模式共用）

| 字段 | 说明 |
|------|------|
| 使用模型 | 主模型或轻量模型，默认值跟随全局设置，可临时切换（v1.6.0 新增） |
| 批量润色 | 勾选「跳过二次润色」可在生成场景下不执行 humanize，每篇少 1-3 次 API 调用，大幅加速（v1.9.0 新增）。全局「降低 AI 痕迹」关闭时该项自动灰显禁用 |
| 每篇字数 | 0 = 不限，填具体数字控制长度 |
| 保存为（文章类型） | 生成文章的 WordPress 文章类型 |
| 文章分类 | 仅文章类型有效 |
| 输出语言 | 中文（简体）/ 中文（繁体）/ English / 日本語 / 한국어 / Español / 法文 / 自定义 |
| 内容结构模板 | 选择预设模板后 AI 按模板结构生成文章（见第 2 节） |

### 模式一：手动输入

适合围绕同一主题批量生成多篇角度各异的文章。

| 字段 | 说明 |
|------|------|
| 核心关键词 | **必填**，AI 重点体现的关键词 |
| 主题/要求 | 可选，不填则以关键词为主题 |
| 补充说明 | 可选，填写风格要求、目标受众等 |
| 生成篇数 | 1~50 篇，同关键词但角度各异 |

### 模式二：批量导入关键词

适合为多个不同关键词分别生成文章。

1. 在文本框粘贴关键词，**每行一个**
2. 点击「📋 导入关键词」
3. 在列表中为每个关键词设置篇数（1~20）
4. 可选：主题前缀（如填"如何选购"，生成主题为"如何选购 + 关键词"）
5. 点击「✨ 开始批量生成文章」

### 模式三：改写 / 伪原创

将已有文章改写为全新内容，保留主题但完全重写句式和结构。

1. 将原文粘贴到文本框
2. 可选填关键词、改写要求、输出语言
3. 点击「✂️ 开始改写文章」
4. 改写结果进入结果列表，审阅后保存到 WordPress

> AI 改写规则：主题相同，句式/段落/表达全部重写，不直接复制原文任何句子

### 查看和保存生成结果

生成完成后在结果列表中：
1. 点击「编辑/保存」展开详情
2. 编辑：标题、摘要、正文（HTML）、SEO 字段
3. 查看 **SEO 评分**（非灰色指示灯可点击让 AI 重新优化对应字段，手动修改后评分自动更新）
4. 选择保存状态：草稿 / 立即发布 / 定时发布
5. 点击「保存到 WordPress」

**批量保存**：勾选多篇 → 底部「批量保存到 WordPress」→ 选状态 → 「批量保存」

> 💡 生成结果不会自动写入 WordPress，确认后才会保存，避免草稿堆积

---

## 7. 优化历史

路径：**AI SEO+GEO → 优化历史**

### Tab 1：待处理

显示所有 **AI 生成或优化、尚未保存到 WordPress** 的内容。

- **来源**：批量优化未应用的结果、单篇编辑页点击「暂存」的结果、AI 生成未保存的文章、定时自动优化的结果
- **搜索/筛选**：列表上方支持按标题/关键词搜索，按类型筛选（全部 / AI生成 / AI优化）
- **每页条数**：搜索栏右侧可输入每页显示条数（10~2000），点击「应用」刷新页面
- **批量应用到 WordPress**（v1.5.0 新增）：勾选多条记录 → 选择状态（草稿/待审/发布/定时）→ 点击「📥 批量应用到 WordPress」，逐条将暂存内容保存到 WordPress，完成后自动从列表移除
- **批量删除**：每行左侧有勾选框，支持全选当前页；勾选后点击「批量删除选中」一次性删除多条记录
- **操作**：点击「编辑/保存到WP」打开弹窗，可编辑全部字段后选发布状态保存；已保存后自动从列表移除

### Tab 2：备份历史

每次 AI 优化前自动备份的旧版本快照。

- **每页条数**：与待处理 Tab 相同，支持自定义每页显示条数（10~2000）
- **批量删除**：勾选多条备份记录后点击「批量删除选中」一次性删除

| 操作 | 说明 |
|------|------|
| 查看 | 查看该历史版本所有字段内容 |
| 对比当前 | 左右对比历史版本与文章当前版本，高亮差异字段 |
| 回滚 | 将文章恢复到该历史版本（回滚前自动再备份一次当前版本） |
| 删除 | 删除该条备份记录 |

### 留痕记录清理（两个 Tab 通用）

页面右上方（Tab 导航下）有 **🧹 清理留痕记录** 按钮，用于清理 `saved`（已保存）/ `applied`（已应用）两类**不在列表中显示**的留痕记录。暂存内容保存/应用到 WordPress 后，原记录会改类型留痕（用于防重复保存和批量优化页的"再次应用"），长期累积占表。点击按钮 → 先统计数量 → 确认后物理删除，**不影响已保存的文章内容**。

> 💡 **级联清理**：在 WordPress 后台**永久删除**某篇文章时（含从回收站彻底删除、文章列表批量永久删除），该文章在 `waisg_history` 中的全部记录（备份、优化暂存、留痕）会自动一并删除，无需手动清理。仅移入回收站不触发（文章可恢复，历史保留）。
>
> 💡 **删除备份连带清留痕**：在优化历史中删除记录（单条或批量）时，会同步删除同一文章的 `saved`/`applied` 留痕——删完列表里的记录，数据表中即无该文章的任何残留。

---

## 8. 文章列表 AI 状态列

WordPress 后台文章列表新增 **🤖 AI 状态** 列：

| 显示内容 | 含义 |
|----------|------|
| `AI 生成`（蓝色徽标） | 该文章由本插件 AI 生成 |
| `N 次优化`（蓝色数字） | 该文章被 AI 优化过 N 次 |
| `—`（灰色） | 未经过 AI 处理 |

**按状态筛选**：列表顶部筛选栏新增 AI 状态过滤，可筛选「AI生成」/「已优化」/「未优化」的文章。

**批量操作**：文章列表勾选后，批量操作下拉有「🤖 AI 批量优化」选项，点击跳转到批量优化页面并自动填入所选文章 ID。

---

## 9. 定时自动优化

路径：**基本设置 → 七、定时自动优化**

定期自动对旧文章执行 AI 优化，结果存入「待处理」，由你手动审阅后决定是否应用，**不会直接覆盖文章**。

### 配置项

| 配置项 | 说明 |
|--------|------|
| 启用定时优化 | 勾选后启用，保存设置生效 |
| 执行频率 | 每天 / 每天两次 / 每周 / 每两周 |
| 每次优化篇数 | 每次最多优化的文章数，建议 3~10 篇 |
| 优化目标 | 最旧的文章优先 / 优化次数最少的优先 / 随机选取 |
| 优化文章类型 | 勾选要纳入定时优化的文章类型 |

### 执行状态

- **上次执行**：显示上次执行时间和处理篇数
- **下次执行**：页面加载时自动显示 WP-Cron 计划的下次执行时间

### 手动触发

点击「立即执行一次」按钮，立即触发一次定时优化（不影响既有定时计划），执行完成后显示本次处理篇数。

---

## 10. 核心工作流程

> 插件所有 AI 操作均采用**「暂存审核」模式**，AI 结果不会自动写入 WordPress，由你确认后再决定是否保存。

### 单篇优化流程

```
进入编辑页 → 点击"一键优化全部"
     ↓
AI 处理（显示进度）→ 结果填入编辑器
     ↓
查看 SEO 评分 → 点击非灰色指示灯让 AI 重新优化
     ↓
检查/修改内容 → 点击"更新"保存
（或点击"暂存到待处理"稍后再决定）
```

### 批量优化流程

```
筛选文章 → 勾选 → 开始批量优化
     ↓
AI 逐篇优化（进度条显示）→ 结果存入暂存区
     ↓
展开详情：左侧原文 vs 右侧 AI 优化（可编辑）
     ↓
查看 SEO 评分 → 点击非灰色指示灯让 AI 重新优化
     ↓
选择状态 → 应用到文章
（未应用的结果保存在"优化历史→待处理"）
```

### AI 生成文章流程

```
填写关键词 → 选择模式 → 开始生成
     ↓
AI 逐篇生成（进度显示）→ 结果存入暂存区
     ↓
展开详情 → 编辑字段 → 查看 SEO 评分
     ↓
选择状态（草稿/发布/定时）→ 保存到 WordPress
```

---

## 11. 任务中断恢复

批量优化和文章生成任务的进度自动保存到浏览器 **localStorage**。

如果中途关闭页面或网络中断，下次打开对应页面时会出现黄色提示条：

```
⚠️ 发现上次未完成的任务（已完成 X/Y 篇，剩余 Z 篇）。
[继续执行]  [放弃]
```

- **继续执行**：从上次断点恢复，继续处理剩余任务
- **放弃**：清除记录，重新开始

> 💡 已完成的优化结果不会因为中断而丢失，它们已保存在「优化历史→待处理」中

---

## 12. 常见问题

**Q：AI 请求超时怎么办？**
> v1.3.7 起插件会根据内容长度自动延长 timeout（最高 600 秒），无需手动调整。若仍然超时，可在「基本设置 → 请求超时」中将基准值调大到 120~180 秒，长文场景会在此基础上自动追加时间。同时插件会自动调用 `set_time_limit()` 放宽 PHP 执行时间，避免 PHP 端先超时。

**Q：SEO 描述长度不达标？**
> v1.5.0 通过两层机制大幅提升达标率：① Prompt 改为步骤式指令（"先写3句+再追加2句"），AI 遵从率从 ~20% 提升到 ~85%+；② PHP `auto_fix_seo` 兜底——如果 AI 仍然写短了，自动从摘要/正文抽取真实句子补到 120+ 字，零 Token 消耗。如果仍不满意，可点击评分指示灯让 AI 重新生成，或手动编辑。

**Q：点击 SEO 评分指示灯没有反应？**
> 灰色指示灯（字段为空）不支持点击。其他颜色（绿色、黄色、红色）均支持点击让 AI 重新优化该字段。请确认文章已保存（编辑页需有有效的文章 ID）。

**Q：Unsplash 图片不显示？**
> 检查填写的是否是 **Access Key**（不是 Application ID，不是 Secret Key）。Demo 应用每小时限 50 次请求，申请 Production 访问可提升至 5000 次/小时。

**Q：SEO 字段没有保存成功？**
> 检查「基本设置 → 四、SEO 字段名配置」是否与你使用的 SEO 插件匹配。只有明确配置了字段名的项才会被保存，未配置的字段会被跳过并显示提示。可在数据库 `wp_postmeta` 表中查找文章的 meta_key 来确认。

**Q：批量优化中途停止，结果丢失了吗？**
> 不会丢失。每篇优化完成后结果立即保存到「优化历史→待处理」，停止后可在那里继续处理，也可点击「继续执行」恢复任务。

**Q：回滚后想撤销回滚？**
> 每次回滚前插件会自动备份当前版本，在「优化历史→备份历史」找到最新一条备份再次回滚即可。

**Q：定时优化没有执行？**
> WP-Cron 依赖网站访问量触发。低流量站点可在服务器配置 `crontab` 定期请求 `wp-cron.php`，或使用 WP Crontrol 插件手动触发测试。

**Q：生成多篇文章标题都一样？**
> 尝试将温度从 0.3 调高到 0.5~0.7，增加 AI 输出的多样性。

**Q：AI 生成的文章被检测为 AI 写作怎么办？**
> v1.4.1 显著增强了反 AI 检测能力，包含三层机制：①**所有正文 Prompt 自动注入反 AI 检测写作规则**（长短句交错、禁用过渡词、像真人博主）；②**PHP 端 `filter_ai_phrases()` 替换 60+ 个 AI 高频词**（覆盖机械化过渡词、学术化套话、模板化句式开头三大类）；③**`humanize()` 二次润色**（v1.4.1 起 prompt 大幅强化）：明确要求加入个人观点词（「我觉得」「说实话」）、口语化连接（「对吧」「话说回来」）、反问句、不规则句长、列表项长度差异化，并使用 0.75 温度让输出更"有人味"。长文（>3000 字符）按 H2 边界分段润色，每段独立处理，单段失败不影响其他段。短文（<300 字符）自动跳过润色（节省 token 且本就无明显 AI 特征）。三层同时生效可显著降低 AI 检测率。

**Q：Token 消耗太高怎么优化？**
> v1.4.1 已对所有 Prompt 做了精简（节省约 30%~45% 输入 Token），并新增以下机制：① **prompt caching**——OpenAI 自动缓存 + Claude 显式 `cache_control` 标记，所有 build_*_prompt 接口统一支持，批量场景下可节省 50%~90% 的输入 token；② **长文分段 humanize**——超过 3000 字符的正文按 H2 边界拆分润色，单段失败不影响其他段；③ **空字段不输出**——原文中未填写的 SEO 字段不会出现在 prompt 中；④ **`parse_json_response` 鲁棒解析**——括号配对算法避免贪婪匹配，解析失败率降低。此外还有三种省 Token 方式：①设置轻量模型（如 `gpt-4o-mini`），SEO 字段优化和二次润色会自动使用轻量模型；②使用「仅优化 SEO」功能，不处理正文，Token 消耗仅为全量优化的约 30%；③关闭「降低 AI 痕迹」开关可减少一次 API 调用（短文 <300 字符已自动跳过 humanize）。
>
> ⚠️ **重要**：如需 prompt caching 生效，请保持「基本设置 → 系统提示词」**不含变量**（`[标题]/[内容]/[关键词]/[摘要]/[描述]`），任何变量都会让 system prompt 每次内容不同，无法命中缓存。

**Q：批量优化后文章中的图片丢失了？**
> v1.2 版本已加入图片保护机制：优化前自动将图片替换为占位符，AI 处理后自动还原。即使 AI 丢弃了部分占位符，插件也会自动补回缺失的图片。同时会根据文章长度动态调整 max_tokens，避免长文被截断导致图片丢失。

---

# 开发者文档

---

## 13. 插件结构

```
wp-ai-seo-geo/
├── wp-ai-seo-geo.php          # 插件主入口，常量定义、类加载、Hook 注册
├── README.md                  # 本文档
│
├── uninstall.php              # 插件删除时自动清理 DB / options / post meta
│
├── includes/                  # PHP 核心类
│   ├── class-ai-api.php       # AI 接口调用 + 全部 Prompt 构建
│   ├── class-settings.php     # 设置页面、模板 CRUD、Token 统计、AJAX
│   ├── class-meta-box.php     # 编辑页元框、单篇优化 AJAX
│   ├── class-batch.php        # 批量优化页面和 AJAX
│   ├── class-generator.php    # AI 文章生成页面和 AJAX
│   ├── class-history.php      # 历史记录、暂存区、回滚 AJAX
│   ├── class-cron.php         # 定时自动优化（WP-Cron）
│   ├── class-post-list.php    # 文章列表 AI 状态列
│   └── class-schema.php       # Schema 结构化数据自动注入（v1.5.0 新增）
│
├── admin/
│   └── views/
│       ├── settings.php       # 设置页面视图
│       ├── batch.php          # 批量优化页面视图
│       ├── generator.php      # AI 生成器页面视图
│       └── history.php        # 优化历史页面视图
│
└── assets/
    ├── css/
    │   └── admin.css          # 后台样式（元框、评分面板、批量页面）
    └── js/
        ├── admin.js           # 编辑页逻辑（优化、SEO评分、内链建议）
        ├── batch.js           # 批量优化页面逻辑
        ├── generator.js       # 文章生成器页面逻辑
        └── settings.js        # 设置页逻辑（API测试、Token统计、模板CRUD、定时优化）
```

---

## 14. 核心类说明

### `WAISG_AI_API`

AI 接口调用的唯一入口，所有 AI 请求必须经过此类。

**静态方法：**

```php
// 发起 AI 请求，返回 ['text' => string, 'tokens' => int, 'is_reasoning' => bool] 或 WP_Error
// v1.9.1 起：识别为推理模型时自动追加 max_tokens 预算，并通过 dispatch_with_reasoning_retry() 兜底重试
WAISG_AI_API::call( string $user_prompt, string $system_prompt = '', array $extra = [] )

// 使用 build_*_prompt 返回的 prompts 数组发起调用（自动处理 prompt caching，v1.4.0 新增）
WAISG_AI_API::call_prompts( array $prompts, array $extra = [] )

// 解析 AI 返回的 JSON（自动处理 markdown 代码块包裹）
WAISG_AI_API::parse_json_response( string $text ): array|false

// 替换 Prompt 中的 [标题] [内容] 等变量
WAISG_AI_API::replace_vars( string $prompt, array $vars ): string

// 构建各场景的 Prompt（返回 ['system'=>..., 'user'=>...]）
WAISG_AI_API::build_optimize_all_prompt( array $vars, ?array $template )
WAISG_AI_API::build_optimize_seo_only_prompt( array $vars, ?array $template )   // v1.2 新增
WAISG_AI_API::build_generate_prompt( string $topic, string $keywords, int $length, string $description, string $language, ?array $template )
WAISG_AI_API::build_premium_prompt( string $topic, string $keywords, int $length, string $description, string $language, ?array $template )
WAISG_AI_API::build_rewrite_prompt( string $content, string $keywords, string $language, string $description, ?array $template )
WAISG_AI_API::build_single_field_prompt( string $field, array $vars )

// 降低 AI 痕迹（v1.2 新增，v1.4.0 起长文自动分段处理，v1.5.0 起可自定义词库和润色提示词）
WAISG_AI_API::filter_ai_phrases( string $text ): string          // PHP 端替换 AI 高频词（用户可自定义词库）
WAISG_AI_API::humanize( string $content, string $model_override = '' ): string  // 二次润色（>3000 字符按 H2 分段每批 4 并发，失败返回原文，提示词可自定义；模型由「润色模型」设置决定，follow 模式按 $model_override 跟随主任务，v1.9.0 新增参数）
WAISG_AI_API::get_default_phrases(): array                       // 获取内置默认高频词替换规则
WAISG_AI_API::get_default_phrases_text(): string                 // 获取默认词库的文本格式（供设置页展示）
WAISG_AI_API::get_default_humanize_prompt(): string              // 获取默认润色提示词（供设置页展示）

// SEO 评分与本地修复（v1.5.0 新增）
WAISG_AI_API::calc_seo_score( string $title, string $seo_title, string $seo_desc, string $seo_kw ): array
    // 返回 { total: int, grades: { titleLen, seoTLen, seoDLen, kwInT, kwInD: string } }
WAISG_AI_API::auto_fix_seo( array $fields, int $post_id = 0 ): array
    // 纯 PHP 本地修复（零 Token）：描述补长/截断/关键词注入/SEO标题回填

// 图片保护（v1.2 新增）
WAISG_AI_API::protect_images( string $html ): array              // 返回 ['html'=>..., 'map'=>...]；v1.6.1 起占位符也保护 iframe
WAISG_AI_API::restore_images( string $html, array $map ): string // 还原占位符 + 补回丢失图片

// 正文处理入口（v1.6.2 起：零过滤，原样返回）
WAISG_AI_API::sanitize_content( string $html ): string           // 零过滤，原样返回正文（管理员场景，保证 AI 优化前后字符级一致）

// Token / Timeout 估算（v1.2 新增，v1.3.7 增加 timeout 估算）
WAISG_AI_API::estimate_max_tokens( string $content ): int        // 根据内容长度动态估算 max_tokens
WAISG_AI_API::estimate_timeout( string $content ): int           // 根据内容长度动态估算 timeout（30~600 秒）
WAISG_AI_API::build_long_content_extra( string $content ): array // 一次性返回 ['max_tokens'=>..., 'timeout'=>...]
```

**`call()` 返回值结构：**
```php
[
    'text'         => 'AI 返回的文本内容',
    'tokens'       => 1234,      // 本次消耗的 Token 数（total_tokens）
    'is_reasoning' => false,     // v1.9.1 新增：本次响应是否含推理内容（reasoning_content 非空）
]
```

**`call()` 中 `$extra` 可覆盖的参数：**
```php
[ 'model' => 'gpt-4o', 'temperature' => 0.5, 'max_tokens' => 8192, 'timeout' => 120 ]
```

---

### `WAISG_Settings`

设置读写、Token 统计、内容结构模板 CRUD。

```php
// 读取单个设置项（含默认值）
WAISG_Settings::get( string $key, mixed $default = '' ): mixed

// 获取已启用的文章类型列表（string[]）
WAISG_Settings::get_post_types(): array

// 记录 Token 消耗（累加到月度和历史总计）
WAISG_Settings::record_tokens( int $count ): void

// 获取所有内容结构模板（返回含 id/name/structure/extra 的关联数组列表）
WAISG_Settings::get_templates(): array

// 更新推理模型实测名单（v1.9.1 新增，测试连接时调用）
// $is_reasoning=true 加入名单，false 从名单移除；直接 update_option 写入
WAISG_Settings::update_reasoning_model( string $model, bool $is_reasoning ): void
```

**wp_options 存储键：**

| key | 类型 | 说明 |
|-----|------|------|
| `waisg_settings` | array | 全部设置项（含 `lightweight_model`、`humanize_enabled`、`humanize_prompt`、`humanize_model`、`ai_phrases_enabled`、`ai_phrases_custom`、`schema_faq_enabled`、`schema_base_enabled`、`schema_website`、`schema_breadcrumb`、`schema_article`、`schema_default_image`、`canonical_enabled`、`reasoning_models`（v1.9.1 新增，string[]，测试连接实测探测的推理模型名单）等） |
| `waisg_token_stats` | array | `{total, month, monthly}` |
| `waisg_content_templates` | string(JSON) | 内容结构模板列表 |
| `waisg_cron_log` | array | `{last_run, last_count, note}` |

---

### `WAISG_History`

历史记录和暂存区的读写操作。

```php
// 备份当前文章版本（AI 优化前调用）
WAISG_History::snapshot( int $post_id ): int|false

// 保存 AI 生成/优化的暂存内容
WAISG_History::save_staged( array $data ): int|false

// 将文章回滚到指定历史版本（回滚前自动 snapshot 一次）
WAISG_History::rollback( int $history_id ): bool

// 查询
WAISG_History::get_one( int $history_id ): object|null
WAISG_History::get_list( int $post_id, int $limit = 20 ): array
WAISG_History::get_pending_list( int $paged, int $per_page, string $search, string $entry_type ): array
WAISG_History::get_pending_count( string $search, string $entry_type ): int
```

**`save_staged()` 的 `$data` 结构：**
```php
[
    'entry_type'   => 'generated',   // 'generated' | 'optimized'
    'post_id'      => 0,             // 0 = 新文章，>0 = 待优化的原文章 ID
    'keyword'      => '关键词',       // 生成模式使用
    'post_type'    => 'post',
    'category_id'  => 0,
    'post_title'   => '...',
    'post_content' => '...',
    'post_excerpt' => '...',
    'seo_title'    => '...',
    'seo_desc'     => '...',
    'seo_kw'       => '...',
]
```

**`entry_type` 枚举值：**

| 值 | 说明 |
|----|------|
| `backup` | 优化前自动备份的旧版本（可回滚） |
| `generated` | AI 生成，尚未写入 WordPress |
| `optimized` | AI 优化，尚未应用到文章 |
| `saved` | 由 `generated` 已保存到 WordPress |
| `applied` | 由 `optimized` 已应用到文章 |

---

### `WAISG_Meta_Box`

编辑页元框渲染和单篇优化 AJAX 处理。

**关键方法：**
```php
// 获取 SEO 字段 meta key（自动检测已安装的 SEO 插件，仅用于读取）
$meta_box->get_seo_field_name( string $type ): string
// $type: 'title' | 'description' | 'keywords'
```

> **注意**：`save_post` 钩子和 AJAX 保存 SEO 字段时，均使用用户在「基本设置 → SEO 字段名配置」中显式配置的 meta key（`WAISG_Settings::get('seo_title_field')` 等），而非 `get_seo_field_name()` 的自动检测结果。只有配置了字段名的项才会被写入数据库。

**前端编辑器检测：**

`admin.js` 中使用 DOM 检测方式判断当前编辑器类型：

```javascript
// 通过 DOM 元素判断是否为 Gutenberg 编辑器
function isGutenberg() {
    return !!document.querySelector('.block-editor');
}
```

经典编辑器模式下，AI 结果会同时写入 textarea 和 TinyMCE 实例，兼容可视模式和文本/代码模式。

**`get_post_vars()` 返回的字段结构（用于 Prompt 构建）：**
```php
[
    'title'     => '文章标题',
    'content'   => '正文 HTML',
    'excerpt'   => '摘要',
    'seo_title' => 'SEO 标题 meta 值',
    'seo_desc'  => 'SEO 描述 meta 值',
    'seo_kw'    => 'SEO 关键词 meta 值',
]
```

---

### `WAISG_Cron`

WP-Cron 定时自动优化。

```php
// 清除计划任务（插件停用时调用）
WAISG_Cron::unschedule(): void
```

**自定义 Cron 间隔：**
- `weekly`：每 7 天（兼容 WP < 6.0）
- `waisg_biweekly`：每 14 天

---

### `WAISG_Schema`（v1.5.0 新增）

Schema 结构化数据 + Canonical URL 自动注入，通过 `wp_head` 输出。

**实例方法（由构造函数自动注册到 Hook）：**

```php
// 输出所有 Schema JSON-LD（wp_head priority 99）
$schema->output_schemas(): void

// 输出 canonical link 标签（wp_head priority 1）
$schema->output_canonical(): void
```

**功能组成：**

| 功能 | 设置键 | 输出内容 |
|------|--------|----------|
| FAQPage Schema | `schema_faq_enabled` | 从正文 `<h3>` + 后续段落提取 FAQ，生成 FAQPage JSON-LD |
| 全站 Schema | `schema_base_enabled` | WebSite / BreadcrumbList / Article（各有独立开关） |
| Canonical URL | `canonical_enabled` | `<link rel="canonical" href="...">` |

**Article Schema 图片四层兜底**：
1. `has_post_thumbnail()` → 特色图
2. 正文第一个 `<img>` 标签（含相对路径转绝对路径）
3. 站点 Logo（`custom_logo`）
4. 设置中的 `schema_default_image`（留空则不输出 image 字段）

**Canonical URL 覆盖页面**：singular / front_page / home / category / tag / taxonomy / post_type_archive / author / date / paged（指向第一页）

---

## 15. AJAX 接口列表

所有接口均需 nonce 验证（`check_ajax_referer('waisg_nonce', 'nonce')`）和相应权限。

### 编辑页（WAISG_Meta_Box）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_generate_article` | `edit_posts` | AI 生成文章（编辑页） |
| `waisg_optimize_all` | `edit_posts` + `edit_post($id)` | 一键优化全部字段 |
| `waisg_optimize_seo_only` | `edit_posts` + `edit_post($id)` | 仅优化 SEO 字段（不修改正文，v1.2 新增） |
| `waisg_optimize_single` | `edit_posts` + `edit_post($id)` | 单字段优化（含 SEO 指示灯点击修复） |
| `waisg_save_result` | `edit_posts` + `edit_post($id)` | 保存 SEO 字段到数据库 |
| `waisg_get_seo_fields` | `edit_posts` | 获取当前 SEO 字段值 |
| `waisg_get_opt_count` | `edit_posts` | 获取文章优化次数（Gutenberg 无刷新保存后更新） |
| `waisg_stage_from_editor` | `edit_posts` + `edit_post($id)` | 从编辑器暂存到待处理 |
| `waisg_suggest_links` | `edit_posts` | 获取内链建议 |

**`waisg_optimize_single` 请求参数：**
```
post_id            int     文章 ID
field              string  title|content|excerpt|seo_title|seo_description|seo_keywords
current_title      string  编辑器当前标题
current_excerpt    string  编辑器当前摘要
current_seo_title  string  当前 SEO 标题
current_seo_desc   string  当前 SEO 描述
current_seo_kw     string  当前 SEO 关键词
current_value      string  当前待优化字段的值
```

**`waisg_optimize_single` 返回：**
```json
{ "success": true, "data": { "field": "seo_title", "value": "优化后的内容" } }
```

---

### 批量优化（WAISG_Batch）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_batch_get_posts` | `edit_posts` | 获取待优化文章列表（已验证 post_type 白名单） |
| `waisg_batch_optimize_one` | `edit_posts` + `edit_post($id)` | 批量优化单篇（结果存暂存区），支持 `model_override` 参数（v1.6.0） |

---

### AI 文章生成（WAISG_Generator）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_gen_article` | `edit_posts` | 生成单篇文章（结果存暂存区），支持 `model_override` 参数（v1.6.0） |
| `waisg_rewrite_article` | `edit_posts` | 改写/伪原创，支持 `model_override` 参数（v1.6.0） |

---

### 优化历史（functions in class-history.php）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_rollback` | `edit_posts` + `edit_post($id)` | 回滚到历史版本 |
| `waisg_get_history` | `edit_posts` + `edit_post($id)` | 获取历史版本详情 |
| `waisg_delete_history` | `manage_options` | 删除单条历史记录 |
| `waisg_batch_delete_history` | `manage_options` | 批量删除历史记录（v1.2 新增） |
| `waisg_get_current_post` | `edit_posts` + `edit_post($id)` | 获取文章当前内容（用于对比） |
| `waisg_save_staged_to_wp` | `edit_posts` + `edit_post($id)` | 将暂存内容保存到 WordPress |

---

### 设置页（WAISG_Settings）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_test_api` | `manage_options` | 测试 AI API 连通性 |
| `waisg_test_lightweight_api` | `manage_options` | 测试轻量模型连通性（v1.2 新增） |
| `waisg_test_image_api` | `manage_options` | 测试图片 API |
| `waisg_get_token_stats` | `manage_options` | 获取 Token 用量统计 |
| `waisg_reset_token_stats` | `manage_options` | 重置 Token 统计 |
| `waisg_save_template` | `manage_options` | 保存内容结构模板 |
| `waisg_delete_template` | `manage_options` | 删除内容结构模板 |

---

### 定时优化（WAISG_Cron）

| action | 权限 | 说明 |
|--------|------|------|
| `waisg_run_cron_now` | `manage_options` | 手动立即触发一次定时优化 |
| `waisg_get_cron_log` | `manage_options` | 获取定时优化日志和下次执行时间 |

---

## 16. 数据库结构

### 表：`{prefix}waisg_history`

所有历史记录（备份、暂存）统一存放在一张表中。

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | bigint PK | 自增主键 |
| `post_id` | bigint | 关联文章 ID（AI 生成的新文章初始为 0） |
| `created_at` | datetime | 记录创建时间（WordPress 本地时间） |
| `entry_type` | varchar(20) | 见下方枚举 |
| `keyword` | varchar(500) | 生成模式使用的关键词 |
| `post_type` | varchar(50) | 文章类型 |
| `category_id` | bigint | 分类 ID |
| `post_title` | text | 文章标题 |
| `post_content` | longtext | 文章正文（HTML） |
| `post_excerpt` | text | 文章摘要 |
| `seo_title` | varchar(255) | SEO 标题 |
| `seo_desc` | text | SEO 描述 |
| `seo_kw` | varchar(500) | SEO 关键词 |

**索引：** `post_id`、`entry_type`

**版本：** `waisg_db_version` 存于 wp_options，当前为 `1.1`

---

### Post Meta 字段

| meta_key | 类型 | 说明 |
|----------|------|------|
| `_waisg_opt_count` | int | 该文章的 AI 优化次数 |
| `_waisg_ai_generated` | int(1) | 是否由本插件 AI 生成（=1） |
| `_waisg_ai_pending` | int(1) | 是否有 AI 内容待保存（随文章表单提交） |

---

## 17. 主要 WordPress Hook

### Actions（监听）

| Hook | 类 | 说明 |
|------|----|------|
| `plugins_loaded` | main file | 初始化所有功能类 |
| `pre_post_update` | WAISG_Meta_Box | 文章写库前备份旧版本（唯一能读旧数据的时机） |
| `before_delete_post` | WAISG_History | 文章永久删除时级联清理其在 `waisg_history` 中的全部记录（移入回收站不触发；v2.0.9 新增） |
| `save_post` | WAISG_Meta_Box | 写库后：保存 SEO 字段到用户配置的 meta key、增加优化次数 |
| `update_option_waisg_settings` | WAISG_Cron | 设置保存后重新调度定时任务 |
| `waisg_auto_optimize` | WAISG_Cron | WP-Cron 触发的定时优化执行钩子 |
| `cron_schedules` | WAISG_Cron | 注册 `weekly` / `waisg_biweekly` 自定义间隔 |
| `add_meta_boxes` | WAISG_Meta_Box | 注册编辑页元框 |
| `admin_menu` | 多个类 | 注册后台子菜单页面 |
| `admin_enqueue_scripts` | 多个类 | 按页面条件加载 JS/CSS |
| `manage_posts_columns` | WAISG_Post_List | 在文章列表添加 AI 状态列 |
| `wp_head` (priority 1) | WAISG_Schema | 输出 Canonical URL（v1.5.0 新增） |
| `wp_head` (priority 99) | WAISG_Schema | 输出 FAQPage / WebSite / BreadcrumbList / Article Schema（v1.5.0 新增） |

### 注册激活/停用

```php
// 插件激活
register_activation_hook → WAISG_History::create_table()

// 插件停用
register_deactivation_hook → WAISG_Cron::unschedule()

// 插件删除（从后台点"删除插件"）
uninstall.php → 删除 waisg_history 表 + 所有 options + 所有 post meta + cron 任务
```

> **注意**：*停用* 插件不会删除任何数据，方便日后重新启用。只有从 WordPress 后台彻底**删除**插件时，`uninstall.php` 才会执行，届时所有数据将被不可逆地清除。

---

## 18. 扩展开发指南

### 添加新的 Prompt 场景

1. 在 `WAISG_AI_API` 中添加静态方法 `build_xxx_prompt()`，返回 `['system'=>..., 'user'=>...]`
2. 如涉及正文生成/优化，在 prompt 中调用 `self::get_writing_style_rules()` 注入 anti-AI 写作规则
3. 在对应类中调用 `WAISG_AI_API::call($prompts['user'], $prompts['system'])`
4. 处理返回的 `$result['text']` 或用 `WAISG_AI_API::parse_json_response()` 解析 JSON
5. 如涉及正文内容，按以下顺序后处理：
   ```php
   // 1. 还原图片占位符（如果之前做了 protect_images）
   $content = WAISG_AI_API::restore_images( $content, $img_protected['map'] );
   // 2. 替换 AI 高频词（始终执行）
   $content = WAISG_AI_API::filter_ai_phrases( $content );
   // 3. 二次润色（如已开启）
   if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
       $content = WAISG_AI_API::humanize( $content );
   }
   ```

### 图片保护模式

处理含有图片的已有文章时，使用图片占位符保护机制防止 AI 丢失图片：

```php
// 1. AI 处理前：将图片替换为占位符
$img_protected = WAISG_AI_API::protect_images( $content );
$safe_content  = $img_protected['html'];  // 用这个发给 AI

// 2. 一次性获取动态 max_tokens 和 timeout（v1.3.7 新增）
$extra = WAISG_AI_API::build_long_content_extra( $safe_content );

// 3. AI 调用...
$result = WAISG_AI_API::call( $prompts['user'], $prompts['system'], $extra );

// 4. AI 返回后：还原占位符 + 自动补回丢失的图片
$content = WAISG_AI_API::restore_images( $ai_content, $img_protected['map'] );
```

**示例（新增翻译场景）：**
```php
public static function build_translate_prompt( $content, $target_lang ) {
    $system = WAISG_Settings::get( 'system_prompt', '' ) ?: '你是专业翻译。';
    $user   = "请将以下内容翻译为{$target_lang}，保持 HTML 格式不变：\n\n{$content}";
    return [ 'system' => $system, 'user' => $user ];
}
```

### 支持新的 SEO 插件

在 `WAISG_Meta_Box::get_seo_field_name()` 中按照现有格式添加新插件的检测条件：

```php
if ( defined( 'YOUR_SEO_PLUGIN_VERSION' ) ) {
    return $defaults[ $type ][ $new_index ];
}
```

同时在 `$defaults` 数组中增加对应字段名。

### 前端 SEO 评分扩展

SEO 评分逻辑在三个文件中各有一份（`admin.js`/`batch.js`/`generator.js`），均使用相同的评分规则。如需修改评分标准，同步修改三处：

```javascript
// 示例：修改 SEO 标题合格范围为 25~65 字符
var s = {
    seoTLen: grade(stl, 25, 65, 15, 75),   // 原为 grade(stl, 30, 60, 20, 80)
    // ...
};
```

### 添加新 AJAX 接口

```php
// 在对应类的 __construct() 中注册
add_action( 'wp_ajax_waisg_your_action', array( $this, 'ajax_your_action' ) );

// 实现方法（必须包含 nonce 验证 + 权限检查）
public function ajax_your_action() {
    check_ajax_referer( 'waisg_nonce', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => '权限不足。' ) );
    }
    // ... 业务逻辑 ...
    wp_send_json_success( array( 'key' => 'value' ) );
}
```

前端 JS 调用示例：
```javascript
$.post(ajaxurl, {
    action: 'waisg_your_action',
    nonce:  nonce,          // waisgCfg.nonce 或 waisgSettings.nonce 等
    param:  'value',
}, function (res) {
    if (res.success) { /* 处理成功 */ }
});
```

### 数据库迁移

如需新增表字段，参考 `WAISG_History::maybe_migrate()` 的模式：

```php
public static function maybe_migrate() {
    if ( version_compare( get_option( 'waisg_db_version', '1.0' ), self::DB_VERSION, '>=' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    $cols  = $wpdb->get_col( "DESCRIBE `{$table}`" );
    if ( ! in_array( 'new_column', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN new_column VARCHAR(100) DEFAULT ''" );
    }
    update_option( 'waisg_db_version', self::DB_VERSION );
}
```

将新版本号更新到 `DB_VERSION` 常量，下次插件加载时自动执行迁移。

---

## 19. 更新日志

### v2.0.13

#### Bug 修复

**优化/生成内容中的年份依旧陈旧（v2.0.12 只修了提示词示例，原文旧年份仍被原样保留）— `includes/class-ai-api.php`**
- 现象：v2.0.12 之后优化「2025年10个…」这类标题/正文，年份仍是 2025 不刷新（用户实测反馈：批量优化结果标题仍为旧年份）
- 根因：① 所有 Prompt 均未注入当前日期，AI 不知道真实时间；②【数据保护】规则要求"原文中的年份必须原样保留"，原文旧年份被强制保留
- 修复：
  - 新增 `get_time_base_rule()`：用 `current_time()`（站点时区）生成【时间基准】指令——"今天是 YYYY年M月D日；时效性年份（「XX年最新」「XX年榜单/趋势/排行」「截至XX年」）一律使用当前年份，不得沿用旧年份；历史事件与统计数据的年份属于事实本身，保持原样，不得虚构数据"
  - 注入 6 个场景的 Prompt 动态区：优化全部 / 仅优化 SEO / 生成 / 改写 / 单字段正文 / 单字段短字段（批量优化与定时任务复用前两个 builder，全链路覆盖）
  - 【数据保护】规则同步调整：数字/数据/单位仍原样保留；年份单独按【时间基准】处理（时效性→当前年份，事实→保留）
- 缓存影响：时间基准位于动态区（cache 前缀之外），可缓存前缀不变，Prompt 缓存命中率不受影响；GEO 示例年份随请求实时计算，仅跨年时缓存自然失效一次
- 验证：PHP 8.0.2 `php -l` 通过；冒烟测试 28 项全过（6 场景均含真实日期与当前年份、缓存前缀不含完整日期与写死年份、数据保护年份例外生效）

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | 新增 `get_time_base_rule()` 注入 6 场景动态区；`get_writing_style_rules()`【数据保护】年份例外 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.13 |
| `README.md` | 修改 | 版本号 + v2.0.13 更新日志 |
| `CODE_REVIEW.md` | 修改 | 补第十七轮审查记录 |

---

### v2.0.12

#### Bug 修复

**优化/生成内容中的年份总是旧的（写死「2024年」）— `includes/class-ai-api.php`**
- 现象：使用「一键优化全部」等正文优化功能后，AI 生成的内容里年份总是旧的（如「截至2024年」），不会跟随当前时间
- 根因：「优化全部」Prompt 的 GEO 规则里硬编码了示例文本「使用数据/统计/年份佐证观点（如『截至2024年…』）」，AI 会直接模仿示例照抄该年份；同时所有 Prompt 均未注入当前日期，AI 不知道真实时间，只能依赖训练数据里的旧年份
- 修复：示例年份改为动态取站点时区当前年份（`current_time( 'Y' )`），Prompt 每次构建时自动跟随真实时间（如 2026 年则输出「截至2026年…」）
- 说明：`current_time()` 为 WordPress 时区感知函数，与后台显示时间一致；该行位于可缓存 Prompt 前缀中，年份一年才变化一次，对 Prompt 缓存命中率无实际影响
- 验证：PHP 8.0.2 `php -l` 通过

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | `build_optimize_all_prompt()` GEO 规则示例年份写死「2024」改为 `current_time( 'Y' )` 动态取当前年份 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.12 |
| `README.md` | 修改 | 版本号 + 补 v2.0.12 更新日志 |
| `CODE_REVIEW.md` | 修改 | 补第十六轮审查记录 |

---

### v2.0.11

#### 功能改进

**AI 生成/优化正文自动清理无用包装标签（div/p/span 等）— `includes/class-ai-api.php` + `includes/class-settings.php` + `admin/views/settings.php`**
- 背景：AI 返回的正文常带 `<div class="..."><p>...</p></div>`、`<span style="...">`、`<font>` 等无语义包装标签。写入 WordPress 后，这些标签让块编辑器把全篇识别为「经典块」且内部残留无效布局标签，前台布局样式容易失控；而 `<p>` 段落本可由 WordPress 的 wpautop 依据空行在前台自动重建，无需 AI 显式输出
- 实现：
  - `sanitize_content()`（所有生成/优化/定时/应用路径共用的正文净化入口）新增包装标签剥除步骤，设置页「清理无用标签」开关控制，默认开启
  - 剥除规则：块级包装（p/div/section/article/main/aside/nav/header/footer）闭合标签转为空行（保留段落结构，前台 wpautop 自动重建 `<p>`），开标签（含属性/自闭合斜杠）直接剥除；行内包装（span/font）开闭均剥除；剥除产生的 3+ 连续空行压成单个空行；孤立成行的 `&nbsp;`（原空段落残留）整行清除，行中间的正常 `&nbsp;` 间距不受影响
  - 保护规则：h1-h6、ul/ol/li、table、img/a/strong/em/blockquote、figure/figcaption、hr 等语义标签原样保留；HTML 注释（图片/嵌入占位符 `<!--WAISG_IMG_N-->`、Gutenberg 块注释）天然不匹配正则，原样保留，图片还原不受影响；pre/code 内容先摘出后还原，代码示例中的同名标签（字面文本）不被误剥；`\b` 词边界防止 `<picture>` 等 p 开头标签误伤
  - 所有正文 Prompt 注入【包装标签】规则：禁止输出 div/span/font/section 等包装标签与内联 style，从源头减少无效标签（PHP 端剥除仍兜底）
  - 提示词正则与代码剥除正则均有「未知修饰符/回溯溢出」防护（回退原值不丢内容），剥除逻辑幂等（重复处理结果一致）
- 验证（PHP 8.0.2 phpStudy Pro）：`php -l` 通过（class-ai-api.php / class-settings.php / settings.php / 主文件）；`sanitize_content` 功能测试 19 组用例全过——div+p 剥除、开关关闭原样保留、span/font 剥除、li 内 p 剥除、pre/code 保护、图片占位符与 Gutenberg 块注释保留、`<picture>` 不误伤、完整文档提取 body、空段落 nbsp 清除、行内 nbsp 保留、深层嵌套、幂等性、figure/table 保留、null 参数走设置（开/关两态）、null/空串/纯文本退化输入

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | `sanitize_content()` 新增 `$strip_wrapper_tags` 参数与包装标签剥除（pre/code 摘出还原、nbsp 幽灵行清除、空行压缩）；`get_writing_style_rules()` 注入【包装标签】规则 |
| `includes/class-settings.php` | 修改 | 设置项白名单新增 `strip_wrapper_tags` |
| `admin/views/settings.php` | 修改 | 「批量优化设置」区块新增「清理无用标签」开关（默认勾选） |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.11 |
| `README.md` | 修改 | 版本号 + 基本设置章节补「清理无用标签」说明 + 补 v2.0.11 更新日志 |
| `CODE_REVIEW.md` | 修改 | 补第十五轮审查记录 |

---

### v2.0.10

#### 功能改进

**批量优化支持「待审」文章 — `admin/views/batch.php` + `assets/js/batch.js` + `includes/class-history.php` + `admin/views/history.php`**
- 背景：批量优化页「文章状态」筛选只有 已发布/草稿/全部 三档，待审（pending）文章只能借「全部」混入；更关键的是应用优化结果的接口 `waisg_save_staged_to_wp` 的目标状态白名单（`draft/publish/future`）不含 `pending`——即使筛出来优化了，应用时也无法保持待审，不选状态就会被强制变成草稿
- 变更：
  - 批量优化页状态筛选新增「待审」（`waisg_batch_get_posts` 的 status 参数本就透传，无后端改动）
  - `waisg_ajax_apply_result` 目标状态白名单两处（入参校验回退、更新分支写入校验）加入 `pending`，非法值仍回退草稿；该接口为批量优化页与优化历史「待处理」Tab 共用，两处同时生效
  - 批量应用操作栏、单篇详情「应用为」、优化历史页批量应用与单篇保存四处下拉均新增「保持待审」；应用成功的状态提示标签加入「待审」
  - 优化历史「待处理」Tab 的 AI 生成记录同样受益（`generated` 分支 `post_status` 直取白名单入参），可将暂存内容应用为待审文章
  - 「待审」不触发定时发布日期输入框（联动逻辑仅匹配 `future`），无需额外前端改动
- 注意：AI 生成器页的状态修改接口（`class-generator.php` `ajax_update_post_status`）白名单未动，仍为三态，与批量优化流程无关
- 验证：PHP 8.0.2（phpStudy Pro）`php -l` 通过（batch.php / history.php 视图、class-history.php、class-batch.php、主文件）；应用状态白名单逻辑模拟用例 6 组全过（pending 接受、非法/空值回退 draft）；`batch.js` `node --check` 通过

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `admin/views/batch.php` | 修改 | 状态筛选新增「待审」；批量应用操作栏新增「保持待审」 |
| `assets/js/batch.js` | 修改 | 单篇详情「应用为」新增「保持待审」；应用成功状态标签加入 pending |
| `includes/class-history.php` | 修改 | `waisg_save_staged_to_wp` 目标状态白名单两处加入 `pending` |
| `admin/views/history.php` | 修改 | 待处理 Tab 批量应用与单篇保存下拉新增「保持待审」；状态标签加入 pending |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.10 |
| `README.md` | 修改 | 版本号 + 批量优化/优化历史章节补充待审说明 + 补 v2.0.10 更新日志 |
| `CODE_REVIEW.md` | 修改 | 补第十四轮审查记录 |

---

### v2.0.9

#### Bug 修复

**优化历史删除记录后列表不刷新，需手动点「应用」— `admin/views/history.php` + `includes/class-history.php`**
- 现象：备份历史（及待处理 Tab）批量删除选中后，仅前端移除已删行；若当前页被删空（全选当前页删除的最常见场景），表格直接空白，剩余记录不会从后续页上移，「共 N 条」「第 X / Y 页」和 Tab 徽章也全是过期数据——必须手动点「应用」重载页面才能看到正确列表。单条「删除」按钮、「批量应用到 WordPress」完成后存在同样问题
- 根因：三个删除/应用流程成功后都只做 `fadeOut().remove()` 的客户端删行，从不重新拉取服务端列表；列表的总数、分页、徽章均为 PHP 服务端渲染，客户端无法自愈
- 修复：
  - 视图注入 `listCfg`（当前 Tab / 总数 / 每页条数 / 页码）到内联脚本；给总数（`#waisg-list-total`）、页码（`#waisg-list-pageinfo`）、待处理徽章（`#waisg-pending-badge`）、备份 tbody（`#waisg-history-tbody`）补上 id
  - 新增 `refreshListAfterDelete()` 辅助函数，三处统一调用：本页还有剩余行 → 就地更新总数/页码/徽章（待处理 Tab 徽章同步递减，减到 0 移除）；本页删空 → 600ms 后自动跳转到 clamp 后的有效页码（`min(当前页, 新总页数)`）重新拉取列表，剩余记录上移，全部删光则跳回第 1 页显示空状态提示——不再需要手动点「应用」
  - 后端 `waisg_ajax_batch_delete_history()` 返回值从 `count($ids)`（请求数）改为 `$wpdb->rows_affected`（实际删除行数），避免记录已被其他会话删除时前端总数多减
  - 按钮状态恢复：成功分支无论"就地更新"还是"跳页刷新"都恢复按钮文案（跳页时页面随即重载，恢复无副作用），禁用状态交给 `updateBatchCount()` 依据勾选数决定——修复部分删除（含单删一条）后按钮永远卡在"删除中..."的回归
- 验证：PHP 8.0.2 `php -l` 通过；内联 JS 经 PHP 片段替换后 `node --check` 通过；页码计算 5 组边界场景（删空末页回退、部分删除就地更新、末页单行、删光唯一记录、实删少于请求）全部正确；按钮文案三条返回路径（成功/业务失败/网络失败）均有恢复点

#### 功能改进

**优化历史每页显示条数上限 200 → 2000 — `admin/views/history.php`**
- 大批量清理备份时，200 条/页需要翻页多轮操作；上限放宽到 2000（待处理/备份两个 Tab 生效）
- 同步修改 4 处限制点：服务端 `$per_page` clamp（`max(10, min(2000, ...))`）、待处理/备份两个每页输入框的 `max` 属性、JS 侧「应用」按钮的 clamp；`get_pending_list()` / `get_backup_list()` 模型方法本就无内部限制，`per_page` 直接进 LIMIT，无需改动
- 注意：2000 行服务端渲染页面较大，建议日常使用 20~50，大批量清理时临时调高

**批量优化页默认值与分页选项调整 — `admin/views/batch.php` + `includes/class-batch.php`**
- 「每次最多获取」默认值 50 → 100 篇；单次上限 200 → 500 篇，服务端 `min($limit, 500)` 与输入框 `max="500"` 对齐（此前输入框允许 500 但服务端截断到 200，两处不一致）
- 「每页显示」下拉新增「200 篇/页」一档（前端 `getPerPage()` 直接读取下拉值，无额外 clamp，加选项即生效）

**新增「清理留痕记录」功能 — `class-history.php` + `admin/views/history.php`**
- 背景：暂存内容保存/应用到 WordPress 后，`waisg_history` 表中的记录会改类型留痕（`generated`→`saved`、`optimized`→`applied`），这两类记录在优化历史两个 Tab 中均不显示，导致"后台删光了列表、数据表里还有数据"的困惑，且留痕无任何清理入口、只增不减
- 实现：优化历史页 Tab 导航下方新增「🧹 清理留痕记录」按钮（两个 Tab 通用）。新增 AJAX 接口 `waisg_purge_traces`（`manage_options` 权限，支持 `dryrun` 预检）：点击先统计 saved/applied 数量 → 为 0 提示无需清理 → 否则弹确认框（说明留痕用途与影响）→ 确认后 `DELETE FROM ... WHERE entry_type IN ('saved','applied')` 物理删除
- 影响：删除留痕后，批量优化页对应结果的"再次应用"能力随记录一起消失（内容已在文章中，一般无影响）；已保存的文章内容不受任何影响
- **删除联动留痕**：单条删除（`waisg_ajax_delete_history`）与批量删除（`waisg_ajax_batch_delete_history`）记录时，同步删除同文章的 `saved`/`applied` 留痕——"删除备份时该文章的优化留痕一起清掉"，列表删干净即表干净，无需再单独跑清理按钮。注意批量删除返回的 `deleted` 为列表行数（不含连带清理的留痕数），前端总数同步不受影响

**文章永久删除时级联清理历史记录 — `class-history.php`**
- 背景：在后台删除文章后，该文章在 `waisg_history` 表中的备份、优化暂存、留痕记录不会被连带删除（表与 `wp_posts` 无外键、无级联），残留的"（已删除）"行和留痕越积越多，需手动进数据库或用清理按钮删除
- 实现：注册 `before_delete_post` 钩子，文章被永久删除时按 `post_id` 物理删除其在 `waisg_history` 表中的全部记录（`waisg_delete_post_history()`）；排除 `post_id <= 0`——`post_id=0` 的是 AI 生成尚未落库的暂存文章，与任何具体文章无关，不能被误删
- 行为说明：移入回收站不触发（文章可恢复，历史保留）；从回收站彻底删除、文章列表"永久删除"、批量永久删除均逐篇触发；AI 生成未保存的暂存文章（`post_id=0`）不受影响，仍需在待处理 Tab 手动删除

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `admin/views/history.php` | 修改 | 注入 `listCfg`；新增 `refreshListAfterDelete()`；批量删除/单条删除/批量应用三处成功回调接入列表同步；总数/页码/徽章/tbody 补 id；每页条数上限 10~2000；新增「清理留痕记录」按钮与 JS |
| `includes/class-history.php` | 修改 | 批量删除返回实际删除行数 `rows_affected`；新增 `waisg_purge_traces` 清理留痕接口（支持 dryrun 预检）；注册 `before_delete_post` 级联清理文章历史记录 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.9 |
| `README.md` | 修改 | 版本号 + 补 v2.0.9 更新日志 |

---

### v2.0.8

#### 功能改进

**优化/生成正文自动清理空标签（`<p></p>`、`<p>&nbsp;</p>`、`<p><br></p>` 等）— `class-ai-api.php`**

- 现象：AI 优化/生成/改写后的正文里常出现 `<p></p>`、`<p>&nbsp;</p>`、`<p><br></p>`、`<h2></h2>` 这类"空壳标签"，渲染出来是成片多余空行；无效嵌套（如 `<p><h2>标题</h2></p>`）经解析纠正后也会产生幽灵空段落
- 说明：**有文字内容的 `<p>` 不清理**——它承载段落结构，全部删除会让正文挤成一团。清理对象仅限"剥掉标签和空白后没有任何文字"的空壳
- 修复（两层配合）：
  - **PHP 层**：`sanitize_content()` 新增空标签清理步骤（正则回调，零依赖），覆盖 `p` / `h1-h6` / `li` / `blockquote` 五类标签。该函数是全部正文路径的统一漏斗（单字段优化 / 一键优化 / 生成 / 改写 / 批量 / 定时 / humanize / 历史对比等 9 条链路），清理自动生效，无需改各调用点
  - **Prompt 层**：`get_writing_style_rules()` 新增【空段落】规则，约束 AI 不要输出空标签，从源头减少产生
- 保留规则（不误删）：
  - 含图片/iframe/video/audio/svg/object/embed/figure/table/ul/ol/blockquote/hr/div 等结构标签的块原样保留
  - 含 HTML 注释（图片/嵌入占位符 `<!--WAISG_IMG_x-->`、Gutenberg 块注释）的块保留——否则占位符会被连同空壳删除，`restore_images()` 无法还原图片
  - 含短代码文本的段落保留
  - 干净内容进出字符级一致（byte 级不变）
- 实测（PHP 8.0.2）：14 组场景 + 3 项回归（文档级标签剥除、字符级一致、长文回溯安全）全部通过

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | `sanitize_content()` 增加空标签清理（含占位符/媒体/短代码保留规则）；写作风格规则新增【空段落】约束 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.8 |
| `README.md` | 修改 | 版本号 + 补 v2.0.8 更新日志 |

---

### v2.0.7

#### Bug 修复

**批量优化成功/失败计数不准（第二轮起虚增）— `assets/js/batch.js`**
- 现象：批量优化第一轮统计正确；从第二轮起（仅重试失败项 / 停止后继续 / 再次勾选运行）收尾的"✅ 成功 N 篇"包含之前所有轮次的成功数，可远大于本轮总数。如第一轮 10 篇（8✅ 2❌）→ 重试 2 篇失败项全部成功 → 收尾显示"共 2 篇，成功 10 篇，已完成 10 / 2"
- 根因：收尾统计（原第 248 行起）用 `successIds.length` 作为本轮成功数，但 `successIds` 是整个页面会话的累计值（从不重置，用于"跳过已优化"过滤）；而 `totalCount` / `failedIds` / `doneCount` 都是每轮重置的本轮值，两个口径混用导致成功数虚增、进度百分比被 clamp 到 100%
- 修复：
  - 新增本轮维度计数 `roundSuccess`（每轮开始清零，成功入列时同步累加），收尾展示改用 `roundSuccess` / `failedIds.length`，不再取会话累计的 `successIds.length`；`successIds` 保留原用途（开始优化时跳过已成功项）
  - 新增 `baseDone`（断点基数）：从 localStorage「继续执行」恢复任务时置为 `saved.done`，收尾"已完成 X / Y"与进度百分比改为 `(baseDone + 本轮完成) / totalCount`——修复恢复场景下 35 篇全部完成却显示"已完成 5 / 35（14%）"的问题；完成日志注明"断点续跑，此前已完成 N 篇"
  - 防双列：`.done` 回调中先 `successIds.push` 再渲染，渲染抛异常落入 catch 时会把同一篇再计入 `failedIds`，导致一篇同时算进 ✅ 和 ❌——catch 里先把该 ID 从 `successIds` 移除（`roundSuccess` 同步回退）再记失败，保证一篇只计一次
  - `.fail` 路径补 `saveBatchState()`：此前只有 `.done` 路径写 localStorage，HTTP 级失败（如超时）不计入"已完成"，断点恢复提示条的进度会偏小
- 收尾日志格式调整：`🏁 本轮完成。共 X 篇，成功 Y 篇...` → `🏁 本轮完成。本轮处理 N 篇，成功 Y 篇，失败 Z 篇（断点续跑，此前已完成 M 篇）。`——手动停止中途结束时"处理 N 篇"（N < X）比"共 X 篇"更准确

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `assets/js/batch.js` | 修改 | 新增 `roundSuccess` / `baseDone` 变量；开始/重试/恢复三处入口重置本轮计数；收尾统计改用本轮计数 + 断点基数；catch 防双列；`.fail` 补 `saveBatchState()` |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.7 |
| `README.md` | 修改 | 版本号 + 补 v2.0.7 更新日志 |

---

### v2.0.6

#### Bug 修复

**单篇文章单独优化正文导致图片全部丢失 — `class-meta-box.php`**
- 现象：用户在编辑页点击「单独优化 → 正文」后，文章中的 `<figure>`、`<img>` 等所有图片标签被全部剥光，只剩纯文本
- 根因：`ajax_optimize_single()` 第 634 行对所有字段统一用 `sanitize_textarea_field()` 处理 `current_value`，而该函数会剥除所有 HTML 标签。正文进入 `protect_images()` 时已无任何图片可保护（map 为空），后续 `restore_images()` 兜底补图机制完全失效
- 修复：content 字段改用 `WAISG_AI_API::sanitize_content()`（零过滤，保留 HTML），与 batch / cron / generator / history 四条保存路径完全一致；其余短字段（title / excerpt / seo_*）保持 `sanitize_textarea_field` 不变（它们本就不该含 HTML）

**优化历史「对比当前」看不到正文 HTML 标签 — `class-history.php` + `history.php`**
- 现象：备份历史的「对比当前」弹窗中，当前版本的正文预览看不到任何 HTML 标签（`<h2>`、`<img>`、`<p>` 等），只能看到纯文本内容
- 根因：① 后端 `waisg_ajax_get_current_post()` 用 `wp_strip_all_tags()` 剥光了正文 HTML；② 前端用 `esc()` 把标签转义成字面文本显示
- 修复：① 后端改用 `WAISG_AI_API::sanitize_content()`（零过滤，与 `waisg_get_history` 一致）；② 前端新增 `contentPreviewHtml()` 函数，支持「渲染 / 源码」两种模式切换——渲染模式显示真实 HTML，源码模式显示原始 HTML 代码；查看详情、对比当前、待处理对比三处都改用该函数；对比差异检测加入 `post_content`（之前被排除，正文有变化也不会高亮）

#### 功能改进

**优化历史 - 备份历史增加搜索功能 — `class-history.php` + `history.php`**
- 备份历史 Tab 新增搜索表单，支持按标题搜索备份记录（与待处理 Tab 搜索体验一致）
- 后端新增 `WAISG_History::get_backup_list()` / `get_backup_count()` 两个模型方法，与待处理的 `get_pending_list()` / `get_pending_count()` 完全对称；视图中的约 55 行内联 SQL 全部删除，改为模型方法调用，SQL 注入防护（`$wpdb->prepare` + `esc_like`）与待处理一致

**优化历史 - 待处理增加「对比当前」功能 — `history.php`**
- 待处理列表的 `optimized` 类型行（有关联文章）新增「对比当前」按钮，可对比暂存内容与当前文章的差异（复用现有对比模态框和差异高亮逻辑）；`generated` 类型无当前版本不显示该按钮

**单篇优化达标时改为确认提示（可强制优化）— `class-meta-box.php` + `admin.js`**
- 现象：单字段优化时，若字段已达标（长度合格 + 含关键词），后端直接返回 error 阻止优化，用户无法再次优化改进表达
- 修复：后端达标时改为返回 `need_confirm: true`（而非 error）；前端弹出 `confirm()` 让用户选择「强制优化」或「取消」，选择强制则带 `force=1` 参数跳过预检直接执行；`content` / `excerpt` / `seo_keywords` 字段不做达标预检（本就不阻断）

**单字段优化后也显示「暂存到待处理」提示条 — `admin.js`**
- 现象：单字段优化（普通按钮 + SEO 评分点击修复）完成后不显示暂存提示条，无法暂存
- 根因：暂存提示条只在 `fillResult()` 里显示，单字段走 `applySingleResult()` 绕过了
- 修复：单字段优化成功回调（`doOptimizeSingle()` + `runScoreOptimize()`）增加显示 `#waisg-staging-bar`，复用已有的 `waisg_stage_from_editor` 暂存逻辑

#### 内容质量优化（提示词 + 词库 + 润色指令）

解决用户反馈的两个内容质量问题：**数据单位丢失**（如描述"500亿美元"，标题只写"500亿"）和**散装句子**（优化后内容不连贯）。根因经全链路分析定位在三层反 AI 机制——写作风格规则、高频词词库、humanize 润色指令——旧版策略是"删除过渡词"，直接破坏了句子间的逻辑连接。

- **写作风格规则 `get_writing_style_rules()`**：新增【数据保护】+【过渡词】两个规则块
  - 数据保护：原文中的数字、数据、单位、年份（如「500亿美元」「3.2亿用户」「2024年」「增长15%」）必须原样保留，标题和正文中都不得缩写、省略或改写单位
  - 过渡词：把书面过渡词换成口语连接（此外→另外、然而→不过、因此→所以、综上所述→总之），保持句子之间的逻辑连贯，不要生硬删除连接词导致句子断裂
- **高频词词库 `get_default_phrases()`**：55 条规则**全部从"删除"改为"自然替换词"**，0 条删除
  - 旧版 41 条规则直接删除过渡词（值 = 空字符串），`str_replace` 全文执行后句子断裂——这是散装句子的直接元凶
  - 改后所有规则替换为等价的口语表达（此外→还有、同时→另外、值得注意的是→要注意的是、毫无疑问→当然、众所周知→大家都知道、事实上→实际上、在当今社会→现在 等）
- **humanize 润色指令 `get_humanize_instruction_prefix()` + `get_default_humanize_prompt()`**：
  - 【严禁使用】硬禁列表 → 【避免使用】+ "这些词替换成自然口语，不要直接删掉"
  - 新增数据保护规则 + "保持句子之间的逻辑连贯：改写连接词时必须用等价的口语连接替代，不得直接删除导致句意断裂"
  - 第 4 条改为"口语化连接替代书面过渡词：此外→另外、然而→不过、因此→所以、同时→另外、综上→总之。用「对吧」「是不是」「你看」「话说回来」等口语连接，保持上下文连贯"
- **本地实测验证**（WordPress 6.9.4 + PHP 8.0.2）：三处改动通过反射调用验证；`filter_ai_phrases` 模拟替换后句子连贯无断裂

> ⚠️ **迁移说明**：如面板中「润色提示词」和「AI 高频词替换词库」已填写自定义内容（非空），代码内置默认值的改动**不生效**——需清空这两个面板框并保存，才能用上优化后的新默认值。「系统提示词」（角色 Prompt）与本次改动无关，无需调整。

**AI 生成的正文包含文档级标签（`<html>`/`<head>`/`<body>`/`<meta>`/`<title>`）— `class-ai-api.php`**
- 现象：优化/生成/改写正文后，正文里出现 `<head>`、`<body>`、`<meta>`、`<title>` 等文档级标签，渲染到前台导致页面结构错乱
- 根因：① prompt 层从未约束 AI 不要输出文档级标签；② PHP 层 `sanitize_content()` 是零过滤，原样返回——AI 写了什么就存什么
- 修复（两层配合）：
  - **Prompt 层**：`get_writing_style_rules()` 新增【正文边界】规则——"content 仅输出 `<body>` 内的正文片段，禁止包含 `<html>`/`<head>`/`<body>`/`<meta>`/`<title>`/`<!DOCTYPE>` 等文档级标签"，所有正文场景自动注入
  - **PHP 层**：`sanitize_content()` 加 DOMDocument 兜底——用 DOM 解析提取 `<body>` 内部内容，剥除文档级标签后返回；正文标签（h2/p/figure/img/iframe 等）完整保留；DOMDocument 不可用时回退正则兜底
- 本地实测：完整文档结构 / 部分文档标签 / 纯正文 / 正文标签保留 四种场景全部通过
- **补充修复**：上述 `sanitize_content()` 只在写入数据库时被调用，但 AJAX 返回前端编辑器时走的是 `filter_ai_phrases()` 链路——两者断开，导致编辑器代码模式下仍能看到 `<body>` 标签。在 `filter_ai_phrases()` 最前面加一道 `sanitize_content()` 调用，覆盖全部 9 个正文处理链的最终返回点（单字段优化 / 一键优化全部 / 生成 / 改写 / 批量 / 定时 / humanize 单次+分段），AJAX 返回前端的正文也会剥除文档级标签

**正文优化后在开头重复标题 — `class-ai-api.php`（4 处 builder）**
- 现象：优化正文后，正文开头出现一个和文章标题一模一样的标题（`<h1>` 或纯文本）；改了标题，正文里的标题也跟着变
- 根因：四个 prompt builder 把标题作为"参考"和正文一起发给 AI，AI 把标题当成正文的一部分写进了 content 字段开头。标题是动态读取的，所以"标题怎么改就生成什么"
- 修复：四个 builder 的 content 指令全部加上"content 是正文片段，不要在开头重复 title（标题已单独输出到 title 字段）"
  - `build_single_field_prompt` content 分支：加"标题仅为参考，不得在正文开头重复标题"+ 动态部分改为"标题（仅供参考，不要写入正文）"
  - `build_optimize_all_prompt` / `build_generate_prompt` / `build_rewrite_prompt`：content 字段指令加"不要在开头重复 title"

#### 全量复查修复（6 项）

对 v2.0.6 全部改动做全量复查后发现 6 个问题，已全部修复：

- **`sanitize_content()` 正则回溯溢出会清空正文**：三处 PCRE 正则（body 提取 / script+style 剥除 / 文档标签剥除）无 null 兜底，长文命中 `pcre.backtrack_limit` 时 `preg_replace` 返回 null，整篇正文被清空。三处都加 `if ( $result !== null )` 兜底，回溯溢出时保留原值
- **词库 6 组"原词==替换词"无用规则**：如 `具体来说，`→`具体来说，`，`str_replace` 做无用功且面板展示困惑。改为更口语的替换词（`具体来说，`→`简单来说，`、`值得一提的是，`→`有意思的是，` 等）
- **`str_replace` 链式覆盖**：`此外→另外`，紧接着 `另外→还有`，导致 `此外` 最终变成 `还有`（与风格规则文案"此外→另外"不一致）。改用 `strtr` 替代 `str_replace`——`strtr` 不会对替换结果二次匹配，天然规避链式覆盖
- **`waisg_ajax_get_history` 未 sanitize 与 `waisg_ajax_get_current_post` 不一致**：对比模态框"历史版本"侧返回原始 post_content（可能含 script），"当前版本"侧已 sanitize。`waisg_ajax_get_history` 加 `sanitize_content()`，两侧一致，消除渲染模式 XSS 残余风险
- **备份搜索表单 action URL 重复 post_id**：action 已带 `&post_id=X`，隐藏域又提交一次。action 去掉 post_id，靠隐藏域携带
- **`build_single_field_prompt` title 为空时提示语留白**：`if ( $title )` 包裹，标题为空时不输出该行

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | 写作风格规则加数据保护+过渡词+正文边界；词库 55 条全改为替换+strtr 替代 str_replace+6 组无用规则修正；humanize 指令【严禁】改【避免】；sanitize_content 加文档标签剥离+null 兜底；filter_ai_phrases 前置 sanitize_content；四个 builder 加"不要重复标题"约束；title 为空时提示语处理 |
| `includes/class-meta-box.php` | 修改 | content 字段改用 `sanitize_content`（修复图片丢失 bug）；达标预检改为 `need_confirm` + `force` 参数 |
| `includes/class-history.php` | 修改 | `get_current_post` 去除 `wp_strip_all_tags` 改 `sanitize_content`；`waisg_ajax_get_history` 加 `sanitize_content` 一致性；新增 `get_backup_list()` / `get_backup_count()` 模型方法 |
| `admin/views/history.php` | 修改 | 备份搜索表单 + 模型方法调用 + post_id 去重；`contentPreviewHtml` 渲染/源码切换；待处理对比当前按钮 + JS；删除内联 SQL |
| `assets/js/admin.js` | 修改 | `doOptimizeSingle` / `runScoreOptimize` 处理 `need_confirm`；单字段优化显示暂存提示条 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.6 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.6 更新日志（含提示词优化 + 文档标签 + 重复标题 + 复查修复） |

---

### v2.0.5

#### Bug 修复

**class-generator.php 两处错误消息未净化（XSS）**
- 现象：`ajax_gen_article`（第 114 行）和 `ajax_rewrite_article`（第 960 行）把 `WP_Error::get_error_message()` 直接塞进 JSON 返回前端，未经 `wp_strip_all_tags()` 净化。同文件 `ajax_fetch_images`（第 156 行）和 `class-batch.php`（v2.0.0 #51 已修）都做了净化，唯独这两处遗漏
- 后果：AI 上游错误页（如网关返回的 HTML 错误页）含 `<script>` 等标签时，前端 `.html()` 渲染 message 会触发 XSS
- 修复：两处都改为 `wp_strip_all_tags( $result->get_error_message() )`，与全插件其他 11 处错误返回路径一致

**batch.js URL 未转义直接拼进 href 属性（XSS 属性逃逸）— `assets/js/batch.js`**
- 现象：批量应用成功后 `safeUrl(d.edit_url)` 只做协议白名单校验（`/^https?:\/\//i`），不转义引号，直接拼进 `href="..."`。对比 `generator.js:343` 正确做了 `escHtml(safeUrl(...))`——batch.js 漏了 `esc()`
- 后果：后端返回的 `edit_url` 若被污染为 `https://evil.com" onmouseover="alert(1)`，会逃逸属性注入标记/JS
- 修复：改为 `esc(safeUrl(d.edit_url))`，与 generator.js 对齐

**class-history.php wp_insert_post 错误消息未净化 — `class-history.php`**
- 现象：`waisg_ajax_save_staged_to_wp` 新建文章分支，`wp_insert_post` 返回 `WP_Error` 时直接拼 message 返回前端，未经净化
- 修复：加 `wp_strip_all_tags( $post_id->get_error_message() )`，与全插件一致

#### 安全加固

**前端服务端 ID 转义加固（6 处 HTML 属性上下文）— `batch.js` + `generator.js` + `settings.js`**
- 现象：后端返回的 `history_id`/`tpl.id` 直接拼进 HTML 属性（`data-history-id="..."`、`value="..."`、`data-id="..."`），未做转义。当前后端返回的是整数无实际风险，但缺少客户端兜底
- 修复：6 处 HTML 属性上下文全部包 `esc()`/`escHtml()`：
  - `batch.js:427` `data-history-id`
  - `generator.js:167/180/240/247/259` `data-history-id` / checkbox `value`
  - `settings.js:177/182/187` `data-id`
- 注：jQuery 选择器内的 4 处（`generator.js:278/347/646/664`）保持原样——选择器字符串不会被解析为 DOM，转义反而破坏匹配

**get_seo_field_name 改静态方法，消除 7 处 new WAISG_Meta_Box() — `class-meta-box.php` + 4 个调用文件**
- 现象：`class-history.php`（4 处）、`class-batch.php`、`class-cron.php`、`class-schema.php` 共 7 处仅为调用 `get_seo_field_name()` 而 `new WAISG_Meta_Box()`，每次实例化重复注册 11 个 `add_action`（含 `pre_post_update`/`save_post`/8 个 AJAX）
- 修复：`get_seo_field_name` 改为 `public static`，调用方改为 `WAISG_Meta_Box::get_seo_field_name()`，消除全部 7 处实例化
- 影响：减少无谓的 hook 重复注册，消除 `waisg_ajax_save_staged_to_wp` 中 `new` 后 `wp_update_post` 触发重复 `save_post` 的脆弱性

**loose_extract_fields 正则回溯加固 — `class-ai-api.php`**
- 现象：`loose_extract_fields` 的 `preg_match` 在正则回溯溢出时返回 `false`，旧代码用 `if ( preg_match(...) )` 隐式判断（false 不等于 0 但会被视为 falsy，实际无 bug 但不够明确）
- 修复：改为 `=== 1` 严格判断，加注释说明 PCRE `backtrack_limit` 兜底

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-generator.php` | 修改 | 第 114/960 行错误消息加 `wp_strip_all_tags()` 防 XSS（#67） |
| `assets/js/batch.js` | 修改 | 第 651 行 `safeUrl(d.edit_url)` 包 `esc()` 防 href 属性逃逸（#68）；第 427 行 `data-history-id` 加 `esc()`（#70） |
| `includes/class-history.php` | 修改 | 第 425 行 wp_insert_post 错误消息加 `wp_strip_all_tags()`（#69）；4 处 `new WAISG_Meta_Box()` 改静态调用（#71） |
| `assets/js/generator.js` | 修改 | 第 167/180/240/247/259 行 historyId 加 `escHtml()`（#70） |
| `assets/js/settings.js` | 修改 | 第 177/182/187 行 tpl.id 加 `esc()`（#70） |
| `includes/class-meta-box.php` | 修改 | `get_seo_field_name` 改 `public static`（#71） |
| `includes/class-batch.php` | 修改 | 1 处 `new WAISG_Meta_Box()` 改静态调用（#71） |
| `includes/class-cron.php` | 修改 | 1 处 `new WAISG_Meta_Box()` 改静态调用（#71） |
| `includes/class-schema.php` | 修改 | 1 处 `new WAISG_Meta_Box()` 改静态调用（#71） |
| `includes/class-ai-api.php` | 修改 | `loose_extract_fields` 正则 `preg_match` 改 `=== 1` 严格判断 + 安全注释（#72） |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.5 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.5 更新日志 + 第十二轮审查记录 |

---

### v2.0.4

#### Bug 修复

**AI 图片生成接口超时不足（中文关键词生成失败）— `class-generator.php`**
- 现象：OpenAI 兼容格式（`/v1/images/generations`）和 Gemini Imagen 格式的图片生成 API 超时硬编码为 60 秒。SD WebUI 已有 120 秒，但前两者未同步
- 后果：使用中文关键词（如"WordPress 插件"）调用 AI 图片生成时，部分模型（如 agnes-image-2.1-flash）生成耗时约 30-40 秒，叠加网络延迟后经常超过 60 秒上限，导致 `wp_remote_post` 超时失败，文章配图插入失败
- 修复：OpenAI 兼容格式和 Gemini Imagen 格式的超时统一调整为 120 秒，与 SD WebUI 保持一致
- 实测：修复后中文关键词"WordPress 插件"生成成功（37.91s），"technology"生成成功（16s），图片插入到正文正常（2 张图）

#### 功能测试验证（PHP 8.0.2 + WordPress 本地环境）

本轮在本地 phpStudy Pro + WordPress 环境下完成全量功能测试，所有核心功能通过验证：

| 测试项 | 结果 | 说明 |
|--------|------|------|
| AI 文本生成 | PASS | 推理模型检测正常，Token 统计正确 |
| Pexels 图片获取 | PASS | 成功获取图片，alt 属性正确 |
| Unsplash 图片获取 | PASS | 成功获取图片，alt 属性正确 |
| AI 图片生成（中文） | PASS | 修复超时后成功，120 秒足够 |
| AI 图片生成（英文） | PASS | 16 秒完成 |
| 图片插入到内容 | PASS | 成功插入图片，alt 属性正确 |
| Schema 输出 | PASS | FAQPage + WebSite + BreadcrumbList + Article 全部正确 |
| Canonical 输出 | PASS | 正确输出 canonical link |
| SEO 优化 | PASS | AI 正确生成 SEO 标题/描述/关键词 |
| 历史记录 | PASS | snapshot/save_staged/get_one/rollback 均正常 |
| 错误日志 | PASS | Logger 正常工作 |
| Token 统计 | PASS | 分模型统计正确（total_main/monthly_main） |
| API Key 加密 | PASS | AES-256-CBC 加密/解密正常 |
| 完整文章生成流程 | PASS | AI 生成→humanize→filter→图片插入→文章创建 |

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-generator.php` | 修改 | OpenAI/Gemini 图片 API 超时从 60 秒调整为 120 秒 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.4 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.4 更新日志 + 第十一轮审查记录 |

---

### v2.0.3

#### Bug 修复

**关键词分隔符归一化遗漏（全角逗号场景误判）— `class-meta-box.php`**
- 现象：`quick_seo_check()` 和 `ajax_suggest_links()` 两处仍用 `explode(',', ...)` 只认半角逗号。插件其他地方（`first_keyword()`、前端三个 JS 的评分逻辑）早已统一归一化 `，、；;｜|` 六种分隔符，唯独这两处漏改
- 后果：中文用户用全角逗号 `，` 填 SEO 关键词（中文场景极常见）时，这两个方法把整串 `kw1，kw2，kw3` 当作单个关键词：
  - `quick_seo_check`：`mb_stripos($title, 整串)` 必然失败 → 判定 not_pass → **触发不必要的 AI 重复优化，白耗 Token**，且与前端评分面板显示绿灯自相矛盾
  - `ajax_suggest_links`：用整串关键词搜索站内文章 → 搜不出匹配 → **内链建议为空**
- 修复：两处都改用 `WAISG_AI_API::first_keyword()`（已做分隔符归一化），与评分逻辑、auto_fix_seo、seo_fields_pass 全链路一致
- 实测：修复后全角逗号 `，`、顿号 `、`、分号 `；;`、竖线 `｜|` 六种分隔符全部正确取到第一个关键词

**前端 URL 协议校验遗漏（XSS 防御不一致）— `admin.js` + `batch.js` + `generator.js`**
- 现象：三个 JS 文件在把后端返回的 URL（permalink、edit_url、view_url）拼进 `href="..."` 时，只做了 HTML 转义，**没有校验 URL 协议**。对比 `settings.js` 的 `safeUrl()` 有 `/^https?:\/\//i` 白名单——这三个文件漏了
- 风险：后端返回的 URL 若被污染为 `javascript:...`（虽然当前 url 来自 `get_permalink()` / `get_edit_post_link()`，实际风险低），前端 `.html()` 渲染后点击可触发 XSS
- 修复：三处各加 `safeUrl()` 协议白名单函数（对齐 settings.js 的防护标准），非 http/https 协议的 URL 降级为 `#`

**前端状态文本未转义 — `batch.js` + `generator.js`**
- 现象：批量应用/保存成功后，`d.status`（draft/publish/future）经 `labels[d.status] || d.status` 后**未转义**直接进 `.html()` 拼接。当前是 enum 值无风险，但属防御缺口
- 修复：`d.status` 在 `.html()` 前经 `escHtml`/`esc` 转义

#### 优化改进

**清理 admin.js 冗余死代码**
- `loadLinkSuggestions` 的复制按钮回调里 `.replace(/&quot;/g, '"')` 是冗余操作——`$.attr()` 读取时已自动把 `&quot;` 解码回 `"`，这次 replace 永远匹配不到。清理掉

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-meta-box.php` | 修改 | `quick_seo_check` 和 `ajax_suggest_links` 改用 `WAISG_AI_API::first_keyword()` 统一关键词归一化 |
| `assets/js/admin.js` | 修改 | `loadLinkSuggestions` 加 URL 协议白名单；清理冗余 `.replace(/&quot;/g,'"')` 死代码 |
| `assets/js/batch.js` | 修改 | `esc()` 加 URL 协议白名单；`d.status` 输出前转义 |
| `assets/js/generator.js` | 修改 | 加 URL 协议白名单；`d.status` 输出前转义 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.3 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.3 更新日志 + 第十轮审查记录 |

---

### v2.0.2

#### Bug 修复

**题材表解析正则被裸换行打散 — `class-settings.php`**
- 现象 A：`parse_image_style_table` 的 `preg_split` 模式被裸换行字面量打散成 `'/(换行)|(换行)|(换行)/'`，原本 9 行的题材表被切成 25 段，后续 `foreach` 行首缩进检测错位——**题材表解析彻底错乱**，`image_style_hint` 永远拿不到题材类别，AI 配图匹配度退化到通用扁平插画（v1.9.9 题材分派功能失效）
- 现象 B：`sanitize_settings` 的 default 兜底 `preg_match` 模式同样被裸换行打散，`$dm[1]` 捕获的是整个 default 块含换行，拼回后结构错乱——用户自定义题材表且删了 default 行时，兜底补回的块格式破损，题材表解析失败回退内置默认（用户自定义失效）
- 根因：两处正则原本想用 `\r?\n` / `\r\n|\r|\n` 匹配换行，但写成了跨多行的裸换行字面量（PHP 源码里的 `\r\n` 字符），模式语义偏离。`php -l` 只查语法不查正则语义，裸换行字面量嵌入正则模式时语法仍合法（PHP 字符串允许跨行），但运行时命中行为错误——前八轮都用了 `php -l` + grep，未对这两处正则做运行时验证，漏过了这两个高优 bug
- 修复：`parse_image_style_table` 模式改显式 `'/\r\n|\r|\n/'`；`sanitize_settings` default 兜底模式改显式 `'/(default:\s*\r?\n  zh: .+\r?\n  en: .+)$/im'`，拼接用 `"\r\n" . $dm[1]`
- 实测：修复后 9 行题材表正确切成 9 段；真 WordPress 6.9.4 环境反射调用确认 15 块全解析（14 题材+default），`gaming.words` 31 个特征词全收录；AI 配图题材分派功能恢复

**控制字符剥除正则触发 NUL warning — `class-settings.php`**
- 现象：`sanitize_settings` 第 219 行 `preg_replace` 触发 `Warning: Null byte in regex`
- 根因：正则定界符用了双引号 `"..."`，PHP 双引号字符串里 `\x00` 被解释成真实 NUL 字节再进正则
- 修复：定界符改单引号 `'...'`，`\x00` 保留为字面量转义序列
- 实测：真 WordPress 环境反射调用 `sanitize_settings`，warning 消失

#### 运行时验证（真实 WordPress 6.9.4 + 推理模型环境，request_timeout=290s）

第九轮审查在 phpStudy 本地 WordPress（`http://www.wp.com`，WP 6.9.4，PHP 8.0.2 NTS，管理员 admin）实跑校验三条核心链路：

| 链路 | 耗时 | 结果 |
|------|------|------|
| AI 生成文章 | 32.1s | ✅ 标题/正文 HTML/SEO 字段全齐，`<h2>/<h3>/<ul>` 结构完整 |
| 批量优化（全量） | 20.5s AI + 11.5s humanize | ✅ JSON 解析 YES / recovered NO / `save_staged` id=4 |
| AI 图片 | 14.1s | ✅ 1 张图成功，URL `https://platform-outputs.agnes-ai.space/images/t2i/...` |
| 题材表解析（#60 修复点） | <1s | ✅ 15 块全解析 |
| sanitize_settings（#59/#61 修复点） | <1s | ✅ default 兜底 YES / warning 消失 |

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-settings.php` | 修改 | `sanitize_settings` default 兜底 `preg_match` 模式改显式 `\r?\n`；`parse_image_style_table` `preg_split` 模式改显式 `\r\n\|\r\|\n`；控制字符剥除正则定界符双引号→单引号 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.2 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.2 更新日志 + 第九轮审查记录 |

---

### v2.0.1

#### Bug 修复

**SEO 字段读/写路径不对称 — `class-meta-box.php`**
- 现象：元框读取 SEO 字段用 `get_seo_field_name()`（自动检测 Yoast/RankMath 等），但 `on_save_post` 和 `ajax_save_result` 用 `WAISG_Settings::get()`（只读用户配置）。用户未手动配置字段名时，元框能读出 SEO 插件的值，但保存时跳过不写入——用户以为改了但实际上没存
- 根因：读取和写入两条路径使用了不同的字段名解析策略，行为不一致
- 修复：`on_save_post` 写入前先读用户配置，为空时回退 `get_seo_field_name()` 自动检测，与读取路径对齐

**Gutenberg 保存后优化次数不递增 — `class-meta-box.php`**
- 现象：Gutenberg 块编辑器走 REST API 保存文章，`$_POST` 不含 `waisg_save_nonce` 和 `_waisg_ai_pending`，`on_save_post` 直接 return，优化次数不自增、SEO 字段不保存
- 根因：`on_save_post` 强依赖经典编辑器的 `$_POST` 表单字段，REST 路径不携带
- 修复：新增 `increment_opt_count()` 静态方法；`ajax_save_result`（Gutenberg 保存后调用的 AJAX 接口）保存 SEO 字段后调用此方法递增计数，确保 REST 路径不遗漏

**单字段优化缺少 JSON 解析和 recovered 透传 — `class-meta-box.php`**
- 现象：`ajax_optimize_single` 直接取 `$result['text']` 为纯文本，未调用 `parse_json_response` 解析 JSON；非 content 字段（title/seo_title 等）返回的 JSON 中的对应字段未正确提取；`_recovered` 标志未透传到前端
- 根因：单字段优化走的是纯文本路径（单字段 prompt 本身不输出 JSON），但 SEO 指示灯点击修复场景下 AI 可能返回 JSON 格式，未解析会导致字段值不正确
- 修复：非 content 字段尝试 `parse_json_response` 解析 JSON 并取对应字段值，失败回退纯文本；`wp_send_json_success` 补透传 `recovered` 字段

**`ajax_save_result` 的 `save_type='count'` 分支未实现 — `class-meta-box.php`**
- 现象：注释写 `// seo | count`，但方法体只处理 `seo`，`count` 落到 `wp_send_json_error('无效操作。')`
- 修复：补 `count` 分支，增量增加 `_waisg_opt_count`

**`ajax_get_opt_count` 缺 `edit_post` 权限检查（IDOR） — `class-meta-box.php`**
- 现象：只检查 `edit_posts`，无 `edit_post($post_id)` 验证，任何管理员可读取任意文章优化次数
- 修复：补 `current_user_can('edit_post', $post_id)` 检查

#### 优化改进

**`ajax_optimize_single` 非 content 字段尝试 JSON 解析**
- 单字段优化 prompt 通常返回纯文本，但 AI 可能输出 JSON 格式。非 content 字段（title/seo_title/seo_description/seo_keywords/excerpt）现在先尝试 `parse_json_response` 解析，提取对应字段值，失败回退纯文本

**死代码标记 `@deprecated`**
- `class-generator.php` 的 `extract_image_keyword()` 和 `translate_keyword_for_stock()` 已被 `extract_and_translate()` 取代，暂无调用方，标记 `@deprecated 3.0.0` 供后续版本删除

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-meta-box.php` | 修改 | `on_save_post` SEO 字段回退自动检测；新增 `increment_opt_count()` 静态方法；`ajax_save_result` 补 `count` 分支 + 调用计数递增；`ajax_optimize_single` 补 JSON 解析 + recovered 透传；`ajax_get_opt_count` 补 edit_post 权限 |
| `includes/class-generator.php` | 修改 | 死代码 `extract_image_keyword`/`translate_keyword_for_stock` 标记 `@deprecated` |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.1 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 版本号 + 补 v2.0.1 更新日志 + 第七轮审查记录 |

---

### v2.0.0

#### Bug 修复

**`class-settings.php` 正则里嵌入了真实 NUL 字节和控制字符**
- 现象：`includes/class-settings.php` 被编辑器/Git 工具判定为"二进制文件"无法正常读取。`sanitize_settings()` 方法第 219 行的 `preg_replace` 正则本意是匹配控制字符 `'/[\x00-\x08\x0b\x0c\x0e-\x1f]/'`（PHP 转义序列），但文件里写入的不是转义序列 `\x00`，而是真实的 NUL 字节（0x00）和控制字节（0x08/0x0b/0x0c/0x0e/0x1f）。五轮代码审查全部漏掉——`php -l` 只查语法不查字节完整性，控制字符嵌入源码时语法仍合法
- 根因：该正则用于净化"配图题材风格表"文本（剥控制字符）。某次编辑/保存过程中转义序列被解释成了真实字节写入文件，1 个 NUL + 5 个控制字符嵌入源码
- 修复：用 PHP 字节级操作精确定位偏移 11901，将真实控制字节替换为正确的 PHP 转义序列 `\x00-\x08\x0b\x0c\x0e-\x1f`。修复后 NUL=0，CTRL=0，文件恢复为纯文本 UTF-8
- 影响：文件可被所有编辑器/工具正常读取；正则语义恢复正确

**`class-batch.php` 批量优化错误消息未净化，XSS 风险**
- 现象：`ajax_batch_optimize_one()` 把 AI API 返回的 `WP_Error` message 直接塞进 JSON 返回前端，未经 `wp_strip_all_tags()` 净化。同文件其它位置（meta-box 的 4 处）都做了净化，唯独此处遗漏
- 根因：AI 上游错误页（如网关返回的 HTML 错误页）可能含 `<script>` 等标签，前端若用 `.html()` 渲染 message 会触发 XSS
- 修复：改为 `wp_strip_all_tags( $result->get_error_message() )`，与其它 AJAX 处理器一致

**`class-batch.php` 批量「仅优化 SEO」模式忽略用户选择的模型**
- 现象：用户在批量优化页面选择「使用主模型」做「仅优化 SEO」时无效——只要配了轻量模型就强制走轻量模型
- 根因：SEO-only 分支只判断 `$lm`（轻量模型名）是否非空，完全无视 `$use_model`（用户前端选择或全局 `batch_model` 设置）。全量分支反而正确使用了 `$use_model === 'lightweight'`，两个分支逻辑不一致
- 修复：删除 SEO-only 与全量的分支差异，统一用三元运算选 `$extra`，然后统一走 `$use_model === 'lightweight'` 的模型选择逻辑。SEO-only 和全量现在行为一致，与 UI 承诺对齐

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-settings.php` | 修改 | 第 219 行正则里真实 NUL 字节和控制字符替换为 PHP 转义序列 `\x00-\x08\x0b\x0c\x0e-\x1f`；文件恢复为纯文本 UTF-8 |
| `includes/class-batch.php` | 修改 | 第 243 行错误消息加 `wp_strip_all_tags()` 防 XSS；SEO-only 与全量模型选择逻辑统一，修复 SEO-only 无视 `$use_model` 的 bug |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 2.0.0 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 顶部版本号 + 补 v2.0.0 更新日志 + 补第六轮审查记录 |

---

### v1.9.9

#### Bug 修复

**配图搜词翻译 max_tokens 写死 200 致推理模型返空触发重试**
- 现象：AI 错误日志出现两条紧邻记录——`推理重试 模型 deepseek-v4-flash 推理耗尽 max_tokens=200，放大到 400 重试` + `AI 返回空内容 AI 返回内容为空（模型 deepseek-v4-flash，策略 standard，finish_reason：length）`。配图时把节级中文标题翻译成英文搜图词这一步，`extract_and_translate` / `translate_keyword_for_stock` 两处把 `max_tokens` 写死成 200。推理模型（如 deepseek-v4-flash）的"思考 token"也计入此配额，200 全被思考吃光没留给最终答案，`finish_reason=length` 返空；兜底重试把 200 翻倍到 400 仍不够，再返空——用户每次配图都要在日志里看到这两条报错，且搜图词回退到原中文，Pexels/Unsplash 搜不出对图
- 根因：旧版假设"翻译输出很短 200 足矣"，没考虑推理模型的思考开销。这是配图专用的一条短链路（不走主 `call()` 入口的推理预算叠加逻辑），写死值无法覆盖推理模型场景
- 修复：基本设置 → 一、AI 大模型接口配置新增「配图搜词 Tokens」设置项（`image_keyword_max_tokens`，默认 200，范围 100~32000）。`extract_and_translate` / `translate_keyword_for_stock` 两处改读设置值替代硬编码。用户用推理模型配图时把这个值调到 800+ 即可一次成功，避免返空+重试两轮空耗；普通模型保持 200 不变

#### 优化改进

**AI 生成图按题材分派视觉风格指令**
- 现象：用户反馈"搜出来的图片，包括 AI 生成的图片都不行，匹配度不高"。AI 生成图的 prompt 是笼统的"一张与以下主题相关的高质量插画：XXX"，对题材类型、视觉风格描述不足——无论文章是游戏攻略还是美食教程还是科技测评，模型都产出偏抽象的通用扁平插画，与文章主题贴合度差
- 修复：`fetch_ai_images` 调用前先经新增的 `image_style_hint( $keyword )` 按关键词分派题材类别，每类给具体的视觉风格指令（视角/元素/画风/配色），替代笼统的"插画"。题材识别用关键词特征词匹配分派（零 API 调用，比让 AI 再识别题材更省），枚举 9 个常用题材：游戏 / 科技数码 / 美食 / 旅游风景 / 健身运动 / 宠物 / 商业职场 / 教育 / 人物肖像，未命中的回退通用扁平插画风（含"避免具体人脸或品牌 Logo"安全指令）。每题材的中英双版风格指令硬编码在方法里，例如游戏类要"体现游戏氛围，可含游戏画面截图/电竞装备/角色立绘/UI 界面，画风鲜明张力，配色饱和度高"，美食类要"突出食物色泽与质感，暖光俯拍或特写，画风诱人配色暖"——让模型产出贴合主题具体场景的图，而非通用抽象插画

**Pexels / Unsplash 搜图加横向过滤**
- 现象：搜图返回竖图和正方图混在结果里，文章配图一般用横图，竖图插入后排版难看，正方图也不适配正文宽度
- 修复：`fetch_pexels` / `fetch_unsplash` 搜图请求加 `orientation=landscape` 参数，Pexels 和 Unsplash API 都原生支持该过滤。命中后只返回横图，可用度提升

**AI 生成图接口多协议自动适配**
- 现象：用户反馈"AI 大模型生成图片（自定义接口）这个是不是很多接口不支持"——原版只认 OpenAI 的 `/v1/images/generations` 格式（固定 `{model, prompt, n, size}` body + Bearer 鉴权 + `data[].url` 解析），填了 Google Gemini Imagen 或本地 Stable Diffusion WebUI 的地址也走不通，要么报错要么拿不到图。而文章大模型的 `WAISG_AI_API::call()` 早已支持 OpenAI 兼容 / Anthropic / Gemini / Responses 四种协议自动识别——图片接口该有同样的能力
- 修复：参考文章大模型的 `detect_protocol` 思路，新增 `detect_image_protocol( $api_url )` 按 API 地址特征自动识别图片协议，`fetch_ai_images` 拆成三分支各自构造请求体和解析响应：
  - **OpenAI 兼容**（默认，含 OpenAI dall-e-3 / 阿里通义万相 / 智谱 / 国产中转等）：填 `.../v1/images/generations`，Bearer 鉴权，解析 `data[].url`（兼容部分中转平台把图放 `b64_json` 字段，自动补 `data:image/png;base64,` 前缀）
  - **Google Gemini Imagen**：填 `.../models/imagen-3.0:predict`（地址含 `googleapis`），URL Query `?key=` 鉴权，body `{instances:[{prompt}], parameters:{sampleCount}}`，解析 `predictions[].bytesBase64Encoded` 自动补 MIME 前缀
  - **Stable Diffusion WebUI**（AUTOMATIC1111）：填 `.../sdapi/v1/txt2img`，可选 Basic Auth（Key 填 `user:pass`，无鉴权留空），body `{prompt, batch_size, width, height, steps, cfg_scale, sampler_name}`，解析 `images[]` base64 数组自动补前缀；timeout 放宽到 120s（SD 本地生图比 dall-e 慢）
- 老用户无感升级：UI 字段不变（API 地址/Key/模型/尺寸都不动），填 OpenAI 格式地址仍走默认分支；新用户填不同平台地址自动适配
- 不覆盖的：Anthropic Claude 原生不支持图片生成（Claude 是文本模型），故无此分支；Replicate / fal.ai 等小众平台走各自 REST 格式，如需支持后续可加 `custom` 分支（留扩展点）

**AI 生图配图匹配度根治 + 节级图 alt 英文 bug 修**
- 现象：① 用户反馈"AI 生成的图和内容都不太匹配"——中文《和平精英》游戏攻略文章，节标题"矿场南侧山腰房"配出来的图是个山腰房子的实景照片风，与游戏攻略主题完全不搭；② 第 2+ 张图 `<img alt>` 是英文 `mine hillside house`，但文章是中文——alt 应该是中文节标题
- 根因：① `insert_images_into_content` 不区分图源——**同一套翻译后简洁英文搜图词喂给 AI 生图和实景库**。但这两者要的 prompt 完全不同：Pexels/Unsplash 实景库要简洁名词（`mine hillside house`）才搜得到；AI 生图要具体场景描述（`矿场南侧山腰房`）才画得贴合。`extract_and_translate` 产出的简洁英文词喂给 AI 生图，主题具体场景丢失，画出来的图不贴合。② 节级图 alt 用了翻译后 `$section_kw`（英文搜图词），应该用原始中文节标题 `$raw_title`
- 修复：
  - **区分图源**（配图匹配度根治）：`insert_images_into_content` 入口读 `image_source` 设置，AI 生图分支用**原始中文节标题**直接做 prompt（AI 生图模型懂多语言，中文 prompt 直接送进去比先翻译成简洁名词再送更贴合主题具体场景）；Pexels/Unsplash 分支才调 `extract_and_translate` 产简洁英文搜图词（实景库要简洁名词才搜得到）。第 1 张总图和第 2+ 张节级图都按此逻辑区分
  - **题材识别用总关键词透传**：节标题"矿场南侧山腰房"本身不含题材特征词，直接 `image_style_hint` 会误回退通用扁平插画风。改用文章总关键词"和平精英"在 `insert_images_into_content` 入口识别题材一次，透传给各张图——保证游戏攻略的节级图也走 gaming 风格指令（"游戏画面截图/电竞装备/UI 界面，画风鲜明张力"），而非通用扁平插画。`image_style_hint` / `fetch_ai_images` / `fetch_images` 三方法签名加 `$category_override` 参数透传，`image_style_hint` 返回值加 `'category'` 键供调用方拿类别
  - **alt 用原始中文**（bug 修）：第 2+ 张图 `esc_attr( $section_kw )` 改 `esc_attr( $raw_title )`——alt 用节级原始中文标题，不用翻译后英文搜图词。第 1 张图 `esc_attr( $keyword )` 原本就是对的，不动
- 配图匹配度对比：
  | 项 | 旧行为 | 新行为 |
  |----|--------|--------|
  | AI 生图 prompt 主关键词 | `mine hillside house`（翻译后简洁英文） | `矿场南侧山腰房`（原始中文节标题） |
  | AI 生图题材风格指令 | 节标题不含题材词误回退通用扁平插画 | 总关键词识别 gaming 类透传，走"游戏画面截图风格" |
  | 节级图 alt | `mine hillside house`（英文搜图词） | `矿场南侧山腰房`（原始中文节标题） |

**配图题材风格表面板化（不再改代码即可增删题材）**
- 现象：用户反馈"希望所有的东西都能直接在面板上改，而不是总去改代码"——`image_style_hint` 的题材表和风格指令硬编码在 PHP 里，每加一个题材或调一句风格指令都要改代码，不合理
- 修复：题材表从硬编码迁到后台设置项 `image_style_table`，做成面板可编辑的大文本框。基本设置 → 图片配置区新增「配图题材风格表」卡片，形态与已有的「AI 高频词替换词库」一致——大文本框 + 格式约定 + "查看/恢复内置默认"折叠面板：
  - 格式（YAML 风格多行，每条题材 4 行）：`题材名:` 无缩进一行 + `  zh: 中文风格` / `  en: 英文风格` / `  words: 特征词逗号分隔` 三行（缩进**必须2空格**，不是 Tab 不是1空格）
  - **特征词中英双版都放**（如 `游戏,电竞,吃鸡,game,gaming,pubg`）——按文章总关键词命中识别题材，中英文章都能识别
  - **风格指令中英双版**——按文章输出语言选：中文文章用 zh 字段中文风格，英文/其他语言用 en 字段英文风格
  - `default` 行为通用兜底（没命中任何题材时用），sanitize 时强制保留——用户删了保存会自动补回内置默认兜底
  - 题材表里题材的**排列顺序就是命中优先级**（排在前面的先匹配），用户可在面板上调整顺序控制命中优先级
  - 留空整框 = 使用内置默认题材表
  - 旧版单行竖线格式 `题材名|中文|英文|特征词` 已废，改 YAML 多行——字段边界一目了然，编辑某字段只动那行不用整行重写
- 内置默认题材表从 9 套扩到 **14 套 + default 兜底**，新增 5 套：
  - `anime`（动漫/漫画/动画）：扁平日系动画风/赛璐璐风格，色彩明快，可含角色立绘/番剧截图/漫画分镜
  - `movie`（电影/影视）：电影剧照风，宽银幕构图，光影戏剧化，可含角色剧照/场景截图/海报风
  - `blockchain`（区块链/加密/Web3）：抽象数字科技风，可含链式结构/节点网络/加密符号/币图腾
  - `health`（医疗健康）：可含医疗器械/医院场景/健康图标，画风专业可信，配色冷净
  - `auto`（汽车）：突出车型线条，干净背景产品摄影风或道路场景体现驾驶感
- 改动文件：`class-settings.php` 加 `image_style_table` 字段净化 + `get_default_image_style_table()` / `get_default_image_style_table_text()` / `parse_image_style_table()` 三方法；`class-generator.php` `image_style_hint` 从硬编码改成读设置解析；`settings.php` 加题材表卡片 UI（含查看/恢复默认折叠面板）

#### 已知局限（非 bug，图片库本身限制）

- **Pexels/Unsplash 是实景照片库**，游戏/动漫/科技截图极少——即使搜图词准了，搜出来的也是沾边实景图（如"吃鸡游戏"搜出战场/持枪实景，搜不出真游戏截图）。这类题材建议用 AI 大模型生成图（自定义接口），按 prompt 直接生成贴合内容的图
- 题材识别用关键词匹配，对含蓄/隐喻的标题可能命中不了（如"吃鸡"已特别处理，但其他黑话未必）——命中不了时回退通用扁平插画风，不会报错

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-settings.php` | 修改 | `sanitize_settings` 新增 `image_keyword_max_tokens` 字段校验（100~32000，默认 200） |
| `admin/views/settings.php` | 修改 | API 配置区新增「配图搜词 Tokens」输入框 + 说明文案（推理模型建议 800+） |
| `includes/class-generator.php` | 修改 | `extract_and_translate` / `translate_keyword_for_stock` 两处 `max_tokens` 改读设置值替代硬编码 200；新增 `image_style_hint` 题材分派方法（9 题材+通用兜底，中英双版风格指令，返回值带 `category` 键，接受 `$category_override` 透传）；`insert_images_into_content` 区分图源——AI 生图用原始中文节标题直接做 prompt + 入口识别题材透传各张图，实景库才调 `extract_and_translate`；节级图 alt 改用原始中文 `$raw_title` 替代翻译后 `$section_kw`；`fetch_ai_images`/`fetch_images` 加 `$category_override` 参数透传；`fetch_ai_images` prompt 改用 `image_style_hint` 产出 + 多协议自动适配（新增 `detect_image_protocol` + `fetch_gemini_image` + `fetch_sdwebui_image` 三分支，OpenAI 分支兼容 `b64_json`）；`fetch_pexels`/`fetch_unsplash` 加 `orientation=landscape` 横向过滤 |
| `admin/views/settings.php` | 修改 | API 配置区新增「配图搜词 Tokens」输入框 + 说明文案（推理模型建议 800+）；图片生成 API 地址说明文案补多协议适配告知（OpenAI/Gemini Imagen/SD WebUI 三种填法） |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.9 |
| `README.md` + `CODE_REVIEW.md` | 修改 | 顶部版本号 + 补 v1.9.9 更新日志 + 补第五轮（v1.9.9 追加）审查记录 |

---

### v1.9.8

#### 优化改进

**配图节级关键词匹配**
- 现象：原设计全文共用一个总关键词搜/生成所有配图——文章有多个 `<h2>`/`<h3>` 小节，每节主题不同，但配的图都按同一个总关键词，节级匹配度差
- 修复：`insert_images_into_content` 遍历各 `<h2>`/`<h3>` 取**标题文本**作节级关键词——第 1 张图仍用总关键词（文章开头配总图统领全文），第 2+ 张图按所在小节标题独立搜/生成；节级搜图失败静默跳过该节不阻断；节标题用尽回退总关键词。`$inserted`（已插入张数）和 `$section_idx`（节标题索引）两值独立，避免节级失败时取错位标题
- 不限张数：按 `images_per_post`（1~10）配到目标张数为止

**AI 生成图 prompt 中英适配**
- 现象：prompt 是英文模板 + 中文关键词拼接（`A high-quality illustration related to: 狗训练`），中英混杂时模型对中文关键词理解精度下降
- 修复：`fetch_ai_images` 加 `$language` 参数透传，按文章语言切换 prompt 模板——中文文章用中文 prompt（"一张与以下主题相关的高质量插画：..."），英文文章用英文 prompt，其他语言回退英文模板。`$language` 来自文章生成流程的 `$_POST['language']`（默认 `zh-CN`），透传链路：生成入口 → `insert_images_into_content` → `fetch_images` → `fetch_ai_images`

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-generator.php` | 修改 | `insert_images_into_content` 加 `$language` 参数 + 实现节级关键词（取各 `<h2>/<h3>` 标题，第 1 张总图，第 2+ 张节级标题，失败静默跳过，`$inserted`/`$section_idx` 独立避免错位）；`fetch_images`/`fetch_ai_images` 加 `$language` 参数透传 + 按语言切换 prompt 模板；去掉诊断日志 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.8 |

---

### v1.9.7

#### 优化改进

**Pexels / Unsplash 拆成独立 API Key 字段**
- 现象：Pexels 和 Unsplash 共用同一个「图片 API Key」输入框——切换来源时不清空，测试时也分不清，容易混淆
- 修复：拆成两个独立字段 `image_api_key_pexels` / `image_api_key_unsplash`，各自独立存储和测试；下拉切换时显示对应那行；旧 `image_api_key` 兼容迁移（按当前 `image_source` 分发到对应新字段，一次性）；`get()` 透明解密白名单加入两个新字段
- 视图：两个独立行 `waisg-img-row-apikey-pexels`（默认显示）/ `waisg-img-row-apikey-unsplash`（默认隐藏），各自的眼睛按钮 + hint 文案；`waisgToggleImageFields()` 改为按选中来源切显示对应行
- JS：测试搜图按当前 `image_source` 取对应框的 Key 发送
- `fetch_images` 按 `source` 取对应字段 Key，新字段空时回退旧 `image_api_key`（迁移前数据兼容）

**说明：Pexels/Unsplash 的 Key 主要用于限速和合规**
- Pexels API 的 Key 不是强鉴权门——假 Key 甚至空 Key 也能拿到图片（走匿名/公开配额池），但违反 Pexels 服务条款，随时可能被封 IP
- 真实 Key 独享 200/小时配额，稳定不受他人影响；匿名池是全网共享的 25000/小时，高峰期可能被挤爆返 429
- 测试搜图按钮的语义是**连通性测试**（能拿到图 = 链路通 = 正常），不校验 Key 归属——这和大模型测试按钮的语义一致

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `admin/views/settings.php` | 修改 | 拆 Pexels/Unsplash 为两个独立 Key 字段行；`waisgToggleImageFields()` 改按来源切显示对应行 |
| `assets/js/settings.js` | 修改 | 测试搜图按当前来源取对应框的 Key 发送 |
| `includes/class-settings.php` | 修改 | `sanitize_settings` 新增 `image_api_key_pexels`/`_unsplash` 加密落库 + 旧字段兼容迁移；`get()` 解密白名单加入两个新字段 |
| `includes/class-generator.php` | 修改 | `fetch_images` 按 `source` 取对应字段 Key，回退兼容旧 `image_api_key` |
| `wp-ai-seo-geo.php` + `README.md` | 修改 | 版本号升至 1.9.7 |

---

### v1.9.6

#### Bug 修复

**图片测试必须先保存才能测**
- 现象：大模型测试用表单即时值（填了就能测），图片测试却走数据库——必须先点保存设置才能测，与用户体验预期不符
- 修复：JS 测试搜图按钮发送时带上 `image_source`/`image_api_key`/`image_ai_url`/`image_ai_key`/`image_ai_model`/`image_ai_size` 6 个表单即时值；后端 `ajax_test_image_api` 接收并组 override 数组传给 `fetch_images`；`fetch_images`/`fetch_ai_images` 加 `$override` 参数，优先用 override 回退数据库。与大模型测试行为完全一致——填了就能测，测好再决定是否保存

**图片测试假 Key 也显示"正常"**
- 现象：随便填假 Key 点测试也显示"✅ 图片 API 正常"，测试根本没真正校验 API 响应
- 根因：`fetch_pexels`/`fetch_unsplash`/`fetch_ai_images` 在 API 返回 401/403/500 时**静默返回空数组**；`ajax_test_image_api` 只判 `empty($images)`——把"API 拒绝"和"真没搜到"混为一谈，假 Key 的 401 被当成"没搜到"看起来像"正常"
- 修复：三个 `fetch_*` 方法返回 `array|WP_Error`：HTTP 非 2xx/网络失败/格式异常时返 `WP_Error` 并带真实错误信息（含 HTTP 状态码 + API 返回的 error 字段 + Key 无效提示）；`ajax_test_image_api` 和 `ajax_fetch_images` 透传 `WP_Error` 给前端；3 处 `fetch_images` 调用方全部兼容新返回类型

**图片尺寸硬限 OpenAI dall-e-3 的 3 个尺寸**
- 现象：用其他平台（如某国产接口支持 `1664x2496` / `2048x2048` 等）报错 `Size invalid`
- 根因：`sanitize_settings` 把 `image_ai_size` 硬编码白名单成 `1024x1024` / `1792x1024` / `1024x1792`，视图下拉也只有这 3 个，填其他尺寸会被强制改回 `1024x1024`
- 修复：视图下拉改自由文本输入框，可填任意 `宽x高`；`sanitize_settings` 去白名单，改 `preg_match` 格式校验 `\d{2,5}x\d{2,5}`，无效才回退 `1024x1024`

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-generator.php` | 修改 | `fetch_images`/`fetch_ai_images` 加 `$override` 参数；`fetch_pexels`/`fetch_unsplash`/`fetch_ai_images` 返回 `array\|WP_Error` 透传真实错误；`insert_images_into_content` 兼容 `WP_Error` 静默跳过；`ajax_fetch_images` 透传错误 |
| `includes/class-settings.php` | 修改 | `ajax_test_image_api` 接收表单即时值组 override + 透传 `WP_Error`；`image_ai_size` 去硬编码白名单改格式校验 |
| `admin/views/settings.php` | 修改 | `image_ai_size` 从 `<select>` 3 选项改 `<input type="text">` 自由填写 |
| `assets/js/settings.js` | 修改 | 测试搜图按钮发送带上 6 个表单即时值 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.6（强制浏览器拉新 JS） |

---

### v1.9.5

#### 安全强化

**API Key 加密存储**
- 现象：API Key / 图片 API Key / 图片生成 API Key 三个密钥字段以明文存于 `wp_options`，数据库被脱库、备份泄露、误传日志都会暴露密钥
- 修复：新增 `WAISG_Settings::encrypt_secret` / `decrypt_secret`，采用 `openssl` AES-256-CBC 加密落库；密钥取自 `wp-config.php` 的 `AUTH_KEY`（WordPress 装好即存在），未定义时用 `home_url() + ABSPATH` 站点特定值兜底（避免同主机所有站点共用密钥）；加密产物格式 `waisg_enc::base64(iv + ciphertext)`，每次加密 IV 随机
- 兼容降级：`openssl` 扩展不可用时透明退回明文（不阻断功能，与旧行为一致）；加密失败也退回明文
- 透明迁移：`sanitize_settings` 落库前统一加密（表单提交的总是明文，视图 `input value` 已透明解密填入），老用户第一次保存设置即完成迁移；读路径 `get()` 遇旧明文也透明解密兼容，功能不受影响
- 设置页 3 处 `<input value>` 改调 `WAISG_Settings::get()` 显示明文，避免渲染出乱码 `waisg_enc::xxx`

#### 优化改进

**统一错误日志到 WAISG_Logger**
- 现象：`error_log` 直接调用与自家的 `WAISG_Logger` 并存，两套日志——`error_log` 写到 PHP/server error log（站长看不到、易被轮转冲掉），`WAISG_Logger` 写到自建表（后台有 UI、可导出 CSV），用户无法在一处看全所有错误
- 修复：① `class-ai-api.php` 的 AI 返回空内容错误从 `error_log` 改调 `WAISG_Logger::log(0, 'empty_response', ...)`，新增 `empty_response` 场景代码；② `class-batch.php` / `class-generator.php` 共 3 处与 `WAISG_Logger::log()` 重复记录的 `error_log` 删除（此前同一 `if` 块内既写 PHP error log 又写 WAISG_Logger，冗余）；③ `class-ai-api.php` `run_strategies` 中"命中兼容策略"属成功路径上的信息日志，无受众（站长看不到 server error log，开发者会看 WAISG_Logger 而非 server log），删除
- 至此 `includes/` 下 `error_log` 全清零，所有 AI 出错信息统一到后台「AI 错误日志」页一处可查

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-settings.php` | 修改 | 新增 `encrypt_secret`/`decrypt_secret`/`is_encrypted`；`sanitize_settings` 落库前加密三密钥字段；`get()` 透明解密（只读不写库，迁移交给保存路径） |
| `includes/class-logger.php` | 修改 | `$context_labels` 新增 `empty_response => 'AI 返回空内容'` |
| `includes/class-ai-api.php` | 修改 | `error_log` 改调 `WAISG_Logger::log`；删除 `run_strategies` 成功路径信息日志 |
| `includes/class-batch.php` | 修改 | 删除与 `WAISG_Logger::log` 重复的 `error_log` |
| `includes/class-generator.php` | 修改 | 删除 2 处与 `WAISG_Logger::log` 重复的 `error_log` |
| `admin/views/settings.php` | 修改 | 3 处密钥字段 `<input value>` 改调 `WAISG_Settings::get()` 显示明文 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.5（强制浏览器拉新 JS/CSS） |
| `CODE_REVIEW.md` | 修改 | 第 5 节标注两项已处置；新增第五轮复核记录 |

---

### v1.9.4

#### Bug 修复

**批量优化进度条显示与最终统计矛盾**
- 现象：20 篇优化成功 18、失败 2，进度条却显示"已完成 20/20"，与日志"成功 18、失败 2"矛盾，用户误以为全部成功
- 根因：收尾分支用 `totalCount`（队列总数）而非实际完成数写进度条；旧兜底 `doneN = doneCount || (successCount + failCount)` 在 `doneCount` 为正整数时永远走不到右边（JS 的 `||` 只在 0/null/undefined 时兜底），等于直接用 `doneCount`，而并发竞态下 `settle → processNext` 读到的 `doneCount` 可能尚未更新到最后一篇
- 修复：收尾分支改为 `doneN = successCount + failCount` 显式计算，不依赖 `doneCount` 时序；进度百分比按 `doneN / totalCount` 实算，手动停止时不再虚报 100%；进度文案统一为"已完成 N / 总（✅ 成功　❌ 失败）"，与日志一致

**批量应用后单篇不能再次应用**
- 现象：批量优化后点"批量应用"成功，但再去点单篇"应用到文章"按钮时报"此记录已保存，无需重复操作"
- 根因链：① 后端 `waisg_ajax_save_staged_to_wp` 入口把 `applied`/`saved` 一律拒掉；② 更新分支入口 `if (entry_type === 'optimized')` 不接受 `applied`，即使放行也走不到更新逻辑；③ 前端批量应用成功后把勾选框 `disabled`，单篇按钮拿到的 history_id 已是 `applied`，再点触发后端拒绝
- 修复：
  - 后端入口白名单改为 `{generated, optimized, applied}`，允许 applied 再次应用；`saved`（generated 已新建文章）单独拦截避免重复新建；更新分支入口改为 `in_array(entry_type, array('optimized', 'applied'))`，让 applied 走更新同一篇文章的逻辑
  - 前端单篇应用 + 批量应用两处：应用成功后只清空当前勾选，不再 `disabled` 勾选框，允许再次勾选/单篇再次应用
- 行为对照：generated（AI 生成）保存后再应用仍拒绝（避免重复新建文章）；optimized/applied 可反复应用（更新同一篇文章）

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-history.php` | 修改 | `waisg_ajax_save_staged_to_wp` 入口白名单放行 applied；更新分支入口接受 applied；`saved` 单独拦截 |
| `assets/js/batch.js` | 修改 | 收尾分支进度条改 `doneN = successCount + failCount` 显式计算 + 实算百分比；单篇/批量应用成功后不再禁用勾选框 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.4（强制浏览器拉新 JS） |

---

### v1.9.3

#### Bug 修复

**Token 分模型月度统计错乱**
- 现象：设置页 Token 统计区"本月主模型/轻量模型"分项数据严重不准，只能信最后一个调用模型的数
- 根因：`record_tokens()` 用单一标量 `stats['model']` 只记最近一次调用模型，主/轻量在同月交替调用时 `monthly_main` 与 `monthly_lightweight` 反复覆盖丢失，而非各自累加
- 修复：改两套独立字段 `monthly_main`/`monthly_lightweight` 各自累加，配 `month_main`/`month_lightweight` 记各自归零月份；删 `stats['model']` 标量；`ajax_get_token_stats` 同步读新字段
- 迁移注意：首次升级后本月分项计数从 0 重新累加，建议升级后在设置页点一次"重置统计"清旧

**暂存应用时 SEO 字段写错 meta key**
- 现象：用户在「基本设置」配置了非主流 SEO 插件字段名时，从"待处理"保存到 WP 时 SEO 字段写不到 SEO 插件能读到的位置
- 根因：`save_staged_to_wp` 两个分支用 `get_seo_field_name()`（自动检测）而非 `WAISG_Settings::get('seo_*_field')`（用户配置），与 `ajax_save_result`/`on_save_post` 行为不一致
- 修复：两个分支改读用户配置 key，未配置的字段跳过；`rollback()` 同步改用用户配置 key
- 迁移注意：已保存的字段不会自动迁移，需手动重新应用一次

**rollback 未验证记录类型**
- 根因：`rollback()` 不检查 `entry_type === 'backup'`，传入 generated/optimized 类型的 history_id 会把暂存内容当作历史版本覆盖到文章上，`$snap->post_id` 为 0 时 `wp_update_post(['ID'=>0])` 行为未定义
- 修复：入口加 `entry_type === 'backup'` 和 `post_id > 0` 检查，非 backup 记录无法回滚

**save_staged_to_wp 未断言 post_id 状态**
- 根因：已有 entry_type 白名单，但未断言 generated 的 post_id 是否为 0、optimized 的 post_id 是否大于 0，完全信任数据库状态
- 修复：generated 分支断言 `post_id === 0`，optimized 分支断言 `post_id > 0`，数据库被外部改坏时报错中止

**protect_images 嵌套标签被截断**
- 现象：嵌套 `<figure><figure>..</figure></figure>` 或 `<object><embed></object>` 的外层被拆开，AI 看到碎片化 HTML，丢失嵌入内容
- 根因：正则 `.*?</figure>` 配合 `s` 修饰符非贪婪匹配到第一个结束符，外层被截断
- 修复：改用 DOMDocument 解析（与 `extract_faqs` 一致），先收集所有目标节点再逐个用注释节点替换，遍历时跳过已被祖先节点包含的子孙避免重复占位；DOMDocument 不可用时退回正则兜底

**estimate_max_tokens 中文 token 比例不适用英文**
- 现象：英文/多语言站长文生成时 max_tokens 被严重高估（浪费）或日韩文被低估（截断）
- 根因：旧公式 `ceil(mb_strlen/1.5)` 只对中文准确（1 token/1.5 字符），英文约 1 token/4 字符
- 修复：统计 UTF-8 多字节字符数 ≈ 非 ASCII（CJK/日韩），单字节字符数 ≈ ASCII（英文/符号），中文部分 token = 非 ASCII/1.5，英文部分 token = ASCII/4，两者之和为 base，再留 50% 扩写余量 + 1500 开销

**批量「仅优化 SEO」关键词为空浪费 AI 调用**
- 现象：文章 SEO 关键词为空但其他字段达标时，触发一次 AI 调用，AI 大概率返回原样，然后 PHP 从标题抽前 10 字补关键词——浪费一次 AI 调用做 PHP 本就能做的事
- 修复：批量 SEO-only 预检前，关键词为空且标题非空时先用 PHP 从标题抽前 10 字补上（与 `auto_fix_seo` 一致），再判 `seo_fields_pass`

**filter_ai_phrases 破坏代码示例和链接**
- 现象：`<code>`/`<pre>` 块或 `<a href="...">` URL 里恰好含 AI 高频词（如"此外，""十分"）时被 `str_replace` 替换，破坏代码示例和链接
- 修复：加保护块机制：`<code>/<pre>/<kbd>/<samp>/<a>` 内容临时替换为占位符 `<!--WAISG_PROT_N-->`，替换完再还原

**humanize 长文分段缺 curl_multi 防护**
- 现象：启用 humanize + 长文（>3000 字符）时，受限主机禁用 `curl_multi_exec` 会 fatal error 白屏
- 修复：`humanize_chunked` 入口加 `function_exists('curl_multi_init')` 防护，不可用时降级为 `humanize_single`（串行慢但可用）

**Cron 可能撞主机时限**
- 现象：跑多篇文章 + humanize 耗时较长，部分主机默认 30/60 秒 cron 限制会中途中断；`sleep(2)` 在部分 SAPI 下会被 `max_execution_time` 中断
- 修复：`run()` 入口加 `set_time_limit(0)`（部分主机禁用该函数时 `@` 静默失败）；`sleep(2)` 改 `usleep(2000000)`（部分主机 `max_execution_time` 会中断 `sleep` 但不计 `usleep`）

**update_reasoning_model transient 锁不可靠**
- 根因：用 `set_transient` 做互斥锁，但裸主机（无对象缓存）transient 存 option 表，`set_transient` 内部 `update_option` 非原子，两个进程同时 set 都可能"成功"，锁失效
- 修复：去掉 transient 锁，直接 read-modify-write；`update_option` 整字段覆盖最后写入者赢——推理名单只增不删，并发丢失最多漏一个"增"，下次测试会补回。可接受风险

#### 优化改进

**parse_json_response 截断抢救加 `_recovered` 标志**
- 截断抢救和宽容提取成功时在返回数据里加 `_recovered => 'truncated'/'loose'` 标志，上层可据此在前端高亮提示用户核对半残正文，或不直接自动应用
- 前端兼容性：各调用方用 `$data['title'] ?? ''` 取值不受影响；如前端 JS 遍历全字段显示，可能看到 `_recovered` 字段，建议测试时确认前端表现

**主入口类加载改为显式 require_once 列表**
- 旧版用 `glob` 自动加载 `class-*.php`，顺序依赖文件系统（一般按字母序但不保证）
- 改为显式 `require_once` 列出 10 个类文件，加载顺序可控（History 前置因 `maybe_migrate` 先调；Settings 次之因其余类构造常调 `WAISG_Settings::get`）

**文章列表 AI 状态列徽标内联 style 迁入 admin.css**
- `class-post-list.php` 的 AI 生成徽标 / 优化次数 / 空占位 3 处内联 style 迁入 `admin.css`，改用 `.waisg-list-badge` / `.waisg-list-opt-count` / `.waisg-list-empty` class

#### 死代码清理
- 删除零调用的 `str_ends_with_compat`（v1.7.0 死代码清理漏网）

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-settings.php` | 修改 | `record_tokens` 分模型月度统计改造；`ajax_get_token_stats` 同步；`update_reasoning_model` 去 transient 锁改 CAS |
| `includes/class-history.php` | 修改 | `save_staged_to_wp` SEO 字段改用户配置 + post_id 断言；`rollback` 验 entry_type + SEO 字段改用户配置 |
| `includes/class-ai-api.php` | 修改 | `protect_images` 改 DOMDocument；`estimate_max_tokens` 按 ASCII 加权；`filter_ai_phrases` 加保护块；`parse_json_response` 加 `_recovered`；`humanize_chunked` 加 curl 防护；删 `str_ends_with_compat` |
| `includes/class-batch.php` | 修改 | 批量 SEO-only 关键词预检前 PHP 补全 |
| `includes/class-cron.php` | 修改 | 入口 `set_time_limit(0)`；`sleep` 改 `usleep` |
| `includes/class-post-list.php` | 修改 | AI 状态列徽标内联 style 改 CSS class |
| `assets/css/admin.css` | 修改 | 新增 `.waisg-list-badge` / `.waisg-list-opt-count` / `.waisg-list-empty`（收纳内联样式） |
| `uninstall.php` | 修改 | transient 清理说明调整（#13 改造后已无 transient） |
| `wp-ai-seo-geo.php` | 修改 | 类加载改显式 `require_once`；版本号升至 1.9.3 |
| `CODE_REVIEW.md` | 新增 | 代码审查说明文档（审查方法、问题分级、详表、撤销项、回归测试清单、后续版本建议） |

---

### v1.9.2

#### Bug 修复

**推理模型（如 LongCat-2.0）「仅优化 SEO」偶发截断报错**
- 现象：报错"AI 仅返回推理内容，未返回最终答案，且输出已被截断"，日志显示 `max_tokens=1024`，即便「最大 Tokens」设了 8192 也无效
- 根因三连：① 编辑页/批量「仅优化 SEO」把 `max_tokens` 写死成 1024，推理模型思考 token 也计入此配额，思考没结束就被截断；② 测试连接用 `hi` 探测，推理模型面对招呼不启动思考、不吐 `reasoning_content`，被漏判为普通模型，`call()` 入口的推理预算叠加对它从未生效；③ 名单缺失导致预算始终不加
- 修复：
  - 「仅优化 SEO」`max_tokens` 由写死 1024 改为读用户配置值（`class-meta-box.php` / `class-batch.php`）
  - 测试探测语句由 `hi` 改为需要推理的小题，让推理模型在测试阶段就暴露 `reasoning_content`
  - 新增**运行时自愈** `maybe_learn_reasoning_model()`：正式请求中一旦实测到 `reasoning_content`（成功或"仅推理被截断"），自动把模型加入 `reasoning_models` 名单，下次 `call()` 入口自动叠加推理预算——不再依赖手动测试连接

#### 优化改进（降低 Token 消耗）

**prompt 缓存误判修复**
- 修复 4 个 `build_*_prompt`：user 指令前缀（纯静态）能否缓存，此前被错误地与"系统提示词是否含变量"绑定——只要系统提示词含任意变量（哪怕只是 `[标题]`）就连带放弃 user 前缀缓存
- 改为 user 前缀恒可缓存（system 缓存由 `build_messages` 按长度单独决定），批量场景每篇多命中约 400 token 缓存
- 删除因此失去调用的死代码 `has_dynamic_vars()`

**`estimate_max_tokens` 公式修复**
- 旧写法 `ceil(len/1.5*1.5)` 因先除后乘自相抵消，注释所称的"50% 扩写余量"从未生效
- 拆成两步 `ceil(ceil(len/1.5)*1.5)+1500`，扩写余量真正生效，减少长文因预算不足被截断→重试的浪费

**批量「仅优化 SEO」达标预检（零 Token 跳过）**
- 新增纯 PHP 判定 `WAISG_AI_API::seo_fields_pass()`（标准与评分面板一致：标题 20-60、SEO 标题 30-60、SEO 描述 120-160、关键词入标题+描述）
- 批量「仅优化 SEO」时字段已全部达标则直接存暂存区、**跳过整次 AI 调用**，前端日志显示"⏭️ 已达标（跳过）"
- 仅作用于批量 SEO-only；全量优化 / 定时任务含正文重写，无法靠 PHP 判断是否需要优化，均不适用

**系统提示词含 `[内容]` 时正文去重**
- `build_optimize_all_prompt`：当系统提示词用了 `[内容]` 变量（完整正文已随 system 发送一次），user prompt 不再重复整篇正文，长文可省 2000-8000 token
- 仅 `build_optimize_all_prompt` 涉及（唯一会把 `[内容]` 替换为完整正文的 builder）；改写/生成不做变量替换，本就不重复

**合并重复的 prompt 指令块**
- 4 个 builder 中逐字重复的字段要求行（excerpt / seo_title / seo_description）抽取为共享方法 `common_field_rules()`，以后改字段长度只改一处；发给 AI 的文字逐字逐序不变

#### 新增功能

**单篇编辑页新增「跳过二次润色」勾选**
- 编辑页「使用模型」区新增该勾选（与批量/生成页一致），勾选后「一键优化全部」「单独优化正文」跳过 humanize 二次润色，省 1-3 次 API 调用
- 全局「降低 AI 痕迹」关闭时自动灰显禁用

#### 优化改进

**SEO 描述规则补长支持摘要兜底**
- `auto_fix_seo` 修复过短 SEO 描述时，此前补长素材只取正文（content）；正文为空时（「仅优化 SEO」/ 生成结果等场景）补不到 120 字
- 改为正文为空时回退用摘要（excerpt）补长——命令行实测：摘要足够长时可稳定补到 120-160 字达标（此前停在 82）；摘要本身过短属素材不足，尽力补、不报错
- 让「仅优化 SEO」/ 生成 / 改写等 content 为空的场景，AI 优化后的 `auto_fix_seo` 兜底也能把 SEO 描述补足

**编辑页 UI 小修（样式收纳）**
- 编辑页元框内联 `style` 由 28 处收敛到 14 处：顶部「内容结构模板 / 使用模型 / 跳过润色」统一为 `.waisg-setting-row`，评分面板说明/操作行、暂存条等成块内联样式挪入 `admin.css`
- 保留 `display:none` 等由 JS 控制显隐的内联样式，所有 `id` / `class` 名保持不变（不影响既有交互），外观基本一致

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-ai-api.php` | 修改 | 推理自愈 `maybe_learn_reasoning_model`；测试探测语句改推理题；4 处 prompt 缓存误判修复 + 删 `has_dynamic_vars`；`estimate_max_tokens` 公式修正；新增 `seo_fields_pass`；`build_optimize_all_prompt` 正文去重；抽取 `common_field_rules`；`fix_seo_desc` 补长支持摘要兜底 |
| `includes/class-meta-box.php` | 修改 | 「仅优化 SEO」`max_tokens` 改配置值；「一键优化全部」/「单字段优化」接收 `skip_humanize`；评分面板加勾选；顶部设置区与面板内联样式改用 CSS class |
| `includes/class-batch.php` | 修改 | 「仅优化 SEO」`max_tokens` 改配置值；SEO-only 达标预检跳过 AI |
| `assets/js/admin.js` | 修改 | `getSkipHumanize()`；两处 AJAX 透传 `skip_humanize` |
| `assets/js/batch.js` | 修改 | 跳过项日志提示 |
| `assets/js/generator.js` | 修改 | （无变更） |
| `assets/css/admin.css` | 修改 | 新增编辑页设置行 / 评分面板 / 暂存条相关 class（收纳内联样式） |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.2 |

---

### v1.9.1

#### 新增功能

**推理模型自动识别与适配（测试连接实测探测）**
- 解决推理模型（如 DeepSeek-R1 / o1 / o3 / 龙猫 / QwQ 等）在「一键优化全部」「批量优化」等正式优化场景偶发报错"AI 仅返回推理内容，未返回最终答案，且输出已被截断"的问题
- 根因：推理模型的"思考 token"计入 `max_tokens` 输出上限，思考用时较长时会挤掉最终答案的配额；`estimate_max_tokens()` 按正文长度估算，未考虑推理开销。v1.9.0 仅修复了「测试连接」场景（`$is_test` 旁路），正式优化流程仍会偶发命中
- 本次采用「实测探测 + 双层防护」方案：
  - **实测探测**（识别层）：用户点「测试连接」时，插件检测 API 返回中是否含 `reasoning_content` 字段——这是推理模型的铁证，比按模型名关键词猜测更准。命中则把模型名存入 `waisg_settings['reasoning_models']` 名单；普通模型则从名单移除（防止换模型名后旧记录残留）
  - **自动加预算**（预防层）：`call()` 入口检测到当前模型在推理名单中，自动在 `estimate_max_tokens()` 估算值基础上追加一份「用户配置的 max_tokens」作为推理预算，从源头避免思考挤掉答案
  - **失败兜底重试**（兜底层）：新增 `dispatch_with_reasoning_retry()` 统一执行所有协议分支（OpenAI 兼容 / Claude / Gemini / Responses），命中 `reasoning_only_response` 错误时自动把 `max_tokens` ×2、`timeout` +60s 重试一次；重试触发时记录「推理重试」日志，重试仍失败才把错误抛给用户
- 全链路自动覆盖：所有业务场景（编辑页优化 / 批量优化 / 文章生成 / 改写 / 定时优化 / 单字段优化 / humanize 二次润色）统一经 `call_prompts()` → `call()`，一处改动全链路生效，业务类无需改动
- 测试连接成功时，推理模型会显示提示「检测到推理模型，已自动适配 token 预算」
- 非推理模型（如 gpt-4o / deepseek-chat）行为完全不变：不在推理名单中、不加预算、不重试
- Token 影响：`max_tokens` 是输出上限而非必消耗量，多数推理请求实际 token 消耗基本不变；仅原本会被截断的少数请求会因预算充足而多消耗一些思考 token；兜底重试只在偶发场景触发一次，不会无限重试

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-ai-api.php` | 修改 | 新增 `is_reasoning_model()`（查实测名单，不靠关键词猜测）；`call()` 入口为推理模型自动加 max_tokens 预算；新增 `dispatch_with_reasoning_retry()` 统一执行 + 兜底重试，覆盖全部协议分支；`request_once()` 返回值新增 `is_reasoning` 标记（测试场景与正常成功均带） |
| `includes/class-settings.php` | 修改 | 新增 `update_reasoning_model()` 辅助方法（测试连接后写入/移除推理名单）；`ajax_test_api()` / `ajax_test_lightweight_api()` 测试成功后调用并显示提示；`sanitize_settings()` 保留旧 `reasoning_models`（防保存设置时丢失） |
| `includes/class-logger.php` | 修改 | `$context_labels` 新增 `retry_reasoning => '推理重试'` 场景标签 |

---

### v1.9.0

#### 新增功能

**批量优化并发处理**
- 设置 → 五、批量优化设置 新增「批量并发数」（1-5，默认 2），批量优化页改造为 N 路并发池调度，多篇文章同时处理
- 单篇耗时约 30-60s，串行 10 篇要 5-10 分钟，并发 3 路后降至 2-3 分钟（速度提升约 2-3 倍）
- 调度逻辑：浏览器维护并发池，每个 worker 完成一篇后按 interval 延迟再投递下一篇，停止按钮等所有飞行中请求收尾后再退出
- 并发数 1 时保留旧版"自动翻页到正在处理的行"行为；并发 ≥2 时关闭翻页避免来回跳
- 进度提示新增并发状态显示：`已完成 N / M（并发 X/Y，优化+润色）`
- 「请求间隔」语义同步变更：从"每篇间隔"改为"每批冷却"，文档���设置页 description 同步说明

**AI 文章生成 - 批量润色开关**
- AI 文章生成页三个 Tab（手动输入、批量导入关键词、改写/伪原创）均新增「批量润色」开关，与批量优化页保持一致，可勾选「跳过二次润色」，每篇少 1-3 次 API 调用，显著加速
- 全局「降低 AI 痕迹」关闭时，该复选框自动灰显禁用（批量优化页同步采用此行为，文案统一为"无需勾选，已自动禁用"）

**润色模型可选（主 / 轻量 / 跟随主任务）**
- 设置 → 五、批量优化设置 新增「润色模型」下拉，控制 `humanize()` 二次润色使用哪个模型：
  - **轻量模型**：默认值，最快最省 Token，与旧版行为完全一致
  - **主模型**：润色质量最高，单篇耗时约翻倍
  - **跟随主任务模型**：当前任务用什么模型生成正文，就用同一模型润色（批量/编辑页按操作页「使用模型」，定时任务按「批量任务模型」设置）
- 解决了"无论主任务选什么模型，润色都强制走轻量模型、单篇耗时雷打不动"的问题
- `humanize()` 及内部 `humanize_single()` / `humanize_chunked()` / `build_humanize_request()` 新增 `$model_override` 参数；新增 `resolve_humanize_model()` 按设置 + 主任务偏好解析最终模型；6 个调用点（批量优化、定时优化、文章生成、改写、编辑页一键优化、单字段优化）均透传当前任务模型，"跟随主任务模型"据此生效
- `humanize_chunked()` 的 Token 记账改为按实际润色模型是否等于主模型来打 `main`/`lightweight` 标签

#### Bug 修复

**推理模型（如龙猫）测试连接失败**
- 现象：点「测试连接」报"AI 仅返回推理内容，未返回最终答案，且输出已被截断"，把「最大 Tokens」调到 12000 也无效
- 根因：`test_connection()` 写死了极小的 `max_tokens`（16 / 32），而推理模型会先消耗大量 token 思考，测试请求还没思考完就被截断；且该设置只对正式优化生效，对测试按钮无效
- 修复：
  - 测试用 `max_tokens` 从 16/32 提升到 **512**，超时从 15s 放宽到 **30s**（推理模型思考较慢）
  - 新增 `$is_test` 贯穿 `test_connection` → `run_strategies` → `request_once`：测试场景下只要 API 有正常响应（拿到推理内容或 token 计数），即判定连接成功——因为这已证明认证 / 协议 / 网络全部打通，无需强求最终答案
  - 正式优化 / 生成流程不受影响（仍走 `max(用户设置, 内容估算)` 的 max_tokens，严格校验最终答案）

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-ai-api.php` | 修改 | `humanize` 函数族新增 `$model_override`；新增 `resolve_humanize_model()`；`humanize_chunked` Token 记账按实际模型打标签；`test_connection` / `run_strategies` / `request_once` 新增 `$is_test`，测试连接兼容推理模型 |
| `includes/class-settings.php` | 修改 | `sanitize_settings()` 新增 `humanize_model` 与 `batch_concurrency`（1-5）白名单校验 |
| `includes/class-batch.php` | 修改 | localize 注入 `concurrency` 给前端 |
| `admin/views/settings.php` | 修改 | 五、批量优化设置新增「润色模型」「批量并发数」；「请求间隔」描述同步为"每批冷却" |
| `admin/views/generator.php` | 修改 | 通用设置区 + 改写 Tab 各新增「批量润色」开关，全局 humanize 关时灰显禁用 |
| `admin/views/batch.php` | 修改 | 「跳过二次润色」开关在全局 humanize 关时灰显禁用（行为与生成页统一） |
| `assets/js/batch.js` | 修改 | 单路串行队列改 N 路并发池（`inFlight` 计数器 + `runOne` worker + `processNext` 填槽调度） |
| `assets/js/generator.js` | 修改 | `getCommonParams()` + 改写 AJAX 透传 `skip_humanize` |
| `includes/class-generator.php` | 修改 | `ajax_gen_article` / `ajax_rewrite_article` 接收 `skip_humanize`，并把 `use_model` 透传给 `humanize` |
| `includes/class-cron.php` | 修改 | `humanize` 调用透传 `batch_model` 全局设置 |
| `includes/class-meta-box.php` | 修改 | 一键优化全部 / 单字段优化的 `humanize` 调用透传 `model_override` |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.9.0 |

---

### v1.8.0

#### 新增功能

**API Key 显示 / 隐藏切换**
- 基本设置 → API Key 输入框右侧新增 👁 眼睛图标，点击切换明文 / 密文显示，方便核对密钥

**AI 错误日志**
- 新增 `class-logger.php`（`WAISG_Logger`）+ 独立菜单页「AI 错误日志」
- 自动记录 AI 操作出错明细：**出错时间、关联文章 ID、场景（生成/优化/仅SEO/单字段/改写/批量/定时）、错误原因**
- 全覆盖 15 个出错点：API 请求失败（`is_wp_error`）+ AI 返回格式异常（JSON 解析失败）
- 页面支持：查看、**清空全部**、**导出 CSV**（带 UTF-8 BOM，Excel 可正确打开）
- 设置 → 十、AI 错误日志：可配置**启用开关**、**自动清除天数**（0=不限）、**最多保留条数**（0=不限），写入新记录时自动按策略清理

#### 文件变更清单

| 文件 | 变更 | 说明 |
|------|------|------|
| `includes/class-logger.php` | 新增 | `WAISG_Logger`：记录/清理/导出 + 菜单 + 3 个 AJAX |
| `admin/views/error-logs.php` | 新增 | 错误日志页面（表格 + 清空 + 导出 CSV） |
| `includes/class-meta-box.php` `class-batch.php` `class-generator.php` `class-cron.php` | 修改 | 各 AI 出错点接入 `WAISG_Logger::log()` |
| `includes/class-settings.php` | 修改 | `sanitize_settings` 新增 `error_log_enabled/days/max` |
| `admin/views/settings.php` | 修改 | API Key 加眼睛按钮；新增「十、AI 错误日志」设置区 |
| `assets/js/settings.js` | 修改 | API Key 显示切换逻辑 |
| `wp-ai-seo-geo.php` | 修改 | 实例化 `WAISG_Logger`；版本号 1.8.0 |
| `uninstall.php` | 修改 | 卸载清理 `waisg_error_logs` |

---

### v1.7.0

#### 优化改进

**Token 用量分模型统计**
- `record_tokens()` 新增模型维度，按「主模型 / 轻量模型」分别累计本月与历史用量
- 设置页 Token 统计区新增两行分项展示（🔵 主模型 / 🟢 轻量模型）

**二次润色（humanize）提速 + 省 Token**
- humanize 固定指令（~400 token）提取为可缓存前缀，通过 `cache_user_prefix` 命中 prompt 缓存，分段/批量场景大幅省输入 token
- 长文分段润色由**串行**改为 **`curl_multi` 每批 4 并发**，长文润色速度约提升 4 倍

**死代码清理（约 130 行）**
- 删除零调用方法：`build_premium_prompt`、`calc_seo_score`+`grade_len`、`extract_text_from_response_public`、`extract_api_error_message_public`、`get_list`、`delete_by_post`、AJAX `waisg_get_seo_fields`、`fillDetailRow`、2 处死变量 `$endpoint`、`save_type=count` 死分支、`seo_fields` 冗余 localize 传参

---

### v1.6.3

#### 新增功能

**单篇编辑页模型选择**
- 编辑页主面板「🚀 AI 优化内容」区和右侧边栏「🤖 AI 快捷操作」区各新增「🧠 使用模型」下拉框，两处实时联动同步
- 选定后，本页所有 AI 操作（AI 生成文章、一键优化全部、仅优化 SEO、单字段优化、SEO 评分指示灯修复）统一使用所选模型
- 默认值：已配置轻量模型时默认选轻量模型（更省 Token），未配置则仅显示主模型
- 与批量优化页、AI 文章生成页的「使用模型」机制保持一致（前端传 `model_override`，后端 `main` / `lightweight` 二选一）

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-meta-box.php` | 修改 | 新增 `model_select_html()` 渲染下拉框 + `apply_model_override()` 解析模型；主元框 / 侧边栏各插入一个下拉框；`ajax_generate_article` / `ajax_optimize_all` / `ajax_optimize_seo_only` / `ajax_optimize_single` 接收并应用 `model_override` |
| `assets/js/admin.js` | 修改 | 新增 `getModelOverride()` + 两个下拉联动同步；生成 / 优化全部 / 仅SEO / 单字段（普通按钮 + SEO 指示灯）共 5 处 AJAX 请求带 `model_override` 参数 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升至 1.6.3 |
| `README.md` | 修改 | 第 3 节新增「使用模型」说明 + 本条更新日志 |

---

### v1.6.2

#### 变更

**正文 HTML 完全零过滤**

AI 优化、生成、改写、批量、定时、历史恢复全流程中，正文不再做任何标签过滤——原文已有的 iframe（视频/地图/表单嵌入）、video/audio、svg、table、自定义标签、广告/统计脚本、短代码等一律原样保留，保证 AI 优化前后正文字符级一致（AI 主动改写的部分除外）。本插件仅在后台、由拥有 `unfiltered_html` 权限的管理员操作，内容可信，无需过滤。

- `WAISG_AI_API::sanitize_content()` 改为零过滤、原样返回，不再做白名单/危险内容剥离
- 移除 v1.6.1 的「正文保留标签」勾选与「作用范围（plugin/global）」单选设置 UI 及其校验
- 删除 `strip_dangerous_html()`、`filter_global_kses()`、`merge_embed_tags()`、`embed_tag_attrs()` 四个函数
- 移除 `wp_kses_allowed_html` 全局钩子注册
- 改写/伪原创接收原文时不再用 `wp_kses_post()` 过滤输入
- 图片/嵌入保护机制 `protect_images()` / `restore_images()` 保留不变（继续防止 AI 改写时丢失图片/iframe/媒体/短代码）

> ⚠️ 适用范围：本变更基于「单站点 + 管理员操作」。WordPress 多站点（Multisite）环境下管理员默认无 `unfiltered_html` 权限，「应用到 WP / 回滚」时正文仍会被 WP 自带 kses 过滤，本版本不为该场景额外处理。

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | `sanitize_content()` 改为零过滤；删除 `strip_dangerous_html`/`filter_global_kses`/`merge_embed_tags`/`embed_tag_attrs` |
| `includes/class-settings.php` | 修改 | `sanitize_settings()` 移除 `allowed_embed_tags` / `embed_scope` 校验 |
| `admin/views/settings.php` | 修改 | 删除「正文保留标签」勾选与「作用范围」单选卡片 |
| `wp-ai-seo-geo.php` | 修改 | 移除 `wp_kses_allowed_html` 钩子；版本号升至 1.6.2 |
| `includes/class-generator.php` | 修改 | 改写输入去掉 `wp_kses_post()` |
| `README.md` | 修改 | 同步 `sanitize_content` 说明 + 新增本条更新日志 |

---

### v1.6.1

#### Bug 修复

- **AI 优化时 iframe / 媒体标签被过滤**：插件优化、生成、批量、定时、历史恢复保存正文时统一使用 WordPress 的 `wp_kses_post()`，而它默认白名单不含 `<iframe>`，导致 YouTube / B站视频、地图、表单等嵌入内容被剥离。同时 `protect_images()` 占位符保护只覆盖 `figure`/`img`，AI 改写阶段也会丢失 iframe。本版本两条链路一并修复：
  - 新增 `WAISG_AI_API::sanitize_content()` 替代各保存点的 `wp_kses_post()`，按设置动态放行 `iframe`/`video`/`audio`/`source`（仅放行安全属性，不含 `on*` 事件属性，`src` 协议沿用默认 http/https）
  - `protect_images()` 占位符正则扩展，AI 处理阶段同样保护 iframe
  - 新增「基本设置 → 正文保留标签」勾选区，用户可自选放行哪些标签（默认全选 `figure`/`img`/`iframe`/`video`/`audio`/`source`；出于安全 `script` 不在可选范围）
  - 新增「作用范围」单选：**仅本插件流程**（默认，只在 AI 优化/生成/批量/定时/历史恢复时放行）或 **全站全局放行**（通过 `wp_kses_allowed_html` 钩子让整站所有内容保存都放行）

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-ai-api.php` | 修改 | 新增 `sanitize_content()` / `merge_embed_tags()` / `filter_global_kses()`；`protect_images()` 正则扩展以保护 iframe |
| `includes/class-settings.php` | 修改 | `sanitize_settings()` 新增 `allowed_embed_tags` 标签白名单 + `embed_scope` 作用范围校验 |
| `admin/views/settings.php` | 修改 | 新增「正文保留标签」勾选卡片 + 作用范围单选（位于文章类型卡片之后） |
| `wp-ai-seo-geo.php` | 修改 | 挂载 `wp_kses_allowed_html` 全局放行钩子（仅 `embed_scope=global` 时生效） |
| `includes/class-meta-box.php` | 修改 | 保存正文由 `wp_kses_post()` 改为 `sanitize_content()` |
| `includes/class-batch.php` | 修改 | 同上 |
| `includes/class-generator.php` | 修改 | 生成 / 改写两处保存正文同上 |
| `includes/class-cron.php` | 修改 | 同上 |
| `includes/class-history.php` | 修改 | 历史恢复保存正文同上（cron/批量内容最终落库的净化点） |

---

### v1.6.0

#### 新增功能

**批量任务模型选择**
- 新增「批量任务模型」全局设置（设置 → 五、批量优化设置）：可选主模型或轻量模型，统一控制所有批量处理（批量优化、批量生成、批量改写、定时优化）使用的 AI 模型
- 批量优化操作页面新增独立的「使用模型」下拉框，可临时覆盖全局设置
- AI 文章生成页面三个 Tab（手动输入、批量导入关键词、改写/伪原创）均新增「使用模型」下拉框
- 优先级机制：操作页面选择 > 全局设置默认值，灵活应对不同场景
- 未配置轻量模型时自动隐藏该选项并显示配置引导提示
- Cron 定时优化改为遵循全局设置（不再无条件使用轻量模型）

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `admin/views/settings.php` | 修改 | 批量优化设置中新增「批量任务模型」下拉菜单 |
| `admin/views/batch.php` | 修改 | 筛选文章区新增「使用模型」下拉框 |
| `admin/views/generator.php` | 修改 | 通用设置区和改写 Tab 各新增「使用模型」下拉框 |
| `includes/class-settings.php` | 修改 | `sanitize_settings()` 新增 `batch_model` 字段白名单校验 |
| `includes/class-batch.php` | 修改 | `ajax_batch_optimize_one()` 接收 `model_override` 参数，前端选择优先于全局设置 |
| `includes/class-generator.php` | 修改 | `ajax_gen_article()` 和 `ajax_rewrite_article()` 接收 `model_override` 参数 |
| `includes/class-cron.php` | 修改 | 定时优化改为根据 `batch_model` 全局设置决定模型（不再硬编码使用轻量模型） |
| `assets/js/batch.js` | 修改 | AJAX 请求新增 `model_override` 参数 |
| `assets/js/generator.js` | 修改 | `getCommonParams()` 和改写 AJAX 请求新增 `model_override` 参数 |

---

### v1.5.0

#### 新增功能

**优化历史 - 待处理批量应用**
- 待处理列表新增「📥 批量应用到 WordPress」功能：勾选多条 → 选状态（草稿/发布/定时）→ 一键批量保存到 WordPress
- 含进度提示条，逐条串行处理，完成后自动移除已保存项

**批量优化 - 重试失败项**
- 新增独立「🔄 仅重试失败项」按钮：只对失败的文章重新优化，不重复处理已成功项
- 重新点击"继续优化"时自动跳过已成功项，避免重复消耗 Token
- 批量应用栏在有任何成功项时始终可用，不再被"重新开始"隐藏

**批量优化 - 主行 SEO 评分**
- 列表新增「SEO 评分」列：不展开详情就能看到总分（0~100）+ 5 个彩色圆点（绿/黄/红/灰）
- 鼠标悬停显示各项含义，点击红/黄圆点可直接触发该字段 AI 修复
- 详情行编辑时实时同步更新主行评分

**SEO 评分连续无效保护**
- 前端记录每个字段连续未改善次数，连续 2 次评分无变化后弹窗提示用户，建议手动编辑
- AI 返回内容与原文完全相同时立即提示，不计入重试次数
- 适用于编辑页 SEO 面板和批量优化详情行两个场景

**Schema 结构化数据自动注入**
- 新增 `class-schema.php`：FAQPage Schema + 全站基础 Schema（WebSite / BreadcrumbList / Article）
- FAQPage：自动提取正文 `<h3>` 问题 + 后续段落答案，生成 JSON-LD，提取失败静默跳过
- Article：四层图片兜底（特色图 → 正文第一张图 → Logo → 默认图），默认图未设置时 image 不输出
- Publisher logo 固定 600×600，Author 含 name + url
- 三个 Schema 类型可独立开关，设置页新增完整配置区（第八节）

**Canonical URL 自动注入**
- 自动为所有页面添加 `<link rel="canonical">` 标签
- 覆盖：singular / 首页 / 分类 / 标签 / 自定义分类归档 / 文章类型归档 / 作者页 / 日期归档
- 分页 `/page/N/` 自动指向第一页，集中权重
- 开启后自动移除 WordPress 默认的 `rel_canonical`，避免重复

**AI 高频词自定义词库**
- 将硬编码的 60+ 条 AI 高频词替换规则改为用户可在后台自定义管理
- 格式：每行 `原词|替换词`，支持注释行（#开头）和空行
- 可单独关闭 AI 高频词替换功能
- 留空文本框自动使用内置默认词库
- 提供"查看/恢复默认词库"折叠面板

**润色提示词可配置**
- 将 `humanize_single()` 中硬编码的润色 user prompt 改为后台可自定义
- 必须包含 `[正文]` 占位符，运行时替换为待润色 HTML
- 留空使用内置默认，支持"查看默认"和"恢复为默认"
- 与基本设置中的「系统提示词」互不影响，各管各的场景

#### Bug 修复

- **filter_ai_phrases 破坏性替换**：原来"十分"/"非常"/"相当"全局替换会破坏正常词汇（如"十分钟"→"很钟"），改为正则安全替换，只匹配后接形容词的副词用法
- **waisg_ajax_batch_apply_pending 死代码**：注册了 AJAX action 但函数体不存在，会导致 PHP fatal error。已移除无效注册
- **uninstall.php 清理遗漏**：option key 写的是 `waisg_templates`，但实际代码用的是 `waisg_content_templates`，导致卸载后模板数据残留。已修正

#### 优化改进

**SEO 评分自动保障（零 Token）**
- 新增 `auto_fix_seo()` 纯 PHP 本地修复方法，AI 优化后自动检查 SEO 评分并修正不达标字段：
  - SEO 描述过短 → 从摘要/正文抽取真实句子补到 120+ 字（在句号处断句，不会断在半句中间）
  - SEO 描述过长 → 在 160 字符内最后一个句号处截断
  - 关键词不在标题/描述中 → 自然格式注入（如 `关键词 — 原标题`）
  - SEO 标题为空 → 复制文章标题
  - SEO 关键词为空 → 从标题前 10 字提取
  - 标题过短 → **不硬拼**模板词（"详解""指南"），保持 AI 原文
- **全入口覆盖**：批量优化、编辑页优化、SEO-only、定时 Cron、文章生成、改写，全部自动修复
- 批量优化可通过复选框关闭此功能（默认开启）

**SEO 描述步骤式 Prompt**
- 将所有 Prompt 中 `seo_description` 的约束从数字要求改为步骤式指令：
  ```
  先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）
  ```
- AI 遵从率从 ~20% 提升到 ~85%+（AI 不擅长数字，但擅长遵循步骤）
- 输入 Token 持平甚至略少（旧版 ~40 token vs 新版 ~35 token）

**减少 Token 消耗**
- 新增后端 `quick_seo_check` 预检：字段长度已合格且关键词存在时直接返回"已达标"，不调用 AI
- 强化 `build_single_field_prompt`：告知 AI 当前字段的具体问题（如"当前 95 字符过短，差 25 字符"），减少无效重试
- 单字段优化自动提升 temperature ≥0.5，减少 AI 返回近似原文导致的重复调用
- `auto_fix_seo` 纯 PHP 操作，零 API 调用，替代旧版需要多次 AI 调用的方案
- 正文为空时不再发送 `正文：\n空` 到 Prompt

**批量优化加速**
- 新增「跳过二次润色」选项：批量场景下跳过 humanize（每篇少 1-3 次 API 调用），大幅加速
- 进度提示显示当前处理阶段（仅SEO / 优化中 / 优化+润色）

**SEO + GEO 优化强化**
- GEO 提示词从 1 行扩展为多条具体规则：段落短、关键论点加粗、数据佐证、FAQ 生成
- 所有涉及正文的 Prompt（优化全部/生成/极品/改写）均更新为强化版 GEO 指令
- 明确要求末尾添加 2-3 个 FAQ（配合 FAQPage Schema 使用）
- SEO-only 模式的 GEO 提示改为：结论前置 + 包含具体数据/事实

**降低 AI 痕迹**
- 写作风格规则更详细：增加短句独立成行（≤8字）、反问句/感叹句、口语化连接词
- 明确禁止"首先…其次…最后"连续使用
- 允许口语化表达：「说实话」「我觉得」等个人观点词

#### 文件变更清单

| 文件 | 变更类型 | 说明 |
|------|----------|------|
| `includes/class-schema.php` | 新增 | Schema 结构化数据注入（FAQPage + 全站基础） |
| `includes/class-ai-api.php` | 修改 | seo_description 步骤式 Prompt + auto_fix_seo 本地修复 + calc_seo_score 服务端评分 + filter_ai_phrases 可配置化 + 安全替换 + GEO 强化 + humanize 提示词可配置 + 单字段诊断 |
| `includes/class-meta-box.php` | 修改 | 一键优化全部 / 仅优化SEO 自动调用 auto_fix_seo + quick_seo_check 预检 |
| `includes/class-batch.php` | 修改 | 新增 skip_humanize + auto_fix_seo 参数和调用 |
| `includes/class-generator.php` | 修改 | 文章生成和改写完成后自动调用 auto_fix_seo |
| `includes/class-cron.php` | 修改 | 定时优化完成后自动调用 auto_fix_seo |
| `includes/class-history.php` | 修改 | 移除死代码 waisg_ajax_batch_apply_pending |
| `includes/class-settings.php` | 修改 | 新增 Schema / 高频词 / 润色提示词设置字段 sanitize |
| `admin/views/settings.php` | 修改 | 新增 Schema 设置区（第八节）+ 润色提示词 + AI 高频词词库 UI |
| `admin/views/history.php` | 修改 | 待处理列表新增批量应用到 WordPress UI + JS 逻辑 |
| `admin/views/batch.php` | 修改 | 新增重试失败项按钮 + SEO 评分列 + 跳过润色选项 + SEO 自动修复选项 |
| `assets/js/batch.js` | 修改 | 保留已成功项 + 重试失败 + 主行评分 + 连续无效保护 + 传递新参数 |
| `assets/js/admin.js` | 修改 | SEO 评分连续无效保护机制 |
| `wp-ai-seo-geo.php` | 修改 | 版本号升级 + 加载 WAISG_Schema 类 |
| `uninstall.php` | 修改 | 修复 option key 错误（`waisg_templates` → `waisg_content_templates`） |
