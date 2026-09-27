<?php
declare(strict_types=1);
require_once __DIR__.'/env.php';

/* APP_BASE_URL peut imposer une valeur en production, par exemple /stagia. */
$baseUrlEnvironnement=getenv('APP_BASE_URL');

/* La valeur explicitement fournie par l'environnement est prioritaire en production. */
if($baseUrlEnvironnement!==false){
    $baseUrl='/'.trim((string)$baseUrlEnvironnement,'/');
    if($baseUrl==='/')$baseUrl='';
}else{
    /* En local, la base URL est déduite du chemin du projet sous DOCUMENT_ROOT. */
    $racineProjet=realpath(__DIR__.'/..')?:dirname(__DIR__);
    $racineWeb=realpath((string)($_SERVER['DOCUMENT_ROOT']??''))?:'';
    $baseUrl='';

    /* La valeur est calculée uniquement si le projet se trouve réellement dans la racine Web. */
    if($racineWeb!==''&&str_starts_with($racineProjet,$racineWeb)){
        $baseUrl='/'.trim(str_replace(DIRECTORY_SEPARATOR,'/',substr($racineProjet,strlen($racineWeb))),'/');
        if($baseUrl==='/')$baseUrl='';
    }
}

/* Constante commune utilisée pour construire les liens et les redirections internes. */
define('BASE_URL',$baseUrl);
