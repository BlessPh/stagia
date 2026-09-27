<?php
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/ajax.php';
try{
    requireRole(['SUPER_ADMIN']);
    $h=$pdo->query("SELECT id,nom FROM etablissements WHERE type_etablissement='HOPITAL' AND statut IN('VALIDE','ACTIF') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $l=$pdo->query("SELECT l.id,l.code,l.libelle,CONCAT(l.code,' — ',l.libelle) label FROM academic_levels l LEFT JOIN academic_cycles c ON c.id=l.academic_cycle_id WHERE l.actif=1 ORDER BY c.ordre,l.ordre,l.code")->fetchAll(PDO::FETCH_ASSOC);
    $i=$pdo->query("SELECT f.*,h.nom host_name,l.code niveau_code,l.libelle niveau_libelle FROM stagia_hospital_level_fees f JOIN etablissements h ON h.id=f.host_etablissement_id JOIN academic_levels l ON l.id=f.academic_level_id ORDER BY h.nom,l.ordre,l.code")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true,'OK',['hospitals'=>$h,'levels'=>$l,'items'=>$i]);
}catch(Throwable $e){jsonResponse(false,$e->getMessage(),[],500);}