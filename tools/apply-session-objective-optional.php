<?php
/**
 * STAGIA-RDC
 * Rend objectif_stage facultatif lors de la création/publication
 * d'une session. Le type de stage conserve son champ objectif.
 *
 * Exécution :
 * php tools/apply-session-objective-optional.php
 */
if(PHP_SAPI!=='cli')exit("Terminal uniquement.\n");

$root=realpath(__DIR__.'/..');
if(!$root)exit("Racine STAGIA introuvable.\n");

function patchFile(string $file,callable $fn):void{
    if(!is_file($file)){
        echo "[ABSENT] $file\n";
        return;
    }

    $src=file_get_contents($file);
    $new=$fn($src);

    if($new===$src){
        echo "[OK] Déjà compatible : $file\n";
        return;
    }

    $bak=$file.'.before-objective-choice.bak';
    if(!is_file($bak))copy($file,$bak);

    file_put_contents($file,$new);
    echo "[MODIFIÉ] $file\n";
}

/* Validation de la session */
patchFile(
    $root.'/includes/stage-campaign.php',
    function(string $s):string{
        $patterns=[
            '~\s*if\s*\(\s*\$objective\s*===\s*[\'"]{2}\s*\)\s*throw new RuntimeException\(\s*"L[\'"]objectif du stage est obligatoire\."\s*\);~u',
            '~\s*if\s*\(\s*\$objective\s*===\s*[\'"]{2}\s*\)\s*throw new RuntimeException\(\s*\'L\\\\?\'objectif du stage est obligatoire\.\'\s*\);~u'
        ];

        foreach($patterns as $p)
            $s=preg_replace($p,'',$s);

        return $s;
    }
);

/* Publication de la session */
patchFile(
    $root.'/actions/stages/campagne-status.php',
    function(string $s):string{
        $patterns=[
            '~\s*if\s*\(\s*empty\(\s*\$c\[[\'"]objectif_stage[\'"]\]\s*\)\s*\)\s*throw new RuntimeException\(\s*"L[\'"]objectif du stage doit être renseigné avant publication\."\s*\);~u',
            '~\s*if\s*\(\s*empty\(\s*\$c\[[\'"]objectif_stage[\'"]\]\s*\)\s*\)\s*throw new RuntimeException\(\s*\'L\\\\?\'objectif du stage doit être renseigné avant publication\.\'\s*\);~u'
        ];

        foreach($patterns as $p)
            $s=preg_replace($p,'',$s);

        return $s;
    }
);

echo "\nObjectif de session maintenant facultatif.\n";
