<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
 /**
  *	this class inherits from wpdb
  *
  * the database connection mechanism is handed off by createDBOObject() to the secondary abstraction class
  * various other methods override the wpdb versions.
  * Thanks to Ulf Ninow for the suggestion on inheritance.  this will make life
  * easier to maintain the package for upgrades and extensions for other database backends.
  */


//call the PDO engine
require_once PDODIR . 'PDOEngine.php';

//make sure that the bespoke wp_install function is in scope
if (DB_TYPE == 'sqlite') require_once PDODIR . 'wp_install.php';

/*
 * this definition is used by EZSQL
 */
if (!defined('SAVEQUERIES')){
	define ('SAVEQUERIES', false);
}

/*
 * PDO_DEBUG set this to true to create a list of queries in your database directory that can be used to debug
 */
if(!defined('PDO_DEBUG')){
	define('PDO_DEBUG', false);
}

//provide a reference for the native wpdb class
if ( ! isset($wpdb) ){
	global $wpdb;
	$wpdb = 'somevar';
	require_once ABSPATH.'wp-includes/wp-db.php';
	unset($wpdb);
}

class pdo_db extends wpdb {

	/**
	*	variables added for the sqlite port
	*
	*	@var $engine mixed - holds a reference to the main query engine
	*	@var $queryType string - holds the type of query, as set by determineQueryType
	*/
	
	var $engine;
	var $queryType;

	/**
	 * Connects to the database server with params needed to create a viable dsn
	 *
	 * @param  $dbuser string
	 * @param  $dbpassword string
	 * @param  $dbname string
	 * @param  $dbhost string
	 */
	public function __construct($dbuser=null, $dbpassword=null, $dbname, $dbhost, $dbType) {
		$this->dbType = $dbType;
		$this->dbuser = $dbuser;
		$this->dbpassword = $dbpassword;
		$this->dbname = $dbname;
		$this->dbhost = $dbhost;
		register_shutdown_function(array(&$this, "__destruct"));

		if ( defined('DB_CHARSET') )
			$this->charset = DB_CHARSET;

		if ( defined('DB_COLLATE') )
			$this->collate = DB_COLLATE;
		
		$this->createDBObject();
	
		if ($this->dbh->isError) {
			$this->bail($this->dbh->getErrorMessage());
		}
		
	}
	
	
	/**
	*	sets the database connection object
	*
	*	this function intermediates within the native
	*	db abstraction class for wordpress.
	*	and allows a connection type object to be created across
	*	the supported database types that have PDO drivers and rewrite scripts provided
	*	no variable is returned but the dbh property is set
	*/
	function createDBObject(){	
		$this->dbh = NEW PDO_Engine(array($this->dbType, $this->dbuser, $this->dbpassword, $this->dbname, $this->dbhost));
		if ($this->dbh === false || $this->dbh->isError){
			$this->bail($this->dbh->getErrorMessage());
		}
	}

	/**
	 *	method to escape the string for use in mysql databases.
	 *
	 *	this is left intact rather than dummying out.  there is a performance hit in doing so
	 *	but trying to dummy it screws with some other WP functionality 
	 *	similarly, using mysql_escape_string() (which you would think would be logical) screws things up to.
	 *	basically does not honour line terminators.
	 *
	 *	@param $string	string	the variable to be escaped
	 *	@return	string	escaped variable
	 */
	function escape($string) {
		return addslashes($string);
	}
	
	/**
     *	sends a query through the abstraction engine 
	 *
	 *	this is largely the same as the original query function
	 *	within wordpress save that it executes via an engine
	 *	rather than directly
	 *
	 *	@param $query string  - the query to be executed
	 *	@return integer/bool - depends on the queryType
	 */
	function query($query) {
		
		// filter the query, if filters are available
		// NOTE: some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists('apply_filters') )
			$query = apply_filters('query', $query);

		// initialise return
		$return_val = 0;
		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// Keep track of the last query for debug..
		$this->last_query = $query;

		
		if (SAVEQUERIES)
			$this->timer_start();
	
		//run the query through the abstraction engine
		
		// and this, basically, is where all the heavy lifting is done!  
		$this->result = $this->dbh->query($query);
		
		++$this->num_queries;

		if (SAVEQUERIES)
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );
		
		// If there is an error then take note of it..
		//	this has been changed to reference the PDO_Engine object which has more
		//	advanced error handling and reporting
		if ( $this->dbh->isError === true ) {
			if (defined('WP_INSTALLING')){
				// $this->suppress_errors(true);
				// $this->print_error($this->dbh->getErrorMessage());
			} else {
				$this->print_error($this->dbh->getErrorMessage());
			}
		}
		
		$this->insert_id = $this->dbh->getInsertID();
		$this->rows_affected = $this->dbh->getAffectedRows();
		$this->col_info = $this->dbh->getColumns();
		$this->last_result = $this->dbh->getQueryResults();
		$this->num_rows = $this->dbh->getNumRows();
		$return_val = $this->dbh->getReturnValue();
		//print_r($return_val);
		return $return_val;
	}

	
	/**
	 * Checks wether of not the database version is high enough to support the features WordPress uses
	 * @global $wp_version
	 */
	function check_database_version(){
		global $wp_version;
		// Make sure the server has MySQL 4.0
		switch ($this->dbType){
			case "mysql":
				// Make sure the server has MySQL 4.0
				if ( version_compare($this->db_version(), '4.0.0', '<') ) {
					return new WP_Error('database_version',sprintf(__('<strong>ERROR</strong>: WordPress %s requires MySQL 4.0.0 or higher'), $wp_version));
				}
				break;
			
		}
	}

	/**
	 * abstract db_version from ezsql
	 */
	function db_version(){
		switch ($this->dbType) {
			case 'sqlite':
				return 4.1;
				break;
			case 'mysql':
				return 4.1;
				break;
			default:
				return false;
		}
	}

	/**
	 * This function is called when WordPress is generating the table schema to determine wether or not the current database
	 * supports or needs the collation statements.
	 */
	function supports_collation(){
		if ($this->dbType == "mysql"){
			return ( version_compare(mysql_get_server_info(), '4.1.0', '>=') );
		} else {
			return false;
		}
	}
	
	/**
	 * stubs out _real escape too
	 */
	
	function _real_escape($string) {
		return addslashes( $string );
	}
	
	function has_cap($db_cap){
		switch ($this->dbType){
			case 'sqlite':
				switch ( strtolower( $db_cap ) ){
					case 'collation' :    // @since 2.5.0
					case 'group_concat' : // @since 2.7
						return false;
					case 'subqueries' :   // @since 2.7
						return true;
						break;
					default:
						return false;
				}
			break;
			default:
				return parent::has_cap($db_cap);
		}
		
	}
	
	/**
	 * Print SQL/DB error.
	 *
	 * @since 0.71
	 * @global array $EZSQL_ERROR Stores error information of query and error string
	 *
	 * @param string $str The error to display
	 * @return bool False if the showing of errors is disabled.
	 */
	function print_error($str = '') {
		global $EZSQL_ERROR;

		if (!$str){
			global $pdo;
			$e = $pdo->errorInfo();
			$str = $e[2];
		}
		
		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);

		if ( $this->suppress_errors )
			return false;

		if ( $caller = $this->get_caller() )
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR_FULL*/'WordPress database error %1$s for query %2$s made by %3$s'/*/WP_I18N_DB_QUERY_ERROR_FULL*/, $str, $this->last_query, $caller);
		else
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR*/'WordPress database error %1$s for query %2$s'/*/WP_I18N_DB_QUERY_ERROR*/, $str, $this->last_query);

		$log_error = true;
		if ( ! function_exists('error_log') )
			$log_error = false;

		$log_file = @ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !@is_writable($log_file) )
			$log_error = false;

		if ( $log_error )
			@error_log($error_str, 0);

		// Is error output turned on or not..
		if ( !$this->show_errors )
			return false;

		$str = htmlspecialchars($str, ENT_QUOTES);
		$query = htmlspecialchars($this->last_query, ENT_QUOTES);

		// If there is an error then take note of it
		print "<div id='error'>
		<p class='wpdberror'><strong>WordPress database error:</strong> [$str]<br />
		<code>$query</code></p>
		</div>";
	}
}

/**
 *	let's go and create the database object.
 *
 *	note that this is changed from wordpress standard to include the DB_TYPE in the parameters
 */
if ( ! isset($wpdb) ){
	global $wpdb;
	$wpdb = new pdo_db(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, DB_TYPE);
}

?>