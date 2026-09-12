<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Settings {
	public const PAGE_SLUG = 'access-analytics-plus-settings';
	private const OPTION_ACTIVE = 'aap_tracking_active';
	private const OPTION_ROLES = 'aap_excluded_roles';
	private const OPTION_IPS = 'aap_excluded_ips';
	private const OPTION_SAMPLE_ENABLED = 'aap_sample_enabled';
	private const OPTION_SAMPLE_SCALE = 'aap_sample_scale';
	private const OPTION_SAMPLE_SEED = 'aap_sample_seed';
	private const OPTION_SHADOW_ENABLED = 'aap_shadow_diagnostics_enabled';
	private const OPTION_COUNTRY_MODE = 'aap_country_mode';
	private const OPTION_ALLOWED_COUNTRIES = 'aap_allowed_countries';
	private const OPTION_TRUST_CLOUDFLARE = 'aap_trust_cloudflare_country';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_aap_save_settings', array( self::class, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			Admin::PAGE_SLUG,
			__( 'アクセス解析設定', 'access-analytics-plus' ),
			__( '設定', 'access-analytics-plus' ),
			Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function tracking_enabled(): bool {
		return (bool) get_option( self::OPTION_ACTIVE, true );
	}

	public static function sample_enabled(): bool {
		return (bool) get_option( self::OPTION_SAMPLE_ENABLED, false );
	}

	public static function sample_scale(): string {
		$scale = sanitize_key( (string) get_option( self::OPTION_SAMPLE_SCALE, 'standard' ) );
		return in_array( $scale, array( 'low', 'standard', 'high' ), true ) ? $scale : 'standard';
	}

	public static function sample_seed(): int {
		return max( 1, (int) get_option( self::OPTION_SAMPLE_SEED, 1 ) );
	}

	public static function country_mode(): string {
		return 'allowlist' === get_option( self::OPTION_COUNTRY_MODE, 'all' ) ? 'allowlist' : 'all';
	}

	/** @return string[] */
	public static function allowed_countries(): array {
		$value = get_option( self::OPTION_ALLOWED_COUNTRIES, array( 'JP' ) );
		if ( ! is_array( $value ) ) { return array( 'JP' ); }
		return array_values( array_filter( array_unique( array_map( static fn ( $code ): string => strtoupper( sanitize_key( (string) $code ) ), $value ) ), static fn ( string $code ): bool => 1 === preg_match( '/^[A-Z]{2}$/', $code ) ) );
	}

	public static function trust_cloudflare_country(): bool {
		return (bool) get_option( self::OPTION_TRUST_CLOUDFLARE, false );
	}

	/**
	 * @return string[]
	 */
	public static function excluded_roles(): array {
		$roles = get_option( self::OPTION_ROLES, array( 'administrator' ) );
		return is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : array( 'administrator' );
	}

	public static function is_user_excluded( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		return $user && (bool) array_intersect( (array) $user->roles, self::excluded_roles() );
	}

	public static function current_ip(): string {
		$remote_ip = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '' );
		$ip        = $remote_ip ?? '';

		if ( defined( 'AAP_TRUST_CF_CONNECTING_IP' ) && AAP_TRUST_CF_CONNECTING_IP && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cloudflare_ip = self::normalize_ip( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( null !== $cloudflare_ip ) {
				$ip = $cloudflare_ip;
			}
		}

		/**
		 * Filters the client IP used only for exact-match exclusion and rate limiting.
		 * The returned value must be a single valid IPv4 or IPv6 address.
		 *
		 * @param string $ip Normalized address detected by the plugin.
		 */
		$filtered = apply_filters( 'aap_client_ip', $ip );
		$normalized = self::normalize_ip( is_string( $filtered ) ? $filtered : '' );
		return $normalized ?? '';
	}

	public static function current_ip_is_safe_for_one_click(): bool {
		$has_proxy_header = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! empty( $_SERVER['HTTP_X_REAL_IP'] );
		$cloudflare_trusted = defined( 'AAP_TRUST_CF_CONNECTING_IP' ) && AAP_TRUST_CF_CONNECTING_IP;
		return (bool) apply_filters( 'aap_current_ip_one_click_safe', ! $has_proxy_header || $cloudflare_trusted );
	}

	public static function is_current_ip_excluded(): bool {
		$ip = self::current_ip();
		if ( '' === $ip ) {
			return false;
		}
		$hash = self::ip_hash( $ip );
		foreach ( self::excluded_ips() as $entry ) {
			if ( isset( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, array{hash:string,label:string}>
	 */
	public static function excluded_ips(): array {
		$entries = get_option( self::OPTION_IPS, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$entries,
				static fn ( mixed $entry ): bool => is_array( $entry ) && isset( $entry['hash'], $entry['label'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $entry['hash'] )
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'access-analytics-plus' ) );
		}

		$roles          = wp_roles()->roles;
		$excluded_roles = self::excluded_roles();
		$excluded_ips   = self::excluded_ips();
		$current_ip     = self::current_ip();
		$one_click_safe = self::current_ip_is_safe_for_one_click();
		$retention      = (int) get_option( 'aap_retention_days', 90 );
		$retention      = in_array( $retention, array( 90, 180, 365 ), true ) ? $retention : 90;
		$sample_scale   = self::sample_scale();
		$shadow_enabled = Shadow_Diagnostics::enabled();
		$shadow_summary = $shadow_enabled ? Shadow_Diagnostics::summary() : array( 'total' => 0, 'human_like' => 0, 'unconfirmed' => 0, 'suspected' => 0, 'geo_excluded' => 0, 'build_mismatch' => 0, 'promotion_failures' => 0, 'signals' => array(), 'reasons' => array(), 'ips' => array(), 'builds' => array(), 'countries' => array(), 'errors' => array() );
		$country_mode = self::country_mode();
		$allowed_countries = implode( ', ', self::allowed_countries() );
		$geoip_status = GeoIP_Database::status();
		$region_status = Region_Database::status();
		$collect_failure = Shadow_Diagnostics::last_collect_failure();
		$geoip_update_url = wp_nonce_url(
			add_query_arg( 'action', 'aap_update_geoip', admin_url( 'admin-post.php' ) ),
			'aap_update_geoip'
		);
		$region_update_url = wp_nonce_url(
			add_query_arg( 'action', 'aap_update_region_database', admin_url( 'admin-post.php' ) ),
			'aap_update_region_database'
		);
		$invalid_count  = isset( $_GET['invalid_ips'] ) ? absint( $_GET['invalid_ips'] ) : 0;
		?>
		<div class="wrap aap-wrap aap-settings">
			<header class="aap-header"><div><h1><?php esc_html_e( 'アクセス解析設定', 'access-analytics-plus' ); ?></h1><p><?php esc_html_e( '通常は初期設定のまま利用できます。必要な除外だけ設定してください。', 'access-analytics-plus' ); ?></p></div></header>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '設定を保存しました。', 'access-analytics-plus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( false !== get_option( 'aap_db_schema_error', false ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'アクセス解析のDB更新が完了していません。現在のDB Versionは更新されていません。開発者向け診断を確認してください。', 'access-analytics-plus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $collect_failure ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'アクセスの仮保存に失敗した記録があります。下の「自動アクセス診断」で詳細を確認してください。', 'access-analytics-plus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $invalid_count > 0 ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( sprintf( __( '正しくないIPアドレスを%d件追加しませんでした。', 'access-analytics-plus' ), $invalid_count ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['geoip_update'] ) && 'success' === sanitize_key( wp_unslash( (string) $_GET['geoip_update'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '国判定データを更新しました。', 'access-analytics-plus' ); ?></p></div>
			<?php elseif ( isset( $_GET['geoip_update'] ) && 'error' === sanitize_key( wp_unslash( (string) $_GET['geoip_update'] ) ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( '国判定データを更新できませんでした。既存または同梱データで国判定を継続します。', 'access-analytics-plus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['region_update'] ) ) : ?>
				<div class="notice <?php echo 'success' === sanitize_key( wp_unslash( (string) $_GET['region_update'] ) ) ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo esc_html( 'success' === sanitize_key( wp_unslash( (string) $_GET['region_update'] ) ) ? __( '都道府県データを更新しました。', 'access-analytics-plus' ) : __( '都道府県データを更新できませんでした。既存または同梱データで判定を継続します。', 'access-analytics-plus' ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aap_save_settings">
				<?php wp_nonce_field( 'aap_save_settings' ); ?>

				<section class="aap-settings-card">
					<h2><?php esc_html_e( '通常設定', 'access-analytics-plus' ); ?></h2>
					<label class="aap-setting-toggle"><input type="checkbox" name="tracking_active" value="1" <?php checked( self::tracking_enabled() ); ?>> <span><strong><?php esc_html_e( 'アクセス解析を計測する', 'access-analytics-plus' ); ?></strong><small><?php esc_html_e( 'OFFにすると新しいアクセスを記録しません。既存データは残ります。', 'access-analytics-plus' ); ?></small></span></label>
					<label class="aap-setting-field"><span><?php esc_html_e( '詳細データの保存期間', 'access-analytics-plus' ); ?></span><select name="retention_days">
						<?php foreach ( array( 90, 180, 365 ) as $days ) : ?>
							<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $retention, $days ); ?>><?php echo esc_html( sprintf( __( '%d日', 'access-analytics-plus' ), $days ) ); ?></option>
						<?php endforeach; ?>
					</select><small><?php esc_html_e( '個別の閲覧・訪問データだけを削除し、日別の合計値は保持します。', 'access-analytics-plus' ); ?></small></label>
				</section>

				<section class="aap-settings-card">
					<h2><?php esc_html_e( 'ログインユーザーの除外', 'access-analytics-plus' ); ?></h2>
					<p class="description"><?php esc_html_e( '選択した権限のユーザーが公開ページを見てもアクセス解析へ含めません。', 'access-analytics-plus' ); ?></p>
					<div class="aap-role-options">
						<?php foreach ( $roles as $role_key => $role ) : ?>
							<label><input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $excluded_roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="aap-settings-card aap-sample-settings">
					<h2><?php esc_html_e( 'サンプル表示', 'access-analytics-plus' ); ?></h2>
					<label class="aap-setting-toggle"><input type="checkbox" name="sample_enabled" value="1" <?php checked( self::sample_enabled() ); ?>> <span><strong><?php esc_html_e( 'サンプルデータを表示する', 'access-analytics-plus' ); ?></strong><small><?php esc_html_e( '実際の解析データには影響せず、専用のアクセス解析画面だけをサンプルデータ表示へ切り替えます。提案・スクリーンショット・表示確認などに利用できます。', 'access-analytics-plus' ); ?></small></span></label>
					<label class="aap-setting-field"><span><?php esc_html_e( 'アクセス規模', 'access-analytics-plus' ); ?></span><select name="sample_scale">
						<option value="low" <?php selected( $sample_scale, 'low' ); ?>><?php esc_html_e( '少なめ（1日5〜30訪問程度）', 'access-analytics-plus' ); ?></option>
						<option value="standard" <?php selected( $sample_scale, 'standard' ); ?>><?php esc_html_e( '標準（1日30〜100訪問程度）', 'access-analytics-plus' ); ?></option>
						<option value="high" <?php selected( $sample_scale, 'high' ); ?>><?php esc_html_e( '多め（1日100〜500訪問程度）', 'access-analytics-plus' ); ?></option>
					</select><small><?php esc_html_e( '同じサンプルは固定して表示され、下のボタンを押したときだけ数値が変わります。', 'access-analytics-plus' ); ?></small></label>
					<button type="submit" class="button button-secondary" name="regenerate_sample" value="1"><?php esc_html_e( 'サンプルデータを再生成', 'access-analytics-plus' ); ?></button>
				</section>

				<section class="aap-settings-card">
					<h2><?php esc_html_e( 'IPアドレスの除外', 'access-analytics-plus' ); ?></h2>
					<p class="description"><?php esc_html_e( 'IPv4・IPv6の完全一致に対応します。登録後は生のIPアドレスを保存せず、マスク表示だけ残します。', 'access-analytics-plus' ); ?></p>
					<?php if ( $excluded_ips ) : ?><div class="aap-ip-list">
						<?php foreach ( $excluded_ips as $entry ) : ?><label><input type="checkbox" name="remove_ips[]" value="<?php echo esc_attr( $entry['hash'] ); ?>"> <?php echo esc_html( $entry['label'] ); ?> <span><?php esc_html_e( '削除', 'access-analytics-plus' ); ?></span></label><?php endforeach; ?>
					</div><?php endif; ?>
					<label class="aap-setting-field"><span><?php esc_html_e( '追加するIPアドレス', 'access-analytics-plus' ); ?></span><textarea name="new_ips" rows="3" placeholder="203.0.113.10&#10;2001:db8::1234"></textarea><small><?php esc_html_e( '1行に1つ入力します。CIDR形式には対応していません。', 'access-analytics-plus' ); ?></small></label>
					<div class="aap-current-ip"><span><?php esc_html_e( '現在の接続元IP：', 'access-analytics-plus' ); ?><code><?php echo esc_html( '' !== $current_ip ? $current_ip : __( '確認できません', 'access-analytics-plus' ) ); ?></code></span>
					<?php if ( '' !== $current_ip && $one_click_safe ) : ?><label><input type="checkbox" name="add_current_ip" value="1"> <?php esc_html_e( 'このIPを除外へ追加', 'access-analytics-plus' ); ?></label><?php endif; ?></div>
					<?php if ( ! $one_click_safe ) : ?><p class="aap-warning"><?php esc_html_e( 'CDNまたはプロキシ環境の可能性があるため、現在IPのワンクリック追加を停止しています。サーバー構成を確認して手動登録してください。', 'access-analytics-plus' ); ?></p><?php endif; ?>
				</section>

				<section class="aap-settings-card">
					<h2><?php esc_html_e( 'アクセス地域の集計', 'access-analytics-plus' ); ?></h2>
					<label><input type="radio" name="country_mode" value="all" <?php checked( $country_mode, 'all' ); ?>> <?php esc_html_e( 'すべての地域を集計', 'access-analytics-plus' ); ?></label><br>
					<label><input type="radio" name="country_mode" value="allowlist" <?php checked( $country_mode, 'allowlist' ); ?>> <?php esc_html_e( '指定した国のみ通常集計', 'access-analytics-plus' ); ?></label>
					<label class="aap-setting-field"><span><?php esc_html_e( '許可する国コード', 'access-analytics-plus' ); ?></span><input type="text" name="allowed_countries" value="<?php echo esc_attr( $allowed_countries ); ?>" placeholder="JP"><small><?php esc_html_e( 'ISO 2文字コードをカンマ区切りで入力します（例：JP, US）。国を判定できないアクセスは除外しません。', 'access-analytics-plus' ); ?></small></label>
					<label class="aap-setting-toggle"><input type="checkbox" name="trust_cloudflare_country" value="1" <?php checked( self::trust_cloudflare_country() ); ?>> <span><strong><?php esc_html_e( 'Cloudflareの国コードを信頼する', 'access-analytics-plus' ); ?></strong><small><?php esc_html_e( 'このサイトがCloudflare経由でのみ公開されている場合に有効にしてください。偽装ヘッダーを避けるため初期状態はOFFです。', 'access-analytics-plus' ); ?></small></span></label>
					<p class="description"><?php echo esc_html( sprintf( __( '現在の判定元：%s', 'access-analytics-plus' ), Country_Resolver::source_label() ) ); ?></p>
					<div class="aap-geoip-status">
						<h3><?php esc_html_e( 'ローカル国判定データ', 'access-analytics-plus' ); ?></h3>
						<dl>
							<div><dt><?php esc_html_e( 'データソース', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $geoip_status['source'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'データ版', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $geoip_status['edition'] ); ?></dd></div>
							<div><dt><?php esc_html_e( '最終更新', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $geoip_status['last_updated'] ); ?></dd></div>
							<div><dt><?php esc_html_e( '状態', 'access-analytics-plus' ); ?></dt><dd class="is-<?php echo esc_attr( $geoip_status['status_level'] ); ?>"><?php echo esc_html( $geoip_status['status'] ); ?></dd></div>
						</dl>
						<a class="button button-secondary" href="<?php echo esc_url( $geoip_update_url ); ?>"><?php esc_html_e( '今すぐ更新', 'access-analytics-plus' ); ?></a>
						<p class="description"><?php esc_html_e( 'DB-IP Country Liteを利用しています。データベースはCC BY 4.0で提供され、IPから国コードだけを判定します。生のIPアドレスは解析データとして保存しません。', 'access-analytics-plus' ); ?> <a href="<?php echo esc_url( GeoIP_Database::PROVIDER_URL ); ?>" target="_blank" rel="noopener noreferrer">DB-IP</a> / <a href="<?php echo esc_url( GeoIP_Database::LICENSE_URL ); ?>" target="_blank" rel="noopener noreferrer">CC BY 4.0</a></p>
					</div>
					<div class="aap-geoip-status">
						<h3><?php esc_html_e( '日本国内の都道府県データ', 'access-analytics-plus' ); ?></h3>
						<dl>
							<div><dt><?php esc_html_e( 'データソース', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $region_status['source'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'データ版', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $region_status['edition'] ); ?></dd></div>
							<div><dt><?php esc_html_e( '最終更新', 'access-analytics-plus' ); ?></dt><dd><?php echo esc_html( $region_status['last_updated'] ); ?></dd></div>
							<div><dt><?php esc_html_e( '状態', 'access-analytics-plus' ); ?></dt><dd class="is-<?php echo esc_attr( $region_status['status_level'] ); ?>"><?php echo esc_html( $region_status['status'] ); ?></dd></div>
						</dl>
						<?php if ( $region_status['feed_available'] ) : ?><a class="button button-secondary" href="<?php echo esc_url( $region_update_url ); ?>"><?php esc_html_e( '今すぐ更新', 'access-analytics-plus' ); ?></a><?php else : ?><button type="button" class="button button-secondary" disabled><?php esc_html_e( '更新フィード準備中', 'access-analytics-plus' ); ?></button><?php endif; ?>
						<p class="description"><?php esc_html_e( 'DB-IP City Lite 2026-09版から、日本だけを抽出・都道府県コードへ正規化・連続範囲を統合した派生MMDBです。CC BY 4.0で提供され、都道府県分析だけに利用します。生IPは保存しません。', 'access-analytics-plus' ); ?> <a href="<?php echo esc_url( GeoIP_Database::PROVIDER_URL ); ?>" target="_blank" rel="noopener noreferrer">DB-IP</a> / <a href="<?php echo esc_url( GeoIP_Database::LICENSE_URL ); ?>" target="_blank" rel="noopener noreferrer">CC BY 4.0</a></p>
					</div>
				</section>

				<details class="aap-settings-card aap-shadow-diagnostics">
					<summary><?php esc_html_e( '自動アクセス診断（直近7日）', 'access-analytics-plus' ); ?></summary>
					<p class="description"><?php esc_html_e( '新しいアクセスは、3秒の表示または操作・閲覧時間送信を確認してから通常集計へ反映します。匿名化した診断情報は7日後に削除します。', 'access-analytics-plus' ); ?></p>
					<?php if ( $shadow_enabled ) : ?>
						<?php if ( $collect_failure ) : ?>
							<h3><?php esc_html_e( '直近の仮保存エラー', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list"><li>
								<span><?php echo esc_html( sprintf( '%1$s / %2$s / DB %3$s / Tracker %4$s', (string) ( $collect_failure['time'] ?? '' ), (string) ( $collect_failure['type'] ?? '' ), (string) ( $collect_failure['db_version'] ?? '' ), (string) ( $collect_failure['tracker_build'] ?? '' ) ) ); ?></span>
								<strong><?php echo esc_html( '' !== (string) ( $collect_failure['message'] ?? '' ) ? (string) $collect_failure['message'] : __( 'DBエラー詳細なし', 'access-analytics-plus' ) ); ?></strong>
							</li></ul>
							<p class="description"><?php echo esc_html( ! empty( $collect_failure['repair_attempted'] ) ? __( 'DBスキーマの自己修復を試行しました。', 'access-analytics-plus' ) : __( '同時実行を避けるため、DBスキーマの自己修復はこの要求では実行されませんでした。', 'access-analytics-plus' ) ); ?></p>
						<?php endif; ?>
						<div class="aap-shadow-summary">
							<div><span><?php esc_html_e( '診断対象アクセス', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['total'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( '人間らしい挙動あり', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['human_like'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( '未確認', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['unconfirmed'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( 'Bot疑い', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['suspected'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( '地域設定による対象外', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['geo_excluded'] ?? 0 ) ); ?></strong></div>
							<div><span><?php esc_html_e( '旧Tracker Build', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['build_mismatch'] ?? 0 ) ); ?></strong></div>
							<div><span><?php esc_html_e( '昇格エラー記録', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['promotion_failures'] ?? 0 ) ); ?></strong></div>
						</div>
						<p class="description"><?php esc_html_e( '記録直後の判定待ちは「未確認」に含まれます。確認済みだけを通常のPV・訪問者として集計し、既存データは変更しません。', 'access-analytics-plus' ); ?></p>
						<?php if ( $shadow_summary['total'] > 0 ) : ?>
							<h3><?php esc_html_e( '確認できた診断信号', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<li><span><?php esc_html_e( '3秒の表示確認', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['visible'] ) ); ?></strong></li>
								<li><span><?php esc_html_e( '操作あり', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['interaction'] ) ); ?></strong></li>
								<li><span><?php esc_html_e( '閲覧時間の送信あり', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['engagement'] ) ); ?></strong></li>
								<li><span><?php esc_html_e( 'webdriver申告あり', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['webdriver'] ) ); ?></strong></li>
								<li><span><?php esc_html_e( 'Origin情報なし', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['origin_missing'] ) ); ?></strong></li>
								<li><span><?php esc_html_e( '端末情報の矛盾', 'access-analytics-plus' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shadow_summary['signals']['device_mismatch'] ) ); ?></strong></li>
							</ul>
						<?php endif; ?>
						<?php if ( $shadow_summary['reasons'] ) : ?>
							<h3><?php esc_html_e( 'Bot疑いとなった主な理由', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<?php foreach ( $shadow_summary['reasons'] as $reason ) : ?><li><span><?php echo esc_html( $reason['label'] ); ?></span><strong><?php echo esc_html( number_format_i18n( $reason['value'] ) ); ?></strong></li><?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( ( $shadow_summary['build_mismatch'] ?? 0 ) > 0 && ( $shadow_summary['builds'] ?? array() ) ) : ?>
							<h3><?php esc_html_e( '受信したTracker Build', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<?php foreach ( $shadow_summary['builds'] as $build_row ) : ?><li><span><?php echo esc_html( (string) $build_row['tracker_build'] ); ?></span><strong><?php echo esc_html( sprintf( '%1$s件%2$s', number_format_i18n( (int) $build_row['total'] ), (int) $build_row['build_mismatch'] === 1 ? __( '（現行と不一致）', 'access-analytics-plus' ) : '' ) ); ?></strong></li><?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( $shadow_summary['countries'] ?? array() ) : ?>
							<h3><?php esc_html_e( '国判定の内訳', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<?php foreach ( $shadow_summary['countries'] as $country_row ) : ?><li><span><?php echo esc_html( sprintf( '%1$s / %2$s', (string) $country_row['country_code'], (string) $country_row['country_source'] ) ); ?></span><strong><?php echo esc_html( sprintf( __( '%1$s件（地域対象外 %2$s件）', 'access-analytics-plus' ), number_format_i18n( (int) $country_row['total'] ), number_format_i18n( (int) $country_row['excluded'] ) ) ); ?></strong></li><?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( $shadow_summary['ips'] ) : ?>
							<h3><?php esc_html_e( 'アクセスが多い匿名IP識別子', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<?php foreach ( $shadow_summary['ips'] as $ip_row ) : ?><li><span><?php echo esc_html( sprintf( __( '匿名IP %s', 'access-analytics-plus' ), $ip_row['key'] ) ); ?></span><strong><?php echo esc_html( sprintf( __( '%1$sアクセス / %2$s visitor ID / 表示%3$s / 閲覧送信%4$s / webdriver%5$s / Bot疑い%6$s', 'access-analytics-plus' ), number_format_i18n( $ip_row['total'] ), number_format_i18n( $ip_row['visitors'] ), number_format_i18n( $ip_row['visible'] ), number_format_i18n( $ip_row['engagement'] ), number_format_i18n( $ip_row['webdriver'] ), number_format_i18n( $ip_row['suspected'] ) ) ); ?></strong></li><?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( $shadow_summary['errors'] ?? array() ) : ?>
							<h3><?php esc_html_e( '直近の昇格エラー', 'access-analytics-plus' ); ?></h3>
							<ul class="aap-shadow-list">
								<?php foreach ( $shadow_summary['errors'] as $error_row ) : ?>
									<li><span><?php echo esc_html( sprintf( '%1$s / 完了:%2$s / 失敗:%3$s / %4$s', (string) $error_row['last_error_at'], (string) $error_row['last_completed_stage'], (string) $error_row['last_error_stage'], (string) $error_row['last_error_type'] ) ); ?></span><strong><?php echo esc_html( '' !== (string) $error_row['last_error_message'] ? (string) $error_row['last_error_message'] : __( 'DBエラー詳細なし', 'access-analytics-plus' ) ); ?></strong></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( '診断は停止中です。既存の通常アクセス計測は継続します。', 'access-analytics-plus' ); ?></p>
					<?php endif; ?>
				</details>

				<details class="aap-settings-card aap-advanced-settings">
					<summary><?php esc_html_e( '詳細設定', 'access-analytics-plus' ); ?></summary>
					<label class="aap-setting-toggle"><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( (bool) get_option( 'aap_delete_data_on_uninstall', false ) ); ?>> <span><strong><?php esc_html_e( 'プラグイン削除時に解析データも削除する', 'access-analytics-plus' ); ?></strong><small><?php esc_html_e( '通常はOFFを推奨します。削除したデータは元に戻せません。', 'access-analytics-plus' ); ?></small></span></label>
					<p class="description"><?php esc_html_e( 'Bot除外は常に有効です。同意管理プラグインからはaap_tracking_enabledフィルターで計測を停止できます。', 'access-analytics-plus' ); ?></p>
				</details>

				<?php submit_button( __( '設定を保存', 'access-analytics-plus' ) ); ?>
			</form>
			<p class="description"><?php echo esc_html( sprintf( __( 'Build %s（正式Version %s）', 'access-analytics-plus' ), AAP_BUILD, AAP_VERSION ) ); ?></p>
		</div>
		<?php
	}

	public static function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( '設定を変更する権限がありません。', 'access-analytics-plus' ) );
		}
		check_admin_referer( 'aap_save_settings' );

		update_option( self::OPTION_ACTIVE, isset( $_POST['tracking_active'] ) ? 1 : 0, false );

		$allowed_roles = array_keys( wp_roles()->roles );
		$posted_roles  = isset( $_POST['excluded_roles'] ) && is_array( $_POST['excluded_roles'] ) ? wp_unslash( $_POST['excluded_roles'] ) : array();
		$roles         = array_values( array_intersect( $allowed_roles, array_map( 'sanitize_key', $posted_roles ) ) );
		update_option( self::OPTION_ROLES, $roles, false );

		$retention = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 90;
		update_option( 'aap_retention_days', in_array( $retention, array( 90, 180, 365 ), true ) ? $retention : 90, false );
		update_option( 'aap_delete_data_on_uninstall', isset( $_POST['delete_on_uninstall'] ) ? 1 : 0, false );
		update_option( self::OPTION_SHADOW_ENABLED, 1, false );
		$country_mode = isset( $_POST['country_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['country_mode'] ) ) : 'all';
		update_option( self::OPTION_COUNTRY_MODE, 'allowlist' === $country_mode ? 'allowlist' : 'all', false );
		$country_raw = isset( $_POST['allowed_countries'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_POST['allowed_countries'] ) ), 0, 200 ) : 'JP';
		$country_codes = array_values( array_filter( array_unique( array_map( static fn ( string $code ): string => strtoupper( trim( $code ) ), preg_split( '/[\s,]+/', $country_raw ) ?: array() ) ), static fn ( string $code ): bool => 1 === preg_match( '/^[A-Z]{2}$/', $code ) ) );
		update_option( self::OPTION_ALLOWED_COUNTRIES, array_slice( $country_codes ?: array( 'JP' ), 0, 20 ), false );
		update_option( self::OPTION_TRUST_CLOUDFLARE, isset( $_POST['trust_cloudflare_country'] ) ? 1 : 0, false );
		update_option( self::OPTION_SAMPLE_ENABLED, isset( $_POST['sample_enabled'] ) ? 1 : 0, false );
		$sample_scale = isset( $_POST['sample_scale'] ) ? sanitize_key( wp_unslash( (string) $_POST['sample_scale'] ) ) : 'standard';
		update_option( self::OPTION_SAMPLE_SCALE, in_array( $sample_scale, array( 'low', 'standard', 'high' ), true ) ? $sample_scale : 'standard', false );
		if ( isset( $_POST['regenerate_sample'] ) || false === get_option( self::OPTION_SAMPLE_SEED, false ) ) {
			update_option( self::OPTION_SAMPLE_SEED, wp_rand( 1, 2147483647 ), false );
		}

		$remove_hashes = isset( $_POST['remove_ips'] ) && is_array( $_POST['remove_ips'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['remove_ips'] ) ) : array();
		$entries       = array_values( array_filter( self::excluded_ips(), static fn ( array $entry ): bool => ! in_array( $entry['hash'], $remove_hashes, true ) ) );
		$new_ips_raw   = isset( $_POST['new_ips'] ) ? substr( sanitize_textarea_field( wp_unslash( (string) $_POST['new_ips'] ) ), 0, 4000 ) : '';
		$new_ips       = preg_split( '/[\r\n,]+/', $new_ips_raw ) ?: array();
		if ( isset( $_POST['add_current_ip'] ) && self::current_ip_is_safe_for_one_click() ) {
			$new_ips[] = self::current_ip();
		}

		$invalid_count = 0;
		foreach ( array_slice( $new_ips, 0, 50 ) as $candidate ) {
			if ( '' === trim( $candidate ) ) {
				continue;
			}
			$ip = self::normalize_ip( $candidate );
			if ( null === $ip ) {
				++$invalid_count;
				continue;
			}
			$hash = self::ip_hash( $ip );
			if ( ! array_filter( $entries, static fn ( array $entry ): bool => hash_equals( $entry['hash'], $hash ) ) ) {
				$entries[] = array( 'hash' => $hash, 'label' => self::mask_ip( $ip ) );
			}
		}
		update_option( self::OPTION_IPS, array_slice( $entries, 0, 50 ), false );

		$url = add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'updated' => 1, 'invalid_ips' => $invalid_count ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function normalize_ip( string $ip ): ?string {
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		return false !== $packed ? strtolower( (string) inet_ntop( $packed ) ) : null;
	}

	private static function ip_hash( string $ip ): string {
		return hash_hmac( 'sha256', $ip, wp_salt( 'secure_auth' ) );
	}

	private static function mask_ip( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			$parts[3] = 'xxx';
			return implode( '.', $parts );
		}
		$parts = explode( ':', $ip );
		return ( $parts[0] ?? '' ) . ':' . ( $parts[1] ?? '' ) . ':…:' . ( end( $parts ) ?: '0' );
	}
}
