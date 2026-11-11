<?php
/**
 * GitHub updater. Ships ONLY in the GitHub release zip (WordPress.org forbids plugin updaters): delete this file for the WordPress.org build.
 * Release rule: tag vX.Y.Z and attach a zip whose name is vaultly-backup.zip or vaultly-backup-X.Y.Z.zip, containing the folder vaultly-backup/.
 */
defined( 'ABSPATH' ) || exit;

final class VAULTLY_Updater {
	const REPO  = 'brandixsoft/vaultly-backup'; // the public GitHub repo, "user/repo"
	const SLUG  = 'vaultly-backup';
	const TESTED = '7.1'; // WordPress version this build was tested against

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) ); // WP's own update check: fetch the release here
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'show' ) ); // every read: cached data only, so the "Enable auto-updates" link is always there
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 ); // "View details" popup for the GitHub version
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) ); // after any update: drop the cached release so the next check is fresh
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source' ), 10, 4 ); // keep the folder name "vaultly-backup" whatever the zip's top folder is
		add_filter( 'plugin_action_links_' . VAULTLY_BASENAME, array( __CLASS__, 'links' ) ); // "Check for updates" link in the Plugins list
		add_action( 'admin_init', array( __CLASS__, 'handle_check' ) ); // the link lands here: refresh now and bounce back
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) ); // result of a manual check
	}

	/** After a manual "Check for updates": tell the admin whether an update was found. */
	public static function notice(): void {
		if ( empty( $_GET['vaultly_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only notice after our own redirect
			return;
		}
		$updates = get_site_transient( 'update_plugins' );
		$has     = is_object( $updates ) && isset( $updates->response[ VAULTLY_BASENAME ] );
		$msg     = $has
			? sprintf( /* translators: %s: new version */ __( 'Vaultly Backup %s is available — update it from the list below.', 'vaultly-backup' ), esc_html( $updates->response[ VAULTLY_BASENAME ]->new_version ) )
			: __( 'Vaultly Backup is up to date.', 'vaultly-backup' );
		echo '<div class="notice notice-' . ( $has ? 'warning' : 'success' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/** Forget the cached GitHub release (called after updates, from the "Check for updates" link, and on activation). */
	public static function flush(): void {
		delete_transient( 'vaultly_gh_release' );
	}

	/** "Check for updates" link under the plugin in the Plugins list, like other pro plugins. */
	public static function links( $links ) {
		$url = wp_nonce_url( add_query_arg( array( 'vaultly_check' => 1 ), admin_url( 'plugins.php' ) ), 'vaultly_check' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'vaultly-backup' ) . '</a>';
		return $links;
	}

	/** Force a fresh check: drop our cache + WordPress's plugin-update cache, then reload the Plugins list. */
	public static function handle_check(): void {
		if ( empty( $_GET['vaultly_check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'vaultly_check' );
		self::flush();
		delete_site_transient( 'update_plugins' ); // make WordPress ask every updater again right now
		wp_update_plugins();
		wp_safe_redirect( add_query_arg( 'vaultly_checked', 1, admin_url( 'plugins.php' ) ) );
		exit;
	}

	/** The release, from a 6h cache; a failed/empty fetch is cached for only 15 min so a just-published release is picked up quickly. */
	private static function release( bool $fetch ) {
		$rel = get_transient( 'vaultly_gh_release' );
		if ( false !== $rel ) {
			return is_array( $rel ) ? $rel : array();
		}
		if ( ! $fetch ) {
			return array();
		}
		$r   = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
		$rel = ( 200 === wp_remote_retrieve_response_code( $r ) ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : array();
		$rel = is_array( $rel ) ? $rel : array();
		set_transient( 'vaultly_gh_release', $rel, empty( $rel['tag_name'] ) ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $rel;
	}

	/** Version string + download URL from a release array. */
	private static function parse( array $rel ): array {
		$ver = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' );
		$zip = '';
		// Accept vaultly-backup.zip or a versioned name like vaultly-backup-1.9.1.zip (whichever the release carries).
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
			if ( preg_match( '/^vaultly-backup(-[0-9][0-9A-Za-z.\-]*)?\.zip$/', (string) ( $a['name'] ?? '' ) ) ) {
				$zip = (string) $a['browser_download_url'];
			}
		}
		return array( $ver, $zip );
	}

	/** Fill the "View details" popup from the release instead of hitting WordPress.org (which has no such plugin). */
	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $res;
		}
		$rel = self::release( true );
		list( $ver ) = self::parse( $rel );
		$body = (string) ( $rel['body'] ?? '' );
		// GitHub release notes are Markdown; show them as readable HTML in the popup.
		$changelog = $body ? wpautop( wp_kses_post( $body ) ) : esc_html__( 'See the GitHub release for details.', 'vaultly-backup' );
		return (object) array(
			'name'           => 'Vaultly Backup',
			'slug'           => self::SLUG,
			'version'        => $ver ?: VAULTLY_VERSION,
			'author'         => '<a href="https://github.com/' . self::REPO . '">Brandixsoft</a>',
			'homepage'       => 'https://github.com/' . self::REPO,
			'requires'       => '7.0',
			'tested'         => self::TESTED,
			'requires_php'   => '8.0',
			'last_updated'   => (string) ( $rel['published_at'] ?? '' ),
			'download_link'  => self::parse( $rel )[1],
			'sections'       => array(
				'description' => esc_html__( 'Fast, resumable full-site backup and restore (database + wp-content) with live progress, verify, scheduling, import-from-URL and an activity log.', 'vaultly-backup' ),
				'changelog'   => $changelog,
			),
		);
	}

	public static function show( $t ) {
		return self::check( $t, false );
	}

	public static function check( $t, bool $fetch = true ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}
		$rel = self::release( $fetch );
		list( $ver, $zip ) = self::parse( $rel );
		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => VAULTLY_BASENAME,
			'new_version'  => $ver ?: VAULTLY_VERSION,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $zip,
			'tested'       => self::TESTED,
			'requires_php' => '8.0',
			'icons'        => array( 'default' => plugins_url( 'assets/icon.svg', VAULTLY_FILE ) ),
		);
		if ( $ver && $zip && version_compare( $ver, VAULTLY_VERSION, '>' ) ) {
			$t->response[ VAULTLY_BASENAME ] = $item;
		} else {
			unset( $t->response[ VAULTLY_BASENAME ] );
			$t->no_update[ VAULTLY_BASENAME ] = $item; // makes "Enable auto-updates" show
		}
		return $t;
	}

	/**
	 * GitHub names the extracted folder "vaultly-backup-1.9.0" (or a commit hash); WordPress would then install it as a
	 * new plugin instead of updating in place. Rename the unpacked folder back to "vaultly-backup".
	 */
	public static function source( $source, $remote, $upgrader, $extra = array() ) {
		if ( ( $extra['plugin'] ?? '' ) !== VAULTLY_BASENAME && false === strpos( (string) $source, self::SLUG ) ) {
			return $source;
		}
		$want = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::SLUG;
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $want ), true ) ) {
			return trailingslashit( $want );
		}
		return $source;
	}
}
VAULTLY_Updater::init();
