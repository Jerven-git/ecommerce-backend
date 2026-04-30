<?php

/*
|--------------------------------------------------------------------------
| Test Bootstrap — DB Isolation Guard
|--------------------------------------------------------------------------
|
| This file runs BEFORE PHPUnit reads phpunit.xml, BEFORE Laravel boots, and
| BEFORE Dotenv loads any .env file. We force-set DB connection env vars
| here so that test runs CANNOT EVER touch the live MySQL database.
|
| Why we need this: docker-compose passes env_file with DB_CONNECTION=mysql
| into the container, which makes mysql an OS-level env var. PHPUnit's
| `<env force="true">` and Laravel's `.env.testing` both lose to OS env vars
| in Dotenv's resolution order. The only way to win is to set the override
| at the lowest level — putenv() — before anything else runs.
|
| If you remove these lines, `php artisan test` will run RefreshDatabase
| against the live MySQL DB and wipe everything. This actually happened.
|
*/

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');
putenv('BROADCAST_CONNECTION=null');

$_SERVER['APP_ENV'] = 'testing';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
$_SERVER['CACHE_STORE'] = 'array';
$_SERVER['SESSION_DRIVER'] = 'array';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
$_SERVER['MAIL_MAILER'] = 'array';
$_SERVER['BROADCAST_CONNECTION'] = 'null';

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['CACHE_STORE'] = 'array';
$_ENV['SESSION_DRIVER'] = 'array';
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_ENV['MAIL_MAILER'] = 'array';
$_ENV['BROADCAST_CONNECTION'] = 'null';

require __DIR__.'/../vendor/autoload.php';
