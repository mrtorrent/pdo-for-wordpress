<?php
/*
Plugin Name: PDO For Wordpress
Plugin URI: rathercurious.net
Version: 2.7.0
Author: Justin Adie
Author URI: rathercurious.net
Description: This 'plugin' enables WP to use databases supported by PHP's PDO abstraction layer. Currently, mysql and sqlite drivers are provided.
*/

/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
/**
 *	db.php in this location, prevents wp-db.php from being loaded.  I use it to perform the "Pre-Flight Checks"
 * 
 *	this file provides some pre-validation function to ensure that 
 *	the user is using a version of php that is compatible with the
 *	pdo abstraction layers and has the right database extensions 
 *	available to him
 *	this file also has some helper functions for versions of wordpress < 2.4
 *	that dummy a call to mysql_server_info in the installation and upgrade processes
 *	finally, this file kicks starts the db abstraction layers
 */

function pdo_log_error($code, $message, $data = NULL){
	/*
	//	can we use the new WP_ERROR object?  it does not seem to bail out gracefuly on its own
	//
	if (class_exists(WP_ERROR)){
		$r = NEW WP_ERROR ($code, $message, $data);
	} else {
		*/
		header('Content-Type: text/html; charset=utf-8');

		if (strpos($_SERVER['PHP_SELF'], 'wp-admin') !== false){
			$admin_dir = '';
		}else{
			$admin_dir = 'wp-admin/';
		}
		die (<<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<title>WordPress &rsaquo; Error</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<link rel="stylesheet" href="{$admin_dir}install.css" type="text/css" />
</head>
<body>
	<h1 id="logo"><img alt="WordPress" src="{$admin_dir}images/wordpress-logo.png" /></h1>
	<h1>$code</h1>
	<p>$message</p>
	<p>$data</p>
</body>
</html>

HTML
);
	//}
}

if ( version_compare( '5.0', phpversion(), '>' ) ) {
	pdo_log_error('Incorrect PHP Version', 'Your server is running PHP version ' . phpversion() . ' but this version of WordPress requires at least 5.0.');
}

if ( !extension_loaded('pdo') ){
	pdo_log_error( "Invalid or missing PHP Extensions", 'Your PHP installation appears to be missing the PDO extension which is required for this version of WordPress.' );
}

if (!extension_loaded('pdo_'.DB_TYPE)){
	pdo_log_error ('Invalid or missing PDO Driver', "Your PHP installation appears not to have the right PDO drivers loaded.  These are required for this version of Wordpress and the type of database you have specified.");
}

function fileModified($file){
	global $wpdb;
	$dbTime = $wpdb->get_var("select modTime from modTimes where modFile = '".$wpdb->escape($file)."'");
	if (empty($dbTime) || $dbTime !== getModTime($file)){
		return true;
	} else {
		return false;
	}
}

/**
*	writes the new file modification timestamp to the management table
*	@param	$file	string	a string holding the full path to the file in question	
*/
function updateFileModified($file){
	global $wpdb;
	$wpdb->query("	replace into 
					modTimes 
					(modTime, modFile) 
					values ('".$wpdb->escape(getModTime($file))."',
							'".$wpdb->escape($file)."')");
}

/**
*	checks the file modification timestamp for a given file
*	@param	$file	string	a string holding the full path to the file in question
*/
function getModTime($file){
	return filemtime($file);
}

/**
*	function to change wordpress files selectively to workaround things that might break the non-mysql ports
*
*	currently this handles only changes going back to version 2.2.
*	Intended that this function is extended as new wordpress versions are issued
*/
function changeFiles_2_4(){
	$files = array (	ABSPATH.'/wp-admin/includes/upgrade.php', 
						ABSPATH.'/wp-admin/includes/schema.php');
	foreach ($files as $file){
		if (fileModified($file)){
			$contents = file_get_contents($file);
			$contents = preg_replace ('/\bmysql_get_server_info\(\)/', '_mysql_get_server_info()', $contents);
			file_put_contents($file, $contents, FILE_TEXT  | LOCK_EX);
			updateFileModified($file);
		}
	}
}

/**
*	dummied function to fool wordpress upgrade functions
*/
function _mysql_get_server_info(){
	return "4.1";
}

if (defined('WP_CONTENT_DIR')){
	define ("PDODIR", WP_CONTENT_DIR .'/pdo/');
	define ('FQDBDIR', WP_CONTENT_DIR .'/database/');
} else {
	define ("PDODIR", ABSPATH.'/wp-content/pdo/');
	define ('FQDBDIR', ABSPATH .'/wp-content/database/');
}
define ('FQDB', FQDBDIR .'MyBlog.sqlite');

//we need to call this now, to instantiate the $wpdb object
//before we do the file rewrites.
require_once PDODIR.'db.php';

//check to see whether we need to make some file changes
if(DB_TYPE !== 'mysql'){
	//to get $wp_version into the global scope
	include_once ABSPATH.'/wp-includes/version.php';
	preg_match('/^\\s*[\d.]*/',$wp_version, $matches);
	$v = trim($matches[0]);
	if (version_compare($v, "2.4") == -1){
		changeFiles_2_4();
	}
}

?>