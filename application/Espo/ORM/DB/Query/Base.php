<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/

namespace Espo\ORM\DB\Query;

use Espo\ORM\Entity;
use Espo\ORM\IEntity;
use Espo\ORM\EntityFactory;
use PDO;

abstract class Base
{
    protected static $selectParamList = array(
        'select',
        'whereClause',
        'offset',
        'limit',
        'order',
        'orderBy',
        'customWhere',
        'customJoin',
        'joins',
        'leftJoins',
        'distinct',
        'joinConditions',
        'aggregation',
        'aggregationBy',
        'groupBy'
    );

    protected static $sqlOperators = array(
        'OR',
        'AND',
    );

    protected static $comparisonOperators = array(
        '!=' => '<>',
        '*' => 'LIKE',
        '>=' => '>=',
        '<=' => '<=',
        '>' => '>',
        '<' => '<',
        '=' => '=',
    );

    protected $entityFactory;

    protected $pdo;

    protected $fieldsMapCache = array();

    protected $aliasesCache = array();

    protected $seedCache = array();

    public function __construct(PDO $pdo, EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
        $this->pdo = $pdo;
    }

    protected function getSeed($entityName)
    {
        if (empty($this->seedCache[$entityName])) {
            $this->seedCache[$entityName] = $this->entityFactory->create($entityName);
        }
        return $this->seedCache[$entityName];
    }

    public function createSelectQuery($entityName, array $params = array(), $deleted = false)
    {
        $entity = $this->getSeed($entityName);

        foreach (self::$selectParamList as $k) {
            $params[$k] = array_key_exists($k, $params) ? $params[$k] : null;
        }

        $whereClause = $params['whereClause'];
        if (empty($whereClause)) {
            $whereClause = array();
        }

        if (!$deleted) {
            $whereClause = $whereClause + array('deleted' => 0);
        }

        if (empty($params['aggregation'])) {
            $selectPart = $this->getSelect($entity, $params['select'], $params['distinct']);
            $orderPart = $this->getOrder($entity, $params['orderBy'], $params['order']);

            if (!empty($params['additionalColumns']) && is_array($params['additionalColumns']) && !empty($params['relationName'])) {
                foreach ($params['additionalColumns'] as $column => $field) {
                    $selectPart .= ", `" . $this->toDb($this->sanitize($params['relationName'])) . "`." . $this->toDb($this->sanitize($column)) . " AS `{$field}`";
                }
            }

        } else {
            $aggDist = false;
            if ($params['distinct'] && $params['aggregation'] == 'COUNT') {
                $aggDist = true;
            }
            $selectPart = $this->getAggregationSelect($entity, $params['aggregation'], $params['aggregationBy'], $aggDist);
        }

        if (empty($params['joins'])) {
            $params['joins'] = array();
        }
        if (empty($params['leftJoins'])) {
            $params['leftJoins'] = array();
        }

        $joinsPart = $this->getBelongsToJoins($entity, $params['select'], array_merge($params['joins'], $params['leftJoins']));

        $wherePart = $this->getWhere($entity, $whereClause);

        if (!empty($params['customWhere'])) {
            $wherePart .= ' ' . $params['customWhere'];
        }

        if (!empty($params['customJoin'])) {
            if (!empty($joinsPart)) {
                $joinsPart .= ' ';
            }
            $joinsPart .= '' . $params['customJoin'] . '';
        }

        if (!empty($params['joins']) && is_array($params['joins'])) {
            $joinsRelated = $this->getJoins($entity, $params['joins'], false, $params['joinConditions']);
            if (!empty($joinsRelated)) {
                if (!empty($joinsPart)) {
                    $joinsPart .= ' ';
                }
                $joinsPart .= $joinsRelated;
            }
        }

        if (!empty($params['leftJoins']) && is_array($params['leftJoins'])) {
            $joinsRelated = $this->getJoins($entity, $params['leftJoins'], true, $params['joinConditions']);
            if (!empty($joinsRelated)) {
                if (!empty($joinsPart)) {
                    $joinsPart .= ' ';
                }
                $joinsPart .= $joinsRelated;
            }
        }

        $groupByPart = null;
        if (!empty($params['groupBy']) && is_array($params['groupBy'])) {
            $arr = array();
            foreach ($params['groupBy'] as $field) {
                $arr[] = $this->convertComplexExpression($entity, $field);
            }
            $groupByPart = implode(', ', $arr);
        }

        if (empty($params['aggregation'])) {
            $sql = $this->composeSelectQuery($this->toDb($entity->getEntityType()), $selectPart, $joinsPart, $wherePart, $orderPart, $params['offset'], $params['limit'], $params['distinct'], null, $groupByPart);
        } else {
            $sql = $this->composeSelectQuery($this->toDb($entity->getEntityType()), $selectPart, $joinsPart, $wherePart, null, null, null, false, $params['aggregation']);
        }

        return $sql;
    }

    protected function getFunctionPart($function, $part, $entityName, $distinct = false)
    {
        switch ($function) {
            case 'MONTH':
                return "DATE_FORMAT({$part}, '%Y-%m')";
            case 'DAY':
                return "DATE_FORMAT({$part}, '%Y-%m-%d')";
        }
        if ($distinct) {
            $idPart = $this->toDb($entityName) . ".id";
            switch ($function) {
                case 'SUM':
                case 'COUNT':
                    return $function . "({$part}) * COUNT(DISTINCT {$idPart}) / COUNT({$idPart})";
            }
        }
        return $function . '(' . $part . ')';
    }


    protected function convertComplexExpression($entity, $field, $distinct = false)
    {
        $function = null;
        $relName = null;

        $entityType = $entity->getEntityType();

        if (strpos($field, ':')) {
            list($function, $field) = explode(':', $field);
        }
        if (strpos($field, '.')) {
            list($relName, $field) = explode('.', $field);
        }

        if (!empty($function)) {
            $function = preg_replace('/[^A-Za-z0-9_]+/', '', $function);
        }
        if (!empty($relName)) {
            $relName = preg_replace('/[^A-Za-z0-9_]+/', '', $relName);
        }
        if (!empty($field)) {
            $field = preg_replace('/[^A-Za-z0-9_]+/', '', $field);
        }

        $part = $this->toDb($field);
        if ($relName) {
            $part = $relName . '.' . $part;
        } else {
            if (!empty($entity->fields[$field]['select'])) {
                $part = $entity->fields[$field]['select'];
            } else {
                $part = $this->toDb($entityType) . '.' . $part;
            }
        }
        if ($function) {
            $part = $this->getFunctionPart(strtoupper($function), $part, $entityType, $distinct);
        }
        return $part;
    }

    protected function getSelect(IEntity $entity, $fields = null, $distinct = false)
    {
        $select = "";
        $arr = array();
        $specifiedList = is_array($fields) ? true : false;

        if (empty($fields)) {
            $fieldList = array_keys($entity->fields);
        } else {
            $fieldList = $fields;
        }

        foreach ($fieldList as $field) {
            if (array_key_exists($field, $entity->fields)) {
                $fieldDefs = $entity->fields[$field];
            } else {
                $part = $this->convertComplexExpression($entity, $field, $distinct);
                $arr[] = $part . ' AS `' . $field . '`';
                continue;
            }

            if (!empty($fieldDefs['select'])) {
                $fieldPath = $fieldDefs['select'];
            } else {
                if (!empty($fieldDefs['notStorable'])) {
                    continue;
                }
                $fieldPath = $this->getFieldPath($entity, $field);
            }

            $arr[] = $fieldPath . ' AS `' . $field . '`';
        }

        $select = implode(', ', $arr);

        return $select;
    }

    protected function getBelongsToJoin(IEntity $entity, $relationName, $r = null)
    {
        if (empty($r)) {
            $r = $entity->relations[$relationName];
        }

        $keySet = $this->getKeys($entity, $relationName);
        $key = $keySet['key'];
        $foreignKey = $keySet['foreignKey'];

        $alias = $this->getAlias($entity, $relationName);

        if ($alias) {
            return "JOIN `" . $this->toDb($r['entity']) . "` AS `" . $alias . "` ON ".
                   $this->toDb($entity->getEntityType()) . "." . $this->toDb($key) . " = " . $alias . "." . $this->toDb($foreignKey);
        }
    }

    protected function getBelongsToJoins(IEntity $entity, $select = null, $skipList = array())
    {
        $joinsArr = array();

        $fieldDefs = $entity->fields;

        $relationsToJoin = array();
        if (is_array($select)) {
            foreach ($select as $field) {
                if (!empty($fieldDefs[$field]) && $fieldDefs[$field]['type'] == 'foreign' && !empty($fieldDefs[$field]['relation'])) {
                    $relationsToJoin[] = $fieldDefs[$field]['relation'];
                }
            }
        }

        foreach ($entity->relations as $relationName => $r) {
            if ($r['type'] == IEntity::BELONGS_TO) {
                if (!empty($r['noJoin'])) {
                    continue;
                }
                if (in_array($relationName, $skipList)) {
                    continue;
                }

                if (!empty($select)) {
                    if (!in_array($relationName, $relationsToJoin)) {
                        continue;
                    }
                }

                $join = $this->getBelongsToJoin($entity, $relationName, $r);
                if ($join) {
                    $joinsArr[] = 'LEFT ' . $join;
                }
            }
        }

        return implode(' ', $joinsArr);
    }

    protected function getOrderPart(IEntity $entity, $orderBy = null, $order = null) {

        if (!is_null($orderBy)) {
            if (is_array($orderBy)) {
                $arr = array();

                foreach ($orderBy as $item) {
                    if (is_array($item)) {
                        $orderByInternal = $item[0];
                        $orderInternal = null;
                        if (!empty($item[1])) {
                            $orderInternal = $item[1];
                        }
                        $arr[] = $this->getOrderPart($entity, $orderByInternal, $orderInternal);
                    }
                }
                return implode(", ", $arr);
            }

            if (strpos($orderBy, 'LIST:') === 0) {
                list($l, $field, $list) = explode(':', $orderBy);
                $fieldPath = $this->getFieldPathForOrderBy($entity, $field);
                return "FIELD(" . $fieldPath . ", '" . implode("', '", explode(",", $list)) . "')";
            }

            if (!is_null($order)) {
                $order = strtoupper($order);
                if (!in_array($order, array('ASC', 'DESC'))) {
                    $order = 'ASC';
                }
            } else {
                $order = 'ASC';
            }

            if (is_integer($orderBy)) {
                return "{$orderBy} " . $order;
            }

            if (!empty($entity->fields[$orderBy])) {
                $fieldDefs = $entity->fields[$orderBy];
            }
            if (!empty($fieldDefs) && !empty($fieldDefs['orderBy'])) {
                $orderPart = str_replace('{direction}', $order, $fieldDefs['orderBy']);
                return "{$orderPart}";
            } else {
                $fieldPath = $this->getFieldPathForOrderBy($entity, $orderBy);

                return "{$fieldPath} " . $order;
            }
        }
    }

    protected function getOrder(IEntity $entity, $orderBy = null, $order = null)
    {
        $orderPart = $this->getOrderPart($entity, $orderBy, $order);
        if ($orderPart) {
            return "ORDER BY " . $orderPart;
        }

    }

    protected function getFieldPathForOrderBy($entity, $orderBy)
    {
        if (strpos($orderBy, '.') !== false) {
            list($alias, $field) = explode('.', $orderBy);
            $fieldPath = $this->toDb($alias) . '.' . $this->toDb($this->sanitize($field));
        } else {
            $fieldPath = $this->getFieldPath($entity, $orderBy);
        }
        return $fieldPath;
    }

    protected function getAggregationSelect(IEntity $entity, $aggregation, $aggregationBy, $distinct = false)
    {
        if (!isset($entity->fields[$aggregationBy])) {
            return false;
        }

        $aggregation = strtoupper($aggregation);

        $distinctPart = '';
        if ($distinct) {
            $distinctPart = 'DISTINCT ';
        }

        $selectPart = "{$aggregation}({$distinctPart}" . $this->toDb($entity->getEntityType()) . "." . $this->toDb($this->sanitize($aggregationBy)) . ") AS AggregateValue";
        return $selectPart;
    }

    public function toDb($field)
    {
        if (array_key_exists($field, $this->fieldsMapCache)) {
            return $this->fieldsMapCache[$field];

        } else {
            $field[0] = strtolower($field[0]);
            $dbField = preg_replace_callback('/([A-Z])/', array($this, 'toDbConversion'), $field);

            $this->fieldsMapCache[$field] = $dbField;
            return $dbField;
        }
    }

    protected function toDbConversion($matches)
    {
        return "_" . strtolower($matches[1]);
    }

    protected function getAlias(IEntity $entity, $relationName)
    {
        if (!isset($this->aliasesCache[$entity->getEntityType()])) {
            $this->aliasesCache[$entity->getEntityType()] = $this->getTableAliases($entity);
        }

        if (isset($this->aliasesCache[$entity->getEntityType()][$relationName])) {
            return $this->aliasesCache[$entity->getEntityType()][$relationName];
        } else {
            return false;
        }
    }

    protected function getTableAliases(IEntity $entity)
    {
        $aliases = array();
        $c = 0;

        $occuranceHash = array();

        foreach ($entity->relations as $name => $r) {
            if ($r['type'] == IEntity::BELONGS_TO) {

                if (!array_key_exists($name, $aliases)) {
                    if (array_key_exists($name, $occuranceHash)) {
                        $occuranceHash[$name]++;
                    } else {
                        $occuranceHash[$name] = 0;
                    }
                    $suffix = '';
                    if ($occuranceHash[$name] > 0) {
                        $suffix .= '_' . $occuranceHash[$name];
                    }

                    $aliases[$name] = $name . $suffix;
                }
            }
        }

        return $aliases;
    }

    protected function getFieldPath(IEntity $entity, $field)
    {
        if (isset($entity->fields[$field])) {
            $f = $entity->fields[$field];

            if (isset($f['source'])) {
                if ($f['source'] != 'db') {
                    return false;
                }
            }

            if (!empty($f['notStorable'])) {
                return false;
            }

            $fieldPath = '';

            switch ($f['type']) {
                case 'foreign':
                    if (isset($f['relation'])) {
                        $relationName = $f['relation'];

                        $foreigh = $f['foreign'];

                        if (is_array($foreigh)) {
                            foreach ($foreigh as $i => $value) {
                                if ($value == ' ') {
                                    $foreigh[$i] = '\' \'';
                                } else {
                                    $foreigh[$i] = $this->getAlias($entity, $relationName) . '.' . $this->toDb($value);
                                }
                            }
                            $fieldPath = 'TRIM(CONCAT(' . implode(', ', $foreigh). '))';
                        } else {
                            $fieldPath = $this->getAlias($entity, $relationName) . '.' . $this->toDb($foreigh);
                        }
                    }
                    break;
                default:
                    $fieldPath = $this->toDb($entity->getEntityType()) . '.' . $this->toDb($this->sanitize($field));
            }

            return $fieldPath;
        }

        return false;
    }

    public function getWhere(IEntity $entity, $whereClause, $sqlOp = 'AND')
    {
        $whereParts = array();

        foreach ($whereClause as $field => $value) {

            if (is_int($field)) {
                $field = 'AND';
            }

            if (!in_array($field, self::$sqlOperators)) {
                $isComplex = false;

                $operator = '=';

                $leftPart = null;

                if (!preg_match('/^[a-z0-9]+$/i', $field)) {
                    foreach (self::$comparisonOperators as $op => $opDb) {
                        if (strpos($field, $op) !== false) {
                            $field = trim(str_replace($op, '', $field));
                            $operator = $opDb;
                            break;
                        }
                    }
                }

                if (strpos($field, '.') !== false || strpos($field, ':') !== false) {
                    $leftPart = $this->convertComplexExpression($entity, $field);
                    $isComplex = true;
                }


                if (empty($isComplex)) {

                    if (!isset($entity->fields[$field])) {
                        continue;
                    }

                    $fieldDefs = $entity->fields[$field];

                    if (!empty($fieldDefs['where']) && !empty($fieldDefs['where'][$operator])) {
                        $whereParts[] = str_replace('{value}', $this->pdo->quote($value), $fieldDefs['where'][$operator]);
                    } else {
                        if ($fieldDefs['type'] == IEntity::FOREIGN) {
                            $leftPart = '';
                            if (isset($fieldDefs['relation'])) {
                                $relationName = $fieldDefs['relation'];
                                if (isset($entity->relations[$relationName])) {

                                    $alias = $this->getAlias($entity, $relationName);
                                    if ($alias) {
                                        $leftPart = $alias . '.' . $this->toDb($fieldDefs['foreign']);
                                    }
                                }
                            }
                        } else {
                            $leftPart = $this->toDb($entity->getEntityType()) . '.' . $this->toDb($this->sanitize($field));
                        }
                    }
                }

                if (!empty($leftPart)) {
                    if (!is_array($value)) {
                        if (!is_null($value)) {
                            $whereParts[] = $leftPart . " " . $operator . " " . $this->pdo->quote($value);
                        } else {
                            if ($operator == '=') {
                                $whereParts[] = $leftPart . " IS NULL";
                            } else if ($operator == '<>') {
                                $whereParts[] = $leftPart . " IS NOT NULL";
                            }
                        }

                    } else {
                        $valArr = $value;
                        foreach ($valArr as $k => $v) {
                            $valArr[$k] = $this->pdo->quote($valArr[$k]);
                        }
                        $oppose = '';
                        if ($operator == '<>') {
                            $oppose = 'NOT';
                        }
                        if (!empty($valArr)) {
                            $whereParts[] = $leftPart . " {$oppose} IN " . "(" . implode(',', $valArr) . ")";
                        } else {
                            $whereParts[] = " 0";
                        }

                    }
                }
            } else {
                $whereParts[] = "(" . $this->getWhere($entity, $value, $field) . ")";
            }
        }
        return implode(" " . $sqlOp . " ", $whereParts);
    }

    protected function sanitize($string)
    {
        return preg_replace('/[^A-Za-z0-9_]+/', '', $string);
    }

    protected function getJoins(IEntity $entity, array $joins, $left = false, $joinConditions = array())
    {
        $joinsArr = array();
        foreach ($joins as $relationName) {
            $conditions = array();
            if (!empty($joinConditions[$relationName])) {
                $conditions = $joinConditions[$relationName];
            }
            if ($joinRelated = $this->getJoinRelated($entity, $relationName, $left, $conditions)) {
                $joinsArr[] = $joinRelated;
            }
        }
        return implode(' ', $joinsArr);
    }

    protected function getJoinRelated(IEntity $entity, $relationName, $left = false, $conditions = array())
    {
        $relOpt = $entity->relations[$relationName];
        $keySet = $this->getKeys($entity, $relationName);

        $pre = ($left) ? 'LEFT ' : '';

        if ($relOpt['type'] == IEntity::MANY_MANY) {

            $key = $keySet['key'];
            $foreignKey = $keySet['foreignKey'];
            $nearKey = $keySet['nearKey'];
            $distantKey = $keySet['distantKey'];

            $relTable = $this->toDb($relOpt['relationName']);
            $midAlias = lcfirst($this->sanitize($relOpt['relationName']));

            $distantTable = $this->toDb($relOpt['entity']);

            $alias = $this->sanitize($relationName);

            $join =
                "{$pre}JOIN `{$relTable}` AS `{$midAlias}` ON {$this->toDb($entity->getEntityType())}." . $this->toDb($key) . " = {$midAlias}." . $this->toDb($nearKey)
                . " AND "
                . "{$midAlias}.deleted = " . $this->pdo->quote(0);

            if (!empty($relOpt['conditions']) && is_array($relOpt['conditions'])) {
                $conditions = array_merge($conditions, $relOpt['conditions']);
            }
            foreach ($conditions as $f => $v) {
                $join .= " AND {$midAlias}." . $this->toDb($this->sanitize($f)) . " = " . $this->pdo->quote($v);
            }

            $join .= " {$pre}JOIN `{$distantTable}` AS `{$alias}` ON {$alias}." . $this->toDb($foreignKey) . " = {$midAlias}." . $this->toDb($distantKey)
                . " AND "
                . "{$alias}.deleted = " . $this->pdo->quote(0) . "";

            return $join;
        }

        if ($relOpt['type'] == IEntity::HAS_MANY) {

            $foreignKey = $keySet['foreignKey'];
            $distantTable = $this->toDb($relOpt['entity']);

            $alias = $this->sanitize($relationName);

            // TODO conditions

            $join =
                "{$pre}JOIN `{$distantTable}` AS `{$alias}` ON {$this->toDb($entity->getEntityType())}." . $this->toDb('id') . " = {$alias}." . $this->toDb($foreignKey)
                . " AND "
                . "{$alias}.deleted = " . $this->pdo->quote(0) . "";

            return $join;
        }

        if ($relOpt['type'] == IEntity::BELONGS_TO) {
            return $pre . $this->getBelongsToJoin($entity, $relationName);
        }

        return false;
    }

    public function composeSelectQuery($table, $select, $joins = '', $where = '', $order = '', $offset = null, $limit = null, $distinct = null, $aggregation = false, $groupBy = null)
    {
        $sql = "SELECT";

        /*if (!empty($distinct)) {
            $sql .= " DISTINCT";
        }*/

        $sql .= " {$select} FROM `{$table}`";

        if (!empty($joins)) {
            $sql .= " {$joins}";
        }

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        if (!empty($groupBy)) {
            $sql .= " GROUP BY {$groupBy}";
        } else {
            if (!empty($distinct)) {
                $sql .= " GROUP BY `{$table}`.id";
            }
        }

        if (!empty($order)) {
            $sql .= " {$order}";
        }

        if (is_null($offset) && !is_null($limit)) {
            $offset = 0;
        }

        $sql = $this->limit($sql, $offset, $limit);

        return $sql;
    }

    abstract public function limit($sql, $offset, $limit);

    public function getKeys(IEntity $entity, $relationName)
    {
        $relOpt = $entity->relations[$relationName];
        $relType = $relOpt['type'];

        switch ($relType) {

            case IEntity::BELONGS_TO:
                $key = $this->toDb($entity->getEntityType()) . 'Id';
                if (isset($relOpt['key'])) {
                    $key = $relOpt['key'];
                }
                $foreignKey = 'id';
                if(isset($relOpt['foreignKey'])){
                    $foreignKey = $relOpt['foreignKey'];
                }
                return array(
                    'key' => $key,
                    'foreignKey' => $foreignKey,
                );

            case IEntity::HAS_MANY:
                $key = 'id';
                if (isset($relOpt['key'])){
                    $key = $relOpt['key'];
                }
                $foreignKey = $this->toDb($entity->getEntityType()) . 'Id';
                if (isset($relOpt['foreignKey'])) {
                    $foreignKey = $relOpt['foreignKey'];
                }
                return array(
                    'key' => $key,
                    'foreignKey' => $foreignKey,
                );
            case IEntity::HAS_CHILDREN:
                $key = 'id';
                if (isset($relOpt['key'])){
                    $key = $relOpt['key'];
                }
                $foreignKey = 'parentId';
                if (isset($relOpt['foreignKey'])) {
                    $foreignKey = $relOpt['foreignKey'];
                }
                $foreignType = 'parentType';
                if (isset($relOpt['foreignType'])) {
                    $foreignType = $relOpt['foreignType'];
                }
                return array(
                    'key' => $key,
                    'foreignKey' => $foreignKey,
                    'foreignType' => $foreignType,
                );

            case IEntity::MANY_MANY:
                $key = 'id';
                if(isset($relOpt['key'])){
                    $key = $relOpt['key'];
                }
                $foreignKey = 'id';
                if(isset($relOpt['foreignKey'])){
                    $foreignKey = $relOpt['foreignKey'];
                }
                $nearKey = $this->toDb($entity->getEntityType()) . 'Id';
                $distantKey = $this->toDb($relOpt['entity']) . 'Id';
                if (isset($relOpt['midKeys']) && is_array($relOpt['midKeys'])){
                    $nearKey = $relOpt['midKeys'][0];
                    $distantKey = $relOpt['midKeys'][1];
                }
                return array(
                    'key' => $key,
                    'foreignKey' => $foreignKey,
                    'nearKey' => $nearKey,
                    'distantKey' => $distantKey,
                );
        }
    }

}

