<?php
declare(strict_types=1);

/** Charge un fichier .env local sans écraser les variables déjà fournies au processus. */
function chargerEnvironnementStagia(?string $fichier=null):void{
    /* Évite de relire le même fichier lorsque plusieurs configurations sont incluses. */
    static $charge=false;
    if($charge)return;
    $charge=true;
    $fichier=$fichier??dirname(__DIR__).'/.env';
    if(!is_file($fichier)||!is_readable($fichier))return;

    /* Chaque ligne valide KEY=VALUE est ajoutée seulement si le processus ne fournit pas déjà cette variable. */
    foreach(file($fichier,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $ligne){
        $ligne=trim($ligne);
        if($ligne===''||str_starts_with($ligne,'#')||!str_contains($ligne,'='))continue;
        [$cle,$valeur]=array_map('trim',explode('=',$ligne,2));
        if($cle===''||getenv($cle)!==false)continue;
        /* Retire les guillemets d'encadrement facultatifs du fichier .env. */
        if(strlen($valeur)>=2&&(($valeur[0]==='"'&&str_ends_with($valeur,'"'))||($valeur[0]==="'"&&str_ends_with($valeur,"'")))){
            $valeur=substr($valeur,1,-1);
        }
        putenv($cle.'='.$valeur);
        $_ENV[$cle]=$valeur;
    }
}

/* Chargement automatique afin que les autres fichiers de configuration accèdent aux variables. */
chargerEnvironnementStagia();

/*
 * Les champs datetime-local transportent une heure locale sans fuseau.
 * PHP doit donc utiliser le même fuseau sur WAMP et sur Render.
 */
$stagiaTimezone=trim((string)(getenv('APP_TIMEZONE')?:'Africa/Kinshasa'));
try{
    new DateTimeZone($stagiaTimezone);
    date_default_timezone_set($stagiaTimezone);
}catch(Throwable){
    date_default_timezone_set('Africa/Kinshasa');
}
