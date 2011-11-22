<?php

/**
 * SQL Dialect for PostgreSQL
 *
 * Copyright (C) 2011 FluxBB (http://fluxbb.org)
 * License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
 */

class Flux_Database_Adapter_PgSQL extends Flux_Database_Adapter
{
	public function generateDsn()
	{
		$args = array();

		if (isset($this->options['host'])) {
			$args[] = 'host='.$this->options['host'];
		}

		if (isset($this->options['port'])) {
			$args[] = 'port='.$this->options['port'];
		}

		if (isset($this->options['dbname'])) {
			$args[] = 'dbname='.$this->options['dbname'];
		} else {
			throw new Exception('No database name specified for PostgreSQL database.');
		}

		return 'pgsql:'.implode(';', $args);
	}

	public function runReplace(Flux_Database_Query_Replace $query, array $params = array())
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A REPLACE query must have a table specified.');

		if (empty($query->values))
			throw new Exception('A REPLACE query must contain at least one value.');
		
		if (empty($query->keys))
			throw new Exception('A REPLACE query must contain at least one key.');

		$values = array();
		foreach ($query->values as $key => $value)
		{
			$values[] = $key.' = '.$value;
		}
		
		$keys = array();
		foreach ($query->keys as $key => $value)
		{
			$keys[] = $key.' = '.$value;
		}
		
		// Update if row exists
		$sql = 'UPDATE '.$table.' SET '.implode(', ', $values).' WHERE '.implode(' AND ', $keys);
		$this->query($sql, $params);
		
		$where = array();
		foreach ($query->keys as $key => $value)
		{
			$where[] = $key.' = '.$value.'_k';
			$params[$value.'_k'] = $params[$value];
		}
		
		// Insert if it did not
		$sql = 'INSERT INTO '.$table.' ('.implode(', ', array_keys(array_merge($query->values, $query->keys))).') SELECT '.implode(', ', array_values(array_merge($query->values, $query->keys))).' WHERE NOT EXISTS (SELECT 1 FROM '.$table.' WHERE ('.implode(' AND ', $where).'))';
		$r = $this->query($sql, $params);
		$insertCount = $r->rowCount();
		
		return $insertCount > 0 ? 1 : 2;
	}

	public function runTruncate(Flux_Database_Query_Truncate $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A TRUNCATE query must have a table specified.');

		try {
			$sql = 'TRUNCATE TABLE '.$table.' RESTART IDENTITY';
			$this->exec($sql);
		} catch (PDOException $e) {
			return false;
		}
		
		return true;
	}

	public function runTableExists(Flux_Database_Query_TableExists $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A TABLE EXISTS query must have a table specified.');
		
		$sql = 'SELECT 1 FROM pg_class WHERE relname = '.$this->quote($table);
		return (bool) $this->query($sql)->fetchColumn();
	}

	public function runAlterField(Flux_Database_Query_AlterField $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('An ALTER FIELD query must have a table specified.');
		
		if ($query->field == NULL)
			throw new Exception('An ALTER FIELD query must have field information specified.');
		
		$now = time();

		// Add a temporary field with new constraints and old values instead of the new one
		$subquery = $this->addField($table);
		$new_field = clone $query->field;
		$new_field->name = $new_field->name.'_t'.$now;
		$subquery->field = $new_field;
		$subquery->run();

		try {
			$this->exec('UPDATE '.$table.' SET '.$query->field->name.'_t'.$now.' = '.$query->field->name);
			$this->dropField($table, $query->field->name)->run();
			$this->exec('ALTER TABLE '.$table.' RENAME COLUMN '.$query->field->name.'_t'.$now.' TO '.$query->field->name);
		} catch (PDOException $e) {
			return false;
		}
		
		return true;
	}

	public function runFieldExists(Flux_Database_Query_FieldExists $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A FIELD EXISTS query must have a table specified.');
		
		if (empty($query->field))
			throw new Exception('A FIELD EXISTS query must have a field specified.');
		
		$sql = 'SELECT 1 FROM pg_class c INNER JOIN pg_attribute a ON a.attrelid = c.oid WHERE c.relname = '.$this->quote($table).' AND a.attname = '.$this->quote($query->field);
		return (bool) $this->query($sql)->fetchColumn();
	}

	public function runAddIndex(Flux_Database_Query_AddIndex $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('An ADD INDEX query must have a table specified.');
		
		if (empty($query->index))
			throw new Exception('An ADD INDEX query must have an index specified.');
		
		if (empty($query->fields))
			throw new Exception('An ADD INDEX query must have at least one field specified.');
		
		try {
			$sql = 'CREATE '.($query->unique ? 'UNIQUE ' : '').'INDEX '.$table.'_'.$query->index.' ON '.$table.' ('.implode(',', $query->fields).')';
			$this->exec($sql);
		} catch (PDOException $e) {
			return false;
		}
		
		return true;
	}
	
	public function runDropIndex(Flux_Database_Query_DropIndex $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A DROP INDEX query must have a table specified.');
	
		if (empty($query->index))
			throw new Exception('A DROP INDEX query must have an index specified.');
	
		try {
			$sql = 'DROP INDEX '.$table.'_'.$query->index;
			$this->exec($sql);
		} catch (PDOException $e) {
			return false;
		}
		
		return true;
	}

	public function runIndexExists(Flux_Database_Query_IndexExists $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('An INDEX EXISTS query must have a table specified.');
		
		if (empty($query->index))
			throw new Exception('An INDEX EXISTS query must have an index specified.');
		
		$sql = 'SELECT 1 FROM pg_index i INNER JOIN pg_class c1 ON c1.oid = i.indrelid INNER JOIN pg_class c2 ON c2.oid = i.indexrelid WHERE c1.relname = '.$this->quote($table).' AND c2.relname = '.$this->quote($table.'_'.$query->index);
		return (bool) $this->query($sql)->fetchColumn();
	}

	public function runTableInfo(Flux_Database_Query_TableInfo $query)
	{
		$table = $query->getTable();
		if (empty($table))
			throw new Exception('A TABLE INFO query must have a table specified.');
		
		$table_info = array(
			'columns'		=> array(),
			'primary_key'	=> array(),
			'unique'		=> array(),
			'indices'		=> array(),
		);

		// Fetch column information
		$sql = 'SELECT column_name FROM information_schema.columns WHERE table_name = '.$this->quote($table).' AND table_schema = '.$this->quote($this->options['dbname']).' ORDER BY ordinal_position ASC';
		$result = $this->query($sql);

		foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$table_info['columns'][$row['column_name']] = array(
				'type'			=> $row['column_type'],
				'default'		=> $row['column_default'],
				'allow_null'	=> $row['is_nullable'] == 'YES',
			);

			if ($row['column_key'] == 'PRI')
			{
				$table_info['primary_key'][] = $row['column_name'];
			}
		}

		// Fetch index information
		$sql = 'SELECT t.relname AS table_name, ix.relname AS index_name, array_to_string(array_agg(col.attname), \',\') AS index_columns, i.indisunique FROM pg_index i JOIN pg_class ix ON ix.oid = i.indexrelid JOIN pg_class t on t.oid = i.indrelid JOIN (SELECT ic.indexrelid, unnest(ic.indkey) AS colnum FROM pg_index ic) icols ON icols.indexrelid = i.indexrelid JOIN pg_attribute col ON col.attrelid = t.oid and col.attnum = icols.colnum WHERE t.relname = '.$this->quote($table).' GROUP BY t.relname, ix.relname, i.indisunique ORDER BY t.relname, ix.relname';
		$result = $this->query($sql);
		
		foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			// Remove table name prefix
			$row['index_name'] = substr($row['index_name'], strlen($table.'_'));
			
			if ($row['index_name'] != 'pkey') {
				$table_info['indices'][$row['index_name']] = array(
					'fields'	=> explode(',', $row['index_columns']),
					'unique'	=> $row['indisunique'] == 't',
				);
				
				if ($row['indisunique'] == 't') {
					$table_info['unique'][] = explode(',', $row['index_columns']);
				}
			} else {
				$table_info['primary_key'] = explode(',', $row['index_columns']);
			}
		}
		
		return $table_info;
	}
	
	protected function compileColumnDefinition(Flux_Database_Query_Helper_TableColumn $column)
	{
		if ($column->type === Flux_Database_Query_Helper_TableColumn::TYPE_SERIAL)
			return $this->compileColumnSerial($column->name);
	
		$sql = $column->name.' '.$this->compileColumnType($column->type);
	
		if (!$column->allow_null)
			$sql .= ' NOT NULL';
	
		if (!empty($column->default))
			$sql .= ' DEFAULT '.$column->default;

		return $sql;
	}
	
	protected function compileColumnType($type)
	{
		if ($type == Flux_Database_Query_Helper_TableColumn::TYPE_INT_UNSIGNED) {
			return 'INTEGER';
		} else if ($type == Flux_Database_Query_Helper_TableColumn::TYPE_MEDIUMINT_UNSIGNED) {
			return 'MEDIUMINT';
		} else if ($type == Flux_Database_Query_Helper_TableColumn::TYPE_TINYINT_UNSIGNED) {
			return 'TINYINT';
		}
		return $type;
	}

	protected function compileColumnSerial($name)
	{
		return $name.' SERIAL NOT NULL';
	}

	protected function compileConditions($conditions)
	{
		$sql = parent::compileConditions($conditions);

		// Replace LIKE with ILIKE to get case insensitive match
		return preg_replace('%(\s)(LIKE)(\s)%i', '$1ILIKE$3', $sql);
	}

	protected function compileLimitOffset($limit, $offset)
	{
		$sql = '';

		if ($limit > 0)
			$sql .= ' LIMIT '.intval($limit);

		if ($offset > 0)
			$sql .= ' OFFSET '.intval($offset);

		return $sql;
	}
}
