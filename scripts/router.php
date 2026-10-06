<?php
/** Router for php -S 127.0.0.1:8000 -t public scripts/router.php */
declare(strict_types=1);
$public=realpath(dirname(__DIR__).'/public');
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$file=realpath($public.$path);
if ($file && str_starts_with($file,$public.DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file,PATHINFO_EXTENSION) !== 'php' && !str_contains($path,'/.')) return false;
$_SERVER['SCRIPT_NAME']='/index.php';
require $public.'/index.php';
