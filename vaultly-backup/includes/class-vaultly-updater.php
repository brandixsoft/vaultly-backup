<?php
/**
 * GitHub updater. Ships ONLY in the GitHub release zip (WordPress.org forbids plugin updaters): delete this file for the WordPress.org build.
 * Release rule: tag vX.Y.Z and attach an asset named vaultly-backup.zip that contains the folder vaultly-backup/.
 */
defined( 'ABSPATH' ) || exit;

final class VAULTLY_Updater {
	const REPO = 'brandixsoft/vaultly-backup'; // set to the public GitHub repo, "user/repo"

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
	}

	public static function check( $t ) {
		if ( ! is_object( $t ) || empty( $t->checked ) ) {
			return $t;
		}
		$rel = get_transient( 'vaultly_gh_release' );
		if ( false === $rel ) {
			$r   = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
			$rel = ( 200 === wp_remote_retrieve_response_code( $r ) ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : array();
			set_transient( 'vaultly_gh_release', is_array( $rel ) ? $rel : array(), 6 * HOUR_IN_SECONDS );
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
			$t->no_update[ VAULTLY_BASENAME ] = $item; // makes "Enable auto-updates" show
		}
		return $t;
	}
}
VAULTLY_Updater::init();
