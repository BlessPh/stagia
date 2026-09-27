<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;


/* =========================================================
   HELPERS
========================================================= */

function conventionEscape($value):string{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function conventionDate(?string $value):string{
    if(!$value)
        return '-';

    $time=strtotime($value);

    return $time
        ?date('d/m/Y',$time)
        :'-';
}


function conventionImageData(string $path):string{
    if(!is_file($path))
        return '';

    $mime=function_exists('mime_content_type')
        ?mime_content_type($path)
        :'image/png';

    return
        'data:'.
        ($mime?:'image/png').
        ';base64,'.
        base64_encode(
            file_get_contents($path)
        );
}


/* =========================================================
   DONNÉES CONVENTION
========================================================= */

function conventionData(
    PDO $pdo,
    string $token
):?array{

    $stmt=$pdo->prepare("
        SELECT
            sc.id,
            sc.uuid AS convention_uuid,
            sc.reference,
            sc.titre,
            sc.version,
            sc.statut,
            sc.date_emission,
            sc.date_signature_etudiant,
            sc.date_signature_universite,
            sc.date_signature_accueil,
            sc.signed_at,
            sc.document_id,
            sc.created_at,

            pl.id AS placement_id,
            pl.student_id,
            pl.host_etablissement_id,
            pl.campaign_id,

            COALESCE(
                sp.stagia_code,
                CONCAT(
                    'STG-ID-',
                    LPAD(pl.student_id,8,'0')
                )
            ) AS stagia_code,

            COALESCE(
                NULLIF(TRIM(sp.nom),''),
                CONCAT(
                    'Étudiant #',
                    pl.student_id
                )
            ) AS nom,

            COALESCE(sp.postnom,'') AS postnom,
            COALESCE(sp.prenom,'') AS prenom,

            sp.user_id AS student_user_id,

            c.code AS campaign_code,
            c.titre AS campaign_title,
            c.owner_etablissement_id AS university_id,

            uni.code AS university_code,
            uni.nom AS university_name,
            uni.adresse AS university_address,
            uni.ville AS university_city,
            uni.province AS university_province,

            host.code AS host_code,
            host.nom AS host_name,
            host.adresse AS host_address,
            host.ville AS host_city,
            host.province AS host_province,

            a.id AS assignment_id,
            a.date_debut,
            a.date_fin,

            hu.nom AS unit_name

        FROM stage_conventions sc

        INNER JOIN stage_placements pl
            ON pl.id=sc.placement_id

        LEFT JOIN student_profiles sp
            ON sp.id=pl.student_id

        INNER JOIN stage_campaigns c
            ON c.id=pl.campaign_id

        INNER JOIN etablissements uni
            ON uni.id=c.owner_etablissement_id

        INNER JOIN etablissements host
            ON host.id=pl.host_etablissement_id

        LEFT JOIN stage_admissions ad
            ON ad.placement_id=pl.id

        LEFT JOIN stage_assignments a
            ON a.admission_id=ad.id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        WHERE
            sc.uuid=?
            OR sc.reference=?

        ORDER BY a.id DESC

        LIMIT 1
    ");

    $stmt->execute([
        $token,
        $token
    ]);

    $data=$stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if(!$data)
        return null;

    foreach([
        'id',
        'placement_id',
        'student_id',
        'host_etablissement_id',
        'campaign_id',
        'university_id',
        'version'
    ] as $field){
        $data[$field]=(int)$data[$field];
    }

    $data['assignment_id']=
        $data['assignment_id']!==null
            ?(int)$data['assignment_id']
            :null;

    $data['student_user_id']=
        $data['student_user_id']!==null
            ?(int)$data['student_user_id']
            :null;

    $data['document_id']=
        $data['document_id']!==null
            ?(int)$data['document_id']
            :null;

    return $data;
}


/* =========================================================
   GÉNÉRER LA CONVENTION PDF
========================================================= */

function conventionEnsurePdf(
    PDO $pdo,
    array $data
):array{

    if(
        !in_array(
            $data['statut'],
            ['SIGNEE','ARCHIVEE'],
            true
        )
    ){
        throw new RuntimeException(
            'Cette convention n’est pas encore disponible.'
        );
    }

    $root=dirname(__DIR__);

    $directory=
        $root.'/storage/conventions';

    if(!is_dir($directory)){
        if(
            !mkdir(
                $directory,
                0775,
                true
            ) &&
            !is_dir($directory)
        ){
            throw new RuntimeException(
                'Impossible de créer le dossier des conventions.'
            );
        }
    }

    $safeReference=preg_replace(
        '/[^A-Za-z0-9._-]/',
        '_',
        $data['reference']
    );

    $fileName=
        $safeReference.'.pdf';

    $relative=
        'storage/conventions/'.
        $fileName;

    $fullPath=
        $root.'/'.
        $relative;

    /* Pendant les tests on régénère */
    if(is_file($fullPath))
        @unlink($fullPath);


    /* =====================================================
       VALEURS
    ====================================================== */

    $logo=conventionImageData(
        $root.'/assets/img/logo.png'
    );

    $studentName=trim(
        ($data['nom']??'').' '.
        ($data['postnom']??'').' '.
        ($data['prenom']??'')
    );

    if(function_exists('mb_strtoupper')){
        $studentName=mb_strtoupper(
            $studentName,
            'UTF-8'
        );
    }else{
        $studentName=strtoupper(
            $studentName
        );
    }

    $student=conventionEscape(
        $studentName
    );

    $stagiaCode=conventionEscape(
        $data['stagia_code']??'-'
    );

    $university=conventionEscape(
        $data['university_name']??'-'
    );

    $universityAddress=conventionEscape(
        trim(
            ($data['university_address']??'').' '.
            ($data['university_city']??'')
        )
    );

    $host=conventionEscape(
        $data['host_name']??'-'
    );

    $hostAddress=conventionEscape(
        trim(
            ($data['host_address']??'').' '.
            ($data['host_city']??'')
        )
    );

    $campaign=conventionEscape(
        $data['campaign_title']??'-'
    );

    $campaignCode=conventionEscape(
        $data['campaign_code']??'-'
    );

    $unit=conventionEscape(
        $data['unit_name']??'À déterminer'
    );

    $reference=conventionEscape(
        $data['reference']??'-'
    );

    $version=(int)($data['version']??1);

    $start=conventionDate(
        $data['date_debut']??null
    );

    $end=conventionDate(
        $data['date_fin']??null
    );

    $issued=conventionDate(
        $data['date_emission']
        ??$data['created_at']
        ??null
    );

    $studentSignature=conventionDate(
        $data['date_signature_etudiant']??null
    );

    $universitySignature=conventionDate(
        $data['date_signature_universite']??null
    );

    $hostSignature=conventionDate(
        $data['date_signature_accueil']??null
    );


    /* =====================================================
       HTML
    ====================================================== */

    $html=<<<HTML
<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<style>

@page{
    size:A4 portrait;
    margin:12mm;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:"DejaVu Sans",sans-serif;
    font-size:10px;
    line-height:1.45;
    color:#15324a;
}

.header{
    text-align:center;
    border-bottom:3px solid #ef6c00;
    padding-bottom:12px;
    margin-bottom:18px;
}

.logo{
    width:70px;
    margin-bottom:5px;
}

.brand{
    color:#ef6c00;
    font-size:9px;
    font-weight:bold;
    letter-spacing:2px;
}

.title{
    font-size:22px;
    font-weight:bold;
    margin-top:7px;
    letter-spacing:1px;
}

.reference{
    color:#64748b;
    font-size:8px;
    margin-top:5px;
}

.section{
    margin-top:18px;
}

.section-title{
    font-size:12px;
    font-weight:bold;
    color:#102f4a;
    border-left:4px solid #ef6c00;
    padding-left:8px;
    margin-bottom:8px;
}

.party-table,
.info-table,
.signature-table{
    width:100%;
    border-collapse:collapse;
}

.party-table td,
.info-table td{
    border:1px solid #dfe6ec;
    padding:8px;
    vertical-align:top;
}

.label{
    color:#64748b;
    font-size:8px;
    margin-bottom:3px;
}

.value{
    font-weight:bold;
    color:#142f46;
}

.student-box{
    margin-top:15px;
    padding:12px;
    background:#f8fafc;
    border:1px solid #e3e9ee;
    border-radius:5px;
}

.student-name{
    color:#ef6c00;
    font-size:16px;
    font-weight:bold;
}

.article{
    margin-top:10px;
    text-align:justify;
}

.article strong{
    color:#102f4a;
}

.conditions{
    margin:8px 0 0 18px;
    padding:0;
}

.conditions li{
    margin-bottom:5px;
}

.signature-table{
    margin-top:25px;
}

.signature-table td{
    width:33.33%;
    text-align:center;
    vertical-align:top;
    padding:5px 10px;
}

.signature-title{
    font-weight:bold;
    font-size:9px;
}

.signature-date{
    margin-top:5px;
    color:#64748b;
    font-size:8px;
}

.signature-line{
    margin:35px auto 0;
    width:90%;
    border-top:1px solid #142f46;
    padding-top:4px;
    font-size:7px;
    color:#64748b;
}

.footer{
    margin-top:24px;
    padding-top:8px;
    border-top:1px solid #e5e9ed;
    text-align:center;
    color:#8795a3;
    font-size:7px;
}

.page-break{
    page-break-before:always;
}

</style>

</head>

<body>


<div class="header">

    <img
        src="$logo"
        class="logo"
        alt="STAGIA-RDC"
    >

    <div class="brand">
        STAGIA-RDC
    </div>

    <div class="title">
        CONVENTION DE STAGE
    </div>

    <div class="reference">
        Référence : $reference
        • Version : v$version
        • Émise le : $issued
    </div>

</div>


<div class="section">

    <div class="section-title">
        1. Parties à la convention
    </div>

    <table class="party-table">

        <tr>

            <td width="50%">

                <div class="label">
                    Établissement d'origine
                </div>

                <div class="value">
                    $university
                </div>

                <div>
                    $universityAddress
                </div>

            </td>

            <td width="50%">

                <div class="label">
                    Établissement d'accueil
                </div>

                <div class="value">
                    $host
                </div>

                <div>
                    $hostAddress
                </div>

            </td>

        </tr>

    </table>

</div>


<div class="student-box">

    <div class="label">
        Stagiaire
    </div>

    <div class="student-name">
        $student
    </div>

    <div>
        Code STAGIA :
        <strong>$stagiaCode</strong>
    </div>

</div>


<div class="section">

    <div class="section-title">
        2. Informations relatives au stage
    </div>

    <table class="info-table">

        <tr>

            <td width="50%">

                <div class="label">
                    Campagne
                </div>

                <div class="value">
                    $campaign
                </div>

                <div>
                    $campaignCode
                </div>

            </td>

            <td width="50%">

                <div class="label">
                    Service / unité d'accueil
                </div>

                <div class="value">
                    $unit
                </div>

            </td>

        </tr>

        <tr>

            <td>

                <div class="label">
                    Date de début
                </div>

                <div class="value">
                    $start
                </div>

            </td>

            <td>

                <div class="label">
                    Date de fin
                </div>

                <div class="value">
                    $end
                </div>

            </td>

        </tr>

    </table>

</div>


<div class="section">

    <div class="section-title">
        3. Objet de la convention
    </div>

    <div class="article">

        La présente convention définit les conditions
        dans lesquelles le stagiaire effectue son stage
        au sein de l'établissement d'accueil.

        Le stage constitue une activité pédagogique
        destinée à permettre au stagiaire de mettre en
        pratique les connaissances acquises dans son
        établissement de formation et de développer
        ses compétences professionnelles.

    </div>

</div>


<div class="section">

    <div class="section-title">
        4. Engagements du stagiaire
    </div>

    <ul class="conditions">

        <li>
            respecter le règlement intérieur de
            l'établissement d'accueil ;
        </li>

        <li>
            respecter les horaires, les consignes
            professionnelles et les règles de sécurité ;
        </li>

        <li>
            respecter la confidentialité des informations
            auxquelles il pourrait avoir accès ;
        </li>

        <li>
            tenir régulièrement son journal de stage ;
        </li>

        <li>
            participer aux activités prévues dans le
            cadre de son stage.
        </li>

    </ul>

</div>


<div class="section">

    <div class="section-title">
        5. Engagements des établissements
    </div>

    <div class="article">

        <strong>L'établissement d'origine</strong>
        assure le suivi pédagogique du stagiaire.

        <br><br>

        <strong>L'établissement d'accueil</strong>
        assure l'encadrement professionnel,
        l'organisation des activités de stage,
        le suivi des présences et l'évaluation
        du stagiaire.

    </div>

</div>


<div class="section">

    <div class="section-title">
        6. Validation de la convention
    </div>

    <div class="article">

        La convention devient applicable après
        validation des parties concernées dans
        STAGIA-RDC.

        Les dates de validation électronique
        enregistrées dans la plateforme constituent
        la trace du processus de signature.

    </div>


    <table class="signature-table">

        <tr>

            <td>

                <div class="signature-title">
                    Le stagiaire
                </div>

                <div class="signature-date">
                    $studentSignature
                </div>

                <div class="signature-line">
                    Signature
                </div>

            </td>


            <td>

                <div class="signature-title">
                    Établissement d'origine
                </div>

                <div class="signature-date">
                    $universitySignature
                </div>

                <div class="signature-line">
                    Signature et cachet
                </div>

            </td>


            <td>

                <div class="signature-title">
                    Établissement d'accueil
                </div>

                <div class="signature-date">
                    $hostSignature
                </div>

                <div class="signature-line">
                    Signature et cachet
                </div>

            </td>

        </tr>

    </table>

</div>


<div class="footer">

    Convention générée électroniquement par STAGIA-RDC
    • $reference

</div>


</body>

</html>
HTML;


    /* =====================================================
       DOMPDF
    ====================================================== */

    $options=new Options();

    $options->set(
        'isRemoteEnabled',
        false
    );

    $options->set(
        'isHtml5ParserEnabled',
        true
    );

    $dompdf=new Dompdf($options);

    $dompdf->loadHtml(
        $html,
        'UTF-8'
    );

    $dompdf->setPaper(
        'A4',
        'portrait'
    );

    $dompdf->render();


    /* =====================================================
       ENREGISTRER
    ====================================================== */

    $content=$dompdf->output();

    if(
        file_put_contents(
            $fullPath,
            $content
        )===false
    ){
        throw new RuntimeException(
            'Impossible d’enregistrer la convention.'
        );
    }

    clearstatcache(
        true,
        $fullPath
    );


    /* =====================================================
       CORRIGER LE DOCUMENT LIÉ EXISTANT
    ====================================================== */

    if(!empty($data['document_id'])){

        $stmt=$pdo->prepare("
            UPDATE student_documents
            SET
                type_code='CONVENTION_STAGE',
                titre=?,
                nom_fichier=?,
                chemin=?,
                mime_type='application/pdf',
                taille=?,
                updated_at=CURRENT_TIMESTAMP
            WHERE id=?
              AND student_id=?
        ");

        $stmt->execute([
            'Convention de stage - '.
            $data['reference'],

            $fileName,

            $relative,

            filesize($fullPath),

            $data['document_id'],

            $data['student_id']
        ]);
    }


    return [
        'path'=>$fullPath,
        'relative'=>$relative
    ];
}