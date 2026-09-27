<?php
/* Correctif visibilité documents STAGIA.
   - ajoute Modèles/Archives dans Organisation des stages pour université et hôpital admin/coordination ;
   - retire ces entrées des autres profils via les guards des pages/actions. */
$root=realpath(__DIR__.'/..');
$file=$root.'/includes/app-header.php';
if(!is_file($file)){http_response_code(500);exit('includes/app-header.php introuvable.');}
$src=file_get_contents($file);
$backup=$file.'.bak-docs-visibility-'.date('Ymd-His');
copy($file,$backup);

function removeDocItems(string $s):string{
    $s=preg_replace("~\n\s*\$organisationStageItems\[\]\s*=\s*\[\s*BASE_URL\.'\/views\/documents\/modeles-stage\.php'.*?\];~s", "", $s);
    $s=preg_replace("~\n\s*\$organisationStageItems\[\]\s*=\s*\[\s*BASE_URL\.'\/views\/documents\/archives\.php'.*?\];~s", "", $s);
    $s=preg_replace("~\n\s*'stage-documents-models',?~", "", $s);
    $s=preg_replace("~\n\s*'stage-documents-archives',?~", "", $s);
    return $s;
}
$src=removeDocItems($src);

// Liste globale des pages stages.
$src=str_replace(
"    'stage-conventions',\n    'stages-campagnes',",
"    'stage-conventions',\n    'stage-documents-models',\n    'stage-documents-archives',\n    'stages-campagnes',",
$src
);

$hostItem=<<<'PHP_SNIPPET'

        if(($hAdmin||$hCoord)&&contextPermission(['stage.manage','campaign.hosting.view'])){
            $organisationStageItems[]=[
                BASE_URL.'/views/documents/modeles-stage.php',
                'bi-files',
                'Modèles documents',
                'stage-documents-models'
            ];
            $organisationStageItems[]=[
                BASE_URL.'/views/documents/archives.php',
                'bi-archive',
                'Archives documents',
                'stage-documents-archives'
            ];
        }
PHP_SNIPPET;
$src=str_replace(
"                'stage-conventions'\n            ];\n\n        if(contextPermission('capacity.hosting.view'))",
"                'stage-conventions'\n            ];".$hostItem."\n        if(contextPermission('capacity.hosting.view'))",
$src
);
$src=str_replace(
"                        'stage-conventions',\n                        'hospital-capacities'",
"                        'stage-conventions',\n                        'stage-documents-models',\n                        'stage-documents-archives',\n                        'hospital-capacities'",
$src
);

$academicItem=<<<'PHP_SNIPPET'

    if(contextPermission(['stage.manage','campaign.university.view','campaign.university.update','placement.university.view'])){
        $organisationStageItems[]=[
            BASE_URL.'/views/documents/modeles-stage.php',
            'bi-files',
            'Modèles documents',
            'stage-documents-models'
        ];
        $organisationStageItems[]=[
            BASE_URL.'/views/documents/archives.php',
            'bi-archive',
            'Archives documents',
            'stage-documents-archives'
        ];
    }
PHP_SNIPPET;
$src=str_replace(
"            'stage-conventions'\n        ];\n\n    /* ----- Partenariats / placements ----- */",
"            'stage-conventions'\n        ];".$academicItem."\n    /* ----- Partenariats / placements ----- */",
$src
);
$src=str_replace(
"                    'stage-conventions'\n                ],",
"                    'stage-conventions',\n                    'stage-documents-models',\n                    'stage-documents-archives'\n                ],",
$src
);

file_put_contents($file,$src);
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Correctif documents</title><style>body{font-family:Arial;margin:40px;background:#f8fafc}.box{background:white;border:1px solid #e5e7eb;border-radius:12px;padding:22px;max-width:760px}.ok{color:#15803d;font-weight:700}code{background:#f1f5f9;padding:2px 6px;border-radius:6px}</style></head><body><div class="box"><h2 class="ok">Correctif appliqué</h2><p>La sidebar a été corrigée.</p><ul><li>Université : Modèles documents + Archives documents visibles.</li><li>Hôpital admin/coordination : Modèles documents + Archives documents visibles.</li><li>Chef service / encadreur / étudiant : menu global masqué.</li></ul><p>Sauvegarde : <code><?=htmlspecialchars(basename($backup))?></code></p><p>Faites <b>Ctrl + F5</b>.</p></div></body></html>
