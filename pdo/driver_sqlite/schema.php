<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */
 
 /**
  *	this script just installs the base database schema as part of the installation process
  */

function installDB(){
	require_once PDODIR . 'driver_sqlite/pdo_sqlite_driver_create.php';
	ob_end_clean();
	echo "installing the database <br/>";
	$pattern = '/\$wp_queries\\s*=\\s*"(.*)";/imsx';
	$contents =  file_get_contents(ABSPATH . 'wp-admin/includes/schema.php');
	echo htmlspecialchars($contents);
	$wp_queries = preg_match('/\$wp_queries\\s*=\\s*"(.*?)";/imsx', $contents, $match);
	echo "<pre>".print_r($match, true);
	$wp_queries = preg_replace('/\$wpdb\\s*->/imsx', "wp_", $match[1]);
	$wp_queries = preg_replace('/\$charset_collate/imsx', '', $wp_queries);
	
	//add a query to deal with file mods
	$query = "create table modTimes (modFile text not null primary key, modTime text not null default '0000-00-00 00:00:00')";
	
	$queries = explode (";", $wp_queries);
	array_push($queries, $query);
	$contents = null;
	$pdo = new PDO('sqlite:'.FQDB);
	foreach ($queries as $query){
		$query = trim($query);
		if (empty($query)) continue;
		$q = new createQuery();
		$_q = $q->rewriteQuery($query);
		echo $_q . "<br/>";
		$q = NULL;
		if (is_array($_q)){
			foreach ($_q as $__q){
				if (!empty($__q)){
					$result = $pdo->exec($__q);
					if ($result === false){
						echo "Error installing the database<br/>Query was $__q.<br/>Error message was: " . print_r($wpdb->pdo->errorInfo(), true);			
					}
				}
			}
		} else {
			$result = $pdo->exec($_q);
			if ($result === false){
				echo "Error installing the database<br/>Query was $_q.<br/>Error message was: " . print_r($wpdb->errorInfo(), true);			
			}
		}
	}
	return true;
}
?>