<?php
/**
 * Endpoint AJAX d'activation ou désactivation logique d'un type de stage local.
 * La suppression est fonctionnelle : les campagnes historiques restent conservées.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-type.php';

verifyAjaxCsrf();

try{
    /* L'action est limitée à DELETE ou RESTORE et au type possédé par l'établissement courant. */
    requireStageTypeManage($pdo);
    $eid=stageTypeEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $action=strtoupper(trim((string)($_POST['action']??'')));

    if(!$id||!in_array($action,['DELETE','RESTORE'],true))
        throw new RuntimeException('Action invalide.');

    $s=$pdo->prepare("SELECT id,libelle,actif FROM stage_types WHERE id=? AND owner_etablissement_id=? LIMIT 1");
    $s->execute([$id,$eid]);
    $type=$s->fetch(PDO::FETCH_ASSOC);
    if(!$type)
        throw new RuntimeException('Vous ne pouvez gérer que les types créés par votre établissement.');

    /* DELETE rend le type inactif ; RESTORE le rend à nouveau sélectionnable. */
    $active=$action==='RESTORE'?1:0;
    $pdo->prepare("UPDATE stage_types SET actif=? WHERE id=? AND owner_etablissement_id=?")
        ->execute([$active,$id,$eid]);

    jsonResponse(true,$active?'Type de stage réactivé.':'Type de stage supprimé de vos choix. Les campagnes existantes restent conservées.',['actif'=>$active]);
}catch(Throwable $e){
    /* Réponse AJAX contrôlée en cas de type introuvable ou d'action invalide. */
    jsonResponse(false,$e->getMessage(),[],422);
}
