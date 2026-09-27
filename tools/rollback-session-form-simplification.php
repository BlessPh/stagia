<?php
if(PHP_SAPI!=='cli')exit("Terminal uniquement.\n");
$root=realpath(__DIR__.'/..');

$files=[
    '/views/stages/campagnes.php',
    '/includes/stage-campaign.php',
    '/actions/stages/campagne-status.php'
];

foreach($files as $rel){
    $file=$root.$rel;
    $bak=$file.'.before-session-form-simplification.bak';

    if(is_file($bak) && copy($bak,$file))
        echo "[RESTAURÉ] $rel\n";
}
