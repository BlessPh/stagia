<?php
/* Détection robuste des rôles : utilise le rôle actif + tous les rôles actifs attribués à l'utilisateur. */
if(!function_exists('stagiaRoleNormalize')){
    function stagiaRoleNormalize(string $v):string{
        $v=trim($v);
        if($v==='')return '';
        $t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);
        if($t!==false)$v=$t;
        $v=strtoupper($v);
        $v=preg_replace('/[^A-Z0-9]+/','_',$v);
        return trim((string)$v,'_');
    }
}

if(!function_exists('stagiaRoleTableColumns')){
    function stagiaRoleTableColumns(PDO $pdo,string $table):array{
        static $cache=[];
        if(isset($cache[$table]))return $cache[$table];
        try{
            $rows=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $cols=[];
            foreach($rows as $r)$cols[$r['Field']]=true;
            return $cache[$table]=$cols;
        }catch(Throwable $e){return $cache[$table]=[];}
    }
}

if(!function_exists('stagiaUserRolePool')){
    function stagiaUserRolePool(PDO $pdo,int $userId,int $etablissementId=0,array $fallback=[]):array{
        $pool=[];
        foreach($fallback as $v){
            $n=stagiaRoleNormalize((string)$v);
            if($n!=='')$pool[$n]=true;
        }
        if($userId<=0)return array_keys($pool);

        try{
            $roleCols=stagiaRoleTableColumns($pdo,'roles');
            $raCols=stagiaRoleTableColumns($pdo,'role_assignments');
            $textCols=array_values(array_filter(['code','role_code','nom','libelle','name','label'],fn($c)=>isset($roleCols[$c])));
            if(!$textCols)return array_keys($pool);

            $select=implode(',',array_map(fn($c)=>'r.`'.$c.'`',$textCols));
            $where=['ra.user_id=?'];
            $params=[$userId];

            if(isset($raCols['actif']))$where[]='ra.actif=1';
            if(isset($raCols['revoked_at']))$where[]='ra.revoked_at IS NULL';
            if(isset($raCols['starts_at']))$where[]='(ra.starts_at IS NULL OR ra.starts_at<=NOW())';
            if(isset($raCols['ends_at']))$where[]='(ra.ends_at IS NULL OR ra.ends_at>=NOW())';
            if($etablissementId>0 && isset($raCols['etablissement_id'])){
                $where[]='(ra.etablissement_id=? OR ra.etablissement_id IS NULL)';
                $params[]=$etablissementId;
            }

            $sql='SELECT DISTINCT '.$select.' FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE '.implode(' AND ',$where);
            $s=$pdo->prepare($sql);
            $s->execute($params);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
                foreach($row as $v){
                    $n=stagiaRoleNormalize((string)$v);
                    if($n!=='')$pool[$n]=true;
                }
            }
        }catch(Throwable $e){error_log('[STAGIA ROLE POOL] '.$e->getMessage());}

        return array_keys($pool);
    }
}

if(!function_exists('stagiaRolePoolContains')){
    function stagiaRolePoolContains(array $pool,array $needles):bool{
        $n=array_map('stagiaRoleNormalize',$needles);
        foreach($pool as $r){
            $r=stagiaRoleNormalize((string)$r);
            if($r==='')continue;
            foreach($n as $x){
                if($x!=='' && ($r===$x || str_contains($r,$x)))return true;
            }
        }
        return false;
    }
}
