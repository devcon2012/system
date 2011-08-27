<?php

class Query {

	private $where = null;
	public $primary_table = null;
	protected $fields = array();
	protected $joins = array();
	protected $join_params = array();
	protected $limit = null;
	protected $offset = null;
	protected $orderby = null;
	protected $groupby = null;

	/**
	 * Construct a Query
	 * @example
	 * $q = new Query('{posts}');
	 * @param $primary_table Name of the primary table (use {table} syntax to expand)
	 */
	public function __construct($primary_table = null)
	{
		$this->primary_table = $primary_table;
	}

	/**
	 * Static helper method to create a new query instance
	 * @example
	 * $q = Query::create('{posts}');;
	 * @static
	 * @param string $primary_table Name of the primary table (use {table} syntax to expand)
	 * @return Query A new instance of the Query class
	 */
	public static function create($primary_table = null)
	{
		return new Query($primary_table);
	}

	public function select($fields)
	{
		$this->fields = array_merge($this->fields, Utils::single_array($fields));
		return $this;
	}

	public function set_select($fields)
	{
		$this->fields = Utils::single_array($fields);
		return $this;
	}

	public function from($primary_table)
	{
		$this->primary_table = $primary_table;
		return $this;
	}

	public function join($join, $parameters = array(), $alias = null)
	{
		if(empty($alias)) {
			$alias = md5($join);
		}
		$this->joins[$alias] = $join;
		$this->join_params = array_merge($this->join_params, $parameters);
		return $this;
	}

	public function joined($alias)
	{
		return array_key_exists($alias, $this->joins);
	}

	/**
	 * Create and/or return a QueryWhere object representing the where clause of this query
	 * @param string $operator The operator (AND/OR) to use between expressions in this clause
	 * @return QueryWhere An instance of the where clause for this query
	 */
	public function where($operator = 'AND')
	{
		if(!isset($this->where)) {
			$this->where = new QueryWhere($operator);
		}
		return $this->where;
	}

	public function groupby($value)
	{
		$this->groupby = empty($value) ? null : $value;
		return $this;
	}

	public function orderby($value)
	{
		$this->orderby = empty($value) ? null : $value;
		return $this;
	}

	public function limit($value)
	{
		$this->limit = is_int($value) ? $value : null;
		return $this;
	}

	public function offset($value)
	{
		$this->offset = is_int($value) ? $value : null;
		return $this;
	}

	/**
	 * Obtain the SQL used to execute this query
	 * @return string The SQL to execute
	 */
	public function get()
	{
		$sql = "SELECT \n\t";
		if(count($this->fields) > 0) {
			$sql .= implode(",\n\t", $this->fields);
		}
		else {
			$sql .= "*";
		}
		$sql .= "\nFROM\n\t" . $this->primary_table;
		foreach($this->joins as $join) {
			$sql .= "\n" . $join;
		}
		$where = $this->where()->get();
		if(isset($where)) {
			$sql .= "\nWHERE\n" . $this->where()->get();
		}

		if(isset($this->groupby)) {
			$sql .= "\nGROUP BY " . $this->groupby;
		}

		if(isset($this->orderby)) {
			$sql .= "\nORDER BY " . $this->orderby;
		}

		if(isset($this->limit)) {
			$sql .= "\nLIMIT " . $this->limit;
			if(isset($this->offset)) {
				$sql .= "\nOFFSET " . $this->offset;
			}
		}

		return $sql;
	}

	/**
	 * Obtain the parameter values needed for the query
	 * @return array An associative array containing the parameters of the query
	 */
	public function params()
	{
		return array_merge($this->where()->params(), $this->join_params);
	}

	public static function new_param_name($prefix = null)
	{
		static $param_names = array();

		if(!isset($prefix)) {
			$prefix = 'param';
		}
		if(!isset($param_names[$prefix])) {
			$param_names[$prefix] = 0;
		}
		$param_names[$prefix]++;
		return $prefix . '_' . $param_names[$prefix];
	}

}

/**
 * QueryWhere
 * Represents a where clause (or subclause) of a Query
 * @see Query
 */
class QueryWhere {
	protected $operator = 'AND';
	protected $expressions = array();
	protected $parameters = array();

	/**
	 * Constructor for the QueryWhere
	 * @param string $operator The operator (AND/OR) to user between expressions in this clause
	 */
	public function __construct($operator = 'AND')
	{
		$this->operator = $operator;
	}

	/**
	 * @param string|QueryWhere $expression A string expression to use as part of the query's where clause or
	 *                                      a compound expression represented by an additional QueryWhere instance
	 * @param array $parameters An associative array of values to use as named parameters in the added expression
	 * @return QueryWhere Returns $this, for fluid interface.
	 */
	public function add($expression, $parameters = array(), $name = null)
	{
		if(empty($name)) {
			$name = count($this->expressions) + 1;
		}
		$this->expressions[$name] = $expression;
		$this->parameters = array_merge($this->parameters, $parameters);
		return $this;
	}

	/**
	 * Shortcut to implementing an IN or equality test for one or more values as a new expression
	 * @param $field
	 * @param $values
	 * @param null $paramname
	 * @param null $validator
	 * @param boolean $positive
	 * @return QueryWhere Retruns $this, for fluid interface
	 */
	public function in($field, $values, $paramname = null, $validator = null, $positive = true)
	{
		$expression = $field . ' ';
		if($values instanceof Query) {
			$expression = $values;
		}
		elseif(is_array($values) && count($values) > 1) {
			$in_elements = array();
			if(is_callable($validator)) {
				foreach($values as $value) {
					$in_elements[] = $validator($value);
				}
			}
			else {
				foreach($values as $value) {
					$value_name = Query::new_param_name($paramname);
					$in_elements[] = ':' . $value_name;
					$this->parameters[$value_name] = $value;
				}
			}
			if(!$positive) {
				$expression .= 'NOT ';
			}
			$expression .= 'IN (' . implode(',', $in_elements) . ')';
		}
		else {
			if(is_array($values)) {
				$values = reset($values);
			}
			if(!$positive) {
				$expression .= ' <> ';
			}
			else {
				$expression .= ' = ';
			}

			if(empty($paramname)) {
				$paramname = Query::new_param_name();
			}

			if(is_callable($validator)) {
				$expression .= $validator($values);
			}
			else {
				$expression .= ':' . $paramname;
				$this->parameters[$paramname] = $values;
			}
		}

		if(empty($paramname)) {
			$paramname = count($this->expressions) + 1;
		}

		$this->expressions[$paramname] = $expression;
		return $this;
	}

	/**
	 * Obtain the parameters supplied for the where clause
	 * @return array An associative array of parameters added to this where clause
	 */
	public function params()
	{
		$parameters = $this->parameters;
		foreach($this->expressions as $expression) {
			if($expression instanceof Query) {
				$parameters = array_merge($parameters, $expression->params());
			}
			if($expression instanceof QueryWhere) {
				$parameters = array_merge($parameters, $expression->params());
			}
		}
		return $parameters;
	}

	/**
	 * Set a parameter value, class magic __set method
	 * @param string $name The name of the parameter to set
	 * @param mixed $value The value to set the parameter to
	 * @return mixed The supplied value
	 */
	public function __set($name, $value)
	{
		$this->parameters[$name] = $value;
		return $this->parameters[$name];
	}

	/**
	 * Get a parameter value, class magic __get method
	 * @param string $name The name of the parameter to get
	 * @return mixed The value of the parameter requested
	 */
	public function __get($name)
	{
		return $this->parameters[$name];
	}

	/**
	 * Obtain the where clause as a string to use in a query
	 * @return string The where clause represented by this object
	 */
	public function get($level = 0)
	{
		$outputs = array();
		$indents = str_repeat("\t", $level);
		if(count($this->expressions) == 0) {
			return null;
		}
		foreach($this->expressions as $expression) {
			if($expression instanceof Query) {
				$outputs[] = $expression->get();
			}
			if($expression instanceof QueryWhere) {
				$outputs[] = $expression->get($level + 1);
			}
			else {
				$outputs[] = $indents . "\t" .  $expression;
			}
		}
		$outputs = array_filter($outputs);
		$output = implode("\n" . $indents . $this->operator . "\n", $outputs);
		if($level == 0) {
			return $output;
		}
		return $indents . "(\n" . $output . "\n" . $indents . ")";
	}

	public function count()
	{
		return count($this->expressions);
	}

}

?>