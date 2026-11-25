<?php
/**
 * Plugin Name: NG Cloud Offloader
 * Description: Offloads Media Library files to Cloudflare R2, Azure Blob Storage or any S3-compatible storage (AWS S3, Wasabi, Backblaze B2, DigitalOcean Spaces). Verified upload before any local delete, one-click restore. Part of the NG WordPress Suite.
 * Version: 0.1.0
 * Author: Nada Gamal
 * License: GPL-2.0-or-later
 * Text Domain: ng-co
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/class-providers.php';

final class NGCO {
	const OPT = 'ngco_settings';
	const META = '_ngco';

	static function defaults() {
		return array( 'provider' => 's3', 'endpoint' => '', 'region' => 'auto', 'bucket' => '', 'account' => '', 'public_base' => '', 'prefix' => '', 'path_style' => 1, 'rewrite' => 1, 'delete_local' => 0, 'auto' => 1, 'key_enc' => '', 'secret_enc' => '' );
	}
	static function s() { return wp_parse_args( get_option( self::OPT, array() ), self::defaults() ); }

	/* credentials: wp-config constants win, else AES-256-GCM encrypted option */
	static function enc( $plain ) {
		$k = hash( 'sha256', wp_salt( 'auth' ), true ); $iv = random_bytes( 12 ); $tag = '';
		$c = openssl_encrypt( $plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $c );
	}
	static function dec( $b ) {
		if ( ! $b ) { return ''; }
		$raw = base64_decode( $b ); $k = hash( 'sha256', wp_salt( 'auth' ), true );
		$p = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $p ? '' : $p;
	}
	static function creds() {
		$s = self::s();
		return array(
			defined( 'NGCO_KEY' ) ? NGCO_KEY : self::dec( $s['key_enc'] ),
			defined( 'NGCO_SECRET' ) ? NGCO_SECRET : self::dec( $s['secret_enc'] ),
		);
	}
	static function provider() { list( $k, $x ) = self::creds(); return NGCO_Providers::make( self::s(), $k, $x ); }

	static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_meta' ), 30, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite' ), 30, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'rewrite_srcset' ), 30, 5 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'rewrite_src' ), 30, 4 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'save', 'test', 'bulk', 'restore' ) as $a ) { add_action( 'admin_post_ngco_' . $a, array( __CLASS__, $a ) ); }
		add_filter( 'gettext_ng-co', function ( $t ) { $m = self::T(); $l = substr( apply_filters( 'ngco_locale', determine_locale() ), 0, 2 ); return isset( $m[ $l ][ $t ] ) ? $m[ $l ][ $t ] : $t; } );
	}

	/* keys of an attachment: relative upload path of the file and its sizes (originals + any NG optimizer .webp/.avif) */
	static function rel_files( $id ) {
		$file = get_attached_file( $id ); if ( ! $file ) { return array(); }
		$up = wp_get_upload_dir(); $base = trailingslashit( $up['basedir'] );
		$list = array( $file );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'] ) ) { foreach ( $meta['sizes'] as $z ) { $list[] = dirname( $file ) . '/' . $z['file']; } }
		if ( ! empty( $meta['original_image'] ) ) { $list[] = dirname( $file ) . '/' . $meta['original_image']; }
		$more = array();
		foreach ( $list as $f ) { foreach ( array( 'webp', 'avif' ) as $x ) { if ( file_exists( "$f.$x" ) ) { $more[] = "$f.$x"; } } }
		$out = array();
		foreach ( array_unique( array_merge( $list, $more ) ) as $f ) { if ( file_exists( $f ) && 0 === strpos( $f, $base ) ) { $out[ substr( $f, strlen( $base ) ) ] = $f; } }
		return $out;
	}
	static function key( $rel ) { $p = trim( self::s()['prefix'], '/' ); return ( $p ? $p . '/' : '' ) . $rel; }

	/* offload with verification. Returns array( ok, message ) */
	static function offload( $id ) {
		$p = self::provider(); if ( is_wp_error( $p ) ) { return array( false, $p->get_error_message() ); }
		$s = self::s(); $done = array(); $fail = array();
		foreach ( self::rel_files( $id ) as $rel => $path ) {
			$key = self::key( $rel ); $size = filesize( $path );
			$r = $p->put( $key, $path, wp_check_filetype( basename( $path ) )['type'] ?: 'application/octet-stream' );
			if ( is_wp_error( $r ) ) { $fail[] = $rel . ': ' . $r->get_error_message(); continue; }
			$v = $p->head( $key );                              // verification: object exists with identical size
			if ( is_wp_error( $v ) || (int) $v !== (int) $size ) { $fail[] = $rel . ': verification failed'; continue; }
			$done[ $rel ] = $size;
		}
		if ( $fail ) { update_post_meta( $id, self::META, array( 'state' => 'partial', 'files' => $done, 'errors' => $fail, 'time' => time() ) ); return array( false, implode( '; ', $fail ) ); }
		$local_deleted = false;
		update_post_meta( $id, self::META, array( 'state' => 'offloaded', 'files' => $done, 'local_deleted' => false, 'time' => time() ) );
		if ( $s['delete_local'] ) {                              // only after EVERY file verified
			foreach ( self::rel_files( $id ) as $rel => $path ) { @unlink( $path ); }
			update_post_meta( $id, self::META, array( 'state' => 'offloaded', 'files' => $done, 'local_deleted' => true, 'time' => time() ) );
			$local_deleted = true;
		}
		return array( true, count( $done ) . ' files' . ( $local_deleted ? ', local copies removed' : '' ) );
	}
	static function on_meta( $meta, $id ) {
		$s = self::s();
		if ( $s['auto'] && $s['bucket'] ) { wp_schedule_single_event( time() + 5, 'ngco_offload_one', array( $id ) ); self::offload_now_if_cli( $id ); }
		return $meta;
	}
	static function offload_now_if_cli( $id ) { if ( apply_filters( 'ngco_offload_inline', true ) ) { self::offload( $id ); } }

	/* restore: download any missing local file, then forget the offload */
	static function restore_one( $id ) {
		$m = get_post_meta( $id, self::META, true ); if ( empty( $m['files'] ) ) { return array( false, 'not offloaded' ); }
		$p = self::provider(); if ( is_wp_error( $p ) ) { return array( false, $p->get_error_message() ); }
		$up = wp_get_upload_dir(); $n = 0;
		foreach ( $m['files'] as $rel => $size ) {
			$dst = $up['basedir'] . '/' . $rel;
			if ( file_exists( $dst ) && filesize( $dst ) == $size ) { continue; }
			wp_mkdir_p( dirname( $dst ) );
			$r = $p->get( self::key( $rel ), $dst );
			if ( is_wp_error( $r ) || ! file_exists( $dst ) || filesize( $dst ) != $size ) { return array( false, 'restore failed for ' . $rel ); }
			$n++;
		}
		$m['state'] = 'restored'; $m['local_deleted'] = false; update_post_meta( $id, self::META, $m ); // remote copies stay (removed if the attachment is deleted)
		return array( true, $n . ' files restored' );
	}
	static function on_delete( $id ) {
		$m = get_post_meta( $id, self::META, true ); if ( empty( $m['files'] ) ) { return; }
		$p = self::provider(); if ( is_wp_error( $p ) ) { return; }
		foreach ( $m['files'] as $rel => $size ) { $p->delete( self::key( $rel ) ); }
	}

	/* URL rewrite */
	static function base() {
		$s = self::s(); if ( $s['public_base'] ) { return untrailingslashit( $s['public_base'] ); }
		return '';
	}
	static function to_cloud( $url ) {
		$s = self::s(); $b = self::base(); if ( ! $s['rewrite'] || ! $b ) { return $url; }
		$up = wp_get_upload_dir(); if ( 0 !== strpos( $url, $up['baseurl'] ) ) { return $url; }
		$rel = ltrim( substr( $url, strlen( $up['baseurl'] ) ), '/' );
		$p = trim( $s['prefix'], '/' );
		return $b . '/' . ( $p ? $p . '/' : '' ) . $rel;
	}
	static function is_off( $id ) { $m = get_post_meta( $id, self::META, true ); return ! empty( $m['state'] ) && 'offloaded' === $m['state']; }
	static function rewrite( $url, $id ) { return ( ! is_admin() || wp_doing_ajax() || true ) && self::is_off( $id ) ? self::to_cloud( $url ) : $url; }
	static function rewrite_src( $img, $id ) { if ( $img && self::is_off( $id ) ) { $img[0] = self::to_cloud( $img[0] ); } return $img; }
	static function rewrite_srcset( $sources, $sz, $src, $meta, $id ) {
		if ( ! is_array( $sources ) || ! self::is_off( $id ) ) { return $sources; }
		foreach ( $sources as $k => $v ) { $sources[ $k ]['url'] = self::to_cloud( $v['url'] ); }
		return $sources;
	}

	/* admin */
	static function T() { return array(
		'ar' => array( 'Cloud Offloader' => 'نقل الملفات للسحابة', 'Save settings' => 'حفظ الإعدادات', 'Test connection' => 'اختبار الاتصال', 'Offload existing media' => 'نقل الوسائط الحالية', 'Restore all to this server' => 'استعادة الكل إلى هذا السيرفر', 'Provider' => 'المزوّد', 'Bucket / container' => 'الحاوية', 'Public base URL' => 'الرابط العام', 'Delete local copies after verified upload' => 'حذف النسخ المحلية بعد التحقق من الرفع' ),
		'fr' => array( 'Cloud Offloader' => 'Déport cloud', 'Save settings' => 'Enregistrer', 'Test connection' => 'Tester la connexion', 'Offload existing media' => 'Déporter les médias existants', 'Restore all to this server' => 'Tout restaurer sur ce serveur', 'Provider' => 'Fournisseur', 'Bucket / container' => 'Bucket / conteneur', 'Public base URL' => 'URL publique', 'Delete local copies after verified upload' => 'Supprimer les copies locales après vérification' ),
		'de' => array( 'Cloud Offloader' => 'Cloud-Auslagerung', 'Save settings' => 'Einstellungen speichern', 'Test connection' => 'Verbindung testen', 'Offload existing media' => 'Vorhandene Medien auslagern', 'Restore all to this server' => 'Alles auf diesen Server zurückholen', 'Provider' => 'Anbieter', 'Bucket / container' => 'Bucket / Container', 'Public base URL' => 'Öffentliche Basis-URL', 'Delete local copies after verified upload' => 'Lokale Kopien nach verifiziertem Upload löschen' ),
	); }
	static function menu() { add_media_page( 'NG Cloud Offloader', __( 'Cloud Offloader', 'ng-co' ), 'manage_options', 'ngco', array( __CLASS__, 'page' ) ); }
	static function field( $label, $name, $val, $type = 'text', $hint = '' ) {
		printf( '<tr><th>%s</th><td><input type="%s" class="regular-text" name="%s" value="%s" autocomplete="off">%s</td></tr>', esc_html( $label ), esc_attr( $type ), esc_attr( $name ), esc_attr( $val ), $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' );
	}
	static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::s(); $act = admin_url( 'admin-post.php' ); $rtl = is_rtl() ? ' dir="rtl"' : '';
		echo '<div class="wrap"' . $rtl . '><h1>NG ' . esc_html__( 'Cloud Offloader', 'ng-co' ) . '</h1>';
		if ( isset( $_GET['msg'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['msg'] ) ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngco' ); echo '<input type="hidden" name="action" value="ngco_save"><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Provider', 'ng-co' ) . '</th><td><select name="provider">';
		foreach ( NGCO_Providers::labels() as $k => $l ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['provider'], $k, false ), esc_html( $l ) ); }
		echo '</select></td></tr>';
		self::field( 'Endpoint', 'endpoint', $s['endpoint'], 'text', 'S3-compatible: https://s3.eu-central-1.wasabisys.com. R2: https://ACCOUNT_ID.r2.cloudflarestorage.com. Leave empty for AWS S3.' );
		self::field( 'Region', 'region', $s['region'], 'text', 'R2: auto. AWS: e.g. eu-west-1.' );
		self::field( 'Azure account name', 'account', $s['account'] );
		self::field( __( 'Bucket / container', 'ng-co' ), 'bucket', $s['bucket'] );
		self::field( __( 'Public base URL', 'ng-co' ), 'public_base', $s['public_base'], 'text', 'Where visitors load files from (CDN or public bucket URL).' );
		self::field( 'Path prefix', 'prefix', $s['prefix'] );
		if ( defined( 'NGCO_KEY' ) && defined( 'NGCO_SECRET' ) ) { echo '<tr><th>Credentials</th><td>Read from wp-config.php constants NGCO_KEY / NGCO_SECRET.</td></tr>'; }
		else { self::field( 'Access key / Azure account', 'key', '', 'text', $s['key_enc'] ? 'Saved (encrypted). Leave empty to keep.' : '' ); self::field( 'Secret key / Azure account key', 'secret', '', 'password', $s['secret_enc'] ? 'Saved (encrypted). Leave empty to keep.' : 'Prefer defining NGCO_KEY and NGCO_SECRET in wp-config.php.' ); }
		foreach ( array( 'rewrite' => 'Rewrite media URLs to the cloud', 'auto' => 'Offload new uploads automatically', 'delete_local' => __( 'Delete local copies after verified upload', 'ng-co' ) ) as $k => $l ) { printf( '<tr><th>%s</th><td><input type="checkbox" name="%s" value="1" %s></td></tr>', esc_html( $l ), esc_attr( $k ), checked( $s[ $k ], 1, false ) ); }
		echo '</table>'; submit_button( __( 'Save settings', 'ng-co' ) ); echo '</form>';
		foreach ( array( 'test' => 'Test connection', 'bulk' => 'Offload existing media', 'restore' => 'Restore all to this server' ) as $a => $l ) {
			echo '<form method="post" style="display:inline-block;margin-inline-end:8px" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngco' );
			echo '<input type="hidden" name="action" value="ngco_' . esc_attr( $a ) . '">'; submit_button( __( $l, 'ng-co' ), 'primary' === 'x' ? '' : 'secondary', '', false ); echo '</form>';
		}
		echo '</div>';
	}
	static function guard() { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); } check_admin_referer( 'ngco' ); }
	static function back( $msg ) { wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $msg ), admin_url( 'upload.php?page=ngco' ) ) ); exit; }
	static function save() {
		self::guard(); $s = self::s(); $in = wp_unslash( $_POST );
		$prov = isset( $in['provider'] ) && isset( NGCO_Providers::labels()[ $in['provider'] ] ) ? $in['provider'] : 's3';
		$new = array( 'provider' => $prov, 'endpoint' => esc_url_raw( $in['endpoint'] ?? '' ), 'region' => sanitize_text_field( $in['region'] ?? 'auto' ), 'bucket' => sanitize_text_field( $in['bucket'] ?? '' ), 'account' => sanitize_text_field( $in['account'] ?? '' ), 'public_base' => esc_url_raw( $in['public_base'] ?? '' ), 'prefix' => trim( sanitize_text_field( $in['prefix'] ?? '' ), '/' ), 'path_style' => 1, 'rewrite' => empty( $in['rewrite'] ) ? 0 : 1, 'delete_local' => empty( $in['delete_local'] ) ? 0 : 1, 'auto' => empty( $in['auto'] ) ? 0 : 1, 'key_enc' => $s['key_enc'], 'secret_enc' => $s['secret_enc'] );
		if ( ! empty( $in['key'] ) ) { $new['key_enc'] = self::enc( trim( $in['key'] ) ); }
		if ( ! empty( $in['secret'] ) ) { $new['secret_enc'] = self::enc( trim( $in['secret'] ) ); }
		update_option( self::OPT, $new, false ); self::back( 'Saved' );
	}
	static function test() {
		self::guard(); $p = self::provider(); if ( is_wp_error( $p ) ) { self::back( $p->get_error_message() ); }
		$tmp = wp_tempnam( 'ngco' ); file_put_contents( $tmp, 'ngco-test-' . time() ); $k = self::key( '.ngco-connection-test.txt' );
		$r = $p->put( $k, $tmp, 'text/plain' ); $v = is_wp_error( $r ) ? $r : $p->head( $k ); @unlink( $tmp );
		if ( ! is_wp_error( $v ) ) { $p->delete( $k ); }
		self::back( is_wp_error( $v ) ? 'Failed: ' . $v->get_error_message() : 'Connection OK (upload, verify and delete worked)' );
	}
	static function bulk() {
		self::guard(); @set_time_limit( 120 ); $ok = 0; $bad = 0;
		foreach ( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 100, 'fields' => 'ids', 'post_status' => 'inherit', 'meta_query' => array( array( 'key' => self::META, 'compare' => 'NOT EXISTS' ) ) ) ) as $id ) { $r = self::offload( $id ); $r[0] ? $ok++ : $bad++; }
		self::back( "Offloaded: $ok, failed: $bad" );
	}
	static function restore() {
		self::guard(); @set_time_limit( 120 ); $ok = 0; $bad = 0;
		foreach ( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'inherit', 'meta_key' => self::META ) ) as $id ) { if ( ! self::is_off( $id ) ) { continue; } $r = self::restore_one( $id ); $r[0] ? $ok++ : $bad++; }
		self::back( "Restored: $ok, failed: $bad" );
	}
}
NGCO::init();
