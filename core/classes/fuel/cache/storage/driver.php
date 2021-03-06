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

abstract class Fuel_Cache_Storage_Driver {
	
	/**
	 * @var string name of the content handler driver
	 */
	protected $content_handler = null;
	
	/**
	 * @var Cache_Handler_Driver handles and formats the cache's contents
	 */
	protected $handler_object = null;
	
	/**
	 * @var string the cache's name, either string or md5'd serialization of something else
	 */
	protected $identifier = null;
	
	/**
	 * @var int timestamp of creation of the cache
	 */
	protected $created = null;
	
	/**
	 * @var int timestamp when this cache will expire
	 */
	protected $expiration = null;
	
	/**
	 * @var array contains identifiers of other caches this one depends on
	 */
	protected $dependencies = array();
	
	/**
	 * @var mixed the contents of this 
	 */
	protected $contents = null;

	/**
	 * @var array defines which class properties are gettable with get_... in the __call() method
	 */
	protected $_gettable = array('created', 'expiration', 'dependencies', 'identifier');

	/**
	 * @var array defines which class properties are settable with set_... in the __call() method
	 */
	protected $_settable = array('expiration', 'dependencies', 'identifier');
	
	/**
	 * Default constructor, any extension should either load this first or act similar
	 *
	 * @param	string	the identifier for this cache
	 * @param	array	additional config values
	 */
	public function __construct($identifier, $config)
	{
		$this->identifier = $identifier;
		
		// fetch options from config and set them
		$this->expiration		= array_key_exists('expiration', $config) ? $config['expiration'] : null;
		$this->dependencies		= array_key_exists('dependencies', $config) ? $config['dependencies'] : array();
		$this->content_handler	= array_key_exists('content_handler', $config) ? new $config['content_handler']() : null;

		// expiration can be set by default if not given
		if (is_null($this->expiration))
		{
			$this->expiration = Config::get('cache.default_expiration') !== false ? Config::get('cache.default_expiration') : null;
		}
	}

	/**
	 * Resets all properties except for the identifier, should be run by default when a delete() is triggered
	 *
	 * @access public
	 */
	public function reset()
	{
		$this->contents			= NULL;
		$this->created			= NULL;
		$this->expiration		= NULL;
		$this->dependencies		= array();
		$this->content_handler	= NULL;
		$this->handler_object	= NULL;
	}
	
	/**
	 * Front for writing the cache, ensures interchangebility of storage engines. Actual writing
	 * is being done by the _set() method which needs to be extended.
	 *
	 * @access	public
	 * @param	mixed			The content to be cached
	 * @param	int				The time in minutes until the cache will expire, =< 0 or null means no expiration
	 * @param	array			Array of names on which this cache depends for 
	 * @return	object			The new request
	 */
	final public function set($contents = null, $expiration = null, $dependencies = array())
	{
		// Use either the given value or the class property
		if ( ! is_null($contents)) $this->set_contents($contents);
		$this->expiration	= ( ! is_null($expiration)) ? $expiration : $this->expiration;
		$this->dependencies	= ( ! empty($dependencies)) ? $dependencies : $this->dependencies;
		
		// Create expiration timestamp when other then null
		if ( ! is_null($this->expiration))
		{
			if ( ! is_numeric($this->expiration))
			{
				throw new Cache_Exception('Expiration must be a valid number.');
			}
			$this->expiration = time() + intval($this->expiration) * 60;
		}
		
		// Convert dependency identifiers to string when set
		$this->dependencies = ( ! is_array($this->dependencies)) ? array($this->dependencies) : $this->dependencies;
		if ( ! empty( $this->dependencies ) )
		{
			foreach($this->dependencies as $key => $id)
			{
				$this->dependencies[$key] = $this->stringify_identifier($id);
			}
		}
		
		$this->created = time();
		
		// Turn everything over to the storage specific method
		$this->_set();
		
		// Convert the expiration back to minutes
		$this->expiration = (int) ( $this->expiration - time() ) / 60;
	}
	
	/**
	 * Does get() & set() in one call that takes a callback and it's arguements to generate the contents
	 *
	 * @access	public
	 * @param	string|array	Valid PHP callback
	 * @param	array 			Arguements for the above function/method
	 * @param	int				Cache expiration in minutes
	 * @param	array			Contains the identifiers of caches this one will depend on
	 */
	final public function call($callback, $args = array(), $expiration = null, $dependencies = array())
	{
		try
		{
			$this->get();
		}
		catch (Cache_Exception $e)
		{
			// Create the contents
			$contents = call_user_func_array($callback, $args);
			
			$this->set($contents, $expiration, $dependencies);
		}
		
		return $this->get_contents();
	}

	/**
	 * Abstract method that should take care of the storage engine specific writing. Needs to write the object properties:
	 * - created
	 * - expiration
	 * - dependencies
	 * - contents
	 * - content_handler
	 *
	 * @access	protected
	 */
	abstract protected function _set();
	
	/**
	 * Front for reading the cache, ensures interchangebility of storage engines. Actual reading
	 * is being done by the _get() method which needs to be extended.
	 *
	 * @access	public
	 * @param	bool
	 * @return	mixed
	 */
	final public function get($use_expiration = true)
	{
		if ( ! $this->_get())
		{
			throw new Cache_Exception('not found');
		}

		if ($use_expiration)
		{
			if ($this->expiration < 0)
			{
				$this->delete();
				throw new Cache_Exception('expired');
			}

			// Check dependencies and handle as expired on failure
			if ( ! $this->check_dependencies($this->dependencies))
			{
				$this->delete();
				throw new Cache_Exception('expired');
			}
		}

		return $this->get_contents();
	}

	/**
	 * Abstract method that should take care of the storage engine specific reading. Needs to set the object properties:
	 * - created
	 * - expiration
	 * - dependencies
	 * - contents
	 * - content_handler
	 *
	 * @access	protected
	 * @return	bool success of the operation
	 */
	abstract protected function _get();
	
	/**
	 * Should check all dependencies against the creation timestamp.
	 * This is static to make it possible in the future to check dependencies from other storages then the current one,
	 * though I don't have a clue yet how to make that possible.
	 * 
	 * @access	protected
	 * @return	bool either true or false on any failure
	 */
	abstract public static function check_dependencies($dependencies);
	
	/**
	 * Should delete this cache instance, should also run reset() afterwards
	 *
	 * @access	public
	 */
	abstract public function delete();

	/**
	 * Flushes the whole cache for a specific storage type or just a part of it when $section is set (might not work
	 * with all storage drivers), defaults to the default storage type
	 *
	 * @access	public
	 * @param	string
	 * @param	string
	 */
	final public static function delete_all($section = null, $storage = null)
	{
		if ( empty( $storage ) )
		{
			$storage = (Config::get( 'cache.storage' ) !== false ) ? Config::get( 'cache.storage' ) : 'file';
		}
		$class = 'Cache_Storage_'.ucfirst($storage);

		$identifier = call_user_func_array($class.'::_delete_all', array($section));
	}

	/**
	 * Should do the delete_all method's work from the storage engine driver
	 *
	 * @access	public
	 * @param	string
	 */
	abstract public static function _delete_all($section);
	
	/**
	 * Converts the identifier to a string when necessary:
	 * A int is just converted to a string, all others are serialized and then md5'd
	 *
	 * @access	public
	 * @param	mixed
	 */
	public static function stringify_identifier($identifier)
	{
		// Identifier may not be empty, but can be false or 0
		if ($identifier === '' || $identifier === null)
		{
			throw new Cache_Exception('The identifier cannot be empty, must contain a value of any kind other than null or an empty string.');
		}
		
		// In case of string or int just return it as a string
		if (is_string($identifier) || is_int($identifier))
		{
			return (string) $identifier;
		}
		// In case of array, bool or object return the md5 of the $identifier's serialization
		else
		{
			return md5(serialize($identifier));
		}
	}

	/**
	 * Allows for default getting and setting
	 *
	 * @access	public
	 * @param	string
	 * @param	array
	 * @return	void|mixed
	 */
	public function __call($method, $args = array())
	{
		// Allow getting any properties set in $this->_gettable
		if (substr($method, 0, 3) == 'get')
		{
			$name = substr($method, 4);
			if (in_array($name, $this->_gettable))
			{
				return $this->{$name};
			}
			else
			{
				throw new Cache_Exception('This property doesn\'t exist or can\'t be read.');
			}
		}
		// Allow setting any properties set in $this->_settable
		elseif (substr($method, 0, 3) == 'set')
		{
			$name = substr($method, 4);
			if (in_array($name, $this->_settable))
			{
				$this->{$name} = @$args[0];
			}
			else
			{
				throw new Cache_Exception('This property doesn\'t exist or can\'t be set.');
			}
			return $this;
		}
		else
		{
			throw new Cache_Exception('Illigal method call');
		}
	}

	/**
	 * Set the contents with optional handler instead of the default
	 *
	 * @access	public
	 * @param	mixed
	 * @param	string
	 */
	public function set_contents($contents, $handler = NULL)
	{
		$this->contents = $contents;
		$this->set_content_handler($handler);
		$this->contents = $this->handle_writing($contents);
		return $this;
	}

	/**
	 * Fetches contents
	 *
	 * @access	public
	 * @return	mixed
	 */
	public function get_contents()
	{
		return $this->handle_reading($this->contents);
	}

	/**
	 * Decides a content handler that makes it possible to write non-strings to a file
	 *
	 * @access	protected
	 * @param	string
	 */
	protected function set_content_handler($handler)
	{
		$this->handler_object = null;
		$this->content_handler = (string) $handler;
		return $this;
	}

	/**
	 * Gets a specific content handler
	 *
	 * @access	public
	 * @param	string
	 * @return	Cache_Handler_Driver
	 */
	public function get_content_handler($handler = null)
	{
		if ( ! empty($this->handler_object))
		{
			return $this->handler_object;
		}

		// When not yet set, use $handler or detect the prefered handler (string = string, otherwise serialize)
		if (empty($this->content_handler) && empty($handler))
		{
			if ( ! empty($handler))
			{
				$this->content_handler = $handler;
			}
			if (is_string($this->contents))
			{
				$this->content_handler = (Config::get('cache.string_handler') !== false) ? Config::get('cache.string_handler') : 'string';
			}
			else
			{
				$type = is_object($this->contents) ? get_class($this->contents) : gettype($this->contents);
				$this->content_handler = (Config::get('cache.'.$type.'_handler') !== false) ? Config::get('cache.'.$type.'_handler') : 'serialized';
			}
		}

		$class = 'Cache_Handler_'.ucfirst($this->content_handler);
		$this->handler_object = new $class();

		return $this->handler_object;
	}

	/**
	 * Converts the contents the cachable format
	 *
	 * @access	protected
	 * @return	string
	 */
	protected function handle_writing($contents)
	{
		return $this->get_content_handler()->writable($contents);
	}

	/**
	 * Converts the cachable format to the original value
	 *
	 * @access	protected
	 * @return	mixed
	 */
	protected function handle_reading($contents)
	{
		return $this->get_content_handler()->readable($contents);
	}
}

/* End of file driver.php */