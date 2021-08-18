<?php

/**
 * Checks if requirements are available on you machine
 * - phpunit
 * - svn
 */
function check_requirements() {
	//1. test for svn command
	exec( 'svn --version', $out1 );
	if ( empty( $out1 ) ) {
		exit( 'FATAL: svn command not found. Add svn to your PATH. Windows: See SO: http://stackoverflow.com/a/9874961/2388747' );
	}
	//2. test phpunit command
	exec( 'phpunit --version', $out2 );
	if ( empty( $out2 ) ) {
		exit( 'FATAL: phpunit command not found. How to set it up: https://phpunit.de/manual/current/en/installation.html' );
	}
}

check_requirements();

/**
 * Downloads a file from $url and saves it at $path
 *
 * @param string $url  to source
 * @param string $path to destination
 */
function download( $url, $path ) {

	$out = fopen( $path, 'w+' );

	$ch = curl_init( str_replace( ' ', '%20', $url ) );

	curl_setopt( $ch, CURLOPT_TIMEOUT, 50 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_FILE, $out );

	curl_setopt(
		$ch,
		CURLOPT_HTTPHEADER,
		array(
			'user-agent: my user agent',
		)
	);

	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

	curl_exec( $ch );//get curl response

	curl_close( $ch );
	fclose( $out );
}

/**
 * Unzip a .zip file
 *
 * @param string $path to zip file
 */
function unzip( $path ) {
	$zip = new ZipArchive();
	if ( ! $zip->open( $path ) ) {
		exit( 'FATAL: could not open: ' . $path );
	}
	if ( ! $zip->extractTo( dirname( $path ) ) ) {
		exit( 'FATAL: could not extract: ' . $path );
	}
	$zip->close();
}

echo "\nLets start installing the WordPress testing suite\n";

/**
 * Install WordPress testing suite for a plugin
 */
$default_tmp_dir = sys_get_temp_dir();
echo "\nInput temp directory or press ENTER for default (Default: {$default_tmp_dir}) : ";

$handle = fopen( 'php://stdin', 'r' );
$line   = trim( fgets( $handle ) );

//read temp directory from keyboard
$tmp_dir = $line ? $line : $default_tmp_dir;
while ( ! is_dir( $tmp_dir ) ) {
	echo "\n{$tmp_dir} Not a directory\n\nInput temp directory or press ENTER for default (Default: {$default_tmp_dir}) : ";
	$line = trim( fgets( $handle ) );

	$tmp_dir = $line ? $line : $default_tmp_dir;
}
$tmp_dir = rtrim( $tmp_dir, '\\/' );

//read WordPress version from keyboard
$default_wp_version = 'latest';
echo "\nType in the WordPress version to install: (Default: {$default_wp_version}): ";
$line       = trim( fgets( $handle ) );
$wp_version = $line ? $line : $default_wp_version;

//fetch the last version of WP
if ( 'latest' === $wp_version ) {
	$data = file_get_contents( 'http://api.wordpress.org/core/version-check/1.7/' );
	if ( ! empty( $data ) ) {
		$data = json_decode( $data, true );
	}
	if ( ! empty( $data['offers'][0]['current'] ) ) {
		$wp_version = $data['offers'][0]['current'];
	}
}

//read db username from keyboard
$default_db_user = 'root';
echo "\nType int the MySQL username: (Default: {$default_db_user}): ";
$line     = trim( fgets( $handle ) );
$username = $line ? $line : $default_db_user;

//read password from keyboard
echo "\nType int the MySQL password: ";
$line     = trim( fgets( $handle ) );
$password = $line ? $line : '';

//read db name from keyboard
$default_db_name = 'wordpress_tests';
echo "\nType in the database name you want to create, it will be dropped before creating it: (Default: ' . $default_db_name . '): ";
$line = trim( fgets( $handle ) );
$db   = $line ? $line : $default_db_name;

$wp_core_dir = $tmp_dir . '/wordpress';
if ( ! is_dir( $wp_core_dir ) && ! mkdir( $wp_core_dir, 0777, true ) ) {
	exit( "\nERROR: cannot create " . $wp_core_dir );
}

//define and check for folder where the wp tests suit are gonna be installed
$wp_tests_dir = $tmp_dir . '/wordpress-tests-lib';
if ( ! is_dir( $wp_tests_dir ) && ! mkdir( $wp_tests_dir, 0777, true ) ) {
	exit( "\nERROR: cannot create " . $wp_tests_dir );
}

//##### 1. INSTALL WordPress #####

$wp_url = "https://wordpress.org/wordpress-{$wp_version}.zip";
echo "\nDownloading WordPress... ";
download( $wp_url, $tmp_dir . '/' . basename( $wp_url ) );
echo "Done.\nExtracting WordPress... ";
unzip( $tmp_dir . '/' . basename( $wp_url ) );
echo "Done.";
/* install DB */
download( 'https://raw.github.com/markoheijnen/wp-mysqli/master/db.php', "{$wp_core_dir}/wp-content/db.php" );

//##### 2. Download WordPress tests suite #####

$wp_tests_tag = 'latest' === $wp_version ? 'trunk' : "tags/{$wp_version}";
echo "\nChecking for test suite... ";
if ( ! is_dir( $wp_tests_dir . '/includes' ) ) {
	exec( "svn co --quiet https://develop.svn.wordpress.org/{$wp_tests_tag}/tests/phpunit/includes/ {$wp_tests_dir}/includes" );
}
echo 'Done.';

//download and update the wp config file
if ( ! is_file( $wp_tests_dir . '/wp-tests-config.php' ) ) {
	download( "https://develop.svn.wordpress.org/{$wp_tests_tag}/wp-tests-config-sample.php", "{$wp_tests_dir}/wp-tests-config.php" );
	// modify the config file to include db credentials
	$contents = file_get_contents( $wp_tests_dir . '/wp-tests-config.php' );

	$contents = str_replace(
		array(
			'youremptytestdbnamehere',
			'yourusernamehere',
			'yourpasswordhere',
			"dirname( __FILE__ ) . '/src/'",
		),
		array(
			$db,
			$username,
			$password,
			"dirname( dirname( __FILE__ ) ) . '/wordpress/'",
		),
		$contents
	);
	file_put_contents( $wp_tests_dir . '/wp-tests-config.php', $contents );
}

//##### 3. Install DB #####

echo "\nCreating database...";
/* step 3 - drop and re-create the tests table */
$driver = new mysqli( 'localhost', $username, $password );
if ( $driver->connect_errno ) {
	exit( "\nCould not connect to the MySQL server." );
}
$driver->query( "DROP DATABASE IF EXISTS `{$db}`" );
if ( ! $driver->query( "CREATE DATABASE `{$db}`" ) ) {
	exit( "\nError: could not create test database " . $db );
}
echo 'Done.';
echo "\n\nYour phpunit setup has been completed with success!\n\n";

fclose( $handle );
