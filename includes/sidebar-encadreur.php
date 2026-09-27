<?php
/**
 * STAGIA-RDC — Sidebar ENCADREUR
 *
 * À inclure depuis includes/app-header.php uniquement lorsque :
 * $role === 'ENCADREUR'
 *
 * Principe :
 * - le rôle définit l'espace fonctionnel ;
 * - les permissions effectives définissent les liens visibles ;
 * - les endpoints restent responsables de la sécurité réelle ;
 * - le périmètre des données est limité aux rotations attribuées
 *   via stage_rotation_supervisors.
 */

if(($role??'')!=='ENCADREUR'){
    return;
}

/**
 * Vérifie une permission dans le contexte courant.
 * On utilise d'abord contextPermission() qui tient compte du contexte
 * établissement + role_assignments. Fallback pour compatibilité legacy.
 */
$encadreurCan=function($permissions):bool{
    $permissions=is_array($permissions)
        ?$permissions
        :[$permissions];

    foreach($permissions as $permission){

        if(
            function_exists('contextPermission') &&
            contextPermission($permission)
        ){
            return true;
        }

        if(
            function_exists('hasPermission') &&
            hasPermission($GLOBALS['pdo']??null,$permission)
        ){
            return true;
        }

        $codes=$_SESSION['permission_codes']??[];

        if(
            is_array($codes) &&
            in_array($permission,$codes,true)
        ){
            return true;
        }
    }

    return false;
};

$showDashboard=$encadreurCan('dashboard.view');

$showSupervision=$encadreurCan('supervision.hosting.view');

$showAttendance=$encadreurCan([
    'attendance.hosting.review',
    'attendance.manage'
]);

$showLogbook=$encadreurCan('logbook.hosting.review');

/*
 * Évaluations :
 * le rôle ENCADREUR doit les gérer selon les documents.
 * Le lien utilise la permission existante evaluation.manage.
 * La sécurité des données devra rester limitée aux rotations
 * où l'utilisateur est encadreur actif.
 */
$showEvaluation=$encadreurCan('evaluation.manage');

if($showDashboard){
    stagiaNav(
        BASE_URL.'/views/espace-hopital/dashboard.php',
        'bi-grid',
        'Tableau de bord',
        'hopital-dashboard',
        $activePage
    );
}

if($showSupervision || $showAttendance || $showLogbook){
    stagiaMenuTitle('MES STAGIAIRES');

    if($showSupervision){
        stagiaNav(
            BASE_URL.'/views/espace-hopital/suivi-encadreur.php',
            'bi-people',
            'Suivi de mes stagiaires',
            'encadreur-suivi',
            $activePage
        );
    }
}

if($showAttendance || $showLogbook || $showEvaluation){
    stagiaMenuTitle('SUIVI');

    if($showAttendance){
        stagiaNav(
            BASE_URL.'/views/espace-hopital/suivi-encadreur.php?tab=attendance',
            'bi-calendar-check',
            'Présences',
            'encadreur-presences',
            $activePage
        );
    }

    if($showLogbook){
        stagiaNav(
            BASE_URL.'/views/espace-hopital/suivi-encadreur.php?tab=logbook',
            'bi-journal-medical',
            'Journaux de stage',
            'encadreur-journaux',
            $activePage
        );
    }

    if($showEvaluation){
        stagiaNav(
            BASE_URL.'/views/espace-hopital/evaluations.php',
            'bi-clipboard-check',
            'Évaluations',
            'hopital-evaluations',
            $activePage
        );
    }
}

/*
 * Ne PAS afficher pour ENCADREUR :
 * - Sollicitations D4
 * - Campagnes d'accueil
 * - Capacités d'accueil
 * - Demandes étudiantes
 * - Stagiaires attendus globaux
 * - Services / unités (gestion)
 * - Affectations administratives
 * - Rotations (planification globale)
 * - Clôture administrative
 * - Paiements
 * - Utilisateurs / administration
 *
 * Ces fonctions relèvent de la structure d'accueil / ADMIN_ACCUEIL,
 * pas de l'encadrement quotidien.
 */
