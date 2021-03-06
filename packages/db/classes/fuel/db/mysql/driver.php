<?php defined('COREPATH') or die('No direct script access.');
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package		Fuel
 * @version		1.0
 * @author		Fuel Development Team
 * @license		MIT License
 * @copyright	2010 Dan Horrigan
 * @link		http://fuelphp.com
 */

class Fuel_DB_MySQL_Driver extends DB_Driver {

	/**
	 * Connects to the database
	 * 
	 * @access	public
	 * @return	void
	 */
	public function connect()
	{
		if ($this->_conn !== null)
		{
			return;
		}

		extract($this->_config['connection']);
		
		try
		{
			if ($persistent)
			{
				$this->_conn = mysql_pconnect($hostname, $username, $password);
			}
			else
			{
				$this->_conn = mysql_connect($hostname, $username, $password, true);
			}
		}
		catch (Fuel_Exception $e)
		{
			$this->_conn = NULL;
			throw new Fuel_Exception(mysql_error(), mysql_errno());
		}
		
		$this->_select_db($database);
	}

	/**
	 * Selects the database on the connection
	 * 
	 * @access	private
	 * @param	string		the database
	 * @return	void
	 */
	private function _select_db($database)
	{
		if ( ! mysql_select_db($database, $this->_conn))
		{
			throw new Database_Exception(mysql_error($this->_conn), mysql_errno($this->_conn));
		}
	}

	/**
	 * Disconnects from the database server
	 * 
	 * @access	public
	 * @return	bool
	 */
	public function disconnect()
	{
		$result = true;

		try
		{
			if (is_resource($this->_conn))
			{
				if ($result = mysql_close($this->_conn))
				{
					$this->_conn = NULL;
				}
			}
		}
		catch (Exception $e)
		{
			$result = ! is_resource($this->_conn);
		}

		return $result;
	}

	/**
	 * Executes a query on the database and returns the appropriate result
	 * 
	 * @access	public
	 * @param	int		the query type
	 * @param	string	the sql query
	 * @param	bool	as an object
	 * @return	object	a mysql result object
	 * @return	array	an array with the insert id and affected rows
	 * @return	int		the number of affected rows
	 */
	public function query($type, $sql, $as_object = true)
	{
		$this->_conn or $this->connect();

		if (($result = mysql_query($sql, $this->_conn)) === false)
		{
			throw new Fuel_Exception(mysql_error($this->_conn), mysql_errno($this->_conn));
		}

		// Set the last query
		$this->last_query = $sql;

		if ($type === DB::SELECT)
		{
			return new DB_Mysql_Result($result, $sql, $as_object);
		}
		elseif ($type === DB::INSERT)
		{
			return array(
				mysql_insert_id($this->_conn),
				mysql_affected_rows($this->_conn),
			);
		}
		else
		{
			return mysql_affected_rows($this->_conn);
		}
	}
}

/* End of file driver.php */