# WP AI SEO + GEO 智能优化 — 完整文档

> 版本 1.8.0 · 作者 [ivye](https://www.3520.net) · 支持 WordPress 5.0+

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

**连通测试**：填写好 API 信息后，点击「测试连接」按钮，插件会用当前填写的配置发送一条极短请求，即时验证接口是否可用，**无需提前保存设置**。如配置了轻量模型，还会显示「测试轻量模型」按钮，可单独验证轻量模型的连通性。

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

- **请求间隔（秒）**：批量优化时每篇文章之间的等待时间，防止 API 过载，推荐 3~5 秒
- **批量任务模型**（v1.6.0 新增）：选择批量处理（批量优化、批量生成、批量改写、定时优化）使用的 AI 模型
  - **主模型**：使用基本设置中的主模型，质量更高但费用较高（默认值）
  - **轻量模型**：使用轻量模型（需先在基本设置中配置），可大幅降低 API 费用
  - 此设置为全局默认值，在批量优化页面和 AI 文章生成页面上也有独立的「使用模型」下拉框，可临时覆盖全局设置
  - 优先级：**操作页面选择 > 全局设置默认值**
- **降低 AI 痕迹**：勾选后启用内容润色功能。所有 AI 生成/优化的正文内容会经过二次润色，打散 AI 写作痕迹，降低被 AI 检测工具识别的概率。会额外消耗一次 API 调用（优先使用轻量模型），默认关闭
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
| 文章状态 | 已发布 / 草稿 / 全部 |
| 分类 | 仅文章类型有效，按分类筛选 |
| 每次最多获取 | 从数据库读取的上限，建议不超过 200 |
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

- **逐篇应用**：在详情中选择状态 → 点击「应用到文章」
- **批量应用**：勾选多篇 → 底部「批量应用到 WordPress」→ 选状态 → 「批量应用」

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
- **每页条数**：搜索栏右侧可输入每页显示条数（10~200），点击「应用」刷新页面
- **批量应用到 WordPress**（v1.5.0 新增）：勾选多条记录 → 选择状态（草稿/发布/定时）→ 点击「📥 批量应用到 WordPress」，逐条将暂存内容保存到 WordPress，完成后自动从列表移除
- **批量删除**：每行左侧有勾选框，支持全选当前页；勾选后点击「批量删除选中」一次性删除多条记录
- **操作**：点击「编辑/保存到WP」打开弹窗，可编辑全部字段后选发布状态保存；已保存后自动从列表移除

### Tab 2：备份历史

每次 AI 优化前自动备份的旧版本快照。

- **每页条数**：与待处理 Tab 相同，支持自定义每页显示条数（10~200）
- **批量删除**：勾选多条备份记录后点击「批量删除选中」一次性删除

| 操作 | 说明 |
|------|------|
| 查看 | 查看该历史版本所有字段内容 |
| 对比当前 | 左右对比历史版本与文章当前版本，高亮差异字段 |
| 回滚 | 将文章恢复到该历史版本（回滚前自动再备份一次当前版本） |
| 删除 | 删除该条备份记录 |

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
// 发起 AI 请求，返回 ['text' => string, 'tokens' => int] 或 WP_Error
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
WAISG_AI_API::humanize( string $content ): string                // 二次润色（>3000 字符按 H2 分段，失败返回原文，提示词可自定义）
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
    'text'   => 'AI 返回的文本内容',
    'tokens' => 1234,   // 本次消耗的 Token 数（total_tokens）
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
```

**wp_options 存储键：**

| key | 类型 | 说明 |
|-----|------|------|
| `waisg_settings` | array | 全部设置项（含 `lightweight_model`、`humanize_enabled`、`humanize_prompt`、`ai_phrases_enabled`、`ai_phrases_custom`、`schema_faq_enabled`、`schema_base_enabled`、`schema_website`、`schema_breadcrumb`、`schema_article`、`schema_default_image`、`canonical_enabled` 等） |
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
- 长文分段润色由**串行**改为 **`curl_multi` 每批 2 并发**，长文润色速度约提升 2 倍

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
