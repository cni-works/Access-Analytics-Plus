<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Admin {
	public const PAGE_SLUG = 'access-analytics-plus';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'アクセス解析', 'access-analytics-plus' ),
			__( 'アクセス解析', 'access-analytics-plus' ),
			Capabilities::VIEW,
			self::PAGE_SLUG,
			array( self::class, 'render' ),
			'dashicons-chart-area',
			3
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		$is_report    = 'toplevel_page_' . self::PAGE_SLUG === $hook_suffix;
		$is_dashboard = 'index.php' === $hook_suffix;
		$is_settings  = str_ends_with( $hook_suffix, '_page_' . Settings::PAGE_SLUG );
		if ( ! $is_report && ! $is_dashboard && ! $is_settings ) {
			return;
		}

		wp_enqueue_style( 'aap-admin', AAP_PLUGIN_URL . 'assets/css/admin-ui5.css', array(), AAP_VERSION . '.' . AAP_BUILD );
		wp_add_inline_style(
			'aap-admin',
			'@media (max-width:782px){.aap-grid{grid-template-columns:minmax(0,1fr)!important}.aap-grid>.aap-panel{box-sizing:border-box;max-width:100%;min-width:0;overflow:hidden;width:100%}}'
		);
		if ( $is_settings ) {
			return;
		}

		$dependencies = array();
		if ( $is_report ) {
			wp_enqueue_script( 'aap-qrcode', AAP_PLUGIN_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
			$dependencies[] = 'aap-qrcode';
		}
		wp_enqueue_script( 'aap-admin', AAP_PLUGIN_URL . 'assets/js/admin-ui5.js', $dependencies, AAP_VERSION . '.' . AAP_BUILD, true );
		wp_localize_script(
			'aap-admin',
			'aapAdmin',
			array(
				'reportEndpoint' => esc_url_raw( rest_url( 'access-analytics-plus/v1/report' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'analyticsUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				'today'          => current_datetime()->format( 'Y-m-d' ),
				'strings'        => array(
					'loading' => __( '読み込み中…', 'access-analytics-plus' ),
					'error'   => __( 'データを読み込めませんでした。', 'access-analytics-plus' ),
					'empty'   => __( 'この期間に記録されたアクセスはありません。', 'access-analytics-plus' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'access-analytics-plus' ) );
		}
		$today = current_datetime()->format( 'Y-m-d' );
		?>
		<div class="wrap aap-wrap" data-aap-report data-range="today" data-period-mode="day">
			<header class="aap-header">
				<div>
					<h1><?php esc_html_e( 'アクセス解析', 'access-analytics-plus' ); ?></h1>
					<p><?php esc_html_e( 'サイトの今の状況を、分かりやすく確認できます。', 'access-analytics-plus' ); ?></p>
				</div>
				<div class="aap-header-actions">
					<span class="aap-sample-badge" data-aap-sample-badge hidden><?php esc_html_e( 'サンプルデータ表示中', 'access-analytics-plus' ); ?></span>
					<span class="aap-measurement-state <?php echo Settings::tracking_enabled() ? 'is-active' : 'is-stopped'; ?>"><?php echo esc_html( Settings::tracking_enabled() ? __( '計測中', 'access-analytics-plus' ) : __( '計測停止中', 'access-analytics-plus' ) ); ?></span>
					<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ); ?>"><?php esc_html_e( '設定', 'access-analytics-plus' ); ?></a><?php endif; ?>
					<button type="button" class="button aap-mobile-button" data-aap-mobile-toggle><?php esc_html_e( 'スマホで見る', 'access-analytics-plus' ); ?></button>
				</div>
			</header>

			<section class="aap-mobile-panel" data-aap-mobile-panel hidden>
				<h2><?php esc_html_e( 'スマートフォンで開く', 'access-analytics-plus' ); ?></h2>
				<p><?php esc_html_e( 'ログイン後、このアクセス解析ページへ戻ります。', 'access-analytics-plus' ); ?></p>
				<div class="aap-copy-row">
					<input type="text" readonly value="<?php echo esc_attr( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" data-aap-url>
					<button type="button" class="button button-secondary" data-aap-copy><?php esc_html_e( 'URLをコピー', 'access-analytics-plus' ); ?></button>
				</div>
				<div class="aap-qr" data-aap-qr aria-label="<?php esc_attr_e( 'アクセス解析ページのQRコード', 'access-analytics-plus' ); ?>"></div>
				<p class="description"><?php esc_html_e( 'ブラウザーの「ホーム画面に追加」を使うと、次回から直接開けます。', 'access-analytics-plus' ); ?></p>
			</section>

			<nav class="aap-periods" aria-label="<?php esc_attr_e( '表示期間', 'access-analytics-plus' ); ?>">
				<button type="button" data-range="today" class="is-active"><?php esc_html_e( '今日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="yesterday"><?php esc_html_e( '昨日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="7d"><?php esc_html_e( '7日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="30d"><?php esc_html_e( '30日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="month"><?php esc_html_e( '今月', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="custom"><?php esc_html_e( '期間指定', 'access-analytics-plus' ); ?></button>
			</nav>
			<div class="aap-custom-period" data-aap-custom-period hidden>
				<label><?php esc_html_e( '開始日', 'access-analytics-plus' ); ?><input type="date" max="<?php echo esc_attr( $today ); ?>" data-aap-start></label>
				<label><?php esc_html_e( '終了日', 'access-analytics-plus' ); ?><input type="date" max="<?php echo esc_attr( $today ); ?>" data-aap-end></label>
				<button type="button" class="button button-primary" data-aap-apply-period><?php esc_html_e( 'この期間を表示', 'access-analytics-plus' ); ?></button>
				<span class="description"><?php esc_html_e( '最大90日', 'access-analytics-plus' ); ?></span>
			</div>
			<div class="aap-overview"><div class="aap-overview-calendar">
			<section class="aap-calendar" data-aap-calendar aria-label="<?php esc_attr_e( '日付を選んでアクセスを確認', 'access-analytics-plus' ); ?>"></section>
			<div class="aap-date-navigation" data-aap-date-navigation hidden>
				<button type="button" data-aap-previous-period aria-label="<?php esc_attr_e( '前の期間を表示', 'access-analytics-plus' ); ?>">‹</button>
				<strong data-aap-period-label></strong>
				<button type="button" data-aap-next-period aria-label="<?php esc_attr_e( '次の期間を表示', 'access-analytics-plus' ); ?>">›</button>
				<div class="aap-day-picker" data-aap-day-picker><button type="button" data-aap-open-day-picker><?php esc_html_e( '日付を直接入力', 'access-analytics-plus' ); ?></button><input type="date" max="<?php echo esc_attr( $today ); ?>" aria-label="<?php esc_attr_e( '表示する日付を直接入力', 'access-analytics-plus' ); ?>" data-aap-day-picker-input hidden></div>
				<button type="button" class="button-link" data-aap-return-latest><?php esc_html_e( '最新期間へ戻る', 'access-analytics-plus' ); ?></button>
			</div>

			</div><div class="aap-overview-metrics">
			<div class="aap-status" data-aap-status aria-live="polite"><?php esc_html_e( '読み込み中…', 'access-analytics-plus' ); ?></div>
			<div class="aap-metrics" data-aap-metrics></div>
			<p class="aap-metrics-summary" data-aap-metrics-summary></p>
			</div></div>

			<section class="aap-panel aap-chart-panel">
				<div class="aap-section-heading">
					<h2><?php esc_html_e( '訪問者数・閲覧回数の推移', 'access-analytics-plus' ); ?></h2>
					<span data-aap-updated></span>
				</div>
				<div data-aap-chart></div>
				<details class="aap-timeseries-disclosure" data-aap-timeseries-disclosure open>
					<summary><?php esc_html_e( '日ごとの数字を見る', 'access-analytics-plus' ); ?></summary>
					<div data-aap-timeseries-list></div>
				</details>
			</section>

			<div class="aap-grid">
				<section class="aap-panel"><h2><?php esc_html_e( 'どこから来た？', 'access-analytics-plus' ); ?> <button type="button" class="aap-help" data-help="<?php esc_attr_e( 'ダイレクトには、参照元を確認できなかった訪問も含まれます。', 'access-analytics-plus' ); ?>" aria-label="<?php esc_attr_e( '流入元の説明', 'access-analytics-plus' ); ?>">?</button></h2><div data-aap-sources></div><div class="aap-source-details" data-aap-source-details></div></section>
				<section class="aap-panel"><h2><?php esc_html_e( 'よく見られているページ', 'access-analytics-plus' ); ?></h2><div data-aap-pages></div></section>
			</div>
			<section class="aap-panel aap-device-panel">
				<h2><?php esc_html_e( 'デバイス', 'access-analytics-plus' ); ?> <button type="button" class="aap-help" data-help="<?php esc_attr_e( 'スマートフォン、PC、タブレットのおおよその割合です。端末の設定により実際と異なる場合があります。', 'access-analytics-plus' ); ?>" aria-label="<?php esc_attr_e( 'デバイス分類の説明', 'access-analytics-plus' ); ?>">?</button></h2>
				<div class="aap-device-content" data-aap-devices></div>
			</section>
			<details class="aap-exclusions" data-aap-exclusions>
				<summary><span><?php esc_html_e( 'この期間に除外したアクセス', 'access-analytics-plus' ); ?></span><strong data-aap-exclusions-total>0件</strong></summary>
				<div class="aap-exclusion-items" data-aap-exclusion-items></div>
			</details>
			<p class="aap-geoip-attribution"><a href="<?php echo esc_url( GeoIP_Database::PROVIDER_URL ); ?>" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a></p>
		</div>
		<?php
	}
}
