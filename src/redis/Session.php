<?php

namespace craft\cloud\redis;

use craft\cloud\Module;

class Session extends \yii\redis\Session
{
    public function __construct($config = [])
    {
        $config += [
            'redis' => Connection::class,
            'keyPrefix' => sprintf('session.%s.', Module::getInstance()->getConfig()->environmentId),
        ];

        parent::__construct($config);
    }
}
