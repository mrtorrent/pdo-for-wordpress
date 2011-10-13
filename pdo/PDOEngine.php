<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
/**
 *	provides a 'driver' for  wordpress to use PDO databases 
 *
 *	The core behaviours of this class is <ul>
 *	<li> 	to create an appropriate factory item for query rewriting
 *	<li>	to extract variables from the query to use in a prepared statements
 * 	<li>	to handback to the standard wpdb object the kind of outputs it wants
 *	</ul>
*/

class PDO_Engine{
	//public properties
	public	$isError = false;
	public 	$foundRowsResult;
	
	//private properties
	private $initialQuery;
	private $rewrittenQuery;
	private $queryType;
	private $rewriteEngine;
	private $needsPostProcessing;
	private $results=array();
	private $pdo;
	private $preparedQuery;
	private $extractedVariables = array();
	private	$errorMessages = array();
	private $errors;
	public	$queries = array();
	private	$dbType;
	private $lastInsertID;
	private $affectedRows;
	private $columnNames;
	private $numRows;
	private $returnValue;
	private $startTime;
	private $stopTime;
	
	/**
	 *	object instantiation.  
	 *
	 *	At this point it's just a connection object...
	 *
	 *	@param array $connectionParams	a simple array of connection parameters (see list command for order
	 */
			
	public function __construct($connectionParams){
		$this->connect($connectionParams);
	}
	/**
	 *	method to construct the dsn dependent on the database type
	 *
	 *	@param array $connectionParams	a simple array of connection parameters (see list command for order
	 *	@return string	a constructed dsn
	 */
	private function connect($connectionParams){
		set_time_limit(30);
		global $wpdb;
		list ($this->dbType, $dbUser, $dbPassword, $dbName, $dbHost) = $connectionParams;
		switch ($this->dbType){
			case 'sqlite':
				//as sqlite is file system database, we need to make sure that permissions
				//don't bite us in the arse
				$u = umask(0000);
				//	determines whether we are in the installation process.  If so, we
				//	create the holding directory, protect it from direct access and create the database
				if (!is_dir(FQDBDIR)){
				
					if (!@mkdir(FQDBDIR, 0777, true)){
						umask($u);					
						$wpdb->bail("<h1>Cannot create folder</h1><p>The installation routine cannot create the folder in which the sqlite database will be stored.  This will usually be because of permissions errors.</p>");
					}
				}
				if (!is_writable (FQDBDIR)){
					umask($u);
					$wpdb->bail('<h1>Permissions Problem</h1><p>PDO For WordPress needs to be able to write to the folder ' .FQDBDIR ."</p>");
				}
				
				if (!is_file(FQDBDIR.'/.htaccess')){
					$fh = fopen(FQDBDIR.'/.htaccess', "w");
					if (!$fh) {
						umask($u);
						$wpdb->bail("<h1>Cannot create htaccess file</h1><p>The installation routine cannot create the htaccess file needed to protect your database</p>");
					}
					fwrite ($fh, "DENY FROM ALL");
					fclose ($fh);
				}	
				//reset the umask to what it was.
				umask($u); 
							
				$dsn = "sqlite:".FQDB;
				if (is_file(FQDB)){
					$this->pdo = new PDO($dsn);
					$s = $this->pdo->query('select count(*) from SQLite_Master where type="table"');
					$count = $s->fetchColumn(0);
					$s = null; //release the recordset
					if ($count < 2){
						//echo 'installing database';
						$this->installDB();
					}
					//install the UDFs
					require_once PDODIR . '/driver_sqlite/pdo_sqlite_udfs.php';
					new PDO_SQLITE_UDFS($this->pdo);
					
				} else {
					$this->pdo = new PDO($dsn);
					//if the database did not exist, we need to step in
					//quickly and create the tables to stop wordpress from trying to do it
					$this->installDB();
				}
				
				
			break;
			case 'mysql':
				$dsn = "mysql:dbname={$dbName};host={$dbHost}";
				$this->pdo = new PDO ($dsn, $dbUser, $dbPassword);
			break;
			default:
				$message = <<<HTML
<h1>You have requested an unsupported database type</h1>
<p>Through this plugin we currently support</p>
<ul>
	<li>mysql</li>
	<li>sqlite</li>
</ul>
<p>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://wordpress.org/support/'>WordPress Support Forums</a>.</p>
HTML;
				$this->setError(__LINE__, __FUNCTION__ , $message);
		}//end switch
		
		if ($this->pdo === false){
			$message = "<h1>Database Error</h1><p>We have been unable to connect to the specified database.<br/>The error message received was " . print_r($this->pdo->errorInfo(), true) .'</p>';
			$this->setError(__LINE__, __FUNCTION__, $message);
		}
	}
	
	/**
	 *	interrupts the usual wordpress table creation regime
	 *
	 *	it does not feel right to have this in this class.  Consider moving it out
	 *	note that we cannot use neat ->bail() error function as at this point $wpdb is not fully instantiated.
	 */
	private function installDB(){
		switch ($this->dbType){
			case "sqlite":
				require_once PDODIR.'/wp_install.php';
				break;
			
		}
	}


	/**
	 *	handles a query request from db.php
	 *
	 *	determines whether the query needs rewriting
	 *	parses out the variables from the query
	 *	escapes them and enquotes them as necessary
	 *	this function is really the big kahuna of this class.
	 *
	 *	@param	$query	string	the query that is to be processed.
	 */
	public function query($query){
		
		//design decision to keep this as one object serving muliple queries rather than a new object per query. 
		$this->flush();
		
		//I store details of the query at each stage of use for ease of debugging
		$this->queries[] = "Raw query:\t$query";
		
		//currently I don't use initialquery again, but keeping just in case.
		$this->initialQuery = $query;
		$this->determineQueryType($query);
		switch (strtolower($this->queryType)){
			case 'foundrows':
				//for a found rows query we really don't need to do anything other than point the query back at 
				//the results stored in this object by the last query.
				$this->results = $this->foundRowsResult;
				$this->foundRowsResult = null;
			break;
			case 'multiinsert':
				switch ($this->dbType){
					case "sqlite":
						list($insertSQLPrefix, $values, $count) = $this->multiInsertMatches;
						$this->multiInsertMatches = array();
					
						if ($count > 1){
							$cnt = 1;
							$first = true;
							$this->reriteEngine = new pdo_sqlite_driver();
							foreach ($values as $value){
								if (substr($value, -1, 1) === ')'){
									$suffix = '';
								} else {
									$suffix = ')';
								}
								$query = $insertSQLPrefix .' ' . $value . $suffix;
								$this->rewrittenQuery = $this->rewriteEngine->rewriteQuery($query, 'insert');
								//logically they should all be the same shape so we can prepare the first one.
								//and execute against subsequents
								$this->queries[] = $this->rewrittenQuery;
								//get variables
								$this->extractedVariables = array();
								$this->extractVariables();
								if ($first){
									$this->prepareQuery();
									$first = false;
									/*if ($this->isError){
										global $wpdb;
										$wpdb->bail($this->getErrorMessage());
									}
									*/
								}	else {
									$this->executeQuery($this->statement);
									/*
									if ($this->isError){
										global $wpdb;
										$wpdb->bail($this->getErrorMessage());
									}
									*/
								}
							}
						}
					break;
						
					case "mysql":
						$this->rewrittenQuery = $this->initialQuery;
						$this->needsPostProcessing = false;
					break;
					
				}
			break;

			default:
				switch ($this->dbType){
					case "sqlite":
						require_once PDODIR."/driver_sqlite/pdo_sqlite_driver.php";
						
						$this->rewriteEngine = new pdo_sqlite_driver();
						$this->rewrittenQuery = $this->rewriteEngine->rewriteQuery($this->initialQuery, $this->queryType);
						$this->needsPostProcessing = true;
					break;
					case "mysql":
						//no rewrite engine needed
						$this->rewrittenQuery = $this->initialQuery;
						$this->needsPostProcessing = false;
					break;
				}
				$this->queries[]="Rewritten: $this->rewrittenQuery";
				// prepare the query to use placeholders (avoids sql injection attacks)
				$this->extractVariables();
				
				//prepare the query
				//and execute it (called from prepare())
				$this->prepareQuery();
				if (!$this->isError){
					//postprocess, for IF statements.
					$this->processResults();
					//$wpdb expects an array of objects as a result whereas ironically we are using arrays
					$this->convertToObject();
				} else {
					/*
					 * 
					 global $wpdb;
					$wpdb->bail($this->getErrorMessage());
					*/
				}
			break;
			
		}
		//dump some queries to a text file for testing
		if (defined('PDO_DEBUG') && PDO_DEBUG === true){
			file_put_contents(ABSPATH.'wp-content/database/debug.txt', $this->getDebugInfo(), FILE_APPEND);	
		}	
	}	
	
	/**
	 *	returns the actual result set, as cleansed by processResults
	 *
	 */
	public function getInsertID(){
		return $this->lastInsertID;
	}

	/**
	 *	returns the number of rows affected by the last query
	 *
	 */
	public function getAffectedRows(){
		return $this->affectedRows;
	}
	
	/**
	 *	returns the actual result set, as cleansed by processResults
	 *
	 */
	public function getColumns(){
		return $this->columnNames;
	}
	
	/**
	 *	returns the actual result set, as cleansed
	 *
	 */
	public function getQueryResults(){
		return $this->results;
	}
	
	/**
	 *	returns the number of rows returned by the previous query
	 *
	 */
	public function getNumRows(){
		return $this->numRows;
	}
	
	/**
	 *	returns a value to be used by WordPress as a result
	 *		
	 */
	public function getReturnValue(){
		return $this->returnValue;
	}
	
	/**
	 *	method to return the current array of error messages in a string form
	 *
	 *	@return	string	a string representing the error messages recorded by this class
	 */
	public function getErrorMessage(){
		if (count($this->errorMessages) === 0){
			$this->isError = false;
			$this->errorMessages = array();
			return '';
		}
		$output = '<div style="clear:both">&nbsp;</div>';
		if ($this->isError === false){
			return $output;
		}
		$output .= "<div class=\"queries\" style=\"clear:both; margin_bottom:2px; border: red dotted thin;\">Queries made or created this session were<br/>\r\n\t<ol>\r\n";
		foreach ($this->queries as $q){
			$output .= "\t\t<li>".$q."</li>\r\n";
		}
		$output .= "\t</ol>\r\n</div>";
		foreach ($this->errorMessages as $num=>$m){
			$output .= "<div style=\"clear:both; margin_bottom:2px; border: red dotted thin;\" class=\"errorMessage\" style=\"border-bottom:dotted blue thin;\">Error occurred at line {$this->errors[$num]['line']} in Function {$this->errors[$num]['function']}. <br/> Error message was: $m </div>";
		}
		
		ob_start();
		debug_print_backtrace();
		$output .= "<pre>" . ob_get_contents() . "</pre>";
		ob_end_clean();
		return $output;
	
	}
	
	/**
	 * method to return a plain text dump of queries for output to a text file
	 * 
	 * @return string: plain text set of queries
	 */
	private function getDebugInfo(){
		$output = '';
		foreach ($this->queries as $q){
			$output .= $q ."\r\n";
		}
		return $output;
	}
	
	/**
	 *	function to reset the object for a new query
	 *		
	 */
	private function flush(){
		$this->initialQuery = '';
		$this->rewrittenQuery = '';
		$this->queryType = '';
		$this->needsPostProcessing = false;
		$this->results = array();
		$this->_results = array();
		$this->lastInsertID = NULL;
		$this->affectedRows = NULL;
		$this->columnNames = array();
		$this->numRows = NULL;
		$this->returnValue = NULL;
		$this->extractedVariables = array();
		$this->errorMessages=array();
		$this->isError = false;
		$this->queries = array();
		
	}
	
	/**
	 *	interacts with the rewrite engine to cleanse the result set from a query
	 *
	 */
	private function processResults(){
		if(in_array($this->queryType, array("select", "describe","show")) && $this->needsPostProcessing){
			$this->results = $this->rewriteEngine->processResults($this->_results);
		}else{
			$this->results = $this->_results;
		}
	}
	
	
	/**
	*	prepares a query (to execute a query)
	*	
	*/
	private function prepareQuery(){
		$this->queries[] = "Prepare:\t". $this->preparedQuery;
		do {
			$this->statement = $this->pdo->prepare($this->preparedQuery);
			if ($this->statement === false){
				$reasons = $this->pdo->errorinfo();
				$reason = $reasons[1];
				$this->pdo->exec('vacuum');
			} else {
				$reason = 0;
			}
		} while ($reason == 17);
		
		if ($reason > 0){
			$message = "Problem preparing the PDO SQL Statement.  Error was ".$reasons[2];
			$this->setError(__LINE__, __FUNCTION__, $message);
			return false;
		}

		return $this->executeQuery($this->statement);
		
	}
	
	/**
	 *	executes a query (prepared by prepareQuery)
	 *
	 *	query is executed and various variables are set
	 *	for use later
	 */
	private function executeQuery( $statement ){
		global $wpdb;
		if (!is_object($statement)) return;
		if (count($this->extractedVariables) > 0){
			$this->queries[] = "Executing:\t ".print_r($this->extractedVariables, true);
			do {
				$result = $statement->execute($this->extractedVariables);
				if (!$result){
					$reasons = $statement->errorinfo();
					$reason = $reasons[1];
				} else {
					$reason = 0;
				}
			} while ($reason == 17);
		} else {
			$this->queries[] = "Executing: (no parameters)\t ";
			do{
				$result = $statement->execute();
				if (!$result){
						$reasons = $statement->errorinfo();
						$reason = $reasons[1];
					} else {
						$reason = 0;
					}
			} while ($reason == 17);
		}
		if ($result === false){
			$message = "Error executing query. Error was was ". $reasons[2];
			$this->setError(__LINE__, __FUNCTION__, $message);
			return FALSE;
		}else {
			//grab the results in an associative array 
			//CONSIDER CHANGING TO FETCH OBJECTS AND THEN REWRITE THE POST PROCESSING FUNCTION
			$this->_results = $statement->fetchAll(PDO::FETCH_ASSOC);
		}
		
		//generate the results that $wpdb will want to see
		switch ($this->queryType){
			case "insert":
			case "update":
			case "replace":
				$this->lastInsertID = $this->pdo->lastInsertId();
				$this->affectedRows = $statement->rowCount();
				$this->returnValue = $this->affectedRows;
				
			break;
			case "select":
			case "show":
			case "describe":
			case "foundrows":
				$this->numRows = count($this->_results);
				$this->returnValue = $this->numRows;
				
			break;
			case "delete":
				$this->affectedRows = $statement->rowCount();
				$this->returnValue = $this->affectedRows;
			break;
			case "alter":
			case "drop":
			case "create":
				if ($this->isError){
					$this->returnValue = true;
				}else {
					$this->returnValue = false;
				}
			break;
		}
	}
	
	/**
	*	releases all string variables and replaces with placeholders
	*
	*	note that this only handles mysql variables that are enquoted.
	*	so variables passed as integers are not given this protection.  
	*	this _should_ always be OK ...
	*	interacts with replaceVariablesWithPlaceHolders	
	*/
	private function extractVariables(){
		if ($this->queryType == 'create'){
			$this->preparedQuery = $this->rewrittenQuery;
			return;
		}
		
		//long queries can really kill this
		$pattern = '/(?<!\\\\)([\'"])(.*?)(?<!\\\\)\\1/imsx';
		$_limit = $limit = ini_get('pcre.backtrack_limit');
		do{
			if ($limit > 10000000){
				$message = "The query is too big to parse properly";
				$this->setError(__LINE__, __FUNCTION__, $message);
				break; //no point in continuing execution, would get into a loop
			} else {
				ini_set('pcre.backtrack_limit', $limit);
				$query = preg_replace_callback($pattern, array($this,'replaceVariablesWithPlaceHolders'), $this->rewrittenQuery);	
			}
			$limit = $limit * 10;
		} while (empty($query));
		
		//reset the pcre.backtrack_limit
		ini_set('pcre.backtrack_limit', $_limit);
		$this->queries[]= "With Placeholders: $query ";
		$this->preparedQuery = $query;
	}
	
	/**
	 *	substitutes enquoted variables (i.e. strings and dates and blobs etc) with placeholders
	 *
	 *	this callback function assumes that variables are escaped for use in
	 *	mysql, i.e. using an inline method (plugin) or a call to $wpdb.  
	 *	it unescapes them, does a bit of cleansing and then spits
	 *	back out a clean variable into a holding array and returns 
	 *	a SQL placeholder for use in the prepared query.
	 *
	 *	@param $matches array [an array provided by the calling function]
	 *	@return string '?' a place holder for use in SQL queries
	 */
	private function replaceVariablesWithPlaceHolders($matches){
		//remove the wordpress escaping mechanism
		$param = stripslashes($matches[0]);
		
		//remove trailing spaces
		$param = trim($param); 
		
		//remove the quotes at the end and the beginning
		if (in_array($param{strlen($param)-1}, array("'",'"'))){
			$param = substr($param,0,-1) ;//end
		}
		if (in_array($param{0}, array("'",'"'))){
			$param = substr($param, 1); //start
		}
		$this->extractedVariables[] = $param;
		//return the placeholder
		return ' ? ';
	}
	
	/**
	 *	takes the query and determines the query type
	 *	
	 *	we divide up the types of query as follows:
	 *	-select
	 *	-select found_rows
	 *	-insert
	 *	-update/replace
	 *	-delete
	 *	-alter
	 *	-create
	 *	-drop
	 *	the result is stored in the queryType property
	 *
	 *	@param string $query - the query being analysed
	 */
	public function determineQueryType($query){
		$result = preg_match('/^\\s*(select\\s*found_rows|select|insert|update|replace|delete|alter|create|drop|show|describe|truncate|optimize)/i', $query, $match);
		
		if (!$result){
			$bailoutString = <<<HTML
<h1>Unknown query type</h1>
<p>Sorry, we cannot determine the type of query that is being requested.</p>
<p>The query is {$query}</p>
HTML;
			$this->setError(__LINE__, __FUNCTION__, $bailoutString);
		} else {
			$this->queryType = strtolower($match[1]);
			if(stristr($this->queryType, 'found') !== FALSE){
				$this->queryType = "foundrows";
			}else {
				if (stristr($this->queryType, 'insert')){
					$pattern = '/(INSERT.*VALUES\s*)(\(.*\))/imsx';
					preg_match($pattern, $query, $match);
					$explodedParts = explode ('),', $match[2]);
					$count = count($explodedParts);
					if ($count > 1){
						$this->queryType = 'multiInsert';
						$this->multiInsertMatches = array($match[1], $explodedParts, $count);
					}
				}
			}
		}
	}
	
	/**
	 *	method for adding data to the error array
	 *	
	 *	@param string $line	the line of the script that throws the error
	 *	@param string $function	the name of the function in which the error was thrown
	 */
	private function setError ($line, $function, $message){
		$this->errors[] = array("line"=>$line, "function"=>$function);
		$this->errorMessages[] = $message;
		$this->isError = true;
		file_put_contents (FQDBDIR .'/debug.txt', "Line $line, Function: $function, Message: $message \n", FILE_APPEND);
	}
	
	/**
	*	method that takes the associative array of query results and creates a numeric array of anonymous objects
	*/
	private function convertToObject(){
		$_results = array();
		if (count ($this->results) === 0){
			echo $this->getErrorMessage();
		} else {
		foreach($this->results as $row){
			$_results[] =  new objArray($row);
		}	
		}	
		$this->results = $_results;
	}

}

/**
 *	class for creating an anonymous object
 */
class objArray 
{ 
    function __construct($data = null,&$node= null) 
    { 
        foreach ($data as $key => $value) 
        { 
            if ( is_array($value) ) 
            { 
                if (!$node) 
                { 
                    $node =& $this; 
                } 
                    $node->$key = new stdClass();         
                    self::__construct($value,$node->$key); 
            } 
            else 
            { 
                if (!$node) 
                { 
                    $node =& $this; 
                } 
                $node->$key = $value; 
            } 
        } 
    } 
}
?>
