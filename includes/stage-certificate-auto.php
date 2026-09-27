<?php
/* =========================================================
   STAGIA-RDC — ATTESTATIONS AUTOMATIQUES APRÈS RÉSULTATS
========================================================= */
require_once __DIR__.'/certificate-service.php';

if(!function_exists('stageCertExec')){
    /** Exécute une évolution de schéma sans bloquer la génération si elle existe déjà. */
    function stageCertExec(PDO $pdo,string $sql):void{try{$pdo->exec($sql);}catch(Throwable $e){error_log('[CERT SCHEMA] '.$e->getMessage().' SQL='.$sql);}}
}

if(!function_exists('stageEnsureCertificateSchema')){
    /** Crée ou complète les tables nécessaires aux attestations automatiques. */
    function stageEnsureCertificateSchema(PDO $pdo):void{
        stageCertExec($pdo,"CREATE TABLE IF NOT EXISTS stage_certificates(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            reference VARCHAR(80) NOT NULL UNIQUE,
            type_document VARCHAR(60) NOT NULL DEFAULT 'ATTESTATION_STAGE',
            statut VARCHAR(30) NOT NULL DEFAULT 'GENERE',
            student_id BIGINT UNSIGNED NOT NULL,
            completion_id BIGINT UNSIGNED NULL,
            host_etablissement_id BIGINT UNSIGNED NULL,
            fichier VARCHAR(255) NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cert_student(student_id),
            INDEX idx_cert_completion(completion_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if(stagiaCertTableExists($pdo,'stage_completions')){
            foreach([
                'uuid CHAR(36) NULL','assignment_id BIGINT UNSIGNED NULL','student_id BIGINT UNSIGNED NULL','campaign_id BIGINT UNSIGNED NULL',
                'statut VARCHAR(40) NOT NULL DEFAULT \'VALIDE\'','note_finale DECIMAL(6,2) NULL','taux_presence DECIMAL(6,2) NULL',
                'validated_at DATETIME NULL','created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP','updated_at DATETIME NULL'
            ] as $def){
                $col=strtok($def,' ');
                if(!stagiaCertColumnExists($pdo,'stage_completions',$col))stageCertExec($pdo,"ALTER TABLE stage_completions ADD COLUMN $def");
            }
        }
    }
}

if(!function_exists('stageCertInsertSmart')){
    /** Insère uniquement les colonnes présentes afin de rester compatible avec les schémas progressifs. */
    function stageCertInsertSmart(PDO $pdo,string $table,array $data):int{
        $cols=[];$vals=[];$params=[];
        foreach($data as $c=>$v){
            if(stagiaCertColumnExists($pdo,$table,$c)){$cols[]=$c;$vals[]='?';$params[]=$v;}
        }
        if(!$cols)return 0;
        $s=$pdo->prepare("INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).")");
        $s->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if(!function_exists('stageCertReference')){
    /** Génère une référence annuelle non utilisée pour une nouvelle attestation. */
    function stageCertReference(PDO $pdo):string{
        $year=date('Y');
        for($i=0;$i<20;$i++){
            $ref='ATT-'.$year.'-'.str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT);
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_certificates WHERE reference=?");
            $s->execute([$ref]);
            if(!(int)$s->fetchColumn())return $ref;
        }
        return 'ATT-'.$year.'-'.date('His').'-'.random_int(10,99);
    }
}

if(!function_exists('stageEnsureCertificatesFromValidatedResults')){
    /** Crée les attestations manquantes à partir des résultats déjà validés. */
    function stageEnsureCertificatesFromValidatedResults(PDO $pdo,int $studentId=0):int{
        if(!stagiaCertTableExists($pdo,'stage_result_transmissions')||!stagiaCertTableExists($pdo,'stage_result_items'))return 0;
        stageEnsureCertificateSchema($pdo);
        if(!stagiaCertTableExists($pdo,'stage_completions'))return 0;

        $where=$studentId>0?' AND i.student_id=?':'';
        $p=$studentId>0?[$studentId]:[];
        $s=$pdo->prepare("
            SELECT t.id transmission_id,t.campaign_id,t.host_etablissement_id,t.university_etablissement_id,
                   COALESCE(t.validated_at,t.archived_at,t.updated_at,t.created_at,NOW()) validated_at,
                   i.assignment_id,i.student_id,i.final_score,i.presence_rate
            FROM stage_result_transmissions t
            JOIN stage_result_items i ON i.transmission_id=t.id
            WHERE t.statut IN('VALIDE','ARCHIVE') $where
        ");
        $s->execute($p);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        $created=0;

        foreach($rows as $r){
            $assignmentId=(int)$r['assignment_id'];
            $sid=(int)$r['student_id'];
            if(!$assignmentId||!$sid)continue;

            $q=$pdo->prepare("SELECT id FROM stage_completions WHERE assignment_id=? LIMIT 1");
            $q->execute([$assignmentId]);
            $completionId=(int)$q->fetchColumn();

            $score=$r['final_score']!==null?(float)$r['final_score']:null;
            $note=$score===null?null:($score>20?round($score/5,2):round($score,2));
            $presence=$r['presence_rate']!==null?(float)$r['presence_rate']:null;

            if(!$completionId){
                $completionId=stageCertInsertSmart($pdo,'stage_completions',[
                    'uuid'=>stagiaCertUuid(),
                    'assignment_id'=>$assignmentId,
                    'student_id'=>$sid,
                    'campaign_id'=>(int)$r['campaign_id'],
                    'statut'=>'VALIDE',
                    'note_finale'=>$note,
                    'taux_presence'=>$presence,
                    'validated_at'=>$r['validated_at'],
                    'created_at'=>date('Y-m-d H:i:s'),
                    'updated_at'=>date('Y-m-d H:i:s')
                ]);
            }else{
                $sets=[];$params=[];
                foreach(['statut'=>'VALIDE','note_finale'=>$note,'taux_presence'=>$presence,'validated_at'=>$r['validated_at'],'updated_at'=>date('Y-m-d H:i:s')] as $c=>$v){
                    if(stagiaCertColumnExists($pdo,'stage_completions',$c)){$sets[]="$c=?";$params[]=$v;}
                }
                if($sets){$params[]=$completionId;$u=$pdo->prepare("UPDATE stage_completions SET ".implode(',',$sets)." WHERE id=?");$u->execute($params);}
            }
            if(!$completionId)continue;

            $q=$pdo->prepare("SELECT id FROM stage_certificates WHERE completion_id=? AND type_document='ATTESTATION_STAGE' AND statut<>'ANNULE' LIMIT 1");
            $q->execute([$completionId]);
            if((int)$q->fetchColumn())continue;

            stageCertInsertSmart($pdo,'stage_certificates',[
                'uuid'=>stagiaCertUuid(),
                'reference'=>stageCertReference($pdo),
                'type_document'=>'ATTESTATION_STAGE',
                'statut'=>'GENERE',
                'student_id'=>$sid,
                'completion_id'=>$completionId,
                'host_etablissement_id'=>(int)$r['host_etablissement_id'],
                'generated_at'=>date('Y-m-d H:i:s'),
                'created_at'=>date('Y-m-d H:i:s')
            ]);
            $created++;
        }
        return $created;
    }
}
