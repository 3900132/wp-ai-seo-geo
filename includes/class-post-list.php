<?php
/**
 * 文章列表列：AI 优化次数 + 按次数筛选
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Post_List {

	public function __construct() {
		$post_types = WAISG_Settings::get_post_types();
		foreach ( $post_types as $pt ) {
			add_filter( "manage_{$pt}_posts_columns",          array( $this, 'add_column' ) );
			add_action( "manage_{$pt}_posts_custom_column",    array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$pt}_sortable_columns",  array( $this, 'sortable_columns' ) );
			// 文章列表顶部添加筛选下拉
			add_action( "restrict_manage_posts",               array( $this, 'add_filter_dropdown' ) );
		}
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
	}

	/** 添加列 */
	public function add_column( $columns ) {
		$columns['waisg_opt_count'] = '🤖 AI 状态';
		return $columns;
	}

	/** 渲染列内容 */
	public function render_column( $column, $post_id ) {
		if ( $column !== 'waisg_opt_count' ) return;

		$count        = (int) get_post_meta( $post_id, '_waisg_opt_count', true );
		$ai_generated = (bool) get_post_meta( $post_id, '_waisg_ai_generated', true );

		if ( $ai_generated ) {
			// AI 直接生成：显示 AI 生成徽标
			echo '<span style="display:inline-block;background:#0073aa;color:#fff;font-size:11px;font-weight:700;'
				. 'padding:2px 7px;border-radius:3px;">AI 生成</span>';
		} elseif ( $count > 0 ) {
			// 普通文章被 AI 优化过：只显示优化次数，不显示徽标
			echo '<span style="font-weight:600;color:#2271b1;">' . $count . ' 次优化</span>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}

	/** 注册可排序列 */
	public function sortable_columns( $columns ) {
		$columns['waisg_opt_count'] = 'waisg_opt_count';
		return $columns;
	}

	/** 按优化次数排序 */
	public function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;

		// 排序
		if ( $query->get( 'orderby' ) === 'waisg_opt_count' ) {
			$query->set( 'meta_key', '_waisg_opt_count' );
			$query->set( 'orderby', 'meta_value_num' );
		}

		// 筛选：按优化次数
		$filter = sanitize_key( $_GET['waisg_opt_filter'] ?? '' );
		if ( empty( $filter ) ) return;

		switch ( $filter ) {
			case 'ai_gen':
				// AI 直接生成的文章
				$query->set( 'meta_query', array(
					array(
						'key'     => '_waisg_ai_generated',
						'value'   => '1',
						'compare' => '=',
					),
				) );
				break;

			case 'ai_opt':
				// 非 AI 生成、但被 AI 优化过的文章
				$query->set( 'meta_query', array(
					'relation' => 'AND',
					array(
						'key'     => '_waisg_opt_count',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_waisg_ai_generated',
						'compare' => 'NOT EXISTS',
					),
				) );
				break;

			case 'zero':
				// 优化次数为 0（包含从未优化的）
				$query->set( 'meta_query', array(
					'relation' => 'OR',
					array(
						'key'     => '_waisg_opt_count',
						'value'   => '0',
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_waisg_opt_count',
						'compare' => 'NOT EXISTS',
					),
				) );
				break;

			case 'gt0':
				// 优化次数 > 0
				$query->set( 'meta_query', array(
					array(
						'key'     => '_waisg_opt_count',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				) );
				break;

			case 'gt5':
				// 优化次数 > 5
				$query->set( 'meta_query', array(
					array(
						'key'     => '_waisg_opt_count',
						'value'   => 5,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				) );
				break;
		}
	}

	/** 获取各分类文章数量（仅查一次，缓存在静态变量中） */
	private function get_counts( $post_type ) {
		static $cache = array();
		if ( isset( $cache[ $post_type ] ) ) return $cache[ $post_type ];

		global $wpdb;
		$pm = $wpdb->postmeta;
		$p  = $wpdb->posts;

		// AI 生成数
		$ai_gen = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$p} p
			 INNER JOIN {$pm} pm ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND p.post_status != 'trash'
			   AND pm.meta_key = '_waisg_ai_generated' AND pm.meta_value = '1'",
			$post_type
		) );

		// AI 优化数（有 opt_count > 0，且无 ai_generated 标记）
		$ai_opt = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$p} p
			 INNER JOIN {$pm} pm1 ON p.ID = pm1.post_id
			 WHERE p.post_type = %s AND p.post_status != 'trash'
			   AND pm1.meta_key = '_waisg_opt_count' AND pm1.meta_value + 0 > 0
			   AND p.ID NOT IN (
			       SELECT post_id FROM {$pm} WHERE meta_key = '_waisg_ai_generated' AND meta_value = '1'
			   )",
			$post_type
		) );

		// 未优化数（无 opt_count 记录，或 opt_count = 0）
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p} WHERE post_type = %s AND post_status != 'trash'",
			$post_type
		) );
		$zero = $total - $ai_gen - $ai_opt;

		$cache[ $post_type ] = compact( 'ai_gen', 'ai_opt', 'zero', 'total' );
		return $cache[ $post_type ];
	}

	/** 在文章列表顶部添加筛选下拉框 */
	public function add_filter_dropdown( $post_type ) {
		if ( ! in_array( $post_type, WAISG_Settings::get_post_types(), true ) ) return;

		$counts  = $this->get_counts( $post_type );
		$current = sanitize_key( $_GET['waisg_opt_filter'] ?? '' );

		$options = array(
			''       => '全部文章（' . $counts['total'] . '）',
			'ai_gen' => '🤖 AI 生成（' . $counts['ai_gen'] . '）',
			'ai_opt' => '✏️ AI 优化（' . $counts['ai_opt'] . '）',
			'zero'   => '未处理（' . max( 0, $counts['zero'] ) . '）',
			'gt0'    => '已优化（次数 ≥ 1）',
			'gt5'    => '多次优化（次数 > 5）',
		);
		echo '<select name="waisg_opt_filter">';
		foreach ( $options as $val => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}
}
