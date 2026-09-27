<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-option-sync.php';

requirePermission($pdo,'academic.manage');verifyAjaxCsrf();

$etabId=(int)($_SESSION['etablissement_id']??0);
$settings=$_SESSION['academic_settings']??[];
if(!$etabId)jsonResponse(false,'Aucun établissement actif.',[],422);
if(empty($settings['option_specialite_active']))
    jsonResponse(false,'Les options / spécialités sont désactivées.',[],403);

$filiereId=(int)($_POST['filiere_id']??0);
$nom=trim((string)($_POST['nom']??''));
$motif=trim((string)($_POST['motif_ajout']??''));
$description=trim((string)($_POST['description']??''));

if(!$filiereId||$nom==='')jsonResponse(false,'Filière et nom obligatoires.',[],422);
if(mb_strlen($nom)>150)jsonResponse(false,'Nom trop long.',[],422);
if($motif==='' || mb_strlen($motif)>500)jsonResponse(false,"Expliquez brièvement pourquoi cet élément manque.",[],422);

$s=$pdo->prepare("SELECT id FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
$s->execute([$filiereId,$etabId]);if(!$s->fetchColumn())jsonResponse(false,'Filière invalide.',[],422);

$code=makeAcademicOptionCode($nom);

$s=$pdo->prepare("
    SELECT id FROM options_specialites
    WHERE etablissement_id=? AND filiere_id=?
      AND (code=? OR LOWER(TRIM(nom))=LOWER(TRIM(?)))
      AND validation_statut<>'REFUSE'
    LIMIT 1
");
$s->execute([$etabId,$filiereId,$code,$nom]);
if($s->fetchColumn())jsonResponse(false,'Cette option / spécialité existe déjà dans cette filière.',[],409);

$pdo->prepare("
    INSERT INTO options_specialites(
        etablissement_id,filiere_id,source_template_option_id,
        ajoute_localement,validation_statut,
        code,nom,description,motif_ajout,created_by_user_id,actif
    ) VALUES(?,?,NULL,1,'EN_ATTENTE',?,?,?,?,?,1)
")->execute([
    $etabId,$filiereId,$code,$nom,
    $description?:null,$motif,(int)$_SESSION['user_id']
]);

jsonResponse(true,"Option ajoutée localement et transmise au Super Admin pour normalisation.");
