<?php
/**
 * Endpoint AJAX de consultation administrative des services et unités d'un établissement.
 * Il normalise les valeurs et construit les statistiques utilisées par l'écran de gestion.
 */
ob_start();

header('Content-Type: application/json; charset=utf-8');

if(session_status()!==PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';


/* =========================================================
   RÉPONSE JSON
========================================================= */
/** Envoie une réponse JSON unique en éliminant tout tampon de sortie précédent. */
function respond(bool $success,string $message='',array $data=[],int $status=200): never{
    http_response_code($status);

    if(ob_get_length()) ob_clean();

    echo json_encode([
        'success'=>$success,
        'message'=>$message,
        'data'=>$data
    ],JSON_UNESCAPED_UNICODE);

    exit;
}


/* =========================================================
   ERREURS FATALES
========================================================= */
/* Convertit une erreur PHP fatale inattendue en réponse JSON lisible par l'interface. */
register_shutdown_function(function(){
    $e=error_get_last();

    if(!$e||!in_array($e['type'],[
        E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR
    ],true)) return;

    respond(
        false,
        'Erreur PHP : '.$e['message'].' - ligne '.$e['line'],
        [],
        500
    );
});


/* =========================================================
   SÉCURITÉ
========================================================= */
if(empty($_SESSION['user_id']))
    respond(false,'Session expirée.',[],401);

if(($_SESSION['role_code']??'')!=='ADMIN_ACCUEIL')
    respond(false,'Accès réservé à l’organisme d’accueil.',[],403);


$step='initialisation';

try{
    /* Chaque étape est mémorisée afin d'identifier précisément un incident côté serveur. */

    /* =====================================================
       ÉTABLISSEMENT
    ====================================================== */
    $step='identification établissement';

    $hopitalId=currentEtablissementId($pdo);

    if(!$hopitalId)
        respond(false,'Aucun établissement associé à ce compte.',[],403);


    /* =====================================================
       SERVICES / UNITÉS
    ====================================================== */
    $step='chargement services';

    /* Chargement de la hiérarchie complète : unités et service parent éventuel. */
    $stmt=$pdo->prepare("
        SELECT
            u.id,
            u.host_etablissement_id,
            u.parent_id,
            u.code,
            u.nom,
            u.type,
            u.description,
            u.capacite,
            u.actif,
            u.created_at,
            u.updated_at,
            p.nom parent_nom,
            p.code parent_code
        FROM host_units u
        LEFT JOIN host_units p
            ON p.id=u.parent_id
           AND p.host_etablissement_id=u.host_etablissement_id
        WHERE u.host_etablissement_id=?
        ORDER BY
            CASE WHEN u.type='SERVICE' THEN 0 ELSE 1 END,
            COALESCE(p.nom,u.nom),
            u.nom
    ");

    $stmt->execute([$hopitalId]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);


    /* =====================================================
       NORMALISATION + STATISTIQUES
    ====================================================== */
    /* Conversion des types JSON et calcul des compteurs de structure. */
    $stats=[
        'total'=>count($items),
        'services'=>0,
        'unites'=>0,
        'actifs'=>0
    ];

    foreach($items as &$item){

        $item['id']=(int)$item['id'];
        $item['host_etablissement_id']=(int)$item['host_etablissement_id'];
        $item['parent_id']=$item['parent_id']!==null?(int)$item['parent_id']:null;
        $item['capacite']=$item['capacite']!==null?(int)$item['capacite']:null;
        $item['actif']=(int)$item['actif'];

        if($item['type']==='SERVICE') $stats['services']++;
        elseif($item['type']==='UNITE') $stats['unites']++;

        if($item['actif']===1) $stats['actifs']++;
    }

    unset($item);


    /* =====================================================
       SUCCÈS
    ====================================================== */
    /* Réponse destinée à l'affichage de gestion des services et unités. */
    respond(true,'',[
        'items'=>$items,
        'stats'=>$stats
    ]);


}catch(Throwable $e){

    respond(
        false,
        'Erreur ['.$step.'] : '.$e->getMessage(),
        [],
        500
    );
}
