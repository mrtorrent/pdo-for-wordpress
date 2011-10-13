<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
/**
 *	provides a class for rewriting create queries
 *
 *	this class borrows its inspiration from the work done on tikiwiki.
 */
class createQuery{

	private $_query = array();
	private $indexQueries = array();
	private $_errors = array();
	private $tableName = '';
	private $hasPrimaryKey = false;
	
	/**
	 *	initialises the object properties
	 *	@param string $query	the query being processed
	 *	@return string	the processed (rewritten) query
	 */
	public function rewriteQuery($query){
		$this->_query[] = $query;
		$this->_errors [] = '';
		$this->getTableName();
		$this->rewriteComments();
		$this->rewriteFieldTypes();
		$this->rewrite_character_set_();
		$this->rewriteEngineInfo();
		$this->rewriteUnsigned();
		$this->rewriteAutoIncrement();
		$this->rewritePrimaryKey();
		$this->rewriteUniqueKey();
		$this->rewriteEnum();
		$this->rewriteSet();
		$this->rewriteKey();
		$this->addIfNotExists();
		$this->stripBackticks();
		
		return $this->postProcess();
	}
	
	/**
	 *	method for getting the table name from the create query.
	 *
	 *	we need the table name later on, to rebuild the query
	 */
	private function getTableName(){
		$pattern = '/^\\s*CREATE\\s*(TEMP|TEMPORARY)?\\s*TABLE\\s*(IF NOT EXISTS)?\\s*([^\(]*)/imsx';
		preg_match($pattern, $this->getCurrentQuery(), $matches);
		$this->tableName =  $matches[3];
	}
	
	/**
	 *	method for getting the table name from the create query.
	 *
	 *	we need the table name later on, to rebuild the query
	 */
	private function rewriteFieldTypes(){
		$arrayTypes = array ( 'bit' => 'integer', 'bool' => 'integer', 'boolean' => 'integer', 'tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer', 'integer' => 'integer', 'bigint' => 'integer', 'float' => 'real', 'double' => 'real', 'decimal' => 'real', 'dec' => 'real', 'numeric' => 'real', 'fixed' => 'real', 'date' => 'text', 'datetime' => 'text', 'timestamp' => 'text', 'time' => 'text', 'year' => 'text', 'char' => 'text', 'varchar' => 'text', 'binary' => 'integer', 'varbinary' => 'blob', 'tinyblob' => 'blob', 'tinytext' => 'blob', 'blob' => 'blob', 'text' => 'text', 'mediumblob' => 'blob', 'mediumtext' => 'text', 'longblob' => 'blob', 'longtext' => 'text');
		foreach ($arrayTypes as $o=>$r){
			$pattern = '/\\b(?<!`)'.$o.'\\b\\s*(\([^\)]*\)*)?\\s*/imsx';
			$_query = preg_replace($pattern, " $r ", $this->getCurrentQuery());
			$this->addQuery($_query);
		}
	}

	/**
	 *	method for stripping the backticks from the create query
	 *
	 *	taken from the tikiwiki project
	 */	
	private function rewriteComments(){
		$_query = preg_replace("/# --------------------------------------------------------/","-- ******************************************************",$this->getCurrentQuery());
		$this->addQuery($_query);
		$_query = preg_replace("/#/","--",$this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for stripping the engine and other related suffix information
	 */	
	private function rewriteEngineInfo(){
		$_query = preg_replace("/ TYPE\\s*=\\s*.*($| )/ims","",$this->getCurrentQuery());
		$this->addQuery($_query);
		$_query = preg_replace("/ AUTO_INCREMENT\\s*=\\s*[0-9]*/ims","",$this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for stripping Unsigned
	 *
	 *	sqlite does not support the use of the unsigned keyword.  
	 */		
	private function rewriteUnsigned(){
		$_query  = preg_replace('/\\bunsigned\\b/ims', ' ', $this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for rewriting auto_increment uses
	 */	
	private function rewriteAutoIncrement(){
		$_query  = preg_replace('/\\bauto_increment\\b\\s*(,)?/ims', ' PRIMARY KEY AUTOINCREMENT $1', $this->getCurrentQuery(), -1, $count);
		if ($count > 0){
			$this->hasPrimaryKey = true;
		}
		$this->addQuery($_query);
	}
	
	/**
	 *	method for rewriting primary key usage
	 */	
	private function rewritePrimaryKey(){
		if ($this->hasPrimaryKey){
			$_query  = preg_replace('/\\bprimary key\\s*\([^\)]*\)/ims', ' ', $this->getCurrentQuery());
		}else{
			$_query  = preg_replace('/\\bprimary key\\s*\([^\)]*\)/ims', '$0', $this->getCurrentQuery());
		}
		$this->addQuery($_query);
	}
	
	/**
	 *	method for rewriting unique key usage
	 */	
	private function rewriteUniqueKey(){
		$_query  = preg_replace_callback('/\\bunique key\\b([^\(]*)(\([^\)]*\))/ims', array($this, '_rewriteUniqueKey'), $this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for handling ENUM fields
	 *
	 *	note that sqlite does not support ENUM so we have to create a text field and set constraints
	 */
	private function rewriteEnum(){
		$pattern = '/(,|\))([^,]*)enum\((.*?)\)([^,\)]*)/ims';
		$_query  = preg_replace_callback($pattern, array($this, '_rewriteEnum'), $this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for the callback function rewriteEnum and rewriteSet
	 */
	private function _rewriteEnum($matches){
		$output = $matches[1] . " " . $matches[2]. ' text '. $matches[4].' check ('.$matches[2].' IN ('.$matches[3].')) ';
		return $output;
	}
	/**
	 *	method for rewriting usage of set
	 *
	 *	whilst not identical to enum, they are similar and sqlite does not support either.
	 */
	private function rewriteSet(){
		$pattern = '/\b(\w)*\bset\\s*\((.*?)\)\\s*(.*?)(,)*/ims';
		$_query  = preg_replace_callback($pattern, array($this, '_rewriteEnum'), $this->getCurrentQuery());
		$this->addQuery($_query);
	}
	
	/**
	 *	method for rewriting usage of key to create an index
	 *
	 *	sqlite cannot create non-unique indices as part of the create query
	 *	so we need to create an index by hand and append it to the create query
	 */
	private function rewriteKey(){
		$_query  = preg_replace_callback('/,\\s*KEY([^\(]*)\(([^\)]*)\)/ims', array($this, '_rewriteKey'), $this->getCurrentQuery());
		$this->addQuery($_query);
	}

	/**
	 *	callback method for rewriteKey
	 *
	 *	@param array	$matches	an array of matches from the Regex
	 */	
	private function _rewriteKey($matches){
		$r = rand(0,50);
		$this->indexQueries[] = "CREATE INDEX ". trim($matches[1]) . "_$r on " . $this->tableName . "(".$matches[2] .")";
		return '';
	}
	
	/**
	 *	callback method for rewriteUniqueKey
	 *
	 *	@param array	$matches	an array of matches from the Regex
	 */
	private function _rewriteUniqueKey($matches){
		$r = rand(0,50);
		$iName = trim($matches[1])."_$r";
		$iName = str_replace(" ", "", $iName);
		$this->indexQueries[] = "CREATE UNIQUE INDEX $iName on " . $this->tableName . $matches[2];
		return '';
	}
	
	/**
	 *	method to maintain a stack of queries.
	 *	
	 *	I do this to assist in debugging so I can tell at which point a particular query
	 *	may have broken the rewrite rules.
	 */
	private function addQuery($query){
		$this->_query[] = $query;
		$this->_errors[] = preg_last_error();
	}
	
	/**
	 *	method to return the last query in the stack
	 *
	 *	@return string 	the current state of the query as being rewritten
	 */
	private function getCurrentQuery(){
		return $this->_query[count($this->_query)-1];
	}
	
	/**
	 *	method to return the index queries as a semi-colon delimited string
	 */
	private function getIndexQueries(){
		if (count($this->indexQueries) > 0){
			return ";" . implode("; ", $this->indexQueries);
		} else {
			return '';
		}
	}
	
	/**
	 *	method to assemble the main query and index queries into a single string to be returned to the base class
	 *
	 *	@return	string	a string of semi-colon delimited, cleansed queries
	 */
	private function postProcess(){
		$mainquery = $this->getCurrentQuery();
		do{
			$count = 0;
			$mainquery = preg_replace('/,\\s*\)/imsx',')', $mainquery, -1, $count);
		} while ($count > 0);
		$return[] = $mainquery;
		$return = array_merge($return, $this->indexQueries);
		return "; ".implode ("; ", $return);
	}
	
	/**
	 *	method to add IF NOT EXISTS to query defs
	 *
	 *	sometimes, if upgrade.php is being called, wordpress seems to want to run new create
	 *	queries. this stops the query from throwing an error and halting output
	 */
	private function addIfNotExists(){
		$pattern = '/^\\s*CREATE\\s*(TEMP|TEMPORARY)?\\s*TABLE\\s*(IF NOT EXISTS)?\\s*/ims';
		$query = preg_replace($pattern, 'CREATE $1 TABLE IF NOT EXISTS ', $this->getCurrentQuery());
		$this->addQuery($query);
		
		$pattern = '/^\\s*CREATE\\s*(UNIQUE)?\\s*INDEX\\s*(IF NOT EXISTS)?\\s*/ims';
		$i=array();
		foreach ($this->indexQueries as $iq){
			$i[] = preg_replace($pattern, 'CREATE $1 INDEX IF NOT EXISTS ', $iq);
		}
		$this->indexQueries = $i;
	}

	/**
	 *	method to stripBackticks
	 *
	 */	
	private function stripBackticks(){
		$this->addQuery(str_replace('`', '', $this->getCurrentQuery()));
	}
	
	/**
	 *	method to remove the character set information from text data types within mysql queries
	 *
	 */	
	private function rewrite_character_set_(){
		$pattern = '/\\bcharacter\\s*set\\s*(?<!\()[^ ]*/im';
		$this->addQuery(preg_replace($pattern, ' ', $this->getCurrentQuery()));
	}
}