<?php
if(PHP_SAPI!=='cli')exit("Ce script doit être exécuté dans le terminal.\n");

$projectRoot=realpath(__DIR__.'/..');

$targets=[
    'views/stages/campagnes.php',
    'views/stages/d4-partenaires.php',
    'views/espace-hopital/sollicitations-d4.php',
    'views/espace-hopital/campagnes-accueil.php',
    'includes/app-header.php',
    'actions/stages/campagne-store.php',
    'actions/stages/campagne-update.php',
    'actions/stages/campagne-status.php',
    'actions/stages/d4-campagne-open-students.php',
    'actions/espace-hopital/host-campaign-store.php'
];

$restored=0;

foreach($targets as $relative){
    $file=$projectRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    $backup=$file.'.before-session-terminology.bak';

    if(!is_file($backup))continue;

    if(copy($backup,$file)){
        $restored++;
        echo "[RESTAURÉ] $relative\n";
    }
}

echo "\n$restored fichier(s) restauré(s).\n";
