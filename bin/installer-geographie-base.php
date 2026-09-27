<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    if(!extension_loaded('pdo_mysql')||!extension_loaded('mbstring'))throw new RuntimeException('Extensions pdo_mysql et mbstring requises.');
    $cible=realpath($argv[1]??'');
    if(!$cible||!is_file($cible.'/config/database.php'))throw new RuntimeException('Projet cible introuvable.');
    // Certains anciens config/database.php font exit(message), donc avec un code 0.
    $termine=false;
    register_shutdown_function(static function()use(&$termine):void{if(!$termine){fwrite(STDERR,"Migration non terminée. Vérifiez la connexion configurée dans le projet cible.\n");exit(1);}});
    require $cible.'/config/database.php';
    // Vérifie les types des clés de la base réelle avant la première commande DDL.
    foreach(['users','etablissements','demandes_adhesion'] as $table){
        $s=$pdo->prepare('SELECT c.COLUMN_TYPE,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=? AND c.COLUMN_NAME=\'id\'');
        $s->execute([$table]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r||!preg_match('/^int(?:\([0-9]+\))?$/i',$r['COLUMN_TYPE'])||strtoupper($r['ENGINE'])!=='INNODB')
            throw new RuntimeException("Schéma différent : $table.id doit être INT signé et la table InnoDB. Aucune migration exécutée.");
    }
    foreach([
        __DIR__.'/../database/migrations/2026_09_14_000007_geographie.sql',
        __DIR__.'/../database/migrations/2026_09_14_000008_geographie_subdivisions.sql'
    ] as $migration){
        $sql=file_get_contents($migration);
        if($sql===false)throw new RuntimeException('Migration absente : '.basename($migration));
        $pdo->exec($sql);
    }
    foreach(['geographie_unites','geographie_localisations','geographie_journal','geographie_version'] as $table)$pdo->query("SELECT 1 FROM $table LIMIT 1");
    $comptes=$pdo->query('SELECT type,COUNT(*) total FROM geographie_unites GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
    echo 'Référentiel installé : '.array_sum($comptes).' unités ('.implode(', ',array_map(fn($type,$total)=>"$total $type",array_keys($comptes),$comptes)).").\n";
    $termine=true;
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Installation géographique interrompue : ".$e->getMessage()."\nLes fichiers applicatifs ne doivent pas être copiés.\n");exit(1);
}
