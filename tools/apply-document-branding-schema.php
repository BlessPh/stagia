<?php
require_once __DIR__.'/../config/database.php';

function column_exists(PDO $pdo,string $column):bool{
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='etablissements' AND COLUMN_NAME=?");
    $s->execute([$column]);
    return (int)$s->fetchColumn()>0;
}

$columns=[
    'logo_path'=>'VARCHAR(255) NULL',
    'adresse'=>'TEXT NULL',
    'telephone'=>'VARCHAR(80) NULL',
    'email'=>'VARCHAR(180) NULL',
    'site_web'=>'VARCHAR(180) NULL'
];

$done=[];
foreach($columns as $name=>$type){
    if(!column_exists($pdo,$name)){
        $pdo->exec("ALTER TABLE etablissements ADD COLUMN `$name` $type");
        $done[]=$name;
    }
}

echo "Schéma branding documents OK. Colonnes ajoutées : ".($done?implode(', ',$done):'aucune').PHP_EOL;
