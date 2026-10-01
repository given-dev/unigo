<?php
/**
 * UniGo - project root entry point.
 *
 * The application lives in public/ and expects its base URL to be
 * ".../unigo/public". Apache serves this project folder directly, so a request
 * for the bare directory URL lands here instead. Handing the browser over to
 * public/ keeps every generated link and the router's base-path detection
 * consistent.
 */

declare(strict_types=1);

if (!is_dir(__DIR__ . '/public')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "UniGo is not installed correctly: the public/ folder is missing.\n";
    return;
}

header('Location: public/', true, 302);