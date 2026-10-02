<?php
namespace DB;
class MySQLi {
	private $connection;
	private $lookup_cache = [];

	public function __construct($hostname, $username, $password, $database, $port = '3306') {
		try {
			$mysqli = @new \MySQLi($hostname, $username, $password, $database, $port);
		} catch (mysqli_sql_exception $e) {
			throw new \Exception('Error: Could not make a database link using ' . $username . '@' . $hostname . '!');
		}

		if (!$mysqli->connect_errno) {
			$this->connection = $mysqli;
			$this->connection->report_mode = MYSQLI_REPORT_ERROR;
			$this->connection->set_charset('utf8');
			$this->connection->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
		} else {
			throw new \Exception('Error: Could not make a database link using ' . $username . '@' . $hostname . '!');
		}
	}

	public function query($sql) {
		$cacheable_lookup = $this->isCacheableLookup($sql);

		if ($cacheable_lookup && isset($this->lookup_cache[$sql])) {
			return clone $this->lookup_cache[$sql];
		}

		if (!$cacheable_lookup && $this->touchesCachedLookupTable($sql)) {
			$this->lookup_cache = [];
		}

		$query = $this->connection->query($sql);

		if (!$this->connection->errno) {
			if ($query instanceof \mysqli_result) {
				$data = [];

				while ($row = $query->fetch_assoc()) {
					$data[] = $row;
				}

				$result = new \stdClass();
				$result->num_rows = $query->num_rows;
				$result->row = isset($data[0]) ? $data[0] : [];
				$result->rows = $data;

				if ($cacheable_lookup && $result->num_rows <= 10 && count($this->lookup_cache) < 512) {
					$this->lookup_cache[$sql] = clone $result;
				}

				$query->close();

				unset($data);

				return $result;
			} else {
				return true;
			}
		} else {
			throw new \Exception('Error: ' . $this->connection->error  . '<br />Error No: ' . $this->connection->errno . '<br />' . $sql);
		}
	}

	private function isCacheableLookup($sql) {
		if (!defined('DB_PREFIX') || !preg_match('/^\s*SELECT\b/i', $sql)) {
			return false;
		}

		$prefix = preg_quote(constant('DB_PREFIX'), '/');
		$pattern = '/^\s*SELECT\b.*?\bFROM\s+`?' . $prefix . '(?:seo_url|hb_url)`?\s+WHERE\s+(.*)$/is';

		if (!preg_match($pattern, $sql, $matches)) {
			return false;
		}

		$where = $matches[1];

		if (preg_match('/\b(?:LIKE|IN)\s*\(/i', $where) || stripos($where, ' LIKE ') !== false) {
			return false;
		}

		return (bool)preg_match('/`?(?:query|keyword|route)`?\s*=\s*/i', $where);
	}

	private function touchesCachedLookupTable($sql) {
		if (!defined('DB_PREFIX')) {
			return false;
		}

		$prefix = preg_quote(constant('DB_PREFIX'), '/');

		return (bool)preg_match('/`?' . $prefix . '(?:seo_url|hb_url)`?(?![A-Z0-9_])/i', $sql);
	}

	public function escape($value) {
		return $this->connection->real_escape_string($value);
	}

	public function countAffected() {
		return $this->connection->affected_rows;
	}

	public function getLastId() {
		return $this->connection->insert_id;
	}

	public function isConnected() {
		if ($this->connection) {
			return $this->connection->ping();
		} else {
			return false;
		}
	}

	/**
	 * __destruct
	 *
	 * Closes the DB connection when this object is destroyed.
	 *
	 */
	public function __destruct() {
		if ($this->connection) {
			$this->connection->close();

			$this->connection = '';
		}
	}
}
