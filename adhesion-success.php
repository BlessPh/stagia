<?php
$ref=trim($_GET['ref']??'');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Demande envoyée | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
<div class="container d-flex align-items-center justify-content-center min-vh-100">
    <div class="success-card text-center">
        <div class="success-icon"><i class="bi bi-check-lg"></i></div>
        <h2>Demande envoyée</h2>
        <p>Votre demande d'adhésion à STAGIA-RDC a été enregistrée avec succès.</p>

        <?php if($ref): ?>
        <div class="reference-box">
            <small>Référence de la demande</small>
            <strong><?= htmlspecialchars($ref) ?></strong>
        </div>
        <?php endif; ?>

        <p class="small text-muted">Conservez cette référence. Votre dossier sera examiné par l'administration STAGIA.</p>

        <a href="index.php" class="btn btn-primary-stagia">Retour au portail</a>
    </div>
</div>
</body>
</html>