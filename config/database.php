<?php

$host = "127.0.0.1";
$port = "5432";
$dbname = "salsa_apparel";
$username = "postgres";

$envFile = dirname(__DIR__) . "/.env";

if (!file_exists($envFile)) {
    die("File .env tidak ditemukan.");
}

$env = parse_ini_file($envFile);

$password = $env["DB_PASSWORD"] ?? "";

try {

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $e) {

    die("Koneksi database gagal: " . $e->getMessage());

}