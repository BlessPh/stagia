<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';


$roleCode = 'SUPER_ADMIN';

$nom = 'Administrateur';

$postnom = 'STAGIA';

$prenom = 'National';

$email = trim((string)(getenv('STAGIA_ADMIN_EMAIL') ?: ''));

$identifiant = trim((string)(getenv('STAGIA_ADMIN_USERNAME') ?: ''));

$motDePasse = (string)(getenv('STAGIA_ADMIN_PASSWORD') ?: '');

if ($email === '' || $identifiant === '' || strlen($motDePasse) < 12) {
    fwrite(
        STDERR,
        "Definissez STAGIA_ADMIN_EMAIL, STAGIA_ADMIN_USERNAME et un STAGIA_ADMIN_PASSWORD d'au moins 12 caracteres.\n"
    );
    exit(1);
}


$stmt = $pdo->prepare(
    "SELECT id
     FROM roles
     WHERE code = ?
     LIMIT 1"
);

$stmt->execute([$roleCode]);

$role = $stmt->fetch();


if (!$role) {

    die("Rôle SUPER_ADMIN introuvable.");

}


$passwordHash =
    password_hash(
        $motDePasse,
        PASSWORD_DEFAULT
    );


$stmt = $pdo->prepare(
    "INSERT INTO users
    (
        role_id,
        nom,
        postnom,
        prenom,
        email,
        identifiant,
        password
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)"
);


$stmt->execute([
    $role['id'],
    $nom,
    $postnom,
    $prenom,
    $email,
    $identifiant,
    $passwordHash
]);


echo "Administrateur créé avec succès.";
