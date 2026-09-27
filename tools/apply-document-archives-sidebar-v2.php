<?php
declare(strict_types=1);

/**
 * STAGIA-RDC
 * Correctif sidebar — Archives documents
 * À lancer une seule fois :
 * http://localhost/stagia/tools/apply-document-archives-sidebar-v2.php
 */

$root = dirname(__DIR__);
$file = $root.'/includes/app-header.php';

header('Content-Type: text/html; charset=utf-8');

function out(string $m): void { echo '<div style="font-family:Arial;padding:6px 0">'.$m.'</div>'; }
function ok(string $m): void { out('✅ '.$m); }
function ko(string $m): void { out('❌ '.$m); }
function info(string $m): void { out('ℹ️ '.$m); }

if (!is_file($file)) {
    ko('Fichier introuvable : '.$file);
    exit;
}

$src = file_get_contents($file);
if ($src === false) {
    ko('Lecture impossible : '.$file);
    exit;
}

$original = $src;
$changed = false;

/* =========================================================
   1) Ajouter la page dans $stagePages
========================================================= */
if (strpos($src, "'stage-documents-archives'") === false) {
    if (strpos($src, "'stage-documents-models'") !== false) {
        $src = str_replace(
            "'stage-documents-models',",
            "'stage-documents-models',\n    'stage-documents-archives',",
            $src
        );
        $changed = true;
        ok("Page active ajoutée après stage-documents-models.");
    } elseif (strpos($src, "'stage-conventions',") !== false) {
        $src = str_replace(
            "'stage-conventions',",
            "'stage-conventions',\n    'stage-documents-archives',",
            $src
        );
        $changed = true;
        ok("Page active ajoutée après stage-conventions.");
    } else {
        info("Impossible d'ajouter automatiquement stage-documents-archives dans \$stagePages.");
    }
} else {
    ok("stage-documents-archives existe déjà dans les pages.");
}

/* =========================================================
   Helpers insertion items
========================================================= */
function archiveItemCode(): string {
    return "        \$organisationStageItems[]=[\n".
           "            BASE_URL.'/views/documents/archives.php',\n".
           "            'bi-archive',\n".
           "            'Archives documents',\n".
           "            'stage-documents-archives'\n".
           "        ];\n";
}

/* =========================================================
   2) Ajouter le lien dans les sous-menus Organisation des stages
      Le lien est ajouté après Modèles documents si présent,
      sinon après Conventions de stage.
========================================================= */
if (strpos($src, "BASE_URL.'/views/documents/archives.php'") === false) {
    $item = archiveItemCode();

    $inserted = false;

    /* Cas idéal : un lien Modèles documents existe déjà */
    $patternModels = "/(\\\$organisationStageItems\\[\\]=\\[\\s*BASE_URL\\.'\\/views\\/documents\\/modeles-stage\\.php'[\\s\\S]*?'stage-documents-models'\\s*\\];\\s*)/";
    if (preg_match_all($patternModels, $src, $matches, PREG_OFFSET_CAPTURE)) {
        /* insérer après chaque occurrence; procéder à l'envers pour préserver les offsets */
        $occ = array_reverse($matches[1]);
        foreach ($occ as $m) {
            $pos = $m[1] + strlen($m[0]);
            $src = substr($src, 0, $pos)."\n".$item.substr($src, $pos);
            $inserted = true;
            $changed = true;
        }
        ok("Lien Archives documents ajouté après Modèles documents.");
    }

    /* Fallback : après conventions */
    if (!$inserted) {
        $patternConv = "/(\\\$organisationStageItems\\[\\]=\\[\\s*BASE_URL\\.'\\/views\\/stages\\/conventions\\.php'[\\s\\S]*?'stage-conventions'\\s*\\];\\s*)/";
        if (preg_match_all($patternConv, $src, $matches, PREG_OFFSET_CAPTURE)) {
            $occ = array_reverse($matches[1]);
            foreach ($occ as $m) {
                $pos = $m[1] + strlen($m[0]);
                $src = substr($src, 0, $pos)."\n".$item.substr($src, $pos);
                $inserted = true;
                $changed = true;
            }
            ok("Lien Archives documents ajouté après Conventions de stage.");
        }
    }

    if (!$inserted) {
        info("Insertion automatique du lien non faite. Bloc manuel affiché plus bas.");
    }
} else {
    ok("Lien Archives documents déjà présent.");
}

/* =========================================================
   3) Ajouter stage-documents-archives aux listes de pages des submenus
========================================================= */
$needPageInSubmenu = strpos($src, "'stage-documents-archives'") !== false;

if ($needPageInSubmenu) {
    $targets = [
        "'stage-documents-models'" => "'stage-documents-models',\n                        'stage-documents-archives'",
        "'stage-conventions'" => "'stage-conventions',\n                        'stage-documents-archives'",
    ];

    /* On évite de doubler si déjà à proximité. */
    if (!preg_match("/'stage-documents-models'\\s*,\\s*'stage-documents-archives'/", $src)
        && strpos($src, "'stage-documents-models'") !== false) {
        $src = str_replace("'stage-documents-models'", "'stage-documents-models',\n                        'stage-documents-archives'", $src);
        $changed = true;
        ok("Archives documents ajouté dans la liste d'ouverture du sous-menu après Modèles documents.");
    } elseif (!preg_match("/'stage-conventions'\\s*,\\s*'stage-documents-archives'/", $src)
        && strpos($src, "'stage-conventions'") !== false
        && strpos($src, "'stage-documents-models'") === false) {
        $src = str_replace("'stage-conventions'", "'stage-conventions',\n                        'stage-documents-archives'", $src);
        $changed = true;
        ok("Archives documents ajouté dans la liste d'ouverture du sous-menu après Conventions.");
    }
}

/* =========================================================
   Sauvegarde
========================================================= */
echo '<div style="max-width:980px;margin:30px auto;font-family:Arial;background:#fff;border:1px solid #ddd;border-radius:10px;padding:20px">';
echo '<h2>Correctif sidebar — Archives documents</h2>';

if ($changed && $src !== $original) {
    $backup = $file.'.bak-archives-'.date('Ymd-His');
    file_put_contents($backup, $original);
    file_put_contents($file, $src);
    ok('Fichier modifié : includes/app-header.php');
    ok('Sauvegarde créée : '.basename($backup));
    echo '<hr><strong>À faire maintenant :</strong>';
    echo '<pre style="background:#f7f7f7;padding:12px;border-radius:8px">Ctrl + F5
Stages → Organisation des stages → Archives documents</pre>';
} else {
    info('Aucune modification écrite.');
}

/* =========================================================
   Bloc manuel
========================================================= */
echo '<hr><h3>Bloc manuel si nécessaire</h3>';
echo '<p>Dans <code>includes/app-header.php</code>, ajoute ce lien dans le tableau <code>$organisationStageItems</code>, juste après <strong>Modèles documents</strong> ou <strong>Conventions de stage</strong> :</p>';
echo '<pre style="background:#0f172a;color:#e5e7eb;padding:14px;border-radius:8px;white-space:pre-wrap">'.htmlspecialchars(archiveItemCode()).'</pre>';
echo '<p>Et ajoute cette page dans les tableaux des pages du sous-menu :</p>';
echo '<pre style="background:#0f172a;color:#e5e7eb;padding:14px;border-radius:8px;white-space:pre-wrap">'.htmlspecialchars("'stage-documents-archives',").'</pre>';
echo '</div>';
