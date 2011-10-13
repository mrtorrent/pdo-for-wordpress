<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */

/**
 * this function overrides the built in wordpress  variant
 * 
 * @param object $blog_title
 * @param object $user_name
 * @param object $user_email
 * @param object $public
 * @param object $deprecated [optional]
 * @return 
 */
function wp_install($blog_title, $user_name, $user_email, $public, $deprecated='') {
	global $wp_rewrite, $wpdb;

	//wp_check_mysql_version(); 
	wp_cache_flush();
	/**** changes start here ***/
	switch (DB_TYPE):
		case 'sqlite':
			require PDODIR . '/driver_sqlite/schema.php';
			installdb();
			break;
		case 'mysql':
			make_db_current_silent();
			break;
	endswitch;
	/**** changes end ***/
	$wpdb->suppress_errors();
	populate_options();
	populate_roles();

	update_option('blogname', $blog_title);
	update_option('admin_email', $user_email);
	update_option('blog_public', $public);

	$guessurl = wp_guess_url();
	update_option('siteurl', $guessurl);

	// If not a public blog, don't ping.
	if ( ! $public )
		update_option('default_pingback_flag', 0);

	// Create default user.  If the user already exists, the user tables are
	// being shared among blogs.  Just set the role in that case.
	$user_id = username_exists($user_name);
	if ( !$user_id ) {
		$random_password = wp_generate_password();
		$message = __('<strong><em>Note that password</em></strong> carefully! It is a <em>random</em> password that was generated just for you.');
		$user_id = wp_create_user($user_name, $random_password, $user_email);
		update_usermeta($user_id, 'default_password_nag', true);
	} else {
		$random_password = '';
		$message =  __('User already exists.  Password inherited.');
	}

	$user = new WP_User($user_id);
	$user->set_role('administrator');

	wp_install_defaults($user_id);

	$wp_rewrite->flush_rules();

	wp_new_blog_notification($blog_title, $guessurl, $user_id, $random_password);

	wp_cache_flush();

	return array('url' => $guessurl, 'user_id' => $user_id, 'password' => $random_password, 'password_message' => $message);
}
?>