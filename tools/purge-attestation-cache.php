<?php
require_once __DIR__.'/../config/config.php';
$dir=__DIR__.'/../storage/certificates';$n=0;
if(is_dir($dir))foreach(glob($dir.'/*')?:[] as $f)if(is_file($f)){@unlink($f);$n++;}
echo '<h3>Cache attestations vidé</h3><p>'.$n.' fichier(s) supprimé(s).</p><p><a href="'.BASE_URL.'/views/espace-etudiant/documents.php">Retour documents</a></p>';
