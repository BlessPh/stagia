<?php
/** Retourne le tarif de stage actif d'une structure d'accueil pour un niveau académique donné. */
function stagiaHospitalLevelFee(PDO $pdo,int $hostId,int $levelId):?array{
    $s=$pdo->prepare("SELECT * FROM stagia_hospital_level_fees WHERE host_etablissement_id=? AND academic_level_id=? AND actif=1 LIMIT 1");
    $s->execute([$hostId,$levelId]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
