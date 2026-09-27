<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/certificate-service.php';

$token=trim($_GET['token']??'');

$data=$token
    ?certificateData($pdo,$token)
    :null;


$valid=
    $data &&
    $data['certificate_status']==='GENERE' &&
    $data['completion_status']==='VALIDE';


$cancelled=
    $data &&
    $data['certificate_status']==='ANNULE';


function e($v){
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>Vérification attestation | STAGIA-RDC</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      rel="stylesheet">


<style>

body{
    background:#f4f6f8;
    font-family:Arial,sans-serif;
}

.verify-card{
    max-width:720px;
    margin:60px auto;
    background:white;
    border-radius:16px;
    box-shadow:0 12px 35px rgba(0,0,0,.08);
    overflow:hidden;
}

.verify-head{
    padding:30px;
    text-align:center;
    border-bottom:1px solid #eee;
}

.logo{
    width:80px;
    margin-bottom:12px;
}

.status-icon{
    width:70px;
    height:70px;
    margin:0 auto 14px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:32px;
}

.valid{
    background:#eaf8ef;
    color:#198754;
}

.invalid{
    background:#fdecec;
    color:#dc3545;
}

.cancelled{
    background:#fff4df;
    color:#e96500;
}

.verify-body{
    padding:28px;
}

.info-row{
    padding:11px 0;
    border-bottom:1px solid #edf0f3;
}

.info-row:last-child{
    border:0;
}

.label{
    color:#718096;
    font-size:12px;
    margin-bottom:3px;
}

.value{
    font-weight:600;
    color:#172b3a;
}

.reference{
    color:#e96500;
}

</style>

</head>


<body>

<div class="container">

<div class="verify-card">


<div class="verify-head">

    <img src="<?= BASE_URL ?>/assets/img/logo.png"
         class="logo"
         alt="STAGIA-RDC">


    <?php if($valid): ?>

        <div class="status-icon valid">
            <i class="bi bi-patch-check-fill"></i>
        </div>

        <h3 class="text-success">
            Attestation authentique
        </h3>

        <p class="text-muted mb-0">
            Ce document est reconnu par STAGIA-RDC.
        </p>


    <?php elseif($cancelled): ?>

        <div class="status-icon cancelled">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>

        <h3 style="color:#e96500">
            Attestation annulée
        </h3>

        <p class="text-muted mb-0">
            Cette attestation n'est plus valide.
        </p>


    <?php else: ?>

        <div class="status-icon invalid">
            <i class="bi bi-x-lg"></i>
        </div>

        <h3 class="text-danger">
            Attestation non reconnue
        </h3>

        <p class="text-muted mb-0">
            Le code fourni ne correspond à aucune
            attestation valide.
        </p>

    <?php endif; ?>

</div>


<?php if($data): ?>

<div class="verify-body">

    <div class="info-row">
        <div class="label">
            Référence
        </div>

        <div class="value reference">
            <?= e($data['reference']) ?>
        </div>
    </div>


    <div class="info-row">

        <div class="label">
            Stagiaire
        </div>

        <div class="value">

            <?= e(
                trim(
                    ($data['nom']??'').' '.
                    ($data['postnom']??'').' '.
                    ($data['prenom']??'')
                )
            ) ?>

        </div>

        <small class="text-muted">
            <?= e($data['stagia_code']) ?>
        </small>

    </div>


    <div class="info-row">

        <div class="label">
            Université
        </div>

        <div class="value">
            <?= e($data['university_name']) ?>
        </div>

    </div>


    <div class="info-row">

        <div class="label">
            Hôpital d'accueil
        </div>

        <div class="value">
            <?= e($data['host_name']) ?>
        </div>

    </div>


    <div class="info-row">

        <div class="label">
            Stage
        </div>

        <div class="value">
            <?= e($data['campaign_title']) ?>
        </div>

    </div>


    <div class="info-row">

        <div class="label">
            Service
        </div>

        <div class="value">
            <?= e($data['unit_name']) ?>
        </div>

    </div>


    <div class="info-row">

        <div class="label">
            Période
        </div>

        <div class="value">

            Du
            <?= certificateDate($data['date_debut']) ?>

            au
            <?= certificateDate($data['date_fin']) ?>

        </div>

    </div>


    <div class="info-row">

        <div class="label">
            Date d'émission
        </div>

        <div class="value">
            <?= certificateDate($data['generated_at']) ?>
        </div>

    </div>

</div>

<?php endif; ?>


</div>

</div>

</body>
</html>