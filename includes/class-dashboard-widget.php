<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Dashboard_Widget {
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	public static function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'aap_dashboard_widget',
			__( 'アクセス解析', 'access-analytics-plus' ),
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		?>
		<div class="aap-widget" data-aap-report data-range="today" data-period-mode="day" data-dashboard-widget>
			<nav class="aap-widget-periods" aria-label="<?php esc_attr_e( '表示期間', 'access-analytics-plus' ); ?>">
				<button type="button" class="is-active" data-range="today" aria-pressed="true"><?php esc_html_e( '今日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="yesterday" aria-pressed="false"><?php esc_html_e( '昨日', 'access-analytics-plus' ); ?></button>
				<button type="button" data-range="7d" aria-pressed="false"><?php esc_html_e( '7日', 'access-analytics-plus' ); ?></button>
			</nav>
			<div class="aap-status" data-aap-status aria-live="polite"><?php esc_html_e( '読み込み中…', 'access-analytics-plus' ); ?></div>
			<div class="aap-widget-metrics" data-aap-metrics></div>
			<div class="aap-widget-chart" data-aap-chart></div>
			<p class="aap-widget-month" data-aap-month></p>
			<div class="aap-widget-top-page" data-aap-pages></div>
			<a class="button button-primary aap-detail-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ) ); ?>"><?php esc_html_e( '詳しいアクセス解析を見る', 'access-analytics-plus' ); ?></a>
		</div>
		<?php
	}
}
