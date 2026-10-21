<?php
// Akeyless fork of upstream plugins/drivers/mongo.php (Adminer 6.1.2) with three changes:
// 1. Parameter type declarations removed from every function signature. The compiled
//    adminer-6.1.2.php declares the SqlDb/SqlDriver methods this file overrides without
//    parameter types, and PHP refuses an override that narrows a parameter type.
// 2. attach(): connection strategy with SRV-then-plain scheme and admin-first authSource
//    (see attachWithRetries), carried over from our 5.4.2 fork.
// 3. Collection create/drop/truncate fall back to database commands when the legacy
//    database handle is unavailable, carried over from our 5.4.2 fork.
namespace Adminer;

add_driver("mongo", "MongoDB alpha");

if (isset($_GET["mongo"])) {
	define('Adminer\DRIVER', "mongo");

	if (class_exists('MongoDB\Driver\Manager')) {
		class Db extends SqlDb {
			public $extension = "MongoDB", $server_info = MONGODB_VERSION, $last_id;
			/** @var \MongoDB\Driver\Manager */ public $_link;
			public $_db, $_db_name;

			function attach($server, $username, $password): string {
				$options = array();
				if ($username . $password != "") {
					$options["username"] = $username;
					$options["password"] = $password;
				}
				$db = adminer()->database();
				if ($db != "") {
					$options["db"] = $db;
				}
				if (($auth_source = getenv("MONGO_AUTH_SOURCE"))) {
					$options["authSource"] = $auth_source;
				}
				$hostport = url_host($server["host"]) . ($server["port"] != "" ? ":$server[port]" : "");
				if (empty($options["username"])) {
					// ping doesn't require authentication so it would pass even on a server requiring it
					$this->_link = new \MongoDB\Driver\Manager("mongodb://$hostport", $options);
					return ($this->executeDbCommand("admin", array('listDatabases' => 1)) ? '' : $this->error);
				}
				$this->_db_name = idx($options, "db", "");
				return $this->attachWithRetries($hostport, $options);
			}

			// Akeyless: try the SRV scheme first (Atlas-style hosts), then plain; authenticate
			// against the driver default ("admin") first and fall back to the login database as
			// authSource. Upstream authenticates against the login database only, which fails for
			// a user that lives in "admin" opening another database.
			private function attachWithRetries($hostport, array $options): string {
				$lastError = '';
				foreach (array("mongodb+srv://", "mongodb://") as $uriPrefix) {
					try {
						$this->_link = new \MongoDB\Driver\Manager($uriPrefix . $hostport, $options);
						$this->executeDbCommand('admin', array('ping' => 1), true);
						return '';
					} catch (\Exception $e) {
						$lastError = $e->getMessage();
						$this->error = $lastError;
					}
				}
				if (empty($options["authSource"]) && !empty($options["db"])) {
					$options["authSource"] = $options["db"];
					return $this->attachWithRetries($hostport, $options);
				}
				return $lastError;
			}

			function executeCommand($command) {
				return $this->executeDbCommand($this->_db_name, $command);
			}

			function executeDbCommand($db, $command, $throwErrors = false) {
				try {
					return $this->_link->executeCommand($db, new \MongoDB\Driver\Command($command));
				} catch (\Exception $e) {
					$this->error = $e->getMessage();
					if ($throwErrors) {
						throw $e;
					}
					return array();
				}
			}

			function executeBulkWrite($namespace, $bulk, $counter): bool {
				try {
					$results = $this->_link->executeBulkWrite($namespace, $bulk);
					$this->affected_rows = $results->$counter();
					return true;
				} catch (\Exception $e) {
					$this->error = $e->getMessage();
					return false;
				}
			}

			function query($query, $unbuffered = false) {
				return false;
			}

			function select_db($database): bool {
				$this->_db_name = $database;
				return true;
			}

			function quote($string): string {
				return json_encode($string, 256); // 256 - JSON_UNESCAPED_UNICODE available since PHP 5.4
			}
		}

		class Result {
			public $num_rows;
			private $rows = array(), $offset = 0, $types = array();

			function __construct($result) {
				foreach ($result as $item) {
					$row = array();
					foreach ($item as $key => $val) {
						if (is_a($val, 'MongoDB\BSON\Binary')) {
							$this->types[$key] = 'blob'; // 'binary' is not recognized by is_blob()
						}
						$row[$key] =
							(is_a($val, 'MongoDB\BSON\ObjectID') ? 'MongoDB\BSON\ObjectID("' . "$val\")" :
							(is_a($val, 'MongoDB\BSON\UTCDatetime') ? $val->toDateTime()->format('Y-m-d H:i:s') :
							(is_a($val, 'MongoDB\BSON\Binary') ? $val->getData() : //! allow downloading
							(is_a($val, 'MongoDB\BSON\Regex') ? "$val" :
							(is_object($val) || is_array($val) ? json_encode($val, 256) : // 256 - JSON_UNESCAPED_UNICODE available since PHP 5.4
							$val))))) // MongoMinKey, MongoMaxKey
						;
					}
					$this->rows[] = $row;
					foreach ($row as $key => $val) {
						if (!isset($this->rows[0][$key])) {
							$this->rows[0][$key] = null;
						}
					}
				}
				$this->num_rows = count($this->rows);
			}

			function fetch_assoc() {
				$row = current($this->rows);
				if (!$row) {
					return $row;
				}
				$return = array();
				foreach ($this->rows[0] as $key => $val) {
					$return[$key] = $row[$key];
				}
				next($this->rows);
				return $return;
			}

			function fetch_row() {
				$return = $this->fetch_assoc();
				if (!$return) {
					return $return;
				}
				return array_values($return);
			}

			function fetch_field(): \stdClass {
				$keys = array_keys($this->rows[0]);
				$name = $keys[$this->offset++];
				return (object) array(
					'name' => $name,
					'native_type' => $this->types[$name],
				);
			}
		}



		function get_databases($flush): array {
			$return = array();
			foreach (connection()->executeCommand(array('listDatabases' => 1)) as $dbs) {
				foreach ($dbs->databases as $db) {
					$return[] = $db->name;
				}
			}
			return $return;
		}

		function count_tables($databases): array {
			$return = array();
			return $return;
		}

		function tables_list(): array {
			$collections = array();
			foreach (connection()->executeCommand(array('listCollections' => 1)) as $result) {
				$collections[$result->name] = 'table';
			}
			return $collections;
		}

		function drop_databases($databases): bool {
			return false;
		}

		function indexes($table, $connection2 = null): array {
			$return = array();
			foreach (connection()->executeCommand(array('listIndexes' => $table)) as $index) {
				$descs = array();
				$columns = array();
				foreach (get_object_vars($index->key) as $column => $type) {
					$descs[] = ($type == -1 ? '1' : null);
					$columns[] = $column;
				}
				$return[$index->name] = array(
					"type" => ($index->name == "_id_" ? "PRIMARY" : (isset($index->unique) ? "UNIQUE" : "INDEX")),
					"columns" => $columns,
					"lengths" => array(),
					"descs" => $descs,
				);
			}
			return $return;
		}

		function fields($table): array {
			$driver = driver();
			$fields = fields_from_edit();
			if (!$fields) {
				$result = $driver->select($table, array("*"), array(), array(), array(), 10);
				if ($result) {
					while ($row = $result->fetch_assoc()) {
						foreach ($row as $key => $val) {
							$row[$key] = null;
							$fields[$key] = array(
								"field" => $key,
								"type" => "string",
								"null" => ($key != $driver->primary),
								"auto_increment" => ($key == $driver->primary),
								"privileges" => array(
									"insert" => 1,
									"select" => 1,
									"update" => 1,
									"where" => 1,
									"order" => 1,
								),
							);
						}
					}
				}
			}
			return $fields;
		}

		function found_rows($table_status, $where) {
			$where = where_to_query($where);
			$toArray = connection()->executeCommand(array('count' => $table_status['Name'], 'query' => $where))->toArray();
			return $toArray[0]->n;
		}

		function sql_query_where_parser($queryWhere): array {
			$queryWhere = preg_replace('~^\s*WHERE\s*~', "", $queryWhere);
			while ($queryWhere[0] == "(") {
				$queryWhere = preg_replace('~^\((.*)\)$~', "$1", $queryWhere);
			}

			$wheres = explode(' AND ', $queryWhere);
			$wheresOr = explode(') OR (', $queryWhere);
			$where = array();
			foreach ($wheres as $whereStr) {
				$where[] = trim($whereStr);
			}
			if (count($wheresOr) == 1) {
				$wheresOr = array();
			} elseif (count($wheresOr) > 1) {
				$where = array();
			}
			return where_to_query($where, $wheresOr);
		}

		/** Get value from an expression created by Db::quote()
		* @param string|mixed[] $expression array from the json function
		* @return string|mixed[]|null null for expressions which are not values, e.g. NULL
		*/
		function unquote($expression) {
			if (is_array($expression)) {
				return $expression;
			}
			$return = json_decode($expression, true);
			return (json_last_error() ? null : $return);
		}

		function where_to_query($whereAnd = array(), $whereOr = array()): array {
			$data = array();
			foreach (array('and' => $whereAnd, 'or' => $whereOr) as $type => $where) {
				if (is_array($where)) {
					foreach ($where as $expression) {
						list($col, $op, $val) = explode(" ", $expression, 3);
						$val = unquote($val);
						if ($col == "_id" && preg_match('~^(MongoDB\\\\BSON\\\\ObjectID)\("(.+)"\)$~', $val, $match)) {
							list(, $class, $val) = $match;
							$val = new $class($val);
						}
						if (!in_array($op, adminer()->operators())) {
							continue;
						}
						if (preg_match('~^\(f\)(.+)~', $op, $match)) {
							$val = (float) $val;
							$op = $match[1];
						} elseif (preg_match('~^\(date\)(.+)~', $op, $match)) {
							$dateTime = new \DateTime($val);
							$val = new \MongoDB\BSON\UTCDatetime($dateTime->getTimestamp() * 1000);
							$op = $match[1];
						}
						switch ($op) {
							case '=':
								$op = '$eq';
								break;
							case '!=':
								$op = '$ne';
								break;
							case '>':
								$op = '$gt';
								break;
							case '<':
								$op = '$lt';
								break;
							case '>=':
								$op = '$gte';
								break;
							case '<=':
								$op = '$lte';
								break;
							case 'regex':
								$op = '$regex';
								break;
							default:
								continue 2;
						}
						if ($type == 'and') {
							$data['$and'][] = array($col => array($op => $val));
						} elseif ($type == 'or') {
							$data['$or'][] = array($col => array($op => $val));
						}
					}
				}
			}
			return $data;
		}
	}



	class Driver extends SqlDriver {
		static $extensions = array("mongodb");
		static $jush = "mongo";

		public $insertFunctions = array("json");

		public $primary = "_id";

		function operators($tableStatus): array {
			return array(
				"=",
				"!=",
				">",
				"<",
				">=",
				"<=",
				"regex",
				"(f)=",
				"(f)!=",
				"(f)>",
				"(f)<",
				"(f)>=",
				"(f)<=",
				"(date)=",
				"(date)!=",
				"(date)>",
				"(date)<",
				"(date)>=",
				"(date)<=",
			);
		}

		static function connect($server, $username, $password) {
			if ($server == "") {
				$server = "localhost:27017";
			}
			return parent::connect($server, $username, $password);
		}

		function select($table, $select, $where, $group, $order = array(), $limit = 1, $page = 0, $print = false) {
			$select = ($select == array("*")
				? array()
				: array_fill_keys($select, 1)
			);
			if (count($select) && !isset($select['_id'])) {
				$select['_id'] = 0;
			}
			$where = where_to_query($where);
			$sort = array();
			foreach ($order as $val) {
				$val = preg_replace('~ DESC$~', '', $val, 1, $count);
				$sort[$val] = ($count ? -1 : 1);
			}
			$options = array('projection' => $select, 'sort' => $sort);
			if ($limit) { // 0 - all rows, used by export
				$limit = min(200, $limit);
				$options['limit'] = $limit;
				$options['skip'] = $page * $limit;
			}
			try {
				return new Result($this->conn->_link->executeQuery(
					$this->conn->_db_name . ".$table",
					new \MongoDB\Driver\Query($where, $options)
				));
			} catch (\Exception $e) {
				$this->conn->error = $e->getMessage();
				return false;
			}
		}

		function update($table, $set, $queryWhere, $limit = 0, $separator = "\n") {
			$db = $this->conn->_db_name;
			$where = sql_query_where_parser($queryWhere);
			$bulk = new \MongoDB\Driver\BulkWrite(array());
			if (isset($set['_id'])) {
				unset($set['_id']);
			}
			$removeFields = array();
			foreach ($set as $key => $value) {
				$value = unquote($value);
				if ($value === null) { // NULL and other expressions
					$removeFields[$key] = 1;
					unset($set[$key]);
				} else {
					$set[$key] = $value;
				}
			}
			$update = array('$set' => $set);
			if (count($removeFields)) {
				$update['$unset'] = $removeFields;
			}
			$bulk->update($where, $update, array('upsert' => false));
			return $this->conn->executeBulkWrite("$db.$table", $bulk, 'getModifiedCount');
		}

		function delete($table, $queryWhere, $limit = 0) {
			$db = $this->conn->_db_name;
			$where = sql_query_where_parser($queryWhere);
			$bulk = new \MongoDB\Driver\BulkWrite(array());
			$bulk->delete($where, array('limit' => $limit));
			return $this->conn->executeBulkWrite("$db.$table", $bulk, 'getDeletedCount');
		}

		function insert($table, $set) {
			$db = $this->conn->_db_name;
			$bulk = new \MongoDB\Driver\BulkWrite(array());
			foreach ($set as $key => $value) {
				$value = unquote($value);
				if ($value === null) { // NULL and other expressions
					unset($set[$key]); // the field is not created at all
				} else {
					$set[$key] = $value;
				}
			}
			if ($set['_id'] == '') {
				unset($set['_id']);
			}
			$bulk->insert($set);
			return $this->conn->executeBulkWrite("$db.$table", $bulk, 'getInsertedCount');
		}

		function isSystem($db, $schema = ""): bool {
			return in_array($db, array("admin", "config", "local"));
		}
	}



	function table($idf): string {
		return $idf;
	}

	function idf_escape($idf): string {
		return $idf;
	}

	function limit($query, $where, $limit, $offset = 0, $separator = " "): string {
		return " $query$where" . ($limit ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	function limit1($table, $query, $where, $separator = "\n"): string {
		return limit($query, $where, 1, 0, $separator);
	}

	function table_status($name = "", $fast = false): array {
		$return = array();
		foreach (($name != "" ? array($name => 1) : tables_list()) as $table => $type) {
			$return[$table] = array("Name" => $table, "Engine" => "");
		}
		return $return;
	}

	function create_database($db, $collation) {
		return true;
	}

	function rename_database($name, $collation): bool {
		return false;
	}

	function last_id($result) {
		return connection()->last_id;
	}

	function error(): string {
		return h(connection()->error);
	}

	function collations(): array {
		return array();
	}

	function logged_user(): string {
		$credentials = adminer()->credentials();
		return $credentials[1];
	}

	function alter_indexes($table, $alter) {
		foreach ($alter as $val) {
			list($type, $name, $set) = $val;
			if ($set == "DROP") {
				$return = connection()->_db->command(array("deleteIndexes" => $table, "index" => $name));
			} else {
				$columns = array();
				foreach ($set as $column) {
					$column = preg_replace('~ DESC$~', '', $column, 1, $count);
					$columns[$column] = ($count ? -1 : 1);
				}
				$return = connection()->_db->selectCollection($table)->ensureIndex($columns, array(
					"unique" => ($type == "UNIQUE"),
					"name" => $name,
					//! "sparse"
				));
			}
			if ($return['errmsg']) {
				connection()->error = $return['errmsg'];
				return false;
			}
		}
		return true;
	}

	function support($feature): bool {
		return preg_match("~database|indexes|descidx~", $feature);
	}

	function db_collation($db, $collations) {
	}

	function information_schema($db) {
	}

	function is_view($table_status) {
	}

	function convert_field($field) {
	}

	function unconvert_field($field, $return): string {
		return $return;
	}

	function foreign_keys($table): array {
		return array();
	}

	function fk_support($table_status) {
	}

	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		if ($table == "") {
			if (!is_null(connection()->_db)) {
				connection()->_db->createCollection($name);
			} else {
				connection()->executeCommand(array('create' => $name));
				if (connection()->error) {
					return false;
				}
			}
			return true;
		}
	}

	function drop_tables($tables) {
		foreach ($tables as $table) {
			if (!is_null(connection()->_db)) {
				$response = connection()->_db->selectCollection($table)->drop();
				if (!$response['ok']) {
					return false;
				}
			} else {
				connection()->executeCommand(array('drop' => $table));
				if (connection()->error) {
					return false;
				}
			}
		}
		return true;
	}

	function truncate_tables($tables): bool {
		foreach ($tables as $table) {
			if (!is_null(connection()->_db)) {
				$response = connection()->_db->selectCollection($table)->remove();
				if (!$response['ok']) {
					return false;
				}
			} else {
				connection()->executeCommand(array('remove' => $table));
				if (connection()->error) {
					return false;
				}
			}
		}
		return true;
	}
}
