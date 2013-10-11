<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

namespace PHPCI\Store;

use PHPCI\Store\Base\BuildStoreBase;

/**
* Build Store
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Core
*/
class BuildStore extends BuildStoreBase
{
    public function getLatestBuilds($projectId)
    {
        $query = 'SELECT * FROM build WHERE project_id = :pid ORDER BY id DESC LIMIT 5';
        $stmt = \b8\Database::getConnection('read')->prepare($query);
        $stmt->bindValue(':pid', $projectId);

        if ($stmt->execute()) {
            $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $map = function ($item) {
                return new \PHPCI\Model\Build($item);
            };
            $rtn = array_map($map, $res);

            return $rtn;
        } else {
            return array();
        }
    }

    public function getMeta($key, $projectId, $buildId = null, $numResults = 1)
    {
        $select = '`build_id`, `meta_key`, `meta_value`';
        $and = $numResults > 1 ? ' AND (`build_id` <= :buildId) ' : ' AND (`build_id` = :buildId) ';
        $where = '`meta_key` = :key AND `project_id` = :projectId ' . $and;
        $query = 'SELECT '.$select.' FROM `build_meta` WHERE '.$where.' ORDER BY id DESC LIMIT :numResults';

        $stmt = \b8\Database::getConnection('read')->prepare($query);
        $stmt->bindValue(':key', $key, \PDO::PARAM_STR);
        $stmt->bindValue(':projectId', (int)$projectId, \PDO::PARAM_INT);
        $stmt->bindValue(':buildId', (int)$buildId, \PDO::PARAM_INT);
        $stmt->bindValue(':numResults', (int)$numResults, \PDO::PARAM_INT);

        if ($stmt->execute()) {
            $rtn = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $rtn = array_reverse($rtn);
            $rtn = array_map(function ($item) {
                $item['meta_value'] = json_decode($item['meta_value'], true);
                return $item;
            }, $rtn);

            if (!count($rtn)) {
                return null;
            } else {
                return $rtn;
            }

        } else {
            return null;
        }
    }

    public function setMeta($projectId, $buildId, $key, $value)
    {
        $cols = '`project_id`, `build_id`, `meta_key`, `meta_value`';
        $query = 'REPLACE INTO build_meta ('.$cols.') VALUES (:projectId, :buildId, :key, :value)';

        $stmt = \b8\Database::getConnection('read')->prepare($query);
        $stmt->bindValue(':key', $key, \PDO::PARAM_STR);
        $stmt->bindValue(':projectId', (int)$projectId, \PDO::PARAM_INT);
        $stmt->bindValue(':buildId', (int)$buildId, \PDO::PARAM_INT);
        $stmt->bindValue(':value', $value, \PDO::PARAM_STR);

        if ($stmt->execute()) {
            return true;
        } else {
            return false;
        }
    }
}
