<?php

declare(strict_types=1);

// Front controller for admin-level pages; admin/*.html (the app shell) and admin/api.raw (JSON) are thin wrappers around this.

require dirname(__DIR__) . '/src/bootstrap.php';

$page = (string) ($argv[1] ?? 'index');
try {
    echo (new GitDeploy\AdminApp())->handle($page);
} catch (Throwable $e) {
    echo $page === 'api'
        ? GitDeploy\Json::error(new GitDeploy\ApiError(500, 'server', 'Laravel Github deploy: ' . $e->getMessage()))
        : '<div class="gd-alert gd-alert-err">Laravel Github deploy: ' . h($e->getMessage()) . '</div>';
}
