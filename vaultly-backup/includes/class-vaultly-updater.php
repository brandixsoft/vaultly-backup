<?php
/**
 * GitHub updater. Ships ONLY in the GitHub release zip (WordPress.org forbids plugin updaters): delete this file for the WordPress.org build.
 * Release rule: tag vX.Y.Z and attach an asset named vaultly-backup.zip that contains the folder vaultly-backup/.
 */
defined( 'ABSPATH' ) || exit;

final class VAULTLY_Updater {
	const REPO = 'brandixsoft/vaultly-backup'; // set to the public GitHub repo, "user/repo"

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) ); // WP's own update check: fetch the release here
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'show' ) ); // every read: cached data only, so the "Enable auto-updates" link is always there
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 ); // "View details" popup for the GitHub version
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) ); // after any update: drop the cached release so the next check is fresh
	}

	/** Forget the cached GitHub release (called after updates; also runs on activation). */
	public static function flush(): void {
		delete_transient( 'vaultly_gh_release' );
	}

	/** Fill the plugin-details popup from the cached release instead of hitting WordPress.org (which has no such plugin). */
	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'vaultly-backup' ) {
			return $res;
		}
		$rel = get_transient( 'vaultly_gh_release' );
		$ver = is_array( $rel ) ? ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' ) : '';
		$zip = '';
		foreach ( is_array( $rel ) ? (array) ( $rel['assets'] ?? array() ) : array() as $a ) {
			if ( 'vaultly-backup.zip' === ( $a['name'] ?? '' ) ) {
				$zip = (string) $a['browser_download_url'];
			}
		}
		return (object) array(
			'name'          => 'Vaultly Backup',
			'slug'          => 'vaultly-backup',
			'version'       => $ver ?: VAULTLY_VERSION,
			'author'        => '<a href="https://github.com/' . self::REPO . '">Brandixsoft</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $zip,
			'sections'      => array( 'description' => (string) ( is_array( $rel ) ? ( $rel['body'] ?? '' ) : '' ) ),
		);
	}

	public static function show( $t ) {
		return self::check( $t, false );
	}

	public static function check( $t, bool $fetch = true ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}
		$rel = get_transient( 'vaultly_gh_release' );
		if ( false === $rel && ! $fetch ) {
			$rel = array();
		} elseif ( false === $rel ) {
			$r   = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
			$rel = ( 200 === wp_remote_retrieve_response_code( $r ) ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : array();
			$rel = is_array( $rel ) ? $rel : array();
			// Cache a good release for 6h; cache a failed/empty fetch for only 15 min, so a just-published release is picked up quickly instead of being hidden for 6 hours.
			set_transient( 'vaultly_gh_release', $rel, empty( $rel['tag_name'] ) ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		}
		$ver = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' );
		$zip = '';
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
			if ( 'vaultly-backup.zip' === ( $a['name'] ?? '' ) ) {
				$zip = (string) $a['browser_download_url'];
			}
		}
		$item = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => 'vaultly-backup',
			'plugin'      => VAULTLY_BASENAME,
			'new_version' => $ver ?: VAULTLY_VERSION,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $zip,
		);
		if ( $ver && $zip && version_compare( $ver, VAULTLY_VERSION, '>' ) ) {
			$t->response[ VAULTLY_BASENAME ] = $item;
		} else {
			unset( $t->response[ VAULTLY_BASENAME ] );
			$t->no_update[ VAULTLY_BASENAME ] = $item; // makes "Enable auto-updates" show
		}
		return $t;
	}
}
VAULTLY_Updater::init();
