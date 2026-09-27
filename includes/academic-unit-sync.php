<?php
function makeAcademicTemplateUnitCode(PDO $pdo,int $templateId,string $name):string{
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name;
    $base=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
    $base=substr($base?:'UNIT',0,32);$code=$base;$n=2;
    while(true){
        $s=$pdo->prepare("SELECT COUNT(*) FROM academic_template_units WHERE template_id=? AND code=?");
        $s->execute([$templateId,$code]);if(!(int)$s->fetchColumn())return $code;
        $suffix='_'.($n++);$code=substr($base,0,40-strlen($suffix)).$suffix;
    }
}

function syncAcademicTemplateUnit(PDO $pdo,int $templateId,int $templateUnitId):void{
    $s=$pdo->prepare("SELECT * FROM academic_template_units WHERE id=? AND template_id=? LIMIT 1");
    $s->execute([$templateUnitId,$templateId]);$u=$s->fetch(PDO::FETCH_ASSOC);
    if(!$u)throw new RuntimeException('Unité nationale introuvable.');

    $parentTpl=(int)($u['parent_id']??0);
    if($parentTpl)syncAcademicTemplateUnit($pdo,$templateId,$parentTpl);

    $s=$pdo->prepare("SELECT etablissement_id FROM etablissement_academic_settings WHERE source_template_id=?");
    $s->execute([$templateId]);
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $eidRaw){
        $eid=(int)$eidRaw;$parentReal=null;
        if($parentTpl){
            $p=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND source_template_unit_id=? LIMIT 1");
            $p->execute([$eid,$parentTpl]);$parentReal=(int)$p->fetchColumn()?:null;
        }
        $f=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND source_template_unit_id=? LIMIT 1");
        $f->execute([$eid,$templateUnitId]);$id=(int)$f->fetchColumn();
        if(!$id){$f=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND code=? LIMIT 1");$f->execute([$eid,$u['code']]);$id=(int)$f->fetchColumn();}

        if($id){
            $pdo->prepare("UPDATE facultes SET parent_id=?,type_unite=?,code=?,nom=?,actif=?,source_template_unit_id=?,ajoute_localement=0,validation_statut='NATIONAL' WHERE id=? AND etablissement_id=?")
                ->execute([$parentReal,$u['type_unite'],$u['code'],$u['nom'],(int)$u['actif'],$templateUnitId,$id,$eid]);
        }elseif((int)$u['actif']===1){
            $pdo->prepare("INSERT INTO facultes(etablissement_id,type_unite,parent_id,source_template_unit_id,ajoute_localement,validation_statut,code,nom,actif) VALUES(?,?,?, ?,0,'NATIONAL',?,?,1)")
                ->execute([$eid,$u['type_unite'],$parentReal,$templateUnitId,$u['code'],$u['nom']]);
        }
    }
}
