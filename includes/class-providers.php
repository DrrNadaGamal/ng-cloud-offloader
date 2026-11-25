<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class NGCO_Provider {
	protected $s; protected $k; protected $x;
	function __construct( $s, $k, $x ) { $this->s = $s; $this->k = $k; $this->x = $x; }
	protected function send( $method, $url, $headers, $body = null, $file = null ) {
		$args = array( 'method' => $method, 'headers' => $headers, 'timeout' => 60, 'redirection' => 0, 'sslverify' => true );
		if ( null !== $body ) { $args['body'] = $body; }
		if ( $file ) { $args['stream'] = true; $args['filename'] = $file; }
		$r = wp_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) { return $r; }
		$c = wp_remote_retrieve_response_code( $r );
		if ( $c < 200 || $c >= 300 ) { return new WP_Error( 'ngco_http', "HTTP $c " . substr( wp_strip_all_tags( wp_remote_retrieve_body( $r ) ), 0, 160 ) ); }
		return $r;
	}
	abstract function put( $key, $path, $mime );
	abstract function head( $key );     // returns int size or WP_Error
	abstract function get( $key, $dst );
	abstract function delete( $key );
}

class NGCO_S3 extends NGCO_Provider {
	function host_url( $key ) {
		$ep = $this->s['endpoint'] ? untrailingslashit( $this->s['endpoint'] ) : 'https://s3.' . $this->s['region'] . '.amazonaws.com';
		$u = wp_parse_url( $ep ); $port = isset( $u['port'] ) ? ':' . $u['port'] : '';
		$path = '/' . rawurlencode( $this->s['bucket'] ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		return array( $u['scheme'] . '://' . $u['host'] . $port . $path, $u['host'] . $port, $path );
	}
	static function sign( $method, $host, $path, $payload_hash, $region, $ak, $sk, $amzdate, $extra = array() ) {
		$date = substr( $amzdate, 0, 8 );
		$h = array_merge( array( 'host' => $host, 'x-amz-content-sha256' => $payload_hash, 'x-amz-date' => $amzdate ), $extra ); ksort( $h );
		$canon_h = ''; foreach ( $h as $k => $v ) { $canon_h .= strtolower( $k ) . ':' . trim( $v ) . "\n"; }
		$signed = implode( ';', array_map( 'strtolower', array_keys( $h ) ) );
		$creq = "$method\n$path\n\n$canon_h\n$signed\n$payload_hash";
		$scope = "$date/$region/s3/aws4_request";
		$sts = "AWS4-HMAC-SHA256\n$amzdate\n$scope\n" . hash( 'sha256', $creq );
		$k = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 's3', hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $date, 'AWS4' . $sk, true ), true ), true ), true );
		return array( 'Authorization' => "AWS4-HMAC-SHA256 Credential=$ak/$scope, SignedHeaders=$signed, Signature=" . hash_hmac( 'sha256', $sts, $k ), 'x-amz-date' => $amzdate, 'x-amz-content-sha256' => $payload_hash );
	}
	protected function req( $method, $key, $body = '', $mime = null, $file = null ) {
		if ( ! $this->k || ! $this->x || ! $this->s['bucket'] ) { return new WP_Error( 'ngco_cfg', 'Missing credentials or bucket' ); }
		list( $url, $host, $path ) = $this->host_url( $key );
		$hash = ( $file && 'PUT' === $method ) ? hash_file( 'sha256', $file ) : hash( 'sha256', $body );
		$region = $this->s['region'] ?: 'auto';
		$hdr = self::sign( $method, $host, $path, $hash, $region, $this->k, $this->x, gmdate( 'Ymd\THis\Z' ) );
		if ( $mime ) { $hdr['Content-Type'] = $mime; }
		if ( 'PUT' === $method && $file ) { return $this->send( 'PUT', $url, $hdr, file_get_contents( $file ) ); }
		return $this->send( $method, $url, $hdr, '' === $body ? null : $body, 'GET' === $method ? $file : null );
	}
	function put( $key, $path, $mime ) { return $this->req( 'PUT', $key, '', $mime, $path ); }
	function head( $key ) { $r = $this->req( 'HEAD', $key ); return is_wp_error( $r ) ? $r : (int) wp_remote_retrieve_header( $r, 'content-length' ); }
	function get( $key, $dst ) { return $this->req( 'GET', $key, '', null, $dst ); }
	function delete( $key ) { return $this->req( 'DELETE', $key ); }
}

class NGCO_Azure extends NGCO_Provider {
	protected function url( $key ) {
		$acct = $this->s['account'] ?: $this->k;
		$base = $this->s['endpoint'] ? untrailingslashit( $this->s['endpoint'] ) : 'https://' . $acct . '.blob.core.windows.net';
		return array( $base . '/' . rawurlencode( $this->s['bucket'] ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) ), $acct );
	}
	static function sign( $method, $acct, $path, $len, $ctype, $xms, $account_key ) {
		ksort( $xms ); $ch = ''; foreach ( $xms as $k => $v ) { $ch .= strtolower( $k ) . ':' . $v . "\n"; }
		$cl = ( 'PUT' === $method || $len > 0 ) ? (string) $len : '';
		$sts = "$method\n\n\n$cl\n\n$ctype\n\n\n\n\n\n\n" . $ch . "/$acct" . $path;
		return base64_encode( hash_hmac( 'sha256', $sts, base64_decode( $account_key ), true ) );
	}
	protected function req( $method, $key, $body = '', $mime = '', $file = null ) {
		if ( ! $this->k || ! $this->x || ! $this->s['bucket'] ) { return new WP_Error( 'ngco_cfg', 'Missing credentials or container' ); }
		list( $url, $acct ) = $this->url( $key );
		$path = wp_parse_url( $url, PHP_URL_PATH ); $path = rawurldecode( $path );
		$xms = array( 'x-ms-date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT', 'x-ms-version' => '2021-08-06' );
		if ( 'PUT' === $method ) { $xms['x-ms-blob-type'] = 'BlockBlob'; }
		$data = ( 'PUT' === $method && $file ) ? file_get_contents( $file ) : $body;
		$len = strlen( $data );
		$sig = self::sign( $method, $acct, $path, $len, $mime, $xms, $this->x );
		$hdr = $xms; $hdr['Authorization'] = 'SharedKey ' . $acct . ':' . $sig; if ( $mime ) { $hdr['Content-Type'] = $mime; }
		return $this->send( $method, $url, $hdr, 'PUT' === $method ? $data : null, 'GET' === $method ? $file : null );
	}
	function put( $key, $path, $mime ) { return $this->req( 'PUT', $key, '', $mime, $path ); }
	function head( $key ) { $r = $this->req( 'HEAD', $key ); return is_wp_error( $r ) ? $r : (int) wp_remote_retrieve_header( $r, 'content-length' ); }
	function get( $key, $dst ) { return $this->req( 'GET', $key, '', '', $dst ); }
	function delete( $key ) { return $this->req( 'DELETE', $key ); }
}

class NGCO_Providers {
	static function labels() { return array( 's3' => 'S3-compatible (AWS S3, Wasabi, Backblaze B2, DigitalOcean Spaces)', 'r2' => 'Cloudflare R2', 'azure' => 'Azure Blob Storage' ); }
	static function make( $s, $k, $x ) {
		if ( 'azure' === $s['provider'] ) { return new NGCO_Azure( $s, $k, $x ); }
		if ( 'r2' === $s['provider'] ) { $s['region'] = 'auto'; }
		return new NGCO_S3( $s, $k, $x );
	}
}
