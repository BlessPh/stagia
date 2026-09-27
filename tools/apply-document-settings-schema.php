<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/document-branding.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL','SUPER_ADMIN']);
header('Content-Type:text/plain; charset=utf-8');

$cols=[
 'document_secretariat'=>'VARCHAR(190) NULL',
 'document_faculte'=>'VARCHAR(190) NULL',
 'document_departement'=>'VARCHAR(190) NULL',
 'document_signataire_nom'=>'VARCHAR(190) NULL',
 'document_signataire_fonction'=>'VARCHAR(190) NULL',
 'document_slogan'=>'VARCHAR(255) NULL',
 'document_footer'=>'VARCHAR(255) NULL',
 'document_signature'=>'VARCHAR(255) NULL',
 'document_cachet'=>'VARCHAR(255) NULL'
];

try{
    foreach($cols as $col=>$def){
        if(!stagiaDocColumnExists($pdo,'etablissements',$col)){
            $pdo->exec("ALTER TABLE etablissements ADD COLUMN `$col` $def");
            echo "Ajout : $col\n";
        }else echo "OK : $col\n";
    }
    echo "\nTerminé. Retournez dans Mon établissement > Paramètres documents.\n";
}catch(Throwable $e){
    http_response_code(500);
    echo 'Erreur : '.$e->getMessage();
}
