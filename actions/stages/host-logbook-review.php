<?php
/**
 * Endpoint AJAX de validation ou rejet d'un journal de bord soumis par un stagiaire.
 * Chaque décision est vérifiée contre la rotation, son encadreur actif et l'historique de revue.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Les rôles de supervision autorisés restent soumis au droit fonctionnel de revue. */
requireAjaxRole(['ADMIN_ACCUEIL','ENCADREUR','EVALUATEUR_CLINIQUE']);
if(function_exists('contextPermission')&&!contextPermission('logbook.hosting.review'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Validation de la décision, du commentaire requis en cas de rejet et des identifiants. */
    $hostId=(int)currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);
    $decision=strtoupper(trim($_POST['decision']??''));
    $commentaire=trim($_POST['commentaire']??'');

    if(!$hostId||!$userId||!$id)
        jsonResponse(false,'Journal invalide.',[],422);

    if(!in_array($decision,['VALIDER','REJETER'],true))
        jsonResponse(false,'Décision invalide.',[],422);

    if($decision==='REJETER'&&$commentaire==='')
        jsonResponse(false,'Le commentaire est obligatoire en cas de rejet.',[],422);

    /* Le journal est verrouillé afin qu'une seule revue puisse le traiter. */
    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        SELECT
            e.id,e.statut,e.rotation_id,e.date_journal,
            r.host_etablissement_id,r.date_debut,r.date_fin,r.statut AS rotation_statut
        FROM stage_logbook_entries e
        INNER JOIN stage_rotations r ON r.id=e.rotation_id
        WHERE e.id=?
          AND e.host_etablissement_id=?
          AND r.host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$id,$hostId,$hostId]);
    $entry=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$entry)
        throw new RuntimeException('Journal introuvable.');

    if($entry['statut']!=='SOUMIS')
        throw new RuntimeException('Ce journal n’est plus en attente de validation.');

    if(
        !$entry['date_journal']||
        $entry['date_journal']<$entry['date_debut']||
        $entry['date_journal']>$entry['date_fin']
    )
        throw new RuntimeException('Le journal est hors de la période de cette rotation.');

    /* En test, on autorise aussi PLANIFIEE. En production, on pourra retirer PLANIFIEE si souhaité. */
    if(!in_array($entry['rotation_statut'],['PLANIFIEE','ACTIVE','TERMINEE'],true))
        throw new RuntimeException('Cette rotation ne permet pas la validation du journal.');

    /* UC-41 : validation uniquement par un encadreur actif de la rotation. */
    $stmt=$pdo->prepare("
        SELECT id
        FROM stage_rotation_supervisors
        WHERE rotation_id=?
          AND user_id=?
          AND actif=1
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$entry['rotation_id'],$userId]);

    if(!$stmt->fetchColumn())
        throw new RuntimeException('Vous n’êtes pas encadreur actif de cette rotation.');

    /* La décision externe est traduite vers le statut persistant de l'entrée de journal. */
    $newStatus=$decision==='VALIDER'?'VALIDE':'REJETE';

    $stmt=$pdo->prepare("
        UPDATE stage_logbook_entries
        SET statut=?,
            commentaire_encadreur=?,
            validated_at=NOW(),
            validated_by=?
        WHERE id=? AND statut='SOUMIS'
    ");
    $stmt->execute([
        $newStatus,
        $commentaire!==''?$commentaire:null,
        $userId,
        $id
    ]);

    if($stmt->rowCount()!==1)
        throw new RuntimeException('Le journal a déjà été traité.');

    /* Historique non destructif créé lors de D3.4. */
    $stmt=$pdo->prepare("
        INSERT INTO stage_logbook_review_history(
            logbook_entry_id,previous_status,new_status,
            commentaire,reviewer_user_id,reviewed_at
        ) VALUES(?,?,?,?,?,NOW())
    ");
    $stmt->execute([
        $id,'SOUMIS',$newStatus,
        $commentaire!==''?$commentaire:null,
        $userId
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        $decision==='VALIDER'
            ?'Journal validé avec succès.'
            :'Journal renvoyé à l’étudiant pour correction.'
    );

}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
