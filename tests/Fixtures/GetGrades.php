<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = new PDO('sqlite:' . database_path('database.sqlite'));
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

foreach (['grades', 'subjects'] as $table) {
    echo "=== $table ===\n";
    $stmt = $pdo->query("SELECT id, name, level AS lvl FROM $table ORDER BY id");
    foreach ($stmt->fetchAll() as $row) {
        echo $row['id'] . ': ' . $row['name'] . ' (lvl ' . $row['lvl'] . ")\n";
    }
}
