<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';

$definitions=[
    'sexe'=>"VARCHAR(20) NULL AFTER `prenom`",
    'date_naissance'=>"DATE NULL AFTER `sexe`",
    'adresse'=>"VARCHAR(255) NULL AFTER `date_naissance`",
    'ville'=>"VARCHAR(120) NULL AFTER `adresse`",
    'province'=>"VARCHAR(120) NULL AFTER `ville`",
    'matricule'=>"VARCHAR(50) NULL AFTER `identifiant`"
];

$stmt=$pdo->prepare("
    SELECT COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='users'
");
$stmt->execute();
$existing=array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true);
$added=[];

foreach($definitions as $column=>$definition){
    if(isset($existing[$column]))continue;
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
    $added[]=$column;
}

echo $added
    ?'Profil utilisateur : colonnes ajoutées : '.implode(', ',$added).PHP_EOL
    :'Profil utilisateur : schéma déjà à jour.'.PHP_EOL;
