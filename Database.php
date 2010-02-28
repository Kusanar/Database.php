<?php
/*  		  Database() is an simple, open-source MySQL wrapper			*
 *			Copyright (C) 2009 - Adrián Navarro <adrian@navarro.at>			*
 *																			*
 *	This program is free software: you can redistribute it and/or modify	*
 *	it under the terms of the GNU General Public License as published by	*
 *	the Free Software Foundation, either version 3 of the License, or		*
 *	(at your option) any later version.										*
 *																			*
 *	This program is distributed in the hope that it will be useful,			*
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of			*
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the			*
 *	GNU General Public License for more details.							*
 *																			*
 *	You should have received a copy of the GNU General Public License		*
 *	along with this program.  If not, see <http://www.gnu.org/licenses/>.	*/

class Database {
	public $hostname;
	public $username;
	public $password;
	public $database;
	
	public $debug = false;
	public $strict = false;
	public $balance = false;
	public $balance_iter = true;
	
	public $affected_rows = null;
	public $insert_id = null;
	public $num_rows = null;
	
	private $resources_rw = array();
	private $resources_r = array();
	private $resources_w = array();
	
	private $connected = false;
	private $gpc_settings = null;
	
	public function connect() {
		/*	Checks variables, creates an array of hosts (if not	*
			already), and connects to the MySQL server			*/
		
		if($this->connected) {
			return $this->debug('notice', 'Already connected');
		} elseif((((is_array($this->hostname) and $this->balance) or (!$this->balance)) and (is_string($this->database) and is_string($this->username)))) {
			if(!is_array($this->hostname)) $this->hostname = array($this->hostname);
			shuffle($this->hostname);
			foreach($this->hostname as $host) {
				if(is_array($host)) {
					$type = $host[0];
					$host = $host[1];
					if($type != 'master' and $type != 'slave') {
						$type = 'master';
					}
				} else {
					$type = 'master';
				}
				if($this->balance) {
					if((($type == 'master' and (count($this->resources_w) == 0 or $this->balance_iter)) or ($type == 'slave' and count($this->resources_r) == 0)) and $try = @mysql_connect($host, $this->username, $this->password)) {
						if($try_select = @mysql_select_db($this->database, $try)) {
							if($type == 'master') {
								$this->resources_w[] = $try;
							} elseif($type == 'slave') {
								$this->resources_r[] = $try;
							}
						} else {
							$this->debug('notice', "Host {$host} connected, but couldn't select {$database}");
							continue;
						}
					}
					# if we balance, we have different servers (in any order), so we keep looping, until the end
					# ... of course we just connect if we have to!
				} else {
					if(count($this->resources_rw) == 0 and $try = @mysql_connect($host, $this->username, $this->password)) {
						if($try_select = @mysql_select_db($this->database, $try)) {
							$this->resources_rw[] = $try;
							break; # no need to loop through
						} else {
							$this->debug('notice', "Host {$host} connected, but couldn't select {$database}");
							continue;
						}
					}
				}
			}
			if(($this->balance and count($this->resources_r) and count($this->resources_w) and !count($this->resources_rw)) or (!$this->balance and count($this->resources_rw))) {
				$this->gpc_settings = get_magic_quotes_gpc();
				$this->connected = true;
				return true;
				/* here, we assume that everything is OK (no bad surprises to deal with!) */
			} else {
				return $this->debug('fatal', 'Couldn\'t connect to any server (or wrong balancer configuration)');
			}
		} else {
			return $this->debug('fatal', 'Wrong parameters');
		}
	}
	
	public function close() {
		/*	If the balancer is enabled, loop in every resource	*
			and close it. If not, just close our opened			*
			resources.											*/
		
		if(!$this->connected) {
			return $this->debug('notice', 'Not connected');
		} elseif($this->balance) {
			if(count($this->resources_r)) {
				foreach($this->resources_r as &$to_close) {
					@mysql_close($to_close);
				}
			}
			if(count($this->resources_w)) {
				foreach($this->resources_w as &$to_close) {
					@mysql_close($to_close);
				}
			}
			$this->resources_r = array();
			$this->resources_w = array();
		} else {
			foreach($this->resources_rw as $to_close) {
				@mysql_close($to_close);
			}
			$this->resources_rw = array();
		}
		$this->connected = false;
	}
	
	public function escape($string) {
		if($this->gpc_settings) {
			$string = stripslashes($string);
		} # escaping a string with GPC on will result on double slashes (mess!)
		
		if($this->connected) {
			if($this->balance) {
				return mysql_real_escape_string($string, $this->resources_r[0]);
			} else {
				return mysql_real_escape_string($string, $this->resources_rw[0]);
			}
		} else {
			return mysql_escape_string($string);
		}
	}
	
	private function debug($type = 'notice', $message) {
		/* 	If debug enabled, show message. Then decide what	*
		 *	to do, depending on 'type' and 'strict' settings	*/
		 
		if($this->debug) {
			switch($type) {
				case 'fatal':
					echo '<h1>MySQL interface: <strong>error</strong></h1>';
					echo $message;
					return false;
				break;
				
				case 'notice':
					echo '<h1>MySQL interface: <strong>notice</strong></h1>';
					echo $message;
					return false;
				break;
				
				default:
					echo '<h1>MySQL interface: <strong>other</strong></h1>';
					echo $message;
					return false;
				break;
			}
		} elseif($this->strict) {		
			switch($type) {
				case 'fatal':
					exit;
				break;
				
				default:
					return false;
				break;
			}
		} else {
			return false;
		}
	}
	
	public function query($query) {
		/*	If balancer is enabled, it's a SELECT query and		*
			there's, at least, one read resource, then send it	*
			to the read resources. If not, then send it to the	*
			write resources. If balancer not enabled, send it	*
			to any RW resource.									*/
		
		if(!$this->connected) {
			return $this->debug('fatal', 'Error running query ('.$query.'): Not connected to the server');
		} elseif($this->balance) {
			$type = trim(strtolower(substr($query, 0, 7)));
			switch($type) {
				case 'select':
					$query_res = @mysql_query($query, $this->resources_r[0]);
					if($query_res) {
						$this->num_rows = mysql_num_rows($query_res);
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_r[0]));
					}
				break;
				
				case 'update':
					foreach($this->resources_w as &$w_res) {
						$query_res = @mysql_query($query, $w_res);
						if($query_res) {
							$this->affected_rows = mysql_affected_rows($w_res);
						} else {
							$this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($w_res));
						}
					}
					return $query_res;
					# see note below
				break;
				
				case 'insert':
					foreach($this->resources_w as &$w_res) {
						$query_res = @mysql_query($query, $w_res);
						if($query_res) {
							$this->insert_id = mysql_insert_id($w_res);
						} else {
							$this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($w_res));
						}
					}
					return $query_res;
					# we do a loop... it will contain just one element, except if we have Database::balance_iter on
					# --- so, we'll send every "write query" to each server
				break;
				
				default:
					# if we can't determine what's that query, we send it to a master server
					$query_res = @mysql_query($query, $this->resources_w[0]);
					if($query_res) {
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_w[0]));
					}
				break;
			}
		} else {
			$type = trim(strtolower(substr($query, 0, 7)));
			switch($type) {
				case 'select':
					$query_res = @mysql_query($query, $this->resources_rw[0]);
					# if we are just reading, we have one open connection
					if($query_res) {
						$this->num_rows = mysql_num_rows($query_res);
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_rw[0]));
					}
				break;
				
				case 'update':
					$query_res = @mysql_query($query, $this->resources_rw[0]);
					if($query_res) {
						$this->affected_rows = mysql_affected_rows($this->resources_rw[0]);
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_rw[0]));
					}
				break;
				
				case 'insert':
					$query_res = @mysql_query($query, $this->resources_rw[0]);
					if($query_res) {
						$this->insert_id = mysql_insert_id($this->resources_rw[0]);
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_rw[0]));
					}
				break;
				
				default:
					$query_res = @mysql_query($query, $this->resources_rw[0]);
					if($query_res) {
						return $query_res;
					} else {
						return $this->debug('notice', 'Error running query ('.$query.'), MySQL said: '.mysql_errno($this->resources_rw[0]));
					}
				break;
			}
		}
	}
	
	public function fetch_one($query, $table = false, $where = false, $limit = false) {
		if($query and $table) {
			$query = $this->build_query($query, $table, $where, $limit);
		}
		$query = $this->query($query);
		if($query) {
			$buffer = mysql_fetch_row($query);
			mysql_free_result($query);
			return $buffer[0];
		} else {
			return false;
		}
	}
	
	public function fetch_row($query, $table = false, $where = false, $limit = false) {
		if($query and $table) {
			$query = $this->build_query($query, $table, $where, $limit);
		}
		$query = $this->query($query);
		if($query) {
			$buffer = mysql_fetch_object($query);
			mysql_free_result($query);
			return $buffer;
		} else {
			return false;
		}
	}
	
	public function fetch($query, $table = false, $where = false, $limit = false) {
		if($query and $table) {
			$query = $this->build_query($query, $table, $where, $limit);
		}
		$query = $this->query($query);
		if($query) {
			$buffer = array();
			while($row = mysql_fetch_object($query)) {
				$buffer[] = $row;
			}
			mysql_free_result($query);
			return $buffer;
		} else {
			return false;
		}
	}
	
	public function add($table) {
		return new DatabaseCollector($this, $table, 'insert');
	}
	
	public function modify($table) {
		return new DatabaseCollector($this, $table, 'update');
	}
	
	public function update($table, $values, $where = false) {
		if(is_string($table) and !empty($table) and is_array($values)) {
			$query = 'UPDATE `';
			$query .= $this->escape($table);
			$query .= '` SET ';
			$stack_added = false;
			foreach($values as $value_key => $value) {
				if(!$value_key) continue;
				$query .= '`';
				$query .= $this->escape($value_key);
				$query .= '` = \'';
				$query .= $this->escape($value);
				$query .= '\',';
				$stack_added = true;
			}
			if($stack_added) {
				$query = substr($query, 0, -1);
				if($where) {
					$stack_added = false;
					if(is_array($where)) {
						foreach($where as $where_key => $where_value) {
							if(!$where_key) continue;
							if(!$stack_added) $query .= ' WHERE `';
							$query .= $this->escape($where_key);
							$query .= '` = \'';
							$query .= $this->escape($where_value);
							$query .= '\' AND ';
							$stack_added = true;
						}
						if($stack_added) $query = substr(trim($query), 0, -4);
					} else {
						$query .= ' WHERE `id` = \'';
						$query .= intval($where);
						$query .= '\'';
					}
				}
				return $this->query($query);
			} else {
				return $this->debug('notice', 'Malformed update');
			}
		} else {
			return $this->debug('fatal', 'Invalid update data');
		}
	}
	
	public function insert($table, $values) {
		if(is_string($table) and !empty($table) and is_array($values)) {
			$query = 'INSERT INTO `';
			$query .= $table;
			$query .= '` SET ';
			$stack_added = false;
			foreach($values as $value_key => $value) {
				if(!$value_key) continue;
				$query .= '`';
				$query .= $this->escape($value_key);
				$query .= '` = \'';
				$query .= $this->escape($value);
				$query .= '\', ';
				$stack_added = true;
			}
			if($stack_added) {
				$query = substr($query, 0, -2);
				return $this->query($query);
			} else {
				return $this->debug('notice', 'Malformed insertion');
			}
		} else {
			return $this->debug('fatal', 'Invalid insertion data');
		}
	}
	
	private function build_query($fields, $table, $where = false, $limit = false) {
		$query = 'SELECT ';
		$query .= $fields;
		$query .= ' FROM `';
		$query .= $table;
		$query .= '`';
		if(is_array($where)) {
			foreach($where as $where_key => $where_value) {
				if(!$where_key) continue;
				if(!$stack_added) $query .= ' WHERE `';
				$query .= $this->escape($where_key);
				$query .= '` = \'';
				$query .= $this->escape($where_value);
				$query .= '\' AND ';
				$stack_added = true;
			}
			if($stack_added) $query = substr(trim($query), 0, -4);
		} elseif($where) {
			$query .= ' WHERE `id` = \'';
			$query .= intval($where);
			$query .= '\'';
		}
		if($limit) {
			$query .= ' LIMIT '.$limit;
		}
		return $query;
	}
}

class DatabaseCollector {
	function __construct(&$main, $table, $type) {
		$this->__main__ = $main;
		$this->__table__ = $table;
		$this->__type__ = $type;
		$this->__valid__ = true;
	}
	
	function save($settings = false) {
		if(!$this->__valid__) return false; # expires the instance ("dead instance")
		$bufer = array();
		$names = get_object_vars($this);
		foreach($names as $key => $value) {
			if($key == '__main__' or $key == '__table__' or $key == '__type__' or $key == '__valid__') {
				continue;
			} else {
				$buffer[$key] = $value;
				unset($this->$key);
			}
		}
		$this->__valid__ = false;
		switch($this->__type__) {
			case 'insert':
				return $this->__main__->insert($this->__table__, $buffer);
			break;
			
			case 'update':
				return $this->__main__->update($this->__table__, $buffer, $settings);
			break;
		}
	}
}