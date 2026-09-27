<?php
if(PHP_SAPI!=='cli')exit("Ce script doit être exécuté dans le terminal.\n");

$projectRoot=realpath(__DIR__.'/..');
if(!$projectRoot)exit("Racine du projet introuvable.\n");

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

$replacements=[
    "Campagnes universitaires"=>"Sessions de stage",
    "Campagne universitaire"=>"Session de stage",
    "campagnes universitaires"=>"sessions de stage",
    "campagne universitaire"=>"session de stage",

    "Nouveau stage / campagne"=>"Lancer une session",
    "Nouvelle campagne"=>"Lancer une session",
    "nouvelle campagne"=>"nouvelle session",
    "Créer une campagne"=>"Lancer une session",
    "créer une campagne"=>"lancer une session",

    "Rechercher une campagne..."=>"Rechercher une session...",
    "<th>CAMPAGNE</th>"=>"<th>SESSION</th>",
    ">CAMPAGNE<"=>">SESSION<",

    "Le comportement d'une campagne dépend du"=>"Le comportement d'une session dépend du",
    "Certaines campagnes peuvent nécessiter"=>"Certaines sessions peuvent nécessiter",

    "Publier cette campagne ?"=>"Publier cette session ?",
    "Ouvrir cette campagne ?"=>"Publier cette session aux étudiants ?",
    "Ouvrir cette campagne aux étudiants éligibles ?"=>"Publier cette session aux étudiants éligibles ?",
    "Terminer cette campagne ?"=>"Terminer cette session ?",

    "Campagne ouverte aux étudiants éligibles."=>"Session publiée aux étudiants éligibles.",
    "Campagne marquée comme terminée."=>"Session marquée comme terminée.",
    "Campagne annulée."=>"Session annulée.",
    "Campagne invalide."=>"Session invalide.",
    "Campagne introuvable."=>"Session introuvable.",

    "Les hôpitaux déjà sélectionnés lors de la création de la campagne"=>"Les hôpitaux déjà sélectionnés lors du lancement de la session",
    "Aucune sollicitation pour cette campagne."=>"Aucune sollicitation pour cette session.",

    "Campagnes d’accueil"=>"Sessions d’accueil",
    "Campagnes d'accueil"=>"Sessions d'accueil",
    "Campagne d’accueil"=>"Session d’accueil",
    "Campagne d'accueil"=>"Session d'accueil",
    "campagnes d’accueil"=>"sessions d’accueil",
    "campagnes d'accueil"=>"sessions d'accueil",
    "campagne d’accueil"=>"session d’accueil",
    "campagne d'accueil"=>"session d'accueil",

    "Aucune campagne d'accueil active n'est disponible."=>"Aucune session d'accueil active n'est disponible.",
    "Campagne d’accueil créée avec succès."=>"Session d’accueil créée avec succès.",
    "Campagne d'accueil créée avec succès."=>"Session d'accueil créée avec succès.",

    "La date d'ouverture des candidatures n'est pas encore atteinte."=>"La date de publication aux étudiants n'est pas encore atteinte."
];

$changed=[];$skipped=[];

foreach($targets as $relative){
    $file=$projectRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);

    if(!is_file($file)){
        $skipped[]=$relative;
        continue;
    }

    $content=file_get_contents($file);
    if($content===false){
        echo "[ERREUR] Lecture impossible : $relative\n";
        continue;
    }

    $new=$content;$count=0;

    foreach($replacements as $from=>$to){
        $local=0;
        $new=str_replace($from,$to,$new,$local);
        $count+=$local;
    }

    if($new===$content){
        echo "[OK] Aucun texte à modifier : $relative\n";
        continue;
    }

    $backup=$file.'.before-session-terminology.bak';
    if(!is_file($backup) && !copy($file,$backup)){
        echo "[ERREUR] Backup impossible : $relative\n";
        continue;
    }

    if(file_put_contents($file,$new)===false){
        echo "[ERREUR] Écriture impossible : $relative\n";
        continue;
    }

    $changed[]=$relative;
    echo "[MODIFIÉ] $relative ($count remplacement(s))\n";
}

echo "\nMigration terminologique terminée.\n";
echo "Fichiers modifiés : ".count($changed)."\n";
echo "Fichiers absents/ignorés : ".count($skipped)."\n";
echo "Aucune table, colonne ou route n'a été renommée.\n";
echo "Sauvegardes : *.before-session-terminology.bak\n";
