<?php
/**
 * Endpoint AJAX qui pilote le cycle de vie d'une convention : soumission, signatures, annulation et archivage.
 * Chaque action vérifie le statut courant et le propriétaire académique ou d'accueil concerné.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

verifyAjaxCsrf();

try{
    /* L'action demandée est limitée à un vocabulaire fermé avant toute écriture. */
    requireConventionAjax();
    $id=(int)($_POST['id']??0);
    $action=strtoupper(trim((string)($_POST['action']??'')));
    $reason=trim((string)($_POST['reason']??''));

    if(!$id||!in_array($action,[
        'SUBMIT','SIGN_STUDENT','SIGN_UNIVERSITY','SIGN_HOST','CANCEL','ARCHIVE'
    ],true))throw new RuntimeException('Action invalide.');

    /* Une transaction protège la convention verrouillée pendant son changement d'état. */
    $pdo->beginTransaction();
    $x=conventionLoad($pdo,$id,true);
    $uid=conventionUserId();

    /* Passage du brouillon à l'état prêt pour les signatures. */
    if($action==='SUBMIT'){
        if(!conventionIsUniversityOwner($x)||$x['statut']!=='BROUILLON')
            throw new RuntimeException('Publication pour signature impossible.');
        $pdo->prepare("
            UPDATE stage_conventions
            SET statut='A_SIGNER',submitted_by_user_id=?,submitted_at=NOW()
            WHERE id=?
        ")->execute([$uid?:null,$id]);
        $message='Convention transmise pour signature.';
    }

    /* La signature du stagiaire peut être enregistrée par l'un des deux établissements habilités. */
    if($action==='SIGN_STUDENT'){
        if($x['statut']!=='A_SIGNER'||(!conventionIsUniversityOwner($x)&&!conventionIsHostOwner($x)))
            throw new RuntimeException("La signature de l'étudiant ne peut pas être enregistrée.");
        if($x['date_signature_etudiant'])
            throw new RuntimeException("La signature de l'étudiant est déjà enregistrée.");
        $pdo->prepare("UPDATE stage_conventions SET date_signature_etudiant=NOW() WHERE id=?")
            ->execute([$id]);
        $message="Signature de l'étudiant enregistrée.";
    }

    /* Signature réalisée exclusivement par l'établissement de formation. */
    if($action==='SIGN_UNIVERSITY'){
        if($x['statut']!=='A_SIGNER'||!conventionIsUniversityOwner($x))
            throw new RuntimeException("Signature de l'établissement de formation impossible.");
        if($x['date_signature_universite'])
            throw new RuntimeException('Signature universitaire déjà enregistrée.');
        $pdo->prepare("
            UPDATE stage_conventions
            SET date_signature_universite=NOW(),signed_university_by_user_id=?
            WHERE id=?
        ")->execute([$uid?:null,$id]);
        $message='Signature de l’établissement de formation enregistrée.';
    }

    /* Signature réalisée exclusivement par l'établissement d'accueil. */
    if($action==='SIGN_HOST'){
        if($x['statut']!=='A_SIGNER'||!conventionIsHostOwner($x))
            throw new RuntimeException("Signature de l'établissement d'accueil impossible.");
        if($x['date_signature_accueil'])
            throw new RuntimeException("Signature de l'accueil déjà enregistrée.");
        $pdo->prepare("
            UPDATE stage_conventions
            SET date_signature_accueil=NOW(),signed_host_by_user_id=?
            WHERE id=?
        ")->execute([$uid?:null,$id]);
        $message="Signature de l'établissement d'accueil enregistrée.";
    }

    /* Une annulation exige un motif et reste limitée aux états non finalisés. */
    if($action==='CANCEL'){
        if(!conventionIsUniversityOwner($x)||!in_array($x['statut'],['BROUILLON','A_SIGNER'],true))
            throw new RuntimeException('Cette convention ne peut pas être annulée.');
        if($reason==='')throw new RuntimeException("Le motif d'annulation est obligatoire.");
        $pdo->prepare("
            UPDATE stage_conventions
            SET statut='ANNULEE',cancelled_by_user_id=?,cancelled_at=NOW(),cancellation_reason=?
            WHERE id=?
        ")->execute([$uid?:null,$reason,$id]);
        $message='Convention annulée.';
    }

    /* L'archivage est permis seulement après signature complète de la convention. */
    if($action==='ARCHIVE'){
        if(!conventionIsUniversityOwner($x)||$x['statut']!=='SIGNEE')
            throw new RuntimeException('Archivage impossible.');
        $pdo->prepare("
            UPDATE stage_conventions
            SET statut='ARCHIVEE',archived_by_user_id=?,archived_at=NOW()
            WHERE id=?
        ")->execute([$uid?:null,$id]);
        $message='Convention archivée.';
    }

    $pdo->commit();
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
