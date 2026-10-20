<?php

/*
 * Makes sure a .env file with an APP_KEY exists before Laravel boots.
 * On a fresh clone this creates .env from .env.example, generates an
 * APP_KEY and a one-time setup token (storage/app/setup-token.txt).
 */

$base = dirname(__DIR__);
$env = $base.'/.env';

if (! file_exists($env) && file_exists($base.'/.env.example')) {
    $content = file_get_contents($base.'/.env.example');
    $key = 'base64:'.base64_encode(random_bytes(32));
    $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $content);
    file_put_contents($env, $content);
    @chmod($env, 0600);
}

$tokenFile = $base.'/storage/app/setup-token.txt';
if (file_exists($env) && ! file_exists($tokenFile)
    && ! preg_match('/^APP_INSTALLED=true\s*$/m', (string) file_get_contents($env))) {
    @mkdir(dirname($tokenFile), 0770, true);
    file_put_contents($tokenFile, bin2hex(random_bytes(16)).PHP_EOL);
    @chmod($tokenFile, 0600);
}
