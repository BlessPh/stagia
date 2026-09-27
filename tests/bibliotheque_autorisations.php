<?php
declare(strict_types=1);
// Exécuter depuis le projet installé : php tests/bibliotheque_autorisations.php
require_once __DIR__.'/../includes/bibliotheque.php';
$n=0;
function checkBib(bool $actual,bool $expected,string $label):void{
    global $n;
    if($actual!==$expected){fwrite(STDERR,"ECHEC : $label\n");exit(1);}
    $n++;echo "OK : $label\n";
}
$student=['user'=>7,'super'=>false,'org_ids'=>[2],'gestion'=>[]];
$other=['user'=>8,'super'=>false,'org_ids'=>[3],'gestion'=>[]];
$admin=['user'=>9,'super'=>false,'org_ids'=>[2],'gestion'=>[2]];
$super=['user'=>1,'super'=>true,'org_ids'=>[],'gestion'=>[]];
$d=['depose_par'=>7,'visibilite'=>'etablissement','etablissement_id'=>2,'statut'=>'publie'];
checkBib(biblioLit($student,$d),true,'Déposant peut lire');
checkBib(biblioLit($other,$d),false,'Autre établissement exclu');
checkBib(biblioLit($admin,$d),true,'Administrateur du même établissement');
checkBib(biblioGere($other,$d),false,'Autre établissement ne gère pas');
checkBib(biblioGere($admin,$d),true,'Administrateur local gère');
checkBib(biblioModifie($student,$d),false,'Déposant ne modifie pas une publication');
checkBib(biblioModifie($admin,$d),true,'Gestionnaire modifie une publication');
$d['visibilite']='globale';$d['etablissement_id']=null;
checkBib(biblioLit($other,$d),true,'Publication globale accessible');
checkBib(biblioGere($admin,$d),false,'Admin local ne valide pas une diffusion globale');
checkBib(biblioGere($super,$d),true,'Super admin valide une diffusion globale');
$d['statut']='soumis';
checkBib(biblioLit($other,$d),false,'Dépôt soumis non public');
checkBib(biblioModifie($student,$d),false,'Dépôt soumis verrouillé pour auteur');
$d['statut']='brouillon';
checkBib(biblioModifie($student,$d),true,'Auteur modifie son brouillon');
checkBib(biblioLit($admin,$d),false,'Admin local ne lit pas brouillon global tiers');
$d['statut']='corbeille';
checkBib(biblioModifie($super,$d),false,'Corbeille non modifiable avant restauration');
$d['statut']='publie';$d['telechargement_autorise']=0;
checkBib(biblioTelecharge($other,$d),false,'Téléchargement interdit par défaut');
checkBib(biblioTelecharge($super,$d),false,'Super admin respecte interdiction');
checkBib(biblioRegleTelechargement($student,$d),true,'Déposant décide du téléchargement');
checkBib(biblioRegleTelechargement($super,$d),false,'Super admin ne décide pas à la place du déposant');
$d['telechargement_autorise']=1;
checkBib(biblioTelecharge($other,$d),true,'Publication globale autorisée au téléchargement');
$d['visibilite']='etablissement';$d['etablissement_id']=2;
checkBib(biblioTelecharge($other,$d),false,'Téléchargement autorisé ne contourne pas la diffusion');
$member=['user'=>10,'super'=>false,'org_ids'=>[2],'gestion'=>[]];
checkBib(biblioTelecharge($member,$d),true,'Membre du même établissement peut télécharger après accord');
foreach(['brouillon','soumis','rejete','archive','corbeille'] as $etat){
    $d['statut']=$etat;
    checkBib(biblioLit($member,$d),false,'Pas de visibilité publique : '.$etat);
    checkBib(biblioTelecharge($member,$d),false,'Pas de téléchargement public : '.$etat);
}
checkBib(biblioTelecharge($student,$d),false,'Corbeille : auteur ne télécharge pas');
checkBib(biblioRegleTelechargement($student,$d),false,'Restaurer avant de régler le téléchargement');
checkBib(str_contains(biblioCatalogueSql($member),"statut='publie'"),true,'Catalogue exige la publication');
checkBib(str_contains(biblioCatalogueSql($member),'depose_par'),false,'Catalogue non filtré sur le déposant');
checkBib(str_contains(biblioCatalogueSql($member),"visibilite='globale'"),true,'Catalogue couvre les ressources globales');
checkBib(str_contains(biblioCatalogueSql($member),'IN (2)'),true,'Catalogue couvre son établissement');
checkBib(str_contains(biblioAppartenancesSql(),"se.statut='ACTIF'"),true,'Source de lecture inclut inscriptions actives');
echo "$n assertions réussies. Ces tests ne remplacent pas les essais MySQL/HTTP.\n";
