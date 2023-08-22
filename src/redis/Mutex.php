<?php

namespace craft\cloud\redis;

use Craft;
use craft\cloud\Module;

class Mutex extends \yii\redis\Mutex
{
    public const EXPIRE_WEB = 30;
    public const EXPIRE_CONSOLE = 900;

    public function __construct($config = [])
    {
        $config += [
            'redis' => Connection::class,
            'keyPrefix' => sprintf('mutex.%s.', Module::getInstance()->getConfig()->environmentId),
            'expire' => Craft::$app->getRequest()->getIsConsoleRequest()
                ? self::EXPIRE_CONSOLE
                : self::EXPIRE_WEB,
        ];

        parent::__construct($config);
    }
}
