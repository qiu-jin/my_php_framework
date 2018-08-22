<?php
namespace framework\driver\db\builder;

class Builder
{
    const KEYWORD_ESCAPE_LEFT = '`';
    const KEYWORD_ESCAPE_RIGHT = '`';

    protected static $where_logic = ['AND', 'OR', 'XOR', 'NOT', 'AND NOT', 'OR NOT', 'XOR NOT'];
    protected static $where_operator = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN', 'IS', 'BETWEEN'];

    public static function select($table, array $options)
    {
        $params = [];
        $sql = self::selectFrom($table, $options['fields'] ?? null);
        if (isset($options['where'])) {
            $sql .= ' WHERE '.self::whereClause($options['where'], $params);
        }
        if (isset($options['group'])) {
            $sql .= self::groupClause($options['group']);
        }
        if (isset($options['having'])) {
            $sql .= ' HAVING '.self::havingClause($options['having'], $params);
        }
        if (isset($options['order'])) {
            $sql .= self::orderClause($options['order']);
        }
        if (isset($options['limit'])) {
            $sql .= static::limitClause($options['limit']);
        }
        return [$sql, $params];
    }
    
    public static function insert($table, $data)
    {
        list($fields, $values, $params) = self::insertData($data);
        return ['INSERT INTO '.self::keywordEscape($table)." ($fields) VALUES ($values)", $params];
    }
    
    public static function update($table, $data, $options)
    {
        list($set, $params) = self::setData($data);
        $sql =  'UPDATE '.self::keywordEscape($table)." SET $set";
        if (isset($options['where'])) {
            $sql .= ' WHERE '.self::whereClause($options['where'], $params);
        }
        if (isset($options['limit'])) {
            $sql .= static::limitClause($options['limit']);
        }
        return [$sql, $params];
    }
    
    public static function delete($table, $options)
    {
        $params = [];
        $sql = 'DELETE FROM '.self::keywordEscape($table);
        if (isset($options['where'])) {
            $sql .= ' WHERE '.self::whereClause($options['where'], $params);
        }
        if (isset($options['limit'])) {
            $sql .= static::limitClause($options['limit']);
        }
        return [$sql, $params];
    }
    
    public static function insertData($data)
    {
        return [
            self::keywordEscape(implode(self::keywordEscape(','), array_keys($data))),
            implode(',', array_fill(0, count($data), '?')),
            array_values($data)
        ];
    }
    
    public static function selectFrom($table, array $fields = null)
    {
        if (!$fields) {
            return "SELECT * FROM ".self::keywordEscape($table);
        }
        foreach ($fields as $field) {
            if (is_array($field)) {
                $count = count($field);
                if ($count === 2) {
                    $select[] = self::keywordEscape($field[0]).' AS '.self::keywordEscape($field[1]);
                } elseif ($count === 3){
                    $select[] = "$field[0](".($field[1] === '*' ? '*'
                              : self::keywordEscape($field[1])).') AS '.self::keywordEscape($field[2]);
                } else {
                    throw new \Exception('SQL Field ERROR: '.var_export($field, true));
                }
            } else {
                $select[] = self::keywordEscape($field);
            }
        }
        return 'SELECT '.implode(',', $select).' FROM '.self::keywordEscape($table);
    }

    public static function whereClause($data, &$params, $prefix = null)
    {
        $sql = null;
		foreach ($data as $k => $v) {
            $sql .= self::whereLogicClause($k, isset($sql));
            if (isset($v[1]) && in_array($v[1] = strtoupper($v[1]), self::$where_operator, true)) {
                $sql .= self::whereItem($prefix, $params, ...$v);
            } else {
                $sql .= '('.self::whereClause($v, $params, $prefix).')';
            }
        }
        return $sql;
    }
    
    public static function groupClause($field, $table = null)
    {
        return ' GROUP BY '.($table ? self::keywordEscapePair($table, $field) : self::keywordEscape($field));
    }
    
    public static function havingClause($data, &$params, $prefix = null)
    {
        $sql = null;
		foreach ($data as $k => $v) {
            $sql .= self::whereLogicClause($k, isset($sql));
            $n = count($v) - 2;
            if (isset($v[$n]) && in_array($v[$n] = strtoupper($v[$n]), self::$where_operator, true)) {
                $sql .= self::havingItem($prefix, $params, $n - 1, $v);
            } else {
                $sql .= '('.self::havingClause($v, $params, $prefix).')';
            }
        }
        return $sql;
    }
    
	public static function orderClause($orders)
    {
        foreach ($orders as $order) {
            $field = isset($order[2]) ? self::keywordEscapePair($order[2], $order[0]) : self::keywordEscape($order[0]);
            $items[] = $order[1] ? "$field DESC" : $field;
        }
        return ' ORDER BY '.implode(',', $items);
	}

    public static function limitClause($limit)
    {
        if (is_array($limit)) {
            return " LIMIT $limit[0],$limit[1]";
        } else {
            return " LIMIT $limit";
        }
    }
    
	public static function setData($data , $glue = ',')
    {
        $params = $items = [];
		foreach ($data as $k => $v) {
            $items[] = self::keywordEscape($k)."=?";
            $params[] = $v;
		}
        return [implode(" $glue ", $items), $params];
	}
    
    public static function whereLogicClause($logic, $and)
    {
        if (is_integer($logic)) {
            if ($and) {
                return ' AND ';
            }
        } else {
            if (in_array($logic = strtoupper(strtok($logic, '#')), self::$where_logic, true)) {
                return " $logic ";
            }
            throw new \Exception('SQL WHERE ERROR: '.var_export($logic, true));
        }
    }
    
    public static function whereItem($prefix, &$params, $field, $exp, $value)
    {
        return ' '.(isset($prefix) ? self::keywordEscapePair($prefix, $field) 
                                   : self::keywordEscape($field)).' '.self::whereItemValue($params, $exp, $value);
    }
    
    public static function havingItem($prefix, &$params, $num, $values)
    {
        $sql = $values[$num] == '*' ? '*' : self::keywordEscape($values[$num]);
        if (isset($prefix)) {
            $sql = self::keywordEscape($prefix).".$sql";
        }
        if ($num == 1) {
            $sql = "$values[0]($sql)";
        }
        return " $sql ".self::whereItemValue($params, $values[$num + 1], $values[$num + 2]);
    }
    
    public static function whereItemValue(&$params, $exp, $value)
    {
        switch ($exp) {
            case 'IN':
                if(is_array($value)) {
                    $params = array_merge($params, $value);
                    return 'IN ('.implode(',', array_fill(0, count($value), '?')).')';
                }
                break;
            case 'BETWEEN':
                if (count($value) === 2) {
                    $params = array_merge($params, $value);
                    return 'BETWEEN ? AND ?';
                }
                break;
            case 'IS':
                if ($value === NULL) {
                    return 'IS NULL';
                }
                break;
            default :
                $params[] = $value;
                return "$exp ?";
        }
        throw new \Exception("SQL where ERROR: $exp ".var_export($value, true));
    }
    
    public static function keywordEscape($kw)
    {
        return static::KEYWORD_ESCAPE_LEFT.$kw.static::KEYWORD_ESCAPE_RIGHT;
    }
    
    public static function keywordEscapePair($kw1, $kw2)
    {
        return self::keywordEscape($kw1).'.'.self::keywordEscape($kw2);
    }
}
