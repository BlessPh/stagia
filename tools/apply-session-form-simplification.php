<?php
/**
 * STAGIA-RDC — Simplification du formulaire Session de stage
 *
 * Exécution :
 *   cd C:\wamp64\www\stagia
 *   php tools\apply-session-form-simplification.php
 *
 * Modifie :
 * - views/stages/campagnes.php
 * - includes/stage-campaign.php
 * - actions/stages/campagne-status.php
 *
 * Sauvegardes automatiques :
 * *.before-session-form-simplification.bak
 */

if(PHP_SAPI!=='cli')exit("Ce script doit être exécuté dans le terminal.\n");

$root=realpath(__DIR__.'/..');
if(!$root)exit("Racine STAGIA introuvable.\n");

function loadFile(string $file):string{
    if(!is_file($file))throw new RuntimeException("Fichier introuvable : $file");
    $s=file_get_contents($file);
    if($s===false)throw new RuntimeException("Lecture impossible : $file");
    return $s;
}

function backupFile(string $file):void{
    $bak=$file.'.before-session-form-simplification.bak';
    if(!is_file($bak) && !copy($file,$bak))
        throw new RuntimeException("Sauvegarde impossible : $file");
}

function saveFile(string $file,string $content):void{
    backupFile($file);
    if(file_put_contents($file,$content)===false)
        throw new RuntimeException("Écriture impossible : $file");
}

/* =========================================================
   1. FORMULAIRE SESSION
========================================================= */
$file=$root.'/views/stages/campagnes.php';
$s=loadFile($file);
$original=$s;

/* Texte d'en-tête cohérent avec Enregistrer et publier. */
$s=str_replace(
    'Enregistrement en brouillon avant publication.',
    'Enregistrement et publication en une seule étape.',
    $s
);

/* Retirer Unité académique responsable.
   Objectif devient un affichage hérité du type + champ caché envoyé au serveur. */
$pattern='~\s*<div class="col-md-6"><label class="form-label">Unité académique responsable</label><select name="owner_faculte_id" id="unit" class="form-select"></select></div>\s*<div class="col-md-6"><label class="form-label">Objectif du stage \*</label><input name="objectif_stage" id="objective" class="form-control" required></div>~u';

$replacement='
    <div class="col-12">
        <label class="form-label">
            Objectif du stage
            <span class="text-muted fw-normal">(hérité du type, facultatif)</span>
        </label>
        <input type="hidden" name="objectif_stage" id="objective">
        <div class="form-control bg-light" id="objectiveDisplay" style="min-height:38px">
            Sélectionnez un type de stage.
        </div>
        <small class="text-muted">
            L’objectif est défini dans « Types de stage » et se charge automatiquement.
        </small>
    </div>';

$s2=preg_replace($pattern,$replacement,$s,1,$count);
if($s2===null)throw new RuntimeException('Erreur regex sur le bloc unité/objectif.');
$s=$s2;

if($count===0 && strpos($s,'id="objectiveDisplay"')===false)
    throw new RuntimeException("Bloc « Unité académique responsable / Objectif » non trouvé dans campagnes.php.");

/* Retirer complètement le bloc visible Règles de candidature + alerte hosting.
   Les valeurs techniques suivent désormais les politiques du type côté serveur. */
$pattern='~\s*<div class="col-12"><div class="border rounded-3 p-3" id="policyRules">.*?</div></div>\s*<div class="col-12 d-none" id="hostingRules">.*?</div>\s*~su';

$replacement='
    <div class="d-none">
        <input type="hidden" name="external_stage_mode" value="FOLLOW_POLICY">
        <input type="hidden" name="direct_service_choice" value="0">
    </div>

    ';

$s2=preg_replace($pattern,$replacement,$s,1,$countRules);
if($s2===null)throw new RuntimeException('Erreur regex sur les règles de candidature.');
$s=$s2;

/* Plus besoin de la liste d'unités dans le JS de cette page. */
$s=preg_replace(
    '~let items=\[\],types=\[\],years=\[\],units=\[\],filieres=\[\],promotions=\[\],permissions=\{\},timer;~',
    'let items=[],types=[],years=[],filieres=[],promotions=[],permissions={},timer;',
    $s,
    1
);

/* Libellé type : supprimer éventuellement "• Mon établissement". */
$s=preg_replace(
    '~function typeLabel\(t\)\{return t\?\.local\?\`\$\{t\.libelle\} • Mon établissement\`:t\?\.libelle\|\|\'\';\}~',
    "function typeLabel(t){return t?.libelle||'';}",
    $s,
    1
);

/* Remplacer updateTypeRules par une version sans champs supprimés,
   et ajouter le chargement automatique de l'objectif. */
$pattern='~function updateTypeRules\(selectedIds=\[\]\)\{.*?\n\}\n\nfunction fillFormLists\(\)\{~su';

$replacement=<<<'JS'
function setTypeObjective(value=''){
    const objective=String(value||'').trim();
    $('objective').value=objective;
    $('objectiveDisplay').textContent=objective||'Aucun objectif défini pour ce type de stage.';
    $('objectiveDisplay').classList.toggle('text-muted',!objective);
}

async function loadTypeObjective(){
    const id=Number($('stageType').value||0);

    if(!id){
        setTypeObjective('');
        $('objectiveDisplay').textContent='Sélectionnez un type de stage.';
        return;
    }

    $('objectiveDisplay').textContent='Chargement de l’objectif...';
    $('objectiveDisplay').classList.add('text-muted');

    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/stages/stage-type-objective.php?id='+encodeURIComponent(id)
        );
        setTypeObjective(r.data?.objectif||'');
    }catch(e){
        setTypeObjective('');
        $('objectiveDisplay').textContent='Objectif indisponible.';
        STAGIA.toast(e.message,'warning');
    }
}

function updateTypeRules(selectedIds=[]){
    const t=typeObject(),
          required=truthy(policyValue(t,'requires_hosting_participation',false))||t?.code==='MEDICAL_D4';

    $('hospitalHint').innerHTML=required
        ?'<span class="text-danger">Au moins un hôpital est obligatoire pour ce type.</span>'
        :'Vous pouvez sélectionner un ou plusieurs hôpitaux à solliciter.';

    renderTypeFinance();
    renderPromotions(selectedIds);
}

function fillFormLists(){
JS;

$s2=preg_replace($pattern,$replacement,$s,1,$countUpdate);
if($s2===null)throw new RuntimeException('Erreur regex updateTypeRules.');
$s=$s2;

if($countUpdate===0 && strpos($s,'async function loadTypeObjective()')===false)
    throw new RuntimeException('Fonction updateTypeRules introuvable.');

/* Retirer le remplissage du select unité supprimé. */
$s=preg_replace(
    '~\s*\$\(\'unit\'\)\.innerHTML=.*?;\s*~',
    "\n    ",
    $s,
    1
);

/* campagne-list peut encore retourner units : on les ignore. */
$s=str_replace(
    "items=d.items||[];types=d.types||[];years=d.years||[];units=d.units||[];filieres=d.filieres||[];promotions=d.promotions||[];permissions=d.permissions||{};",
    "items=d.items||[];types=d.types||[];years=d.years||[];filieres=d.filieres||[];promotions=d.promotions||[];permissions=d.permissions||{};",
    $s
);

/* Nouvelle session : charger immédiatement l'objectif du type sélectionné. */
$s=str_replace(
    'toggleQuickTypeFinance();toggleNewStageTypePanel(false);updateTypeRules();modal.show();',
    'toggleQuickTypeFinance();toggleNewStageTypePanel(false);updateTypeRules();loadTypeObjective();modal.show();',
    $s
);

/* Modification : plus d'unité académique ; conserver le snapshot objectif existant. */
$s=str_replace(
    "\$('title').value=x.titre||'';\$('stageType').value=x.stage_type_id||'';\$('year').value=x.annee_academique_id||'';\$('unit').value=x.owner_faculte_id||'';\n    \$('objective').value=x.objectif_stage||'';",
    "\$('title').value=x.titre||'';\$('stageType').value=x.stage_type_id||'';\$('year').value=x.annee_academique_id||'';\n    setTypeObjective(x.objectif_stage||'');",
    $s
);

/* Variantes minifiées sur une seule ligne. */
$s=str_replace(
    "\$('title').value=x.titre||'';\$('stageType').value=x.stage_type_id||'';\$('year').value=x.annee_academique_id||'';\$('unit').value=x.owner_faculte_id||'';\$('objective').value=x.objectif_stage||'';",
    "\$('title').value=x.titre||'';\$('stageType').value=x.stage_type_id||'';\$('year').value=x.annee_academique_id||'';setTypeObjective(x.objectif_stage||'');",
    $s
);

/* Retirer les références aux champs de règles supprimés dans edit(). */
$s=preg_replace(
    '~\s*\$\(\'maxApplications\'\)\.value=cfg\.max_simultaneous_applications\|\|\'\';\$\(\'externalMode\'\)\.value=cfg\.external_stage_mode\|\|\'FOLLOW_POLICY\';\$\(\'directService\'\)\.checked=!!cfg\.direct_service_choice;~',
    '',
    $s,
    1
);

/* Type changé => objectif rechargé automatiquement. */
$s=str_replace(
    "\$('stageType').onchange=refreshEligiblePromotions;\n\$('year').onchange=refreshEligiblePromotions;",
    "\$('stageType').onchange=()=>{refreshEligiblePromotions();loadTypeObjective();};\n\$('year').onchange=refreshEligiblePromotions;",
    $s
);

$s=str_replace(
    "\$('stageType').addEventListener('change',refreshEligiblePromotions);",
    "\$('stageType').addEventListener('change',()=>{refreshEligiblePromotions();loadTypeObjective();});",
    $s
);

/* Après création rapide d'un type, charger aussi son objectif. */
$s=str_replace(
    "\$('stageType').value=String(t.id);updateTypeRules();",
    "\$('stageType').value=String(t.id);updateTypeRules();loadTypeObjective();",
    $s
);

if($s===$original)
    throw new RuntimeException('Aucune modification appliquée à campagnes.php.');

saveFile($file,$s);
echo "[OK] views/stages/campagnes.php\n";

/* =========================================================
   2. OBJECTIF FACULTATIF DANS LE VALIDATEUR
========================================================= */
$file=$root.'/includes/stage-campaign.php';
$s=loadFile($file);
$before=$s;

$s=preg_replace(
    '~\s*if\s*\(\s*\$objective\s*===\s*[\'"]{2}\s*\)\s*throw new RuntimeException\(\s*[\'"]L[\'"]objectif du stage est obligatoire\.[\'"]\s*\);~u',
    '',
    $s,
    1
);

/* Variante avec guillemets doubles autour du message. */
$s=preg_replace(
    '~\s*if\s*\(\s*\$objective\s*===\s*[\'"]{2}\s*\)\s*throw new RuntimeException\(\s*"L[\'"]objectif du stage est obligatoire\."\s*\);~u',
    '',
    $s,
    1
);

if($s!==$before){
    saveFile($file,$s);
    echo "[OK] includes/stage-campaign.php — objectif facultatif\n";
}else{
    echo "[INFO] includes/stage-campaign.php — aucune règle obligatoire trouvée (peut-être déjà corrigé)\n";
}

/* =========================================================
   3. PUBLICATION : OBJECTIF FACULTATIF
========================================================= */
$file=$root.'/actions/stages/campagne-status.php';
$s=loadFile($file);
$before=$s;

$s=preg_replace(
    '~\s*if\s*\(\s*empty\(\s*\$c\[[\'"]objectif_stage[\'"]\]\s*\)\s*\)\s*throw new RuntimeException\(\s*"L[\'"]objectif du stage doit être renseigné avant publication\."\s*\);~u',
    '',
    $s,
    1
);

if($s!==$before){
    saveFile($file,$s);
    echo "[OK] actions/stages/campagne-status.php — publication sans objectif autorisée\n";
}else{
    echo "[INFO] campagne-status.php — règle objectif obligatoire absente ou déjà corrigée\n";
}

echo "\nTerminé.\n";
echo "Faites Ctrl+F5 dans le navigateur.\n";
