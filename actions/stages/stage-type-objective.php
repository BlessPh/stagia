<?php
/**
 * Endpoint AJAX de lecture de l'objectif pédagogique d'un type de stage actif.
 * Il ne rend accessibles que les types globaux ou visibles dans l'établissement académique courant.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Une permission de consultation des campagnes suffit pour voir cet objectif pédagogique. */
requirePermission($pdo,'campaign.university.view');

if(!contextAcademicEnabled())
    jsonResponse(false,"Cet établissement n'est pas configuré pour les sessions de stage.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_GET['id']??0);

if(!$eid||!$id)
    jsonResponse(false,'Type de stage invalide.',[],422);

try{
    /* Le type est filtré par activité et par propriété globale ou locale. */
    $s=$pdo->prepare("
        SELECT id,libelle,objectif
        FROM stage_types
        WHERE id=?
          AND actif=1
          AND (
              owner_etablissement_id IS NULL
              OR owner_etablissement_id=?
          )
        LIMIT 1
    ");
    $s->execute([$id,$eid]);
    $type=$s->fetch(PDO::FETCH_ASSOC);

    if(!$type)
        jsonResponse(false,'Type de stage introuvable ou inactif.',[],404);

    /* Réponse minimale destinée aux formulaires ou aux détails de campagne. */
    jsonResponse(true,'',[
        'id'=>(int)$type['id'],
        'libelle'=>$type['libelle'],
        'objectif'=>trim((string)($type['objectif']??''))
    ]);

}catch(Throwable $e){
    error_log('[STAGE TYPE OBJECTIVE] '.$e->getMessage());
    jsonResponse(false,"Impossible de charger l'objectif du type de stage.",[],500);
}
