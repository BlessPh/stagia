<?php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../services/MailService.php';

function fail($message, $code = 400){
    http_response_code($code);
    exit($message);
}

if($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role_code'] ?? '') !== 'SUPER_ADMIN')
    fail('Accès refusé.', 403);

if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''))
    fail('Requête invalide.', 403);

$id   = (int)($_POST['id'] ?? 0);
$code = strtoupper(trim($_POST['code'] ?? ''));

if(!$id || !$code) fail('Données invalides.');

$stmt = $pdo->prepare("SELECT * FROM demandes_adhesion WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$d = $stmt->fetch();

if(!$d || !in_array($d['statut'], ['SOUMISE','EN_EXAMEN','A_COMPLETER'], true))
    fail('Cette demande ne peut plus être validée.');

$stmt = $pdo->prepare("SELECT id FROM etablissements WHERE code=? LIMIT 1");
$stmt->execute([$code]);
if($stmt->fetchColumn()) fail("Ce code d'établissement existe déjà.");

$stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->execute([$d['responsable_email']]);
if($stmt->fetchColumn()) fail("Un utilisateur utilise déjà cet e-mail.");

$stmt = $pdo->query("SELECT id FROM roles WHERE code='ADMIN_ETABLISSEMENT' LIMIT 1");
$roleId = $stmt->fetchColumn();

if(!$roleId) fail('Rôle ADMIN_ETABLISSEMENT introuvable.');

/* Token d'activation */
$token       = bin2hex(random_bytes(32));
$tokenHash   = hash('sha256', $token);
$expiration  = date('Y-m-d H:i:s', time() + 48 * 3600);
$identifiant = strtolower($code).'.admin';

/*
 * Le compte n'a pas encore de vrai mot de passe.
 * On place un mot de passe aléatoire inutilisable jusqu'à l'activation.
 */
$passwordTemp = password_hash(
    bin2hex(random_bytes(32)),
    PASSWORD_DEFAULT
);

try {

    $pdo->beginTransaction();

    /* Établissement */
    $stmt = $pdo->prepare("
        INSERT INTO etablissements(
            code,nom,type_etablissement,email,telephone,
            adresse,province,ville,numero_agrement,statut
        )
        VALUES(?,?,?,?,?,?,?,?,?,'VALIDE')
    ");

    $stmt->execute([
        $code,
        $d['nom_etablissement'],
        $d['type_etablissement'],
        $d['email_etablissement'],
        $d['telephone_etablissement'],
        $d['adresse'],
        $d['province'],
        $d['ville'],
        $d['numero_agrement']
    ]);

    $etablissementId = $pdo->lastInsertId();

    /* Administrateur établissement */
    $stmt = $pdo->prepare("
        INSERT INTO users(
            role_id,nom,postnom,prenom,email,identifiant,password,
            telephone,actif,statut_compte,
            activation_token,activation_expire_at
        )
        VALUES(?,?,?,?,?,?,?,?,0,'A_ACTIVER',?,?)
    ");

    $stmt->execute([
        $roleId,
        $d['responsable_nom'],
        $d['responsable_postnom'],
        $d['responsable_prenom'],
        $d['responsable_email'],
        $identifiant,
        $passwordTemp,
        $d['responsable_telephone'],
        $tokenHash,
        $expiration
    ]);

    $userId = $pdo->lastInsertId();

    /* Liaison établissement / utilisateur */
    $pdo->prepare("
        INSERT INTO etablissement_users(
            etablissement_id,user_id,fonction,principal
        )
        VALUES(?,?,?,1)
    ")->execute([
        $etablissementId,
        $userId,
        $d['responsable_fonction']
    ]);

    /* Validation de la demande */
    $pdo->prepare("
        UPDATE demandes_adhesion
        SET statut='VALIDEE',
            traite_par=?,
            traite_le=NOW(),
            etablissement_id=?,
            user_id=?
        WHERE id=?
    ")->execute([
        $_SESSION['user_id'],
        $etablissementId,
        $userId,
        $id
    ]);

    $pdo->commit();

} catch(Throwable $e) {

    if($pdo->inTransaction())
        $pdo->rollBack();

    error_log('[VALIDATION ADHESION] '.$e->getMessage());

    fail('Impossible de valider cette demande.');
}

/* =========================================================
   ENVOI DU LIEN D'ACTIVATION
========================================================= */

$lien = BASE_URL.'/activate.php?token='.urlencode($token);

$nomComplet = trim(
    ($d['responsable_prenom'] ?? '').' '.
    ($d['responsable_nom'] ?? '')
);

$mailEnvoye = MailService::envoyerActivation(
    $d['responsable_email'],
    $nomComplet,
    $lien
);

if(!$mailEnvoye){
    error_log(
        '[SMTP ACTIVATION] '.
        MailService::getLastError()
    );
}

/* Informations affichées après validation */
$_SESSION['new_account'] = [
    'etablissement' => $d['nom_etablissement'],
    'identifiant'   => $identifiant,
    'email'         => $d['responsable_email'],
    'mail_sent'     => $mailEnvoye
];

header(
    'Location: '.BASE_URL.
    '/views/adhesions/account-created.php'
);

exit;