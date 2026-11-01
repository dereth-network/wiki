<?php
# ACCPP Wiki -- MediaWiki 1.43 LTS, read-only public archive.
#
# Everything deployment-specific comes from environment variables (MW_*), so
# this file can live in git unchanged. Defaults are for the local upgrade/test
# setup in scripts/upgrade-local.sh. See UPGRADE-NOTES.md.

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

$env = static function ( string $name, ?string $default = null ): ?string {
	$v = getenv( $name );
	return ( $v === false || $v === '' ) ? $default : $v;
};

## Site identity
$wgSitename = $env( 'MW_SITENAME', 'ACCPP Wiki' );
$wgMetaNamespace = 'ACCPP_Wiki';

## URLs -- core lives at /wiki, articles at /wiki/Title (the web server must
## route /wiki/<anything that isn't a real file> to /wiki/index.php).
$wgServer = $env( 'MW_SERVER', 'http://127.0.0.1:8099' );
$wgCanonicalServer = $wgServer;
$wgScriptPath = '/wiki';
$wgArticlePath = '/wiki/$1';
$wgResourceBasePath = $wgScriptPath;
$wgForceHTTPS = $env( 'MW_FORCE_HTTPS', '0' ) === '1';

$wgLogos = [
	'1x' => "$wgResourceBasePath/resources/assets/ACC1x.png",
];
# Served from the web root, not under /wiki.
$wgFavicon = '/favicon.ico';

## Database
$wgDBtype = 'mysql';
$wgDBserver = $env( 'MW_DB_HOST', '127.0.0.1:33306' );
$wgDBname = $env( 'MW_DB_NAME', 'mediawiki' );
$wgDBuser = $env( 'MW_DB_USER', 'mediawikiadmin' );
$wgDBpassword = $env( 'MW_DB_PASS', 'accpprulez' );
$wgDBprefix = '';
$wgDBssl = false;
$wgDBTableOptions = 'ENGINE=InnoDB, DEFAULT CHARSET=binary';

## Secrets -- MW_SECRET_KEY, or a .secret_key file next to this one (gitignored).
$wgSecretKey = $env( 'MW_SECRET_KEY' )
	?? trim( (string)@file_get_contents( __DIR__ . '/.secret_key' ) );
if ( $wgSecretKey === '' ) {
	die( "Set MW_SECRET_KEY or create .secret_key (openssl rand -hex 32)\n" );
}
$wgAuthenticationTokenVersion = '1';

## Caching. APCu for small hot objects; the parser cache goes to the database
## so rendered pages survive PHP restarts (the wiki is read-only, so entries
## stay valid until a template changes).
$wgMainCacheType = CACHE_ACCEL;
$wgParserCacheType = CACHE_DB;
$wgParserCacheExpireTime = 7 * 86400;
$wgSessionCacheType = CACHE_DB;
$wgMemCachedServers = [];
$wgCacheDirectory = $env( 'MW_CACHE_DIR', "$IP/cache" );
$wgLocalisationCacheConf['store'] = 'files';

## Jobs run from cron / a worker (maintenance/run.php runJobs), never on page views.
$wgJobRunRate = 0;

## Uploads (sysops only -- see permissions below) and thumbnails
$wgEnableUploads = true;
$wgUploadDirectory = $env( 'MW_UPLOAD_DIR', "$IP/images" );
$wgUploadPath = "$wgScriptPath/images";
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = '/usr/bin/convert';
$wgSVGConverter = 'rsvg';
$wgSVGConverterPath = '/usr/bin';
$wgUseInstantCommons = false;

## Email is off until a real SMTP relay is configured; admins reset
## passwords with maintenance/run.php changePassword.
$wgEnableEmail = false;
$wgEnableUserEmail = false;
$wgEmergencyContact = '';
$wgPasswordSender = '';

## Misc
$wgLanguageCode = 'en';
$wgLocaltimezone = 'UTC';
$wgPingback = false;
$wgShowExceptionDetails = false;
$wgShowDBErrorBacktrace = false;
# Show every member on one category page (also keeps a static export simple).
$wgCategoryPagingLimit = 5000;

## License -- the wiki's own Project:Copyrights page states CC BY-SA 3.0
## (migrated from the GFDL in 2009; the merged asheron.fandom.com content is
## CC BY-SA as well). Game screenshots/icons are not covered by this.
$wgRightsPage = 'ACCPP_Wiki:Copyrights';
$wgRightsUrl = 'https://creativecommons.org/licenses/by-sa/3.0/';
$wgRightsText = 'Creative Commons Attribution-ShareAlike 3.0';
$wgRightsIcon = "$wgResourceBasePath/resources/assets/licenses/cc-by-sa.png";

## Read-only. Public visitors can only read. Setting MW_READ_ONLY (to a
## message shown to anyone who tries to edit) freezes the database entirely,
## sysops included.
$wgReadOnly = $env( 'MW_READ_ONLY' );

if ( $wgReadOnly !== null ) {
	# The public instance connects with a SELECT-only database user, so
	# nothing may try to write to the database: keep every cache in APCu
	# (lost on restart, which only costs a re-parse).
	$wgParserCacheType = CACHE_ACCEL;
	$wgSessionCacheType = CACHE_ACCEL;
	$wgMainStash = CACHE_ACCEL;
	# Let Cloudflare cache anonymous page views for a day (s-maxage). After
	# admin edits, purge the Cloudflare cache to publish them immediately.
	$wgUseCdn = true;
	$wgCdnMaxAge = 86400;
	# Read-only mode also freezes file storage, which stops thumbnails from
	# being saved ("Unable to save thumbnail to destination"). Declare the
	# local backend explicitly -- the same layout MediaWiki would build for
	# $wgLocalFileRepo -- with writes allowed. Uploads still fail, because
	# they need the (read-only) database.
	$wgFileBackends[] = [
		'name' => 'local-backend',
		'class' => FSFileBackend::class,
		'lockManager' => 'fsLockManager',
		'readOnly' => false,
		'containerPaths' => [
			'local-public' => $wgUploadDirectory,
			'local-thumb' => "$wgUploadDirectory/thumb",
			'local-transcoded' => "$wgUploadDirectory/transcoded",
			'local-deleted' => "$wgUploadDirectory/deleted",
			'local-temp' => "$wgUploadDirectory/temp",
		],
		'fileMode' => 0644,
		'directoryMode' => 0755,
	];
	# Nobody logs in here -- editing happens on the admin instance -- so drop
	# every way to log in or touch credentials, leaving nothing to
	# brute-force: the special pages, their API modules, and the skin links.
	$wgHooks['SpecialPage_initList'][] = static function ( array &$list ) {
		foreach ( [ 'Userlogin', 'Userlogout', 'CreateAccount', 'PasswordReset',
			'ChangeCredentials', 'RemoveCredentials', 'LinkAccounts',
			'UnlinkAccounts', 'BotPasswords' ] as $name
		) {
			unset( $list[$name] );
		}
	};
	foreach ( [ 'login', 'clientlogin', 'logout', 'createaccount', 'linkaccount',
		'unlinkaccount', 'changeauthenticationdata', 'removeauthenticationdata',
		'resetpassword' ] as $module
	) {
		$wgAPIModules[$module] = 'ApiDisabled';
	}
	$wgHooks['SkinTemplateNavigation::Universal'][] = static function ( $skin, array &$links ) {
		foreach ( [ 'login', 'login-private', 'anonlogin', 'createaccount' ] as $key ) {
			unset( $links['user-menu'][$key] );
		}
	};
}
# TLS ends at Cloudflare, so requests reach Apache as plain HTTP.
$wgCookieSecure = str_starts_with( $wgServer, 'https://' );

$wgGroupPermissions['*']['read'] = true;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['*']['createtalk'] = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['*']['autocreateaccount'] = false;
foreach ( [ 'edit', 'createpage', 'createtalk', 'upload', 'reupload', 'reupload-shared',
	'move', 'move-subpages', 'move-categorypages', 'movefile', 'minoredit',
	'editmyusercss', 'editmyuserjs', 'editmyuserjson', 'sendemail',
	'applychangetags', 'changetags', 'purge' ] as $right
) {
	$wgGroupPermissions['user'][$right] = false;
}
$wgGroupPermissions['sysop']['edit'] = true;
$wgGroupPermissions['sysop']['createpage'] = true;
$wgGroupPermissions['sysop']['upload'] = true;
$wgGroupPermissions['sysop']['reupload'] = true;
$wgGroupPermissions['sysop']['move'] = true;
$wgGroupPermissions['sysop']['movefile'] = true;
$wgGroupPermissions['sysop']['purge'] = true;

## Skins -- legacy Vector stays the default: MediaWiki:Common.css,
## MediaWiki:Common.js and MenuSidebar's flyouts are written against it.
wfLoadSkin( 'Vector' );
wfLoadSkin( 'MonoBook' );
wfLoadSkin( 'Timeless' );
wfLoadSkin( 'MinervaNeue' );
# Citizen: modern layout with built-in light/dark/auto themes. Preview any
# page with ?useskin=citizen; make it the default once templates look right
# in both themes. (It switches the DarkMode extension off for itself.)
wfLoadSkin( 'Citizen' );
$wgDefaultSkin = 'vector';
# Citizen-only fixes for this wiki's templates: dark-mode colours, wide
# tables (extensions-custom/DerethTheme).
wfLoadExtension( 'DerethTheme' );

## Extensions
wfLoadExtension( 'Cite' );
wfLoadExtension( 'ParserFunctions' );
# #pos/#sub/#replace/#len/#explode -- Quest Summary, Dialog, Documentation,
# Noredirect and Number depend on these.
$wgPFEnableStringFunctions = true;
wfLoadExtension( 'InputBox' );
wfLoadExtension( 'LabeledSectionTransclusion' );
wfLoadExtension( 'Variables' );
wfLoadExtension( 'DarkMode' );
# Local extensions (extensions-custom/ in this repo)
wfLoadExtension( 'YouTubeTag' );
wfLoadExtension( 'MenuSidebar' );
$wgParseListItems = true;
