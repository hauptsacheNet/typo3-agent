<?php

declare(strict_types=1);

/**
 * Seed the E2E TYPO3 instance (SQLite) after `typo3 setup`:
 *
 *  - create a workspace, because the agent module refuses to start tasks in
 *    the Live workspace
 *  - put the admin user into that workspace so the module is usable right
 *    after login without clicking through the workspace switcher
 *
 * Usage: php Build/tests/e2e-seed.php <path-to-sqlite.db>
 */

$dbPath = $argv[1] ?? '';
if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "Usage: php e2e-seed.php <path-to-sqlite.db>\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$workspaceExists = $pdo->query("SELECT uid FROM sys_workspace WHERE title = 'E2E Workspace' AND deleted = 0")->fetchColumn();
if ($workspaceExists === false) {
    $pdo->exec("INSERT INTO sys_workspace (pid, title, adminusers, members, tstamp, deleted) VALUES (0, 'E2E Workspace', 'be_users_1', '', strftime('%s','now'), 0)");
    $workspaceId = (int)$pdo->lastInsertId();
} else {
    $workspaceId = (int)$workspaceExists;
}

$pdo->exec("UPDATE be_users SET workspace_id = {$workspaceId} WHERE username = 'admin'");

echo "Seeded workspace #{$workspaceId} and moved admin into it.\n";
