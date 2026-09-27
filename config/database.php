<?php

require_once __DIR__.'/env.php';

/**
 * ============================================================
 * STAGIA-RDC
 * Connexion à la base de données
 * ============================================================
 */

/* Les variables d'environnement permettent de changer d'environnement sans modifier le code. */
$host = getenv('DB_HOST') ?: 'localhost';
$port = (int)(getenv('DB_PORT') ?: 3306);
$dbname = getenv('DB_NAME') ?: 'stagia_recovery';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$sslCa = trim((string)(getenv('DB_SSL_CA') ?: ''));
$sslVerifyServerCert = filter_var(
    getenv('DB_SSL_VERIFY_SERVER_CERT') ?: 'true',
    FILTER_VALIDATE_BOOL
);

/* Création de la connexion PDO : erreurs transformées en exceptions et requêtes préparées natives. */
try {

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];

    /* Aiven exige TLS. Un chemin relatif part toujours de la racine du projet. */
    if ($sslCa !== '') {
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $sslCa)) {
            $sslCa = dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCa);
        }

        if (!is_file($sslCa) || !is_readable($sslCa)) {
            throw new RuntimeException('Certificat CA de la base de donnees introuvable.');
        }

        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $sslVerifyServerCert;
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        $options
    );

} catch (Throwable $e) {

    /* Le détail technique reste masqué pour ne pas exposer la configuration de la base. */
    die(
        "Erreur de connexion à la base de données."
    );
}
