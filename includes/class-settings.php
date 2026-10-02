<?php
/**
 * Settings Management Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class ERM_Settings {

	public static function init() {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	public static function register_settings() {
		// General Settings
		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_retention_days' ),
				'default'           => 30,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_auto_clean',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_per_page',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_per_page' ),
				'default'           => 25,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_display_columns',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_columns' ),
				'default'           => array( 'host', 'count', 'status', 'last_request', 'actions' ),
			)
		);

		// UI Settings
		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_enable_notifications',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_track_all_urls',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_max_urls_logged',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_max_urls' ),
				'default'           => 10,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_track_response',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'default'           => true,
			)
		);

		register_setting(
			ERM_PRO_OPTION_GROUP,
			'erm_pro_max_response_body_length',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_max_response_body_length' ),
				'default'           => 0,
			)
		);

		// Add Settings Sections
		add_settings_section(
			'erm_pro_general',
			__( 'General Settings', 'erm-pro' ),
			null,
			'erm-pro-settings'
		);

		add_settings_section(
			'erm_pro_display',
			__( 'Display Settings', 'erm-pro' ),
			null,
			'erm-pro-settings'
		);

		add_settings_section(
			'erm_pro_cleanup',
			__( 'Log Management', 'erm-pro' ),
			null,
			'erm-pro-settings'
		);

		// Add Settings Fields
		add_settings_field(
			'erm_pro_per_page',
			__( 'Items Per Page', 'erm-pro' ),
			array( self::class, 'field_per_page' ),
			'erm-pro-settings',
			'erm_pro_display',
			array( 'label_for' => 'erm_pro_per_page' )
		);

		add_settings_field(
			'erm_pro_display_columns',
			__( 'Display Columns', 'erm-pro' ),
			array( self::class, 'field_display_columns' ),
			'erm-pro-settings',
			'erm_pro_display'
		);

		add_settings_field(
			'erm_pro_retention_days',
			__( 'Log Retention Period', 'erm-pro' ),
			array( self::class, 'field_retention_days' ),
			'erm-pro-settings',
			'erm_pro_cleanup'
		);

		add_settings_field(
			'erm_pro_auto_clean',
			__( 'Auto-Clean Old Logs', 'erm-pro' ),
			array( self::class, 'field_auto_clean' ),
			'erm-pro-settings',
			'erm_pro_cleanup'
		);

		add_settings_field(
			'erm_pro_enable_notifications',
			__( 'Enable Notifications', 'erm-pro' ),
			array( self::class, 'field_enable_notifications' ),
			'erm-pro-settings',
			'erm_pro_general'
		);

		add_settings_field(
			'erm_pro_track_all_urls',
			__( 'Track All Request URLs', 'erm-pro' ),
			array( self::class, 'field_track_all_urls' ),
			'erm-pro-settings',
			'erm_pro_display'
		);

		add_settings_field(
			'erm_pro_max_urls_logged',
			__( 'Max URLs to Log Per Request', 'erm-pro' ),
			array( self::class, 'field_max_urls_logged' ),
			'erm-pro-settings',
			'erm_pro_display',
			array( 'label_for' => 'erm_pro_max_urls_logged' )
		);

		add_settings_field(
			'erm_pro_track_response',
			__( 'Track Response Code & Time', 'erm-pro' ),
			array( self::class, 'field_track_response' ),
			'erm-pro-settings',
			'erm_pro_display'
		);

		add_settings_field(
			'erm_pro_max_response_body_length',
			__( 'Max Response Body Length', 'erm-pro' ),
			array( self::class, 'field_max_response_body_length' ),
			'erm-pro-settings',
			'erm_pro_display',
			array( 'label_for' => 'erm_pro_max_response_body_length' )
		);
	}

	// Sanitize Functions
	public static function sanitize_retention_days( $value ) {
		$value = (int) $value;
		return max( 0, min( 3650, $value ) );
	}

	public static function sanitize_checkbox( $value ) {
		return in_array( $value, array( true, 1, '1', 'on' ), true ) ? 1 : 0;
	}

	public static function sanitize_per_page( $value ) {
		$value = (int) $value;
		return max( 5, min( 200, $value ) );
	}

	public static function sanitize_max_urls( $value ) {
		$value = (int) $value;
		return max( 1, min( 100, $value ) );
	}

	public static function sanitize_max_response_body_length( $value ) {
		$value = (int) $value;
		// Allow 0 to disable storing bodies, otherwise cap to 1MB
		return max( 0, min( 1024 * 1024, $value ) );
	}

	public static function sanitize_columns( $value ) {
		$allowed = array( 'host', 'source', 'method', 'count', 'size', 'status', 'first_request', 'last_request', 'actions' );
		if ( ! is_array( $value ) ) {
			return array( 'host', 'count', 'status', 'last_request', 'actions' );
		}
		return array_values(
			array_unique(
				array_merge(
					array( 'host', 'actions' ),
					array_filter(
						$value,
						function( $column ) use ( $allowed ) {
							return is_string( $column ) && in_array( $column, $allowed, true );
						}
					)
				)
			)
		);
	}

	// Field Renderers
	public static function field_per_page() {
		$value = get_option( 'erm_pro_per_page', 25 );
		?>
		<input type="number" id="erm_pro_per_page" name="erm_pro_per_page" value="<?php echo esc_attr( $value ); ?>"
			   min="5" max="200" class="small-text">
		<span class="description"><?php esc_html_e( 'Number of items to display per page (5-200)', 'erm-pro' ); ?></span>
		<?php
	}

	public static function field_display_columns() {
		$columns   = self::sanitize_columns( get_option( 'erm_pro_display_columns' ) );
		$available = array(
			'host'          => __( 'Host', 'erm-pro' ),
			'source'        => __( 'Source (Plugin/Theme)', 'erm-pro' ),
			'method'        => __( 'Request Method', 'erm-pro' ),
			'count'         => __( 'Request Count', 'erm-pro' ),
			'size'          => __( 'Request Size', 'erm-pro' ),
			'status'        => __( 'Status (Blocked/Allowed)', 'erm-pro' ),
			'first_request' => __( 'First Request', 'erm-pro' ),
			'last_request'  => __( 'Last Request', 'erm-pro' ),
			'actions'       => __( 'Actions', 'erm-pro' ),
		);
		?>
		<fieldset>
			<?php foreach ( $available as $key => $label ) : ?>
				<label style="display: block; margin: 8px 0;">
					<input type="checkbox" name="erm_pro_display_columns[]" value="<?php echo esc_attr( $key ); ?>"
						   <?php checked( in_array( $key, $columns, true ) ); ?> <?php disabled( in_array( $key, array( 'host', 'actions' ), true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Select optional columns to display in the logs table. Host and Actions are required.', 'erm-pro' ); ?></p>
		<?php
	}

	public static function field_retention_days() {
		$days = get_option( 'erm_pro_retention_days', 30 );
		?>
		<div style="margin-bottom: 15px;">
			<p style="margin: 0 0 8px 0;">
				<label for="erm_pro_retention_days"><?php esc_html_e( 'Keep logs for', 'erm-pro' ); ?>:</label>
			</p>
			<input type="number" id="erm_pro_retention_days" name="erm_pro_retention_days"
				   value="<?php echo esc_attr( $days ); ?>" min="0" max="3650" class="small-text">
			<span><?php esc_html_e( 'days', 'erm-pro' ); ?></span>
			<p class="description">
				<?php esc_html_e( 'Allowed logs without rate limits and deletion audit entries expire after this period. Blocking and rate-limit rules are retained. Set to 0 to keep logs forever.', 'erm-pro' ); ?>
			</p>
		</div>
		<?php
	}

	public static function field_auto_clean() {
		$checked = get_option( 'erm_pro_auto_clean', true ) ? 'checked' : '';
		?>
		<label>
			<input type="checkbox" name="erm_pro_auto_clean" value="1" <?php echo esc_attr( $checked ); ?>>
			<span><?php esc_html_e( 'Automatically delete old logs', 'erm-pro' ); ?></span>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, expired logs and deletion audit entries are removed automatically. Blocking and rate-limit rules are retained.', 'erm-pro' ); ?>
		</p>
		<?php
	}

	public static function field_enable_notifications() {
		$checked = get_option( 'erm_pro_enable_notifications', true ) ? 'checked' : '';
		?>
		<label>
			<input type="checkbox" name="erm_pro_enable_notifications" value="1" <?php echo esc_attr( $checked ); ?>>
			<span><?php esc_html_e( 'Show admin notifications', 'erm-pro' ); ?></span>
		</label>
		<p class="description">
			<?php esc_html_e( 'Display notifications when new external requests are detected', 'erm-pro' ); ?>
		</p>
		<?php
	}

	public static function field_track_all_urls() {
		$checked = get_option( 'erm_pro_track_all_urls', false ) ? 'checked' : '';
		?>
		<label>
			<input type="checkbox" name="erm_pro_track_all_urls" value="1" <?php echo esc_attr( $checked ); ?>>
			<span><?php esc_html_e( 'Keep log of all requested URLs', 'erm-pro' ); ?></span>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, all unique URLs from each request will be logged. Disable to reduce database usage.', 'erm-pro' ); ?>
		</p>
		<?php
	}

	public static function field_max_urls_logged() {
		$value = get_option( 'erm_pro_max_urls_logged', 10 );
		?>
		<input type="number" id="erm_pro_max_urls_logged" name="erm_pro_max_urls_logged" value="<?php echo esc_attr( $value ); ?>"
			   min="1" max="100" class="small-text">
		<span class="description"><?php esc_html_e( 'Maximum number of unique URLs to keep per request (1-100)', 'erm-pro' ); ?></span>
		<?php
	}

	public static function field_track_response() {
		$checked = get_option( 'erm_pro_track_response', true ) ? 'checked' : '';
		?>
		<label>
			<input type="checkbox" name="erm_pro_track_response" value="1" <?php echo esc_attr( $checked ); ?>>
			<span><?php esc_html_e( 'Track HTTP Response Code & Time', 'erm-pro' ); ?></span>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, response codes (200, 404, etc.) will be recorded for each request. This helps monitor external service health.', 'erm-pro' ); ?>
		</p>
		<?php
	}

	public static function field_max_response_body_length() {
		$value = get_option( 'erm_pro_max_response_body_length', 0 );
		?>
		<div>
			<input type="number" id="erm_pro_max_response_body_length" name="erm_pro_max_response_body_length" value="<?php echo esc_attr( $value ); ?>" min="0" max="1048576" class="small-text">
			<span class="description"><?php esc_html_e( 'Maximum bytes to store from response bodies. Default 0 disables storage. Response bodies can contain private data; enable only when needed. Stored downloads contain this bounded excerpt.', 'erm-pro' ); ?></span>
		</div>
		<?php
	}
}
