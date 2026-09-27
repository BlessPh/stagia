<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/admin-scope.php';

requireAjaxAuth();
exigerResponsableMinisterielAjax($pdo);

$userId=currentUserId();
$ids=etablissementsVisiblesAdministration($pdo);
$ph=$ids?implode(',',array_fill(0,count($ids),'?')):'';

try{
    $ministeres=ministeresRepresentes($pdo,$userId);

    $kpis=['organisations'=>0,'responsables'=>0,'etudiants'=>0,'provinces'=>0,'annees'=>0];
    $types=[];$provinces=[];$annees=[];$recentes=[];

    if($ids){
        $s=$pdo->prepare("SELECT COUNT(*) organisations,
                COUNT(DISTINCT NULLIF(TRIM(province),'')) provinces
            FROM etablissements WHERE id IN($ph) AND statut IN('VALIDE','ACTIF')");
        $s->execute($ids);$r=$s->fetch(PDO::FETCH_ASSOC);
        $kpis['organisations']=(int)($r['organisations']??0);
        $kpis['provinces']=(int)($r['provinces']??0);

        $s=$pdo->prepare("SELECT COUNT(DISTINCT ra.user_id)
            FROM role_assignments ra
            JOIN roles r ON r.id=ra.role_id AND r.actif=1 AND r.code<>'STAGIAIRE'
            JOIN users u ON u.id=ra.user_id AND u.actif=1 AND u.statut_compte='ACTIF'
            WHERE ra.etablissement_id IN($ph) AND ra.actif=1
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())");
        $s->execute($ids);$kpis['responsables']=(int)$s->fetchColumn();

        $studentFrom=" FROM student_enrollments se
            JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id
            JOIN annees_academiques aa ON aa.id=sae.annee_academique_id
            JOIN promotions pr ON pr.id=sae.promotion_id
            JOIN filieres f ON f.id=pr.filiere_id
            LEFT JOIN curriculum_references cr ON cr.id=f.curriculum_reference_id
            WHERE se.statut<>'ARCHIVE' AND cr.domaine='SANTE'
              AND se.etablissement_id IN($ph)";
        $s=$pdo->prepare('SELECT COUNT(DISTINCT se.student_id)'.$studentFrom);
        $s->execute($ids);$kpis['etudiants']=(int)$s->fetchColumn();
        $s=$pdo->prepare('SELECT COUNT(DISTINCT aa.libelle)'.$studentFrom);
        $s->execute($ids);$kpis['annees']=(int)$s->fetchColumn();
        $s=$pdo->prepare('SELECT aa.libelle label,COUNT(DISTINCT se.student_id) total'.$studentFrom.' GROUP BY aa.libelle ORDER BY aa.libelle DESC LIMIT 6');
        $s->execute($ids);$annees=$s->fetchAll(PDO::FETCH_ASSOC);

        $s=$pdo->prepare("SELECT COALESCE(t.libelle,e.type_etablissement) label,COUNT(*) total
            FROM etablissements e LEFT JOIN establishment_types t ON t.code=e.type_etablissement
            WHERE e.id IN($ph) AND e.statut IN('VALIDE','ACTIF')
            GROUP BY e.type_etablissement,t.libelle ORDER BY total DESC,label");
        $s->execute($ids);$types=$s->fetchAll(PDO::FETCH_ASSOC);

        $s=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(province),''),'Non renseignée') label,COUNT(*) total
            FROM etablissements WHERE id IN($ph) AND statut IN('VALIDE','ACTIF')
            GROUP BY COALESCE(NULLIF(TRIM(province),''),'Non renseignée') ORDER BY total DESC,label LIMIT 8");
        $s->execute($ids);$provinces=$s->fetchAll(PDO::FETCH_ASSOC);

        $s=$pdo->prepare("SELECT id,code,nom,type_etablissement,province,ville,statut,updated_at
            FROM etablissements WHERE id IN($ph) AND statut IN('VALIDE','ACTIF')
            ORDER BY updated_at DESC,id DESC LIMIT 6");
        $s->execute($ids);$recentes=$s->fetchAll(PDO::FETCH_ASSOC);
    }

    jsonResponse(true,'',['ministeres'=>$ministeres,'kpis'=>$kpis,'types'=>$types,
        'provinces'=>$provinces,'annees'=>$annees,'organisations_recentes'=>$recentes]);
}catch(Throwable $e){
    error_log('[MINISTERE DASHBOARD] '.$e->getMessage());
    jsonResponse(false,'Impossible de charger le tableau de bord ministériel.',[],500);
}
