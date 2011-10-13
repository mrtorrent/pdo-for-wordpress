=== PDO (SQLite) For Wordpress ===
Contributors: Justin Adie (http://rathercurious.net)
Tags: database, PDO, sqlite, SQLite
Requires at least: 2.3.0
Donate Link: rathercurious.net
Tested up to: 2.9.1
Stable tag: 2.7.0

This 'plugin' enables WP to use databases supported by PHP's PDO abstraction layer. Currently, mysql and sqlite drivers are provided.

== Description ==

Wordpress has for a long time, and for good reasons, been locked into using mysql as its database storage engine.  There is a good discussion of 'why' [in the codex](http://codex.wordpress.org/Using_Alternative_Databases#Solutions/ "Codex Discussion")

But this design choice has ramifications; not least because mysql's implementation of sql is not standard.  Even with the use of the EZSQL abstraction layer bundled with Wordpress, this makes plugging in other databases very difficult.

PDO For Wordpress is a step towards eliminating this difficulty.  Think about this 'plugin' in four steps:

1.	the basic layer takes all queries and separates out the variables from the language.  It replaces each variable with a placeholder as well as stripping mysql specific 'nasties' like the slash-escaping and backticks.
1.	then a language specific driver steps in and rewrites the query to use its own native constructs or (in the case of SQLite) pushes the query into some special user-defined functions
1.	the basic layer then puts it all back together and runs the query, finally ...
1.	returning the whole thing to the EZSQL abstraction layer so that Wordpress doesn't know that anything has gone awry

See below/other notes for details of known limitations

== Installation ==

This plugin is not a simple Wordpress plugin. Beware.  You cannot just put the files in plugins and enable through the control panel.  This is because the database needs to be connected adn established BEFORE the plugins are loaded.

So ... to install PDO For Wordpress please do the following:

1.	Step 1:
	unzip the files in your wp-content directory.  After unzipping the structure should look like this

	wp-content  
	->plugins  
	->themes  
	->pdo  
	db.php  
	index.php[maybe]  

	The key thing is the presence of the pdo directory and the db.php file in the 'root' of the wp-content directory.

1.	Step 2:
	Edit your wp-config.php file so that:

	this line of code is placed directly after the define('COLLATE',''); line:

		define('DB_TYPE', 'sqlite');	//mysql or sqlite`

	Note: currently only mysql and sqlite are supported. I hope that more flavours will appear soon.

	As part of the general wordpress installation you should define your secret keys too (in wp-config.php). 

and that's it.  two steps.  

The next time you load your wordpress installation it will automatically create the database and take you through the basic Wordpress installation routine.
If you have problems with the installation you should receive a meaningful error messsage.  If not, the most common error by far is permissions.  You MUST make sure that php can read/write to wp-content/database.

== Frequently Asked Questions ==

What databases are supported?

Currently the basic layer supports any database that is supported by PDO.

*	MS SQL Server (PDO) â  Microsoft SQL Server and Sybase Functions (PDO_DBLIB)  
*	Firebird/Interbase (PDO) â  Firebird/Interbase Functions (PDO_FIREBIRD)  
*	IBM (PDO) â  IBM Functions (PDO_IBM)  
*	Informix (PDO) â  Informix Functions (PDO_INFORMIX)  
*	MySQL (PDO) â  MySQL Functions (PDO_MYSQL)  
*	Oracle (PDO) â  Oracle Functions (PDO_OCI)  
*	ODBC and DB2 (PDO) â  ODBC and DB2 Functions (PDO_ODBC)  
*	PostgreSQL (PDO) â  PostgreSQL Functions (PDO_PGSQL)  
*	SQLite (PDO) â  SQLite Functions (PDO_SQLITE)  

Note that through the PDO_ODBC extension, all ODBC supported databases are also supported, subject to drivers being available

HOWEVER each database needs its own driver and currently the only drivers written for this plugin are for 

*	sqlite and   
*	mysql


The database does not install.  Why?

the main reason for this is permissions.  The php process on your server needs to have permissions to create files and directories. 
Please contact me by leaving a comment at rathercurious.net if this is affecting you.

== Screenshots ==
There are no screenshots

== Known Limitations ==
*	this plugin requires PHP 5.0 + as it uses PDO.  There is no workaround.
*	the database schema cannot be upgraded through the WP automatic systems. I am working on this: it is non-trivial
*	some plugins will not upgrade as they use the WP upgrade functions to upgrade their databases.  Create statements issued through dbdelta() WILL work, however
*	some plugins will not install/work as they do use native mysql calls rather than the WP abstraction layer.  This is contrary to WordPress API guidelines.

== To Do ==
* 	parameterise the database location to allow people to change the name and the location of the database
*	write a routine to allow upgrades/changes to the database schema.  This is not easy as sqlite does not support the alter syntax to the same extent that mysql does.  [We could just fudge this and leave old columns in the table]*	examine and consider replacing some of the clunky code in the sqlite engine to use user defined functions in some cases (e.g. dates and if).  
*	remove some of the clunky debug code
*	consider adding collation support.
*	remove inefficiency of object recreation at the end of the post-processing. it should be easier to keep the pdo resultset as an array of objects to start with.
*	consider using WP's own error handling class  
*	consider revising the prepare->execute syntax to a pure query type syntax for certain types of query  
*	consider altering the directory structure so that the majority of files can sit under the plugins directory and use the auto-updater.  
*	or write an updater plugin (is that possible if they come from different sources?)

== Attributions ==
Early versions of this plugin used a complete replacement for the WP abstraction layer.  Thanks to Ulf Ninow for pointing out the value of inheritance to me and thus hugely simplifying the upkeep of the plugin.

== Version Information ==
version 2.7.0 - 2010 January 13
changes in the WP installation code broke this plugin.  Compatibility is now fixed through a rewrite of wp_install
changes in 2.8.1 broke some other functionality.  Essentially greater use of mysql >=4.1 functions means that it's a constant game of catch up to rewrite.  Really the WP core team should go back to basics and use pure SQL ANSI syntax (particularly if the rumours about migration to MS SQL are true)
Move to UDF's in place of a whole bunch of regex work.  this should speed up execution.
and other minor changes.
still have to fix up the debug code.  it's horribly clunky but we need very accurate feedback to debug failing queries...  

Version 2.6.1 - 2009 June 13
fix error in Optimize queries - thanks fnumatic
fix small error in multi-inserts leading to problem with importing from existing wordpress installation
NB there is still a problem with the regex on multi-inserts, as yet no fix but the error should not manifest except as a small performance decrease

Version 2.6.0 - 2009 June 01
create a new query type to handle insert multiples
fix bug with install routine and sqlite schema changes

Version 2.5.0 - 2009 May 04
[version 2.0.0 + has had a bunch of problems in it (inc 2.4.0).  how these crept in is unknown.]
fixes inconsistency between string and integer based data comparisons in sqlite


Version 2.4.0 - 2009 May 03 
fix another installation problem linked to query buffering

Version 2.3.0 - 2000 May 02
added some more checking into the install routine to avoid perms problems
cleaned up the date handling thanks to Nicholas Schmid.  This will improve permalinks

Version 2.2.0 - 2009 April 29
create new tag and recommit because tag 2.1.0 was corrupt

Version 2.1.0 - 2009 April 21
added global $wpdb to the connect method in PDOEngine.php
fixed some umask errors

Version 2.0.0 - 2009 April 03
overhaul of the sqlite code to use UDF's in place of regex (for the most part)
overhaul of the base class to reuse the wpdb class by inheritance

Version 1.0.2 - 2008 June 29
version control issue.  not all files were committed to svn in 1.0.1

Version 1.0.1 - 2008 June 28
Fixed bug where the comments did not display in Manage Comments due to the use of index hints in the mysql sql
