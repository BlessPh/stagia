<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$academicId=(int)($_GET['academic_enrollment_id']??0);
try{
    $s=$pdo->prepare("SELECT ae.id academic_enrollment_id,ae.statut,ae.promotion_id,se.id enrollment_id,se.etablissement_id university_id,se.matricule,e.code university_code,e.nom university_name,
        aa.id academic_year_id,aa.libelle academic_year,aa.date_debut,aa.date_fin,p.code promotion_code,p.nom promotion,f.code program_code,f.nom program
        FROM student_academic_enrollments ae JOIN student_enrollments se ON se.id=ae.enrollment_id JOIN etablissements e ON e.id=se.etablissement_id
        JOIN annees_academiques aa ON aa.id=ae.annee_academique_id JOIN promotions p ON p.id=ae.promotion_id LEFT JOIN filieres f ON f.id=p.filiere_id
        WHERE se.student_id=? ORDER BY (ae.statut='EN_COURS') DESC,aa.date_debut DESC,ae.id DESC");
    $s->execute([(int)$student['student_id']]);$academics=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($academics as &$item){foreach(['academic_enrollment_id','promotion_id','enrollment_id','university_id','academic_year_id'] as $key)$item[$key]=(int)$item[$key];}unset($item);
    if(!$academics)apiResponse(true,'',['academics'=>[],'academic'=>null,'subjects'=>[],'notes'=>[],'stats'=>['average'=>0,'notes'=>0,'evaluation_types'=>0,'subjects'=>0,'evaluated_subjects'=>0,'latest_note'=>null]]);
    if(!$academicId)$academicId=(int)$academics[0]['academic_enrollment_id'];
    $academic=null;foreach($academics as $candidate)if($candidate['academic_enrollment_id']===$academicId){$academic=$candidate;break;}
    if(!$academic)apiResponse(false,'Parcours academique introuvable.',[],404);

    $s=$pdo->prepare('SELECT id,code,nom,credits,coefficient,note_max FROM matieres WHERE etablissement_id=? AND promotion_id=? AND actif=1 ORDER BY nom');
    $s->execute([$academic['university_id'],$academic['promotion_id']]);$subjects=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($subjects as &$subject){$subject['id']=(int)$subject['id'];$subject['credits']=(float)$subject['credits'];$subject['coefficient']=(float)$subject['coefficient'];$subject['note_max']=(float)$subject['note_max'];}unset($subject);

    $s=$pdo->prepare("SELECT sn.id,sn.matiere_id,sn.type_evaluation,sn.note,sn.note_sur,sn.date_evaluation,sn.observation,m.code subject_code,m.nom subject,m.coefficient,m.note_max
        FROM student_notes sn JOIN matieres m ON m.id=sn.matiere_id AND m.etablissement_id=? AND m.promotion_id=? WHERE sn.academic_enrollment_id=? ORDER BY sn.date_evaluation DESC,m.nom,sn.id DESC");
    $s->execute([$academic['university_id'],$academic['promotion_id'],$academicId]);$notes=$s->fetchAll(PDO::FETCH_ASSOC);
    $groups=[];$types=[];
    foreach($notes as &$note){
        $note['id']=(int)$note['id'];$note['matiere_id']=(int)$note['matiere_id'];$note['note']=(float)$note['note'];$note['note_sur']=(float)$note['note_sur'];$note['coefficient']=(float)$note['coefficient'];$note['note_max']=(float)$note['note_max'];
        $note['note_sur_20']=$note['note_sur']>0?round($note['note']/$note['note_sur']*20,2):null;$note['result']=$note['note_sur_20']===null?null:($note['note_sur_20']>=10?'REUSSI':'ECHEC');
        if($note['note_sur_20']!==null){$id=$note['matiere_id'];$groups[$id]??=['total'=>0,'count'=>0,'coefficient'=>max(1,$note['coefficient'])];$groups[$id]['total']+=$note['note_sur_20'];$groups[$id]['count']++;}
        if($note['type_evaluation'])$types[$note['type_evaluation']]=true;
    }unset($note);
    $weighted=0;$coefficients=0;foreach($groups as $group){$average=$group['total']/$group['count'];$weighted+=$average*$group['coefficient'];$coefficients+=$group['coefficient'];}
    $latest=$notes[0]??null;
    apiResponse(true,'',['academics'=>$academics,'academic'=>$academic,'subjects'=>$subjects,'notes'=>$notes,'stats'=>[
        'average'=>$coefficients?round($weighted/$coefficients,2):0,'notes'=>count($notes),'evaluation_types'=>count($types),'subjects'=>count($subjects),'evaluated_subjects'=>count($groups),'latest_note'=>$latest
    ]]);
}catch(Throwable $e){apiResponse(false,'Erreur notes academiques : '.$e->getMessage(),[],500);}
