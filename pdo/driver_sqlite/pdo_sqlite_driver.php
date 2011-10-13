<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
/**
 *	base class for sql rewriting
 */

class pdo_sqlite_driver{
	
	//required variables
	private 	$ifStatements = array();
	private 	$startingQuery = '';
	public 		$_query = '';
	private 	$dateRewrites = array();
	
	
	/**
	*	method to rewrite sqlite queries (or emulate mysql functions)
	*
	*	
	*	@access	public
	*	@param string $query	the query to be rewritten
	*/
	public function rewriteQuery($query, $queryType){
		$this->startingQuery = $query;
		$this->queryType = $queryType;
		$this->_query = $query;
		switch ($this->queryType){
			case 'truncate':
				$this->handleTruncateQuery();
			break;
			case "alter":
				$this->handleAlterQuery();
			break;
			case "create":
				$this->handleCreateQuery();
			break;
			case "describe":
				$this->handleDescribeQuery();
			break;
			case "show":
				$this->handleShowQuery();
			break;
			case "select":
				$this->stripBackTicks();
				$this->handleSqlCount();
				//$this->handle_if_statements();
				$this->rewriteBadlyFormedDates();
				//$this->rewrite_date_format();
				//$this->rewrite_datetime_functions();
				//$this->rewriteSubstring();
				//$this->rewrite_date_add();
				//$this->rewrite_date_sub();
				//$this->rewriteNowUsage();
				$this->deleteIndexHints();
				$this->fixdatequoting();
				//$this->rewrite_md5();
				//$this->rewrite_rand();
				$this->rewriteRegexp();
			break;
			case "insert":
				$this->stripBackTicks();
				$this->rewrite_insert_ignore();
				//$this->rewriteNowUsage();
				//$this->rewrite_md5();
				//$this->rewrite_rand();
				$this->fixdatequoting();
				$this->rewriteBadlyFormedDates();
				$this->rewriteOnDuplicateKeyUpdate();
				$this->rewriteRegexp();
				break;
			case "update":
				$this->stripBackTicks();
				$this->rewrite_update_ignore();
				//$this->rewriteNowUsage();
				//$this->rewrite_md5();
				//$this->rewrite_rand();
				$this->rewriteBadlyFormedDates();
				$this->rewriteRegexp();
			case "delete":
			case "replace":
				$this->stripBackTicks();
				$this->rewriteBadlyFormedDates();
				//$this->rewrite_date_add();
				//$this->rewrite_date_sub();
				//$this->rewriteNowUsage();
				//$this->rewrite_md5();
				$this->stripBackTicks();
				$this->rewriteLimitUsage();
				$this->fixdatequoting();
				//$this->rewriteNowUsage();
				//$this->rewrite_md5();
				//$this->rewrite_rand();
				$this->rewriteRegexp();
				break;
			case "optimize":
				$this->rewrite_optimize();
			default:
		}
		return $this->_query;
	}
	
	/**
	*	method to process results of an unbuffered query
	*
	*	@access	public
	*	@param array $array (associative array of results)
	*	@return mixed	the cleansed array of query results
	*/
	public function processResults($array = array()){
		return $array;
		$this->_results = $array;		
		//the If results...
		foreach($this->ifStatements as $ifStatement){
			foreach($this->_results as $key=>$row){
				//could use eval here.
				$operation = false;
				switch ($ifStatement['operator']){
					case "=":
						$operation = ($ifStatement['param1_as'] == $ifStatement['param2_as']);
					break;
					case ">":
						$operation = ($ifStatement['param1_as'] > $ifStatement['param2_as']);
					break;
					case "<":
						$operation = ($ifStatement['param1_as'] < $ifStatement['param2_as']);
					break;
					case "<=":
						$operation = ($ifStatement['param1_as'] <= $ifStatement['param2_as']);
					break;
					case ">=":
						$operation = ($ifStatement['param1_as'] >= $ifStatement['param2_as']);
					break;
					case "isnull":
					case "is null":
						$operation = ($ifStatement['param1_as'] === NULL);
					case "like":
						$l = $r = false;
						//determine type of LIKE
						if (substr($ifStatement['param2_as'], 0, 1) == '%'){
							$l = true;
						}
						if(substr($ifStatement['param2_as'], -1, 1) == '%'){
							$r = true;
						}
						if ($l && $r){
							$operation = stristr($ifStatement['param2_as'], $ifStatement['param1_as']);
						}
						if (!$l && !$r){
							$operation = ($ifStatement['param1_as'] == $ifStatement['param2_as']);
						}
						if ($l && !$r){
							$operation = (substr($ifStatement['param1_as'], -1, strlen($ifStatement['param2_as'])-1) == substr($ifStatement['param2_as'],0, strlen($ifStatement['param2_as'] -1)));
						}
						if (!$l && $r){
							$operation = (substr($ifStatement['param1_as'], 0, strlen($ifStatement['param2_as'])-1) == substr($ifStatement['param2_as'],1, strlen($ifStatement['param2_as'] -1)));			
						}
					break;
				}
				//clean up the row
				unset($row[$ifStatement['param1_as']]);
				unset($row[$ifStatement['param2_as']]);
				$row[$ifStatement['as']] = ($operation) ? $ifStatement['trueval'] : $ifStatement['falseval'];
				$this->_results[$key] = $row;
			} //end of foreach $array	
		} //end of IF processing
		
		/*
		//	this code is kept in for future use
		//	it seems neater to abstract the call to sqlite to a julian date time and then fix in php
		//	makes it portable to sqlite.
		$mysql_php_dateformats = array ( '%a' => 'D', '%b' => 'M', '%c' => 'n', '%D' => 'jS', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%h' => 'h', '%I' => 'h', '%i' => 'i', '%j' => 'z', '%k' => 'G', '%l' => 'g', '%M' => 'F', '%m' => 'm', '%p' => 'A', '%r' => 'h:i:s A', '%S' => 's', '%s' => 's', '%T' => 'H:i:s', '%U' => 'W', '%u' => 'W', '%V' => 'W', '%v' => 'W', '%W' => 'l', '%w' => 'w', '%X' => 'Y', '%x' => 'o', '%Y' => 'Y', '%y' => 'y', );

		foreach ($this->dateRewrites as $dateRewrite){
			foreach ($this->_results as $key=>$result){
				//need a mapping of mysql to date() codes
			}
		}
		*/
		
		return $this->_results;
	}
	
	/**
	 *	method to dummy the SHOW TABLES query
	 */
	private function handleShowQuery(){
		$pattern = '/^\\s*SHOW\\s*TABLES\\s*(LIKE\\s*.*)?/im';
		$result = preg_match($pattern, $this->_query, $matches);
		if (!empty($matches[1])){
			$suffix = ' AND name '.$matches[1];		
		} else {
			$suffix = '';
		}
		$this->_query = "SELECT name FROM sqlite_master WHERE type = 'table'" . $suffix . ' ORDER BY name DESC';
		$this->showQuery = true;	
	}
	
	/**
	*	rewrites date_format() clauses for use in sqlite
	*
	*	date_format() is not supported in sqlite and the equivalent
	*	function reverses its parameters ... and not all of the modifiers available
	*	in mysql are available in sqlite
	*	for the time being we just take a view that the formatstring is OK
	*	and swap the parameters around whilst rewriting to strftime.
	*
	*	@todo:	rewrite to a properly abstracted php function
	*/
	private function rewrite_date_format(){
		$pattern = '/date_format\\s*\((.*?),\\s*(\'%.*?\')\\s*\)/ims';
		$query = preg_replace_callback($pattern, array($this, '_rewrite_date_format'), $this->_query);
		$this->_query = $query;
	}
	
	/**
	 *	method to rewrite the datetime functions used in mysql and wordpress
	 */
	private function rewrite_datetime_functions(){
		$pattern = '/\s*\b(year|month|day|dayofmonth|unix_timestamp)\b\s*\((.*?)\)/imx';
		$query = preg_replace_callback($pattern, array($this, '_rewrite_datetime_functions'), $this->_query);
		$this->_query = $query;
	}
	
	/**
	*	callback function for rewrite_datetime_function
	*	thanks to Nicolas Schmid for pointing out the +0 fix
	*/
	private function _rewrite_datetime_functions($matches){
		//may need to expand this to other functions.
		switch (strtolower($matches[1])){
			case "year":
				return " strftime('%Y',{$matches[2]}) ";
			break;
			case "month":
				return " cast(strftime('%m',{$matches[2]}) as int)";
			break;
			case "day":
			case "dayofmonth":
				return " cast(strftime('%d',{$matches[2]}) as int)";
			break;
			case "unix_timestamp":
				return "cast(strftime('%s', {$matches[2]}) as int)";
			break;
		}
	}
		
	/**
	*	method to rewrite the use of the now() function for use in sqlite
	*
	*	now() as a function does not exist in sqlite but an equivalent exists
	*/
	private function rewriteNowUsage(){
		$query = $this->istrreplace('now()', " DATETIME('now') ", $this->_query);
		$query = $this->istrreplace('CURDATE()', " DATETIME('now') ", $query);
		$this->_query = $query;
	}
	
	/**
	*	rewrites if statements to retrieve all columns in the query
	*
	*	the different field elements are captured in a variable through the callback
	*	function and then sorted out in the postprocessing
	*/
	private function handle_if_statements(){
		$pattern = "/\\s*if\\s*\((.*?)(=|>=|>|<=|<|!=|LIKE|isnull|is null)([^,]*),([^,]*),(.*?)\)\\s*as\\s*(\w*)/imsx";
		$query = preg_replace_callback($pattern, array($this, 'emulateIfQuery'), $this->_query);
		$this->_query = $query;
	}

	/**
	*	method to strip all column qualifiers (backticks) from a query
	*/
	private function stripBackTicks(){
		$this->_query = str_replace("`", "", $this->_query);
	}
	
	/**
	*	callback function for handle_if_statements
	*/
	private function emulateIfQuery($matches){
		$tmp_1 = 't_' . md5(uniqid('t_', true));
		$tmp_2 = 't_' . md5(uniqid('t_', true));
		$this->IFStatements[] = array (	'param1'=>$matches[1],
										'param1_as'=>$tmp_1,
										'operator'=>strtolower($matches[2]),
										'param2'=>$matches[3],
										'param2_as'=>$tmp_2,
										'trueval'=>$matches[4],
										'falseval'=>$matches[5],
										'as'=>$matches[6]);
		return " $matches[1] as $tmp_1, $matches[3] as $tmp_2 ";
	}
	
	/**
	*	callback function for rewrite_date_format
	*/
	private function _rewrite_date_format($matches){
		return " strftime('".$matches[2]."', ".$matches[1]." )";
		/*
		$formatString = ('%s');
		$this->dateRewrites[] = array(	'columnName'=>$matches[4],
										'mysqlFormatString'=>$matches[2]);
		$param = $matches[1];
		return " strftime('{$formatString}', '{$param}') ";
		*/
	}
	
	/**
	*	method to rewrite the char_length function for use in sqlite
	*/
	private function rewrite_char_length(){
		$this->_query = $this->iStrReplace('char_length', 'length', $this->_query);
		return $query;
	}
	
	/**
	*	function that abstracts a case insensitive search and replace
	*
	*	implemented for backward compatibility with pre 5.0 versions of 
	*	php.  not really needed as php4 won't work with this class anyway
	*
	*	@param string $search	the needle
	*	@param string $replace	the replacement text
	*	@param string $subject	the haystack
	*/
	private function iStrReplace($search, $replace, $subject){
		if (function_exists('str_ireplace')){
			return str_ireplace($search, $replace, $subject);
		} else {
			return preg_replace("/$search/i", $replace, $subject);
		}
	}
	
	/**
	*	method to emulate the SQL_CALC_FOUND_ROWS placeholder for mysql
	*
	*	this is really yucky. we create a new instance of the database class,
	*	rewrite the query to use a count(*) syntax without the LIMIT
	*	run the rewritten query, grab the recordset with the number of rows in it
	*	and write it to a special variable in the common abstraction object
	*	then delete the SQL_CALC_FOUND_ROWS keyword from the base query and
	*	pass back to the main process.
	*/
	private function handleSqlCount(){
		if (stripos($this->_query, 'SQL_CALC_FOUND_ROWS') === false){
			//do nothing
		} else {
			global $wpdb;
			//echo "handling count rows<br/>";
			//first strip the code
			$this->_query = $this->istrreplace('SQL_CALC_FOUND_ROWS', ' ', $this->_query);
			//echo "prepped query for main use = ". $this->_query ."<br/>";
			
			$unLimitedQuery = preg_replace('/\\bLIMIT\s*.*/imsx', '', $this->_query);
			$unLimitedQuery = $this->transform2Count($unLimitedQuery);
			//echo "prepped query for count use is $unLimitedQuery<br/>";
			$_wpdb = new pdo_db(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, DB_TYPE);
			
			$result = $_wpdb->query($unLimitedQuery);
			$wpdb->dbh->foundRowsResult = $_wpdb->last_result;
			//echo "number of records stored is $rowcount<br/>";
			
		}
	}
	
	/**
	*	transforms a select query to a select count(*)
	*
	*	@param	string $query	the query to be transformed
	*	@return	string			the transformed query
	*/
	private function transform2Count($query){
		$pattern = '/^\\s*select\\s*(distinct)?.*?from\b/imsx';
		$_query = preg_replace($pattern, 'Select \\1 count(*) from ', $query);
		return $_query;
	}
	
	/**
	*	rewrites md5 usage
	*
	*	sqlite does not have an md5 build in capability
	*	so we need to rewrite the query to insert the actual md5 string
	*
	*	@todo	recast this function to remove reliance on single quotes
	*/
	private function rewrite_md5(){
		$pattern = "/md5[(][']([^']*)['][)]/i";
		$this->_query = preg_replace_callback($pattern,array($this, "_rewrite_md5"),$this->_query);
	}
	
	/**
	*	callback function for rewrite_md5()
	*/
	private function _rewrite_md5($matches){
		return "'".md5($matches[1])."'";
	}
	
	/**
	*	rewrites rand() usage for sqlite
	*/
	private function rewrite_rand(){
		$pattern = "/rand\(\)/i";
		$this->_query = preg_replace($pattern, ' random() ', $this->_query);
	} 

	/**
	*	rewrites the insert ignore phrase for sqlite
	*/
	private function rewrite_insert_ignore(){
		$this->_query = $this->istrreplace('insert ignore', 'insert or ignore ', $this->_query); 
	}

	/**
	*	rewrites the update ignore phrase for sqlite
	*/
	private function rewrite_update_ignore(){
		$this->_query = $this->istrreplace('update ignore', 'update or ignore ', $this->_query); 
	}
	
	
	/**
	*	rewrites usage of the date_add function for sqlite
	*/
	private function rewrite_date_add(){
		//(date,interval expression unit)
		$pattern = '/\\s*date_add\\s*\(([^,]*),([^\)]*)\)/imsx';
		$this->_query = preg_replace_callback($pattern, array($this,'_rewrite_date_add'), $this->_query);
	}
	
	/**
	*	callback function for rewrite_date_add()
	*/
	private function _rewrite_date_add($matches){
		$date = $matches[1];
		$_params = $params = array();
		$params = explode (" ", $matches[2]);
		//cleanse the array as sqlite is quite picky
		foreach ($params as $param){
			$_p = trim ($param);
			if (!empty($_p)){
				$_params[] = $_p;
			}
		}
		//we should be after items 1 and 2
		return " datetime($date,'$_params[1] $_params[2]') ";
	}
	
	/**
	 *	method to rewrite date_sub
	 *
	 *	required for drain Hole...
	 */
	private function rewrite_date_sub(){
		//(date,interval expression unit)
		$pattern = '/\\s*date_sub\\s*\(([^,]*),([^\)]*)\)/imsx';
		$this->_query = preg_replace_callback($pattern, array($this,'_rewrite_date_sub'), $this->_query);
	}
	
	/**
	*	callback function for rewrite_date_sub()
	*/
	private function _rewrite_date_sub($matches){
		$date = $matches[1];
		$_params = $params = array();
		$params = explode (" ", $matches[2]);
		//cleanse the array as sqlite is quite picky
		foreach ($params as $param){
			$_p = trim ($param);
			if (!empty($_p)){
				$_params[] = $_p;
			}
		}
		//we should be after items 1 and 2
		return " datetime($date,'-$_params[1] $_params[2]') ";
	}
	
	/**
	*	handles the create query
	*
	*	this method invokes a separate class for the query rewrites
	*	as the create queries are complex to rewrite and I did not 
	*	want to clutter up the function namespace where unnecessary to do so
	*/
	private function handleCreateQuery(){
		require_once PDODIR.'/driver_sqlite/pdo_sqlite_driver_create.php';
		$q = new createQuery();
		$this->_query = $q->rewriteQuery($this->_query);
		$q = NULL;
	}
	
	/**
	*	dummies the alter queries as sqlite does not have a great method for handling them
	*/
	private function handleAlterQuery(){
		$this->_query = "select 1=1";
	}
	
	/**
	*	dummies describe queries
	*/
	private function handleDescribeQuery(){
		$this->_query = "select 1=1";
	}
	
	/**
	*	the new update() method of wp-db makes for some reason insists on adding LIMIT
	*	to the end of each update query. sqlite does not support these.
	*	let's hope that the queries have not been malformed in reliance on this LIMIT clause.
	*	decision decision taken to leave the LIMIT clause in the update() method and rewrite on the fly
	*	so mysql pdo support can be maintained and for portability to other languages.
	*/
	private function rewriteLimitUsage(){
		$pattern = '/\s*LIMIT\s*[0-9]$/i';
		$this->_query = preg_replace($pattern, '', $this->_query);
	}
	

	/**
	 *	rewrites Show usage of the mysql substring function for use with sqlite
	 */
	private function rewriteSubstring(){
		$pattern = '/\\s*SUBSTRING\\s*\(/i';
		$this->_query = preg_replace($pattern, ' SUBSTR(', $this->_query);
	}
	
	private function handleTruncateQuery(){
		$pattern = '/truncate table (.*)/im';
		$this->_query = preg_replace($pattern, 'DELETE FROM $1', $this->_query);
	}
	/**
	 * rewrites use of Optimize queries in mysql for sqlite.
	 * 
	 * no granularity is used here.  an optimize table will vacuum the whole database. 
	 * probably not a bad thing
	 * thanks to fnumatic for spotting the function declaration error.
	 *  
	 */
	private function rewrite_optimize(){
		$this->_query ="VACUUM";
	}
	
	/**
	 * function to ensure date inserts are properly formatted for sqlite standards
	 * 
	 * some wp UI interfaces (notably the post interface) badly composes the day part of the date
	 * leading to problems in sqlite sort ordering etc.
	 * 
	 * @return void
	 */
	private function rewriteBadlyFormedDates(){
		$pattern = '/([12]\d{3,}-\d{2}-)(\d )/ims';
		$this->_query = preg_replace($pattern, '${1}0$2', $this->_query);
	}
	
	/**
	 * function to remove unsupported index hinting from mysql queries
	 * 
	 * @return void 
	 */
	private function deleteIndexHints(){
		$pattern = '/use\s+index\s*\(.*?\)/i';
		$this->_query = preg_replace($pattern, '', $this->_query);
	}
	
	private function rewriteOnDuplicateKeyUpdate(){
		 $pattern = '/ on duplicate key update .*$/im';
		 $this->_query = preg_replace($pattern, '', $this->_query);
		 //now change the query time
		 $pattern = '/^INSERT /im';
		 $this->_query = preg_replace($pattern, 'INSERT OR REPLACE ', $this->_query);
	}
	
		
	/**
	 * method to fix inconsistent use of quoted, unquoted etc date values in query function
	 * 
	 * this is ironic, given the above rewritebadlyformed dates method 
	 * 
	 * examples 
	 * where month(fieldname)=08 becomes month(fieldname)='8'
	 * where month(fieldname)='08' becomes month(fieldname)='8'
	 * 
	 * @return void
	 */
	private function fixDateQuoting(){
		$pattern = '/(month|year|second|day|minute|hour|dayofmonth)\s*\((.*?)\)\s*=\s*["\']?(\d{1,4})[\'"]?\s*/ei';
		$this->_query = preg_replace($pattern, "'\\1(\\2)=\'' . intval('\\3') . '\' ' ", $this->_query);
	}
	
	private function rewriteRegexp(){
		$pattern = '/\s([^\s]*)\s*regexp\s*(\'.*?\')/im';
		$this->_query = preg_replace($pattern, ' regexpp(\1, \2)', $this->_query);
	}
}
?>