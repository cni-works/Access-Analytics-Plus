<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_Error;

/** WordPress admin UI and secure, user-initiated Markdown download endpoint. */
final class AI_Report_Admin {
	public const PAGE_SLUG = 'access-analytics-plus-ai-report';
	private const OPTION_PURPOSE = 'aap_ai_report_site_purpose';
	private const OPTION_TARGET_AREA = 'aap_ai_report_target_area';
	private const OPTION_FOCUS_SERVICES = 'aap_ai_report_focus_services';
	private const PROFILE_LIMIT = 2000;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 15 );
		add_action( 'admin_post_aap_save_ai_report_profile', array( self::class, 'save_profile' ) );
		add_action( 'admin_post_aap_download_ai_report', array( self::class, 'download' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			Admin::PAGE_SLUG,
			__( 'AI相談用レポート', 'access-analytics-plus' ),
			__( 'AI相談用レポート', 'access-analytics-plus' ),
			Capabilities::VIEW,
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/** @return array<string,string> */
	public static function profile(): array {
		return array(
			'purpose'        => self::profile_value( self::OPTION_PURPOSE ),
			'target_area'    => self::profile_value( self::OPTION_TARGET_AREA ),
			'focus_services' => self::profile_value( self::OPTION_FOCUS_SERVICES ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'access-analytics-plus' ) );
		}

		$profile = self::profile();
		$request = self::default_request();
		$payload = null;
		$markdown = '';
		$error = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['aap_ai_preview'] ) ) {
			check_admin_referer( 'aap_preview_ai_report' );
			$normalized = AI_Report::normalize_request( wp_unslash( $_POST ) );
			if ( is_wp_error( $normalized ) ) {
				$error = $normalized;
			} else {
				$request = $normalized;
				$payload = AI_Report::generate( $request, $profile );
				if ( is_wp_error( $payload ) ) {
					$error = $payload;
					$payload = null;
				} else {
					$markdown = AI_Report_Markdown::render( $payload );
				}
			}
		}

		$sample = Settings::sample_enabled();
		$site_kit = Search_Console_Service::is_site_kit_installed();
		$today = current_datetime()->format( 'Y-m-d' );
		?>
		<div class="wrap aap-wrap aap-ai-report-wrap">
			<header class="aap-header">
				<div><h1><?php esc_html_e( 'AI相談用レポート', 'access-analytics-plus' ); ?></h1><p><?php esc_html_e( 'アクセスデータを整理し、ご自身で利用しているAIへ添付できるMarkdownを作成します。AAPからAIへ自動送信することはありません。', 'access-analytics-plus' ); ?></p></div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'アクセス解析へ戻る', 'access-analytics-plus' ); ?></a>
			</header>

			<?php if ( isset( $_GET['profile_saved'] ) ) : ?><div class="notice notice-success inline"><p><?php esc_html_e( 'サイト基本情報を保存しました。', 'access-analytics-plus' ); ?></p></div><?php endif; ?>
			<?php if ( $error instanceof WP_Error ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $error->get_error_message() ); ?></p></div><?php endif; ?>
			<?php if ( $sample ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( '現在サンプルデータを表示しているため、AI相談用レポートは生成できません。設定でサンプル表示をOFFにしてからお試しください。', 'access-analytics-plus' ); ?></p></div>
			<?php endif; ?>

			<section class="aap-panel aap-ai-step">
				<div class="aap-ai-step-heading"><span>STEP 1</span><div><h2><?php esc_html_e( '期間と相談内容を設定', 'access-analytics-plus' ); ?></h2><p><?php esc_html_e( '基本情報は必要な場合だけ保存できます。相談内容と問い合わせ件数は保存されません。', 'access-analytics-plus' ); ?></p></div></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aap-ai-profile-form">
					<input type="hidden" name="action" value="aap_save_ai_report_profile">
					<?php wp_nonce_field( 'aap_save_ai_report_profile' ); ?>
					<div class="aap-ai-fields">
						<label><strong><?php esc_html_e( 'サイトの目的', 'access-analytics-plus' ); ?></strong><textarea name="purpose" maxlength="2000" rows="3" placeholder="<?php esc_attr_e( '例：商品・サービスへの問い合わせを増やしたい', 'access-analytics-plus' ); ?>"><?php echo esc_textarea( $profile['purpose'] ); ?></textarea></label>
						<label><strong><?php esc_html_e( '主な対象地域', 'access-analytics-plus' ); ?></strong><textarea name="target_area" maxlength="2000" rows="2" placeholder="<?php esc_attr_e( '例：○○市とその周辺地域、または全国', 'access-analytics-plus' ); ?>"><?php echo esc_textarea( $profile['target_area'] ); ?></textarea></label>
						<label><strong><?php esc_html_e( '特に力を入れたいサービス', 'access-analytics-plus' ); ?></strong><textarea name="focus_services" maxlength="2000" rows="2" placeholder="<?php esc_attr_e( '例：主力サービス、新しく伸ばしたいサービス', 'access-analytics-plus' ); ?>"><?php echo esc_textarea( $profile['focus_services'] ); ?></textarea></label>
					</div>
					<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?><button type="submit" class="button"><?php esc_html_e( '基本情報を保存', 'access-analytics-plus' ); ?></button><?php else : ?><p class="description"><?php esc_html_e( '基本情報の保存には設定権限が必要です。', 'access-analytics-plus' ); ?></p><?php endif; ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="aap-ai-report-form" data-aap-ai-report-form>
					<?php wp_nonce_field( 'aap_preview_ai_report' ); ?>
					<div class="aap-ai-fields">
						<fieldset><legend><?php esc_html_e( '集計期間', 'access-analytics-plus' ); ?></legend><div class="aap-ai-periods">
							<?php foreach ( array( '7d' => '直近7日', '28d' => '直近28日', '3m' => '直近3か月', 'custom' => '期間指定' ) as $value => $label ) : ?><label><input type="radio" name="report_range" value="<?php echo esc_attr( $value ); ?>" <?php checked( $request['range'], $value ); ?>> <span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?>
						</div></fieldset>
						<div class="aap-ai-custom-period" data-aap-ai-custom-period <?php echo 'custom' === $request['range'] ? '' : 'hidden'; ?>><label><?php esc_html_e( '開始日', 'access-analytics-plus' ); ?><input type="date" name="start" max="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( $request['period']['start']->format( 'Y-m-d' ) ); ?>"></label><label><?php esc_html_e( '終了日', 'access-analytics-plus' ); ?><input type="date" name="end" max="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( $request['period']['end']->format( 'Y-m-d' ) ); ?>"></label><span><?php esc_html_e( '最大90日', 'access-analytics-plus' ); ?></span></div>
						<label><strong><?php esc_html_e( '現在気になっていること', 'access-analytics-plus' ); ?></strong><textarea name="concern" maxlength="2000" rows="3"><?php echo esc_textarea( (string) $request['concern'] ); ?></textarea></label>
						<label><strong><?php esc_html_e( '今回AIに相談したい内容', 'access-analytics-plus' ); ?></strong><textarea name="question" maxlength="2000" rows="3"><?php echo esc_textarea( (string) $request['question'] ); ?></textarea></label>
						<label><strong><?php esc_html_e( '期間中の問い合わせ件数（任意）', 'access-analytics-plus' ); ?></strong><input type="number" name="inquiries" min="0" max="1000000" inputmode="numeric" value="<?php echo null === $request['inquiries'] ? '' : esc_attr( (string) $request['inquiries'] ); ?>"><small><?php esc_html_e( 'フォーム・電話・LINE等を合計した概数でも構いません。', 'access-analytics-plus' ); ?></small></label>
						<div class="aap-ai-options">
							<label><input type="checkbox" name="include_paths" value="1" <?php checked( (bool) $request['include_paths'] ); ?>> <?php esc_html_e( 'ページPathを含める', 'access-analytics-plus' ); ?></label>
							<?php if ( $site_kit ) : ?><label><input type="checkbox" name="include_search" value="1" <?php checked( (bool) $request['include_search'] ); ?>> <?php esc_html_e( 'Google検索データを含める', 'access-analytics-plus' ); ?></label><?php else : ?><span><?php esc_html_e( 'Google検索データ：Site Kit未導入のため利用できません', 'access-analytics-plus' ); ?></span><?php endif; ?>
						</div>
					</div>
					<button type="submit" name="aap_ai_preview" value="1" class="button button-primary button-hero" <?php disabled( $sample ); ?>><?php esc_html_e( 'レポートを作成して確認', 'access-analytics-plus' ); ?></button>
				</form>
			</section>

			<section class="aap-panel aap-ai-step">
				<div class="aap-ai-step-heading"><span>STEP 2</span><div><h2><?php esc_html_e( '利用可能なデータとプレビューを確認', 'access-analytics-plus' ); ?></h2><p><?php esc_html_e( '検索語句や公開ページ情報を確認してからダウンロードしてください。', 'access-analytics-plus' ); ?></p></div></div>
				<?php if ( is_array( $payload ) ) : $sufficiency = (array) $payload['sufficiency']; ?>
					<div class="aap-ai-sufficiency"><div><span><?php esc_html_e( 'データ期間', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( (string) $sufficiency['period_label'] ); ?></strong><small><?php echo esc_html( (int) $sufficiency['available_days'] . '日' ); ?></small></div><div><span><?php esc_html_e( '確認済み訪問', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $sufficiency['visits'] ) . '回' ); ?></strong><small><?php echo esc_html( (string) $sufficiency['volume_label'] ); ?></small></div><div><span><?php esc_html_e( '地域判定率', 'access-analytics-plus' ); ?></span><strong><?php echo null === $sufficiency['region_rate'] ? esc_html__( 'データなし', 'access-analytics-plus' ) : esc_html( (string) $sufficiency['region_rate'] . '%' ); ?></strong></div><div><span><?php esc_html_e( 'Google検索語句', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $sufficiency['query_count'] ) . '件' ); ?></strong></div></div>
					<p class="aap-ai-overall"><?php echo esc_html( (string) $sufficiency['overall'] ); ?></p>
					<pre class="aap-ai-preview" tabindex="0" data-aap-ai-preview><?php echo esc_html( $markdown ); ?></pre>
				<?php else : ?><div class="aap-ai-empty"><?php esc_html_e( 'STEP 1を入力して「レポートを作成して確認」を押すと、ここに内容が表示されます。', 'access-analytics-plus' ); ?></div><?php endif; ?>
			</section>

			<section class="aap-panel aap-ai-step">
				<div class="aap-ai-step-heading"><span>STEP 3</span><div><h2><?php esc_html_e( 'Markdownをダウンロード', 'access-analytics-plus' ); ?></h2><p><?php esc_html_e( 'プレビュー内容を確認したら、AIへの相談に使用するファイルをダウンロードします。', 'access-analytics-plus' ); ?></p></div></div>
				<?php if ( is_array( $payload ) && ! $sample ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="aap_download_ai_report"><?php wp_nonce_field( 'aap_download_ai_report' ); ?>
						<?php self::hidden_request_fields( $request ); ?>
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Markdownをダウンロード', 'access-analytics-plus' ); ?></button>
					</form>
				<?php else : ?><p class="aap-ai-empty"><?php esc_html_e( 'プレビューを作成するとダウンロードできます。', 'access-analytics-plus' ); ?></p><?php endif; ?>
			</section>

			<section class="aap-panel aap-ai-step">
				<div class="aap-ai-step-heading"><span>STEP 4</span><div><h2><?php esc_html_e( '普段ご利用のAIにファイルを添付', 'access-analytics-plus' ); ?></h2><p><?php esc_html_e( 'ダウンロードしたMarkdownファイルを、ChatGPT・Gemini・Claudeなどの普段ご利用のAIへ添付して相談してください。AAPからAIへ自動送信されることはありません。', 'access-analytics-plus' ); ?></p></div></div>
			</section>
		</div>
		<?php
	}

	public static function save_profile(): void {
		self::require_post();
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'サイト基本情報を保存する権限がありません。', 'access-analytics-plus' ) );
		}
		check_admin_referer( 'aap_save_ai_report_profile' );
		update_option( self::OPTION_PURPOSE, self::posted_profile_value( 'purpose' ), false );
		update_option( self::OPTION_TARGET_AREA, self::posted_profile_value( 'target_area' ), false );
		update_option( self::OPTION_FOCUS_SERVICES, self::posted_profile_value( 'focus_services' ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'profile_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function download(): void {
		self::require_post();
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'レポートをダウンロードする権限がありません。', 'access-analytics-plus' ) );
		}
		check_admin_referer( 'aap_download_ai_report' );
		if ( Settings::sample_enabled() ) {
			wp_die( esc_html__( 'サンプル表示中はAI相談用レポートをダウンロードできません。', 'access-analytics-plus' ) );
		}
		$request = AI_Report::normalize_request( wp_unslash( $_POST ) );
		if ( is_wp_error( $request ) ) {
			wp_die( esc_html( $request->get_error_message() ) );
		}
		$payload = AI_Report::generate( $request, self::profile() );
		if ( is_wp_error( $payload ) ) {
			wp_die( esc_html( $payload->get_error_message() ) );
		}
		$markdown = AI_Report_Markdown::render( $payload );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="site-analysis-' . current_datetime()->format( 'Y-m-d' ) . '.md"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $markdown ) );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text download after renderer sanitization.
		exit;
	}

	/** @return array<string,mixed> */
	private static function default_request(): array {
		$normalized = AI_Report::normalize_request( array( 'report_range' => '28d', 'include_search' => 1, 'include_paths' => 1 ) );
		return is_wp_error( $normalized ) ? array() : $normalized;
	}

	private static function require_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'この操作はPOSTリクエストで実行してください。', 'access-analytics-plus' ), '', array( 'response' => 405 ) );
		}
	}

	private static function profile_value( string $key ): string {
		return self::limit( sanitize_textarea_field( (string) get_option( $key, '' ) ) );
	}

	private static function posted_profile_value( string $key ): string {
		return self::limit( sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) ) );
	}

	private static function limit( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::PROFILE_LIMIT );
		}
		return 1 === preg_match( '/^(.{0,' . self::PROFILE_LIMIT . '})/us', $value, $match ) ? $match[1] : substr( $value, 0, self::PROFILE_LIMIT );
	}

	/** @param array<string,mixed> $request */
	private static function hidden_request_fields( array $request ): void {
		$fields = array(
			'report_range' => (string) $request['range'],
			'start'        => $request['period']['start']->format( 'Y-m-d' ),
			'end'          => $request['period']['end']->format( 'Y-m-d' ),
			'concern'      => (string) $request['concern'],
			'question'     => (string) $request['question'],
			'inquiries'    => null === $request['inquiries'] ? '' : (string) $request['inquiries'],
		);
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		if ( ! empty( $request['include_search'] ) ) {
			echo '<input type="hidden" name="include_search" value="1">';
		}
		if ( ! empty( $request['include_paths'] ) ) {
			echo '<input type="hidden" name="include_paths" value="1">';
		}
	}
}
