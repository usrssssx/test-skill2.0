<?php

$root = $argv[1] ?? '';
$template = $argv[2] ?? '';
$url = $argv[3] ?? '';
$portal = $argv[4] ?? '';
if (! preg_match('~^/[a-zA-Z0-9._/-]+/entity-test-app$~', $root)
    || ! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')
    || ! preg_match('/^[a-z0-9-]+\.bitrix24\.ru$/', $portal)) {
    throw new RuntimeException('Invalid bootstrap arguments.');
}
foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'backups'] as $directory) {
    $path = $root.'/shared/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 02770, true)) {
        throw new RuntimeException('Cannot initialize runtime directory.');
    }
    chmod($path, 02770);
}
$envPath = $root.'/shared/.env';
if (file_exists($envPath)) {
    throw new RuntimeException('Existing environment preserved; refusing to overwrite.');
}
$env = file_get_contents($template);
$values = [
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => $url, 'ASSET_URL' => $url,
    'SESSION_PATH' => '/entity-test', 'SESSION_COOKIE' => 'skill_entity_test_session',
    'LOG_LEVEL' => 'warning', 'BITRIX24_PORTAL' => $portal,
];
foreach ($values as $key => $value) {
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $env = preg_match($pattern, $env) ? preg_replace_callback($pattern, static fn (): string => $line, $env) : $env."\n".$line."\n";
}
if (file_put_contents($envPath, $env) === false) {
    throw new RuntimeException('Environment creation failed.');
}
chmod($envPath, 0640);
echo "RUNTIME_PREPARED; secrets not printed\n";
