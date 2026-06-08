<?php
header('Content-Type: application/json');
$dsn = "mysql:host=localhost;port=3306;dbname=ots_db";
try {
    $pdo = new PDO($dsn, "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = [];

    // 1. t_pdrb_prov columns
    $stmt = $pdo->query("DESCRIBE t_pdrb_prov");
    $result['t_pdrb_prov_columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Latest row
    $stmt = $pdo->query("SELECT * FROM t_pdrb_prov ORDER BY id DESC LIMIT 1");
    $result['latest_row'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. All tables with 'm_pdrb' or 'm_' prefix
    $stmt = $pdo->query("SHOW TABLES LIKE 'm_%'");
    $result['master_tables'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 4. All tables
    $stmt = $pdo->query("SHOW TABLES");
    $result['all_tables'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
