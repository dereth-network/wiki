<?php
// Router for PHP's built-in server, for local testing only:
//   php -d apc.enable_cli=1 -S 127.0.0.1:8099 -t <docroot> site/scripts/dev-router.php
// <docroot> holds favicon.ico and wiki/ (MediaWiki core). Mirrors the
// production web server rules: / -> /wiki/, real files are served as-is,
// everything else under /wiki/ goes to index.php as an article title.

$path = rawurldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$root = $_SERVER['DOCUMENT_ROOT'];

if ( $path === '/' ) {
	header( 'Location: /wiki/', true, 301 );
	return true;
}
if ( $path !== '/wiki/' && is_file( $root . $path ) ) {
	if ( str_ends_with( $path, '.php' ) ) {
		chdir( dirname( $root . $path ) );
		$_SERVER['SCRIPT_NAME'] = $path;
		$_SERVER['SCRIPT_FILENAME'] = $root . $path;
		require $root . $path;
		return true;
	}
	// Static file: let the built-in server send it.
	return false;
}
if ( str_starts_with( $path, '/wiki/' ) ) {
	chdir( "$root/wiki" );
	$_SERVER['SCRIPT_NAME'] = '/wiki/index.php';
	$_SERVER['SCRIPT_FILENAME'] = "$root/wiki/index.php";
	require "$root/wiki/index.php";
	return true;
}
http_response_code( 404 );
echo "Not found\n";
return true;
