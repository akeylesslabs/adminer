<?php
// Adminer 6.1.2 calls set_schema() from the SQL command page for every driver that has
// schemas, but its Oracle driver no longer defines it (5.4.2 did; pgsql and mssql still do).
// Restore the 5.4.2 implementation for Oracle only. The guard mirrors how the other drivers
// scope their definition, so there is nothing to collide with.
namespace Adminer;

if (isset($_GET["oracle"]) && !function_exists('Adminer\\set_schema')) {
	function set_schema($scheme, $connection2 = null) {
		if (!$connection2) {
			$connection2 = connection();
		}
		return $connection2->query("ALTER SESSION SET CURRENT_SCHEMA = " . idf_escape($scheme));
	}
}
