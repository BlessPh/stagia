<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

function tcols(PDO $pdo,string $t):array{static $c=[];if(isset($c[$t]))return$c[$t];try{$s=$pdo->query("SHOW COLUMNS FROM `$t`");return$c[$t]=array_column($s->fetchAll(PDO::FETCH_ASSOC),'Field');}catch(Throwable $e){return$c[$t]=[];}}
function colExists(PDO $pdo,string $t,string $c):bool{return in_array($c,tcols($pdo,$t),true);} 
function rowv(array $r,array $keys,$default=''){foreach($keys as $k)if(array_key_exists($k,$r)&&$r[$k]!==null&&$r[$k]!=='')return$r[$k];return$default;}

try{
    $uid=(int)($_SESSION['user_id']??0);
    if(!$uid)jsonResponse(false,'Session étudiant invalide.',[],401);

    $s=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
    $s->execute([$uid]);$studentId=(int)$s->fetchColumn();
    if(!$studentId)jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $uNom=colExists($pdo,'users','nom')?'u.nom':'NULL';
    $uPost=colExists($pdo,'users','postnom')?'u.postnom':'NULL';
    $uPre=colExists($pdo,'users','prenom')?'u.prenom':'NULL';

    $stmt=$pdo->prepare("
        SELECT e.id,e.type_evaluation,e.note_finale,e.appreciation,e.points_forts,e.axes_amelioration,
               e.statut,e.evaluated_at,e.submitted_at,e.validated_at,e.finalized_at,
               r.id rotation_id,r.sequence_no,r.date_debut,r.date_fin,
               hu.nom unit_name,hu.code unit_code,h.nom host_name,
               $uNom evaluator_nom,$uPost evaluator_postnom,$uPre evaluator_prenom
        FROM stage_evaluations e
        JOIN stage_rotations r ON r.id=e.rotation_id
        JOIN stage_assignments ass ON ass.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=ass.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN etablissements h ON h.id=ass.host_etablissement_id
        LEFT JOIN users u ON u.id=e.evaluator_user_id
        WHERE se.student_id=?
          AND e.statut IN('VALIDEE','FINALISEE')
        ORDER BY COALESCE(e.finalized_at,e.validated_at,e.submitted_at,e.evaluated_at,e.created_at) DESC,e.id DESC
    ");
    $stmt->execute([$studentId]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $scoreCols=tcols($pdo,'stage_evaluation_scores');
    $scoreStmt=$pdo->prepare("SELECT * FROM stage_evaluation_scores WHERE evaluation_id=? ORDER BY id");
    foreach($items as &$it){
        $it['id']=(int)$it['id'];$it['rotation_id']=(int)$it['rotation_id'];$it['sequence_no']=(int)$it['sequence_no'];
        $it['note_finale']=(float)($it['note_finale']??0);
        $scoreStmt->execute([$it['id']]);$scores=[];
        foreach($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $r){
            $cid=rowv($r,['competence_id','competency_id','criterion_id','critere_id','referential_item_id','id']);
            $scores[]=[
                'nom'=>rowv($r,['nom','libelle','competence','critere','criterion_label','titre'],'Critère #'.$cid),
                'categorie'=>rowv($r,['categorie','category','type_competence','domaine'],''),
                'commentaire'=>rowv($r,['commentaire','observation','appreciation'],''),
                'note'=>(float)rowv($r,['note','score','note_obtenue','points'],0),
                'note_max'=>(float)rowv($r,['note_max','max_note','bareme','points_max'],20)
            ];
        }
        $it['scores']=$scores;
    }
    unset($it);

    $total=count($items);$sum=0;$rot=[];$finales=0;
    foreach($items as $x){$sum+=(float)$x['note_finale'];$rot[$x['rotation_id']]=1;if($x['statut']==='FINALISEE')$finales++;}
    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>[
            'total'=>$total,
            'moyenne'=>$total?round($sum/$total,2):0,
            'rotations'=>count($rot),
            'finales'=>$finales,
            'validees'=>$total-$finales
        ]
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur évaluations : '.$e->getMessage(),[],500);
}
