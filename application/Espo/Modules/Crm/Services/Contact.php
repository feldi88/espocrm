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

namespace Espo\Modules\Crm\Services;

use \Espo\ORM\Entity;

class Contact extends \Espo\Services\Record
{
    protected function getDuplicateWhereClause(Entity $entity)
    {
        $data = array(
            'OR' => array(
                array(
                    'firstName' => $entity->get('firstName'),
                    'lastName' => $entity->get('lastName'),
                )
            )
        );
        if ($entity->get('emailAddress')) {
            $data['OR'][] = array(
                'emailAddress' => $entity->get('emailAddress'),
             );
        }

        return $data;
    }

    public function afterCreate($entity, array $data)
    {
        parent::afterCreate($entity, $data);
        if (!empty($data['emailId'])) {
            $email = $this->getEntityManager()->getEntity('Email', $data['emailId']);
            if ($email && !$email->get('parentId')) {
                if ($this->getConfig()->get('b2cMode')) {
                    $email->set(array(
                        'parentType' => 'Contact',
                        'parentId' => $entity->id
                    ));
                } else {
                    if ($entity->get('accountId')) {
                        $email->set(array(
                            'parentType' => 'Account',
                            'parentId' => $entity->get('accountId')
                        ));
                    }
                }
                $this->getEntityManager()->saveEntity($email);
            }
        }
    }
}

