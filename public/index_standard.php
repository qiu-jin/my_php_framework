<?php

define('APP_DEBUG', true);

include __DIR__.'/../framework/app.php';

framework\App::start('test', 'standard')->run('dump');