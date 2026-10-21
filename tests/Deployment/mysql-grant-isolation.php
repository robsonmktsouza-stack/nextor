<?php
/** Disposable CI-only MySQL grant audit. NEVER point at a customer server. */
declare(strict_types=1);
$password = getenv('CI_MYSQL_ROOT_PASSWORD');
if (!$password || getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Somente no banco descartável do GitHub Actions.\n");
    exit(1);
}
$root = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$dbs = ['ci_lumeron_a', 'ci_lumeron_b'];
$users = ['ci_lum_a', 'ci_lum_b'];
try {
    foreach ($dbs as $i => $db) {
        $root->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $root->exec("CREATE USER '{$users[$i]}'@'127.0.0.1' IDENTIFIED BY 'local-test-password-$i'");
        $root->exec("GRANT ALL PRIVILEGES ON $db.* TO '{$users[$i]}'@'127.0.0.1'");
    }
    foreach ($users as $i => $user) {
        $own = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$dbs[$i]}", $user,
            "local-test-password-$i", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $own->exec('CREATE TABLE instance_witness (id INT PRIMARY KEY)');
        $own->exec('INSERT INTO instance_witness VALUES (1)');
        if ((int)$own->query('SELECT COUNT(*) FROM instance_witness')->fetchColumn() !== 1) {
            throw new RuntimeException('Own database failed.');
        }
        try {
            $own->query("SELECT * FROM {$dbs[1-$i]}.instance_witness");
            throw new RuntimeException('Cross-company MySQL access was unexpectedly granted.');
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42000','HY000'], true)) {
                throw $e;
            }
        }
    }
    echo "[OK] Dois usuários MySQL sem SELECT no banco alheio.\n";
} finally {
    foreach ($dbs as $i => $db) {
        try {
            $root->exec("DROP USER IF EXISTS '{$users[$i]}'@'127.0.0.1'");
            $root->exec("DROP DATABASE IF EXISTS $db");
        } catch (Throwable $e) {
            fwrite(STDERR, "Falha na limpeza de fixture de CI: ".$e->getMessage()."\n");
        }
    }
}
