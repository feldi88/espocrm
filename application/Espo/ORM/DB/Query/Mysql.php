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

class Mysql extends Base
{
    public function limit($sql, $offset, $limit)
    {
        if (!is_null($offset) && !is_null($limit)) {
            $offset = intval($offset);
            $limit = intval($limit);
            $sql .= " LIMIT {$offset}, {$limit}";
        }
        return $sql;
    }
}
