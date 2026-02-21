<?php
/**
 * File Integrity Checker for HgE Standard plugin
 *
 * Monitors plugin files for unauthorized modifications by computing
 * SHA-256 hashes on activation and verifying them via WP-Cron.
 *
 * @package HgE_FAN_Courier
 * @since   1.0.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HGEZLPFCR_Integrity {

	/**
	 * Option key for stored file hashes.
	 *
	 * @var string
	 */
	const OPTION_HASHES = 'hgezlpfcr_file_hashes';

	/**
	 * Option key for the plugin version that generated the hashes.
	 *
	 * @var string
	 */
	const OPTION_HASHES_VERSION = 'hgezlpfcr_file_hashes_version';

	/**
	 * Transient key used to flag an integrity alert in admin.
	 *
	 * @var string
	 */
	const TRANSIENT_ALERT = 'hgezlpfcr_integrity_alert';

	/**
	 * WP-Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'hgezlpfcr_integrity_check';

	/**
	 * Cron recurrence schedule.
	 *
	 * @var string
	 */
	const CRON_RECURRENCE = 'hourly';

	/**
	 * File extensions to monitor.
	 *
	 * @var array
	 */
	const MONITORED_EXTENSIONS = [ 'php', 'js' ];

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Absolute path to the plugin root directory (with trailing slash).
	 *
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		$this->plugin_dir = plugin_dir_path( HGEZLPFCR_PLUGIN_FILE );

		// Cron callback.
		add_action( self::CRON_HOOK, [ $this, 'check_integrity' ] );

		// Admin notice for integrity failures.
		add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );

		// Regenerate hashes after a plugin update (upgrader_process_complete fires
		// for all updates; we check if our plugin was among them).
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );

		// Regenerate hashes if the stored version does not match the running version.
		// This catches manual file replacements and FTP-based updates.
		add_action( 'admin_init', [ $this, 'maybe_regenerate_hashes' ] );
	}

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Calculate SHA-256 hashes for every monitored file and persist them.
	 *
	 * Called on plugin activation and after plugin updates.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function generate_file_hashes() {
		$files  = $this->get_plugin_files();
		$hashes = [];

		foreach ( $files as $absolute_path ) {
			$relative = $this->relative_path( $absolute_path );
			if ( false === $relative ) {
				continue;
			}

			$hash = $this->hash_file( $absolute_path );
			if ( false !== $hash ) {
				$hashes[ $relative ] = $hash;
			}
		}

		if ( empty( $hashes ) ) {
			HGEZLPFCR_Logger::warning( 'Integrity: No files found to hash.' );
			return false;
		}

		update_option( self::OPTION_HASHES, $hashes, false );
		update_option( self::OPTION_HASHES_VERSION, HGEZLPFCR_PLUGIN_VER, false );

		// Clear any previous alert since we just baselined.
		delete_transient( self::TRANSIENT_ALERT );

		HGEZLPFCR_Logger::log( 'Integrity: File hashes generated.', [
			'file_count' => count( $hashes ),
			'version'    => HGEZLPFCR_PLUGIN_VER,
		] );

		return true;
	}

	/**
	 * WP-Cron callback: verify current file hashes against the stored baseline.
	 *
	 * If mismatches or unexpected files are found, an alert is raised.
	 */
	public function check_integrity() {
		$stored_hashes = get_option( self::OPTION_HASHES, [] );

		if ( empty( $stored_hashes ) || ! is_array( $stored_hashes ) ) {
			// No baseline yet - generate one silently.
			$this->generate_file_hashes();
			return;
		}

		$current_files = $this->get_plugin_files();
		$changes       = [];

		// Build a map of current relative paths => absolute paths.
		$current_map = [];
		foreach ( $current_files as $absolute_path ) {
			$relative = $this->relative_path( $absolute_path );
			if ( false !== $relative ) {
				$current_map[ $relative ] = $absolute_path;
			}
		}

		// 1. Check for modified files.
		foreach ( $stored_hashes as $relative => $expected_hash ) {
			if ( ! isset( $current_map[ $relative ] ) ) {
				// File was deleted.
				$changes[] = [
					'file'          => $relative,
					'type'          => 'deleted',
					'expected_hash' => $expected_hash,
					'actual_hash'   => '',
				];
				continue;
			}

			$actual_hash = $this->hash_file( $current_map[ $relative ] );

			if ( false === $actual_hash ) {
				$changes[] = [
					'file'          => $relative,
					'type'          => 'unreadable',
					'expected_hash' => $expected_hash,
					'actual_hash'   => '',
				];
				continue;
			}

			if ( ! hash_equals( $expected_hash, $actual_hash ) ) {
				$changes[] = [
					'file'          => $relative,
					'type'          => 'modified',
					'expected_hash' => $expected_hash,
					'actual_hash'   => $actual_hash,
				];
			}
		}

		// 2. Check for suspicious / unexpected files.
		foreach ( $current_map as $relative => $absolute_path ) {
			if ( ! isset( $stored_hashes[ $relative ] ) ) {
				$changes[] = [
					'file'          => $relative,
					'type'          => 'unexpected',
					'expected_hash' => '',
					'actual_hash'   => $this->hash_file( $absolute_path ),
				];
			}
		}

		if ( ! empty( $changes ) ) {
			$this->handle_integrity_failure( $changes );
		} else {
			// Clear any stale alert if the check passes.
			delete_transient( self::TRANSIENT_ALERT );

			HGEZLPFCR_Logger::log( 'Integrity: Check passed - all files match baseline.' );
		}
	}

	/**
	 * Recursively collect all monitored files inside the plugin directory.
	 *
	 * @return array List of absolute file paths.
	 */
	public function get_plugin_files() {
		$files = [];

		if ( ! is_dir( $this->plugin_dir ) ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$this->plugin_dir,
				RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
			),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$extension = strtolower( $file_info->getExtension() );
			if ( in_array( $extension, self::MONITORED_EXTENSIONS, true ) ) {
				$files[] = wp_normalize_path( $file_info->getPathname() );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Send an email alert to the site administrator about integrity changes.
	 *
	 * @param array $changes Array of change records.
	 */
	public function send_alert( array $changes ) {
		$admin_email = get_option( 'admin_email' );

		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			HGEZLPFCR_Logger::warning( 'Integrity: Cannot send alert - invalid admin email.' );
			return;
		}

		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] HgE FAN Courier - File Integrity Alert', 'hge-zone-de-livrare-pentru-fan-courier-romania' ),
			$site_name
		);

		$body  = __( 'The file integrity check for "HgE: Shipping Zones for FAN Courier Romania" detected the following changes:', 'hge-zone-de-livrare-pentru-fan-courier-romania' ) . "\n\n";

		foreach ( $changes as $change ) {
			$body .= sprintf( "File: %s\n", $change['file'] );
			$body .= sprintf( "Type: %s\n", strtoupper( $change['type'] ) );

			if ( ! empty( $change['expected_hash'] ) ) {
				$body .= sprintf( "Expected hash: %s\n", $change['expected_hash'] );
			}
			if ( ! empty( $change['actual_hash'] ) ) {
				$body .= sprintf( "Actual hash:   %s\n", $change['actual_hash'] );
			}

			$body .= "\n";
		}

		$body .= __( 'If you recently updated the plugin, this alert is expected and the hashes will be regenerated automatically. Otherwise, please investigate immediately.', 'hge-zone-de-livrare-pentru-fan-courier-romania' ) . "\n\n";
		$body .= sprintf(
			/* translators: %s: admin URL */
			__( 'Review your site: %s', 'hge-zone-de-livrare-pentru-fan-courier-romania' ),
			admin_url( 'plugins.php' )
		) . "\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $admin_email, $subject, $body, $headers );

		HGEZLPFCR_Logger::warning( 'Integrity: Alert email sent.', [
			'to'      => $admin_email,
			'changes' => count( $changes ),
		] );
	}

	/**
	 * Display a persistent admin notice when file integrity check has failed.
	 *
	 * The notice is shown to administrators until the transient expires or
	 * hashes are regenerated.
	 */
	public function show_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$alert = get_transient( self::TRANSIENT_ALERT );

		if ( empty( $alert ) || ! is_array( $alert ) ) {
			return;
		}

		$changes = $alert;
		$count   = count( $changes );

		?>
		<div class="notice notice-error hgezlpfcr-integrity-alert" style="border-left-color: #dc3232;">
			<p>
				<strong><?php esc_html_e( 'HgE FAN Courier - File Integrity Warning', 'hge-zone-de-livrare-pentru-fan-courier-romania' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of file changes detected */
					esc_html( _n(
						'The integrity check detected %d file change in the plugin directory. This could indicate unauthorized modification or a corrupted update.',
						'The integrity check detected %d file changes in the plugin directory. This could indicate unauthorized modification or a corrupted update.',
						$count,
						'hge-zone-de-livrare-pentru-fan-courier-romania'
					) ),
					absint( $count )
				);
				?>
			</p>
			<details style="margin-bottom: 10px;">
				<summary style="cursor: pointer; font-weight: 600;">
					<?php esc_html_e( 'View details', 'hge-zone-de-livrare-pentru-fan-courier-romania' ); ?>
				</summary>
				<table class="widefat striped" style="margin-top: 8px; max-width: 800px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'File', 'hge-zone-de-livrare-pentru-fan-courier-romania' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'hge-zone-de-livrare-pentru-fan-courier-romania' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $changes as $change ) : ?>
							<tr>
								<td><code><?php echo esc_html( $change['file'] ); ?></code></td>
								<td>
									<?php
									switch ( $change['type'] ) {
										case 'modified':
											esc_html_e( 'Modified (hash mismatch)', 'hge-zone-de-livrare-pentru-fan-courier-romania' );
											break;
										case 'deleted':
											esc_html_e( 'Deleted (missing from disk)', 'hge-zone-de-livrare-pentru-fan-courier-romania' );
											break;
										case 'unexpected':
											esc_html_e( 'Unexpected file (not in original distribution)', 'hge-zone-de-livrare-pentru-fan-courier-romania' );
											break;
										case 'unreadable':
											esc_html_e( 'Unreadable (permission error)', 'hge-zone-de-livrare-pentru-fan-courier-romania' );
											break;
										default:
											echo esc_html( ucfirst( $change['type'] ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
			<p>
				<em><?php esc_html_e( 'If you just updated the plugin, deactivate and reactivate it to regenerate the file hash baseline.', 'hge-zone-de-livrare-pentru-fan-courier-romania' ); ?></em>
			</p>
		</div>
		<?php
	}

	/**
	 * Schedule the hourly integrity cron job.
	 *
	 * Called on plugin activation.
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK );

			HGEZLPFCR_Logger::log( 'Integrity: Cron scheduled.', [
				'recurrence' => self::CRON_RECURRENCE,
			] );
		}
	}

	/**
	 * Remove the integrity cron job.
	 *
	 * Called on plugin deactivation.
	 */
	public function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		// Also clear all instances (safety net for multiple scheduled events).
		wp_clear_scheduled_hook( self::CRON_HOOK );

		HGEZLPFCR_Logger::log( 'Integrity: Cron unscheduled.' );
	}

	// ------------------------------------------------------------------
	// Activation / Deactivation callbacks (static, for register_*_hook)
	// ------------------------------------------------------------------

	/**
	 * Plugin activation callback.
	 *
	 * Generates file hashes and schedules the cron job.
	 */
	public static function on_activation() {
		$instance = self::instance();
		$instance->generate_file_hashes();
		$instance->schedule_cron();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Removes the cron job and cleans up transients.
	 * Stored hashes are kept so they can be verified if the plugin is reactivated.
	 */
	public static function on_deactivation() {
		$instance = self::instance();
		$instance->unschedule_cron();
		delete_transient( self::TRANSIENT_ALERT );
	}

	// ------------------------------------------------------------------
	// Hook callbacks
	// ------------------------------------------------------------------

	/**
	 * After a plugin update via the WordPress upgrader, regenerate hashes
	 * if our plugin was included in the update.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array       $hook_extra Extra data about the update.
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$our_basename = plugin_basename( HGEZLPFCR_PLUGIN_FILE );

		// Bulk update.
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( in_array( $our_basename, $hook_extra['plugins'], true ) ) {
				$this->generate_file_hashes();
			}
			return;
		}

		// Single update.
		if ( isset( $hook_extra['plugin'] ) && $our_basename === $hook_extra['plugin'] ) {
			$this->generate_file_hashes();
		}
	}

	/**
	 * Regenerate hashes if the stored version differs from the running version.
	 *
	 * This handles scenarios such as FTP-based file replacements where the
	 * upgrader hook does not fire.
	 */
	public function maybe_regenerate_hashes() {
		$stored_version = get_option( self::OPTION_HASHES_VERSION, '' );

		if ( $stored_version !== HGEZLPFCR_PLUGIN_VER ) {
			$this->generate_file_hashes();
		}
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Compute the SHA-256 hash of a file.
	 *
	 * @param string $file_path Absolute path to file.
	 * @return string|false Hex-encoded hash or false on failure.
	 */
	private function hash_file( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return false;
		}

		$hash = hash_file( 'sha256', $file_path );

		return ( false !== $hash ) ? $hash : false;
	}

	/**
	 * Convert an absolute path to a path relative to the plugin directory.
	 *
	 * Uses forward slashes for consistency across platforms.
	 *
	 * @param string $absolute_path Absolute file path.
	 * @return string|false Relative path or false if outside plugin dir.
	 */
	private function relative_path( $absolute_path ) {
		$normalized_abs = wp_normalize_path( $absolute_path );
		$normalized_dir = wp_normalize_path( $this->plugin_dir );

		if ( 0 !== strpos( $normalized_abs, $normalized_dir ) ) {
			return false;
		}

		return substr( $normalized_abs, strlen( $normalized_dir ) );
	}

	/**
	 * Handle a failed integrity check.
	 *
	 * Logs details, stores the alert transient, and sends an email.
	 *
	 * @param array $changes Array of change records.
	 */
	private function handle_integrity_failure( array $changes ) {
		// Log every change.
		foreach ( $changes as $change ) {
			HGEZLPFCR_Logger::warning( 'Integrity: File change detected.', [
				'file'          => $change['file'],
				'type'          => $change['type'],
				'expected_hash' => $change['expected_hash'],
				'actual_hash'   => $change['actual_hash'],
			] );
		}

		// Store alert for admin notice display (24 hours).
		set_transient( self::TRANSIENT_ALERT, $changes, DAY_IN_SECONDS );

		// Email the site administrator.
		$this->send_alert( $changes );
	}
}
