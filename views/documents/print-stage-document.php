<?php
/**
 * Vue d'impression des documents de stage.
 *
 * Ce fichier prépare les données, applique l'identité visuelle de
 * l'établissement émetteur, puis construit une page A4 imprimable.
 * Les quatre modèles gérés sont : présence, appréciation,
 * recommandation et rapport final.
 */

/* Dépendances : configuration, connexion PDO, sécurité et données métier. */
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-branding.php';
require_once __DIR__.'/../../includes/document-stage-data.php';

/* Seuls les rôles habilités à consulter les documents de stage peuvent ouvrir cette vue. */
requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

/* Lecture et normalisation des paramètres reçus dans l'URL. */
$type=trim((string)($_GET['type']??'presence'));
$assignmentId=(int)($_GET['assignment_id']??($_GET['context_id']??0));

/* Liste blanche : tout type inconnu revient au modèle de présence. */
$allowed=['presence','appreciation','recommandation','rapport'];
if(!in_array($type,$allowed,true))$type='presence';

/* Une recommandation est émise par l'établissement académique uniquement. */
$roleUpper=strtoupper((string)($_SESSION['role_code']??''));
$isAcademicDoc=in_array($roleUpper,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true);
if($type==='recommandation'&&!$isAcademicDoc){http_response_code(403);exit('Lettre de recommandation réservée à l’établissement de formation.');}

/* Résolution de l'établissement courant, avec solution de compatibilité pour les anciennes sessions. */
$eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));

/* Agrégation des informations du stage : contexte, présences, journal, évaluation et résultat. */
$data=stagiaDocFullData($pdo,$assignmentId);
if(!$data){http_response_code(404);exit('Stage introuvable.');}

/* Raccourcis locaux afin de garder les modèles HTML lisibles. */
$c=$data['context'];$att=$data['attendance'];$as=$data['attendance_summary'];$logs=$data['logbook_summary'];$ev=$data['evaluation'];$scores=$data['scores'];$result=$data['result'];

/* Les scores stockent competency_id, mais l'ancien document n'affichait pas
   le nom réel de la compétence. On enrichit les lignes sans modifier
   l'évaluation ni les données enregistrées. */
if($scores){
    /* Déduplication des identifiants pour ne demander chaque compétence qu'une fois. */
    $ids=[];
    foreach($scores as $s){
        $cid=(int)($s['competency_id']??0);
        if($cid>0)$ids[$cid]=$cid;
    }

    if($ids){
        /* Les marqueurs ? conservent une requête préparée, même avec une liste dynamique. */
        $in=implode(',',array_fill(0,count($ids),'?'));

        /* Recherche des libellés et de la catégorie pédagogique de chaque compétence. */
        $q=$pdo->prepare("
            SELECT
                c.id,
                c.nom,
                c.code,
                c.categorie,
                rc.section
            FROM stage_competencies c
            LEFT JOIN stage_referential_competencies rc
              ON rc.competency_id=c.id
             AND rc.referential_id=?
            WHERE c.id IN($in)
        ");
        $q->execute(array_merge([(int)($ev['referential_id']??0)],array_values($ids)));

        /* Indexation par identifiant : la fusion avec les scores devient rapide et explicite. */
        $labels=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)
            $labels[(int)$r['id']]=$r;

        /* Enrichissement en mémoire uniquement : aucune note enregistrée n'est modifiée. */
        foreach($scores as &$s){
            $cid=(int)($s['competency_id']??0);
            if(isset($labels[$cid])){
                $s['competence_label']=$labels[$cid]['nom'];
                $s['competence_code']=$labels[$cid]['code'];
                $s['categorie']=$labels[$cid]['categorie'];
                $s['section']=$labels[$cid]['section'];
            }
        }
        /* Rupture de la référence PHP créée par foreach (&$s). */
        unset($s);
    }
}

/* Lettre de recommandation : l'émetteur est l'établissement de formation.
   Les autres documents gardent l'établissement connecté / d'accueil comme logo principal. */
/* L'émetteur est l'université pour une recommandation, sinon l'établissement courant. */
$issuerEid=$eid;
if($type==='recommandation' && !empty($c['university_id']))$issuerEid=(int)$c['university_id'];
$brand=stagiaDocumentBrand($pdo,$issuerEid?:$eid);
/* Chaque libellé dispose d'une valeur par défaut pour éviter un document incomplet. */
$academicHeader=trim((string)($brand['secretariat_label']??($brand['secretariat']??'')));
if($academicHeader==='')$academicHeader='SECRÉTARIAT GÉNÉRAL À LA RECHERCHE';
$facultyHeader=trim((string)($brand['faculty_label']??($brand['faculte']??($brand['faculty']??($brand['department']??'')))));
if($facultyHeader==='')$facultyHeader='FACULTÉ DE MÉDECINE';
$signatoryName=trim((string)($brand['signatory_name']??''));
$signatoryFunction=trim((string)($brand['signatory_function']??''));
$signatureUrl=trim((string)($brand['signature_url']??''));
$cachetUrl=trim((string)($brand['cachet_url']??''));
$docMotto=trim((string)($brand['motto']??'Former aujourd’hui pour un Congo meilleur demain'));
$docFooter=trim((string)($brand['footer_text']??'Stages pour un avenir meilleur'));

/**
 * Protège toute donnée injectée dans le HTML contre l'interprétation de balises.
 */
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

/**
 * Construit le bloc de signature, avec signature/cachet facultatifs et texte de secours.
 */
function docSignBlock(array $brand,string $title='Responsable habilité'):string{
    $name=trim((string)($brand['signatory_name']??''));
    $func=trim((string)($brand['signatory_function']??''));
    $sig=trim((string)($brand['signature_url']??''));
    $cachet=trim((string)($brand['cachet_url']??''));
    $html='<div><b>'.h($func!==''?$func:$title).'</b>';
    if($sig!==''||$cachet!==''){
        $html.='<div class="stamp-line">';
        if($sig!=='')$html.='<img src="'.h($sig).'" alt="Signature">';
        if($cachet!=='')$html.='<img src="'.h($cachet).'" alt="Cachet">';
        $html.='</div>';
    }
    $html.='<div class="signline">'.h($name!==''?$name:'Nom, signature et cachet').'</div></div>';
    return $html;
}
/** Retourne le libellé humain du critère, malgré les variantes historiques de colonnes. */
function scoreLabel(array $s,int $i):string{return stagiadocPick($s,['critere_label','competence_label','libelle','label','nom'],'Critère #'.$i);}
/** Retourne la valeur obtenue pour un critère. */
function scoreValue(array $s):string{return stagiadocPick($s,['score','note','valeur','points'],'—');}
/** Retourne le barème maximal lorsqu'il est disponible. */
function scoreMax(array $s):string{return stagiadocPick($s,['max_score','note_max','bareme','points_max'],'');}
/** Retourne l'observation pédagogique liée au critère. */
function scoreComment(array $s):string{return stagiadocPick($s,['commentaire','observation','appreciation'],'');}

/**
 * Traduit une catégorie technique en groupe et ordre d'affichage du bulletin.
 */
function appreciationGroupMeta(string $cat):array{
    $cat=strtoupper(trim($cat));
    return match($cat){
        'CLINIQUE','TECHNIQUE' => ['key'=>'A','title'=>'Aptitude professionnelle','order'=>1],
        'COMMUNICATION','ETHIQUE' => ['key'=>'B','title'=>'Relations humaines','order'=>2],
        'PROFESSIONNALISME','ORGANISATION' => ['key'=>'C','title'=>'Présentation','order'=>3],
        default => ['key'=>'D','title'=>'Autres critères','order'=>4]
    };
}
/**
 * Répartit les scores dans leurs groupes, puis trie les groupes dans l'ordre A à D.
 */
function buildAppreciationGroups(array $scores):array{
    $groups=[];
    foreach($scores as $s){
        $meta=appreciationGroupMeta((string)($s['categorie']??$s['section']??''));
        $k=$meta['key'];
        if(!isset($groups[$k]))$groups[$k]=['key'=>$k,'title'=>$meta['title'],'order'=>$meta['order'],'items'=>[]];
        $groups[$k]['items'][]=$s;
    }
    uasort($groups,fn($a,$b)=>($a['order']<=>$b['order']));
    return $groups;
}

/* Correspondance entre le type interne demandé et son intitulé officiel imprimé. */
$titles=['presence'=>'FICHE DE PRÉSENCE DE L’ÉTUDIANT','appreciation'=>'FICHE D’APPRÉCIATION DU STAGIAIRE','recommandation'=>'LETTRE DE RECOMMANDATION DE STAGE','rapport'=>'RAPPORT / CERTIFICAT FINAL DE STAGE'];
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title><?=h($titles[$type])?></title>
<!-- Police d'icônes utilisée uniquement pour le bouton d'impression. -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* Mise en page écran et A4 : barre d'outils, feuille, en-tête, tableaux et signatures. */
@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#172033;font-family:Arial,Helvetica,sans-serif}.toolbar{position:sticky;top:0;background:#0f172a;color:#fff;padding:10px 14px;display:flex;gap:8px;justify-content:flex-end;z-index:5}.toolbar button{border:0;border-radius:8px;padding:9px 14px;font-weight:700}.page{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:14mm 14mm 20mm;position:relative;overflow:hidden;box-shadow:0 14px 40px rgba(15,23,42,.18)}.wm{position:absolute;inset:55mm 25mm auto 25mm;text-align:center;opacity:.045;pointer-events:none}.wm img{width:110mm;max-height:110mm;object-fit:contain}.head{display:grid;grid-template-columns:34mm 1fr 36mm;gap:10mm;align-items:center;padding-bottom:4mm}.logo{width:30mm;height:24mm;object-fit:contain}.brand h2{font-size:17px;margin:0;text-transform:uppercase}.brand p{margin:2px 0;font-size:10px;color:#64748b}.motto{font-size:12px;color:#475569;text-align:right;font-style:italic;line-height:1.25}.academic-head{text-align:center;border-bottom:2px solid #f97316;padding:2mm 0 5mm;margin-bottom:9mm}.academic-head .secretariat{font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:800;color:#172033;text-transform:uppercase;letter-spacing:.2px}.academic-head .faculty{font-family:Georgia,'Times New Roman',serif;font-size:16px;color:#334155;text-transform:uppercase;margin-top:1mm}.title{text-align:center;margin:0 0 8mm}.title span{display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 18px;font-size:18px;font-weight:800;letter-spacing:.3px}.section{margin-top:8mm;position:relative}.section-title{background:#f1f5f9;border-left:4px solid #f97316;padding:7px 10px;font-weight:800;text-transform:uppercase;font-size:12px;margin-bottom:7px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}.field{font-size:12px;padding:6px 0;border-bottom:1px dotted #94a3b8}.field b{display:inline-block;min-width:38mm}.table{width:100%;border-collapse:collapse;font-size:11px}.table th{background:#f8fafc;text-align:left;border:1px solid #cbd5e1;padding:6px}.table td{border:1px solid #cbd5e1;padding:6px;vertical-align:top}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.box{border:1px solid #e2e8f0;border-radius:10px;padding:9px;background:#fff}.box small{display:block;color:#64748b;font-size:10px;text-transform:uppercase}.box strong{font-size:16px}.letter{font-size:13px;line-height:1.8;text-align:justify;margin-top:8mm}.recipient{width:78mm;margin:7mm 0 8mm auto;text-align:left;font-size:13px;line-height:1.7}.recipient b{font-weight:800}.signature{margin-top:14mm;display:flex;justify-content:flex-end}.signature div{width:75mm;text-align:center}.stamp-line{display:flex;justify-content:center;gap:10px;align-items:center;margin-top:8mm;min-height:17mm}.stamp-line img{max-width:28mm;max-height:17mm;object-fit:contain}.signline{border-top:1px solid #334155;margin-top:10mm;padding-top:3mm;font-size:11px}.footer{position:absolute;left:14mm;right:14mm;bottom:8mm;border-top:1px solid #f97316;padding-top:3mm;font-size:9px;color:#64748b;display:flex;justify-content:space-between}.muted{color:#64748b}.badge{display:inline-block;border-radius:20px;background:#f1f5f9;padding:3px 8px;font-size:10px;font-weight:700}.decision{border:1px solid #fed7aa;background:#fff7ed;border-radius:12px;padding:10px}.lines{height:17mm;border-bottom:1px dotted #94a3b8;margin-bottom:4mm}@media print{body{background:#fff}.toolbar{display:none}.page{margin:0;box-shadow:none;width:auto;min-height:auto}}
</style></head><body>
<!-- Commande locale du navigateur : aucune donnée n'est envoyée au serveur. -->
<div class="toolbar"><button onclick="window.print()"><i class="bi bi-printer"></i> Imprimer / PDF</button></div>
<div class="page">
    <!-- Filigrane, identité de l'établissement et titre partagé par tous les modèles. -->
    <div class="wm"><img src="<?=h($brand['stagia_logo_url']??(BASE_URL.'/assets/img/logo.png'))?>" alt="STAGIA"></div>
    <div class="head">
        <img class="logo" src="<?=h($brand['logo_url'])?>" alt="Logo établissement">
        <div class="brand"><h2><?=h($brand['name'])?></h2><p><?=h($brand['address'])?></p><p><?=h($brand['phone'])?><?=!empty($brand['email'])?' · '.h($brand['email']):''?><?=!empty($brand['website'])?' · '.h($brand['website']):''?></p></div>
        <div class="motto"><?=nl2br(h($docMotto))?></div>
    </div>
    <div class="academic-head">
        <div class="secretariat"><?=h($academicHeader)?></div>
        <div class="faculty"><?=h($facultyHeader)?></div>
    </div>
    <div class="title"><span><?=h($titles[$type])?></span></div>

<?php if($type==='recommandation'): ?>
    <!-- Lettre institutionnelle destinée à la structure d'accueil. -->
    <div class="section">
        <div class="grid"><div class="field"><b>N/Réf.</b> STG/REC/<?=h($c['assignment_id'])?>/<?=date('Y')?></div><div class="field"><b>Date</b> <?=h(stagiaDocDateLongFr())?></div></div>
        <p class="letter"><b>Objet : Recommandation de stage</b></p>
        <div class="recipient">
            À Monsieur le Médecin Directeur<br>
            <b><?=h($c['host_name']?:'Structure d’accueil')?></b><br>
            à <?=h($c['host_city']??($c['host_ville']??''))?>
        </div>
        <p class="letter">Monsieur le Médecin Directeur,</p>
        <p class="letter">Nous avons l’honneur de vous recommander l’étudiant(e) <b><?=h($c['student_name'])?></b>, matricule <b><?=h($c['matricule'])?></b>, régulièrement inscrit(e) en <b><?=h($c['promotion'])?></b>, pour effectuer un stage dans votre établissement.</p>
        <p class="letter">Ce stage concerne la session <b><?=h($c['campaign_title']?:$c['campaign_code'])?></b>, pour la période allant du <b><?=h(stagiaDocDateFr($c['period_start']))?></b> au <b><?=h(stagiaDocDateFr($c['period_end']))?></b>, au service <b><?=h($c['unit_name']?:'—')?></b>.</p>
        <p class="letter">Nous vous saurions gré de bien vouloir lui réserver un bon accueil et de lui permettre de bénéficier de l’encadrement nécessaire pour la réussite de ce stage.</p>
        <p class="letter">Veuillez agréer, Monsieur le Médecin Directeur, l’expression de nos sentiments distingués.</p>
    </div>
    <div class="signature"><?=docSignBlock($brand,'Autorité académique')?></div>

<?php else: ?>
    <!-- Bloc d'identification commun aux fiches de présence, d'appréciation et au rapport. -->
    <div class="section"><div class="section-title">I. Identification</div>
        <div class="grid">
            <div class="field"><b>Stagiaire</b> <?=h($c['student_name'])?></div><div class="field"><b>Matricule</b> <?=h($c['matricule'])?></div>
            <div class="field"><b>Promotion</b> <?=h($c['promotion'])?></div><div class="field"><b>Type de stage</b> <?=h($c['stage_type_label']?:'—')?></div>
            <div class="field"><b>Structure</b> <?=h($c['host_name']?:'—')?></div><div class="field"><b>Service</b> <?=h($c['unit_name']?:'—')?></div>
            <div class="field"><b>Période</b> <?=h(stagiaDocDateFr($c['period_start']))?> → <?=h(stagiaDocDateFr($c['period_end']))?></div><div class="field"><b>Session</b> <?=h(($c['campaign_code']?:'').' — '.($c['campaign_title']?:''))?></div>
        </div>
    </div>
<?php endif; ?>

<?php if($type==='presence'): ?>
    <!-- Modèle 1 : indicateurs de présence et détail des pointages disponibles. -->
    <div class="section"><div class="section-title">II. Présences enregistrées</div>
        <div class="summary" style="margin-bottom:8px"><div class="box"><small>Total</small><strong><?=h($as['total'])?></strong></div><div class="box"><small>Présences</small><strong><?=h($as['present'])?></strong></div><div class="box"><small>Retards</small><strong><?=h($as['retard'])?></strong></div><div class="box"><small>Absences</small><strong><?=h($as['absent'])?></strong></div></div>
        <table class="table"><thead><tr><th>Date</th><th>Arrivée</th><th>Départ</th><th>Statut</th><th>Observation</th><th>Validation</th></tr></thead><tbody>
        <?php if($att): foreach($att as $a): ?><tr><td><?=h(stagiaDocDateFr($a['date_presence']??''))?></td><td><?=h($a['heure_arrivee']??'—')?></td><td><?=h($a['heure_depart']??'—')?></td><td><?=h($a['statut']??'—')?></td><td><?=h($a['observation']??($a['justification']??''))?></td><td><?=(!empty($a['validated_at'])||!empty($a['validated_by']))?'Validé':'—'?></td></tr><?php endforeach; else: for($i=0;$i<12;$i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; endif; ?>
        </tbody></table>
    </div>
    <div class="signature"><?=docSignBlock($brand,'Nom et signature de l’encadreur')?></div>

<?php elseif($type==='appreciation'): ?>
    <!-- Modèle 2 : évaluation structurée par groupes de compétences. -->
    <div class="section"><div class="section-title">II. Évaluation</div>
        <?php if($ev): ?>
        <?php $groups=buildAppreciationGroups($scores); ?>
        <div class="summary" style="margin-bottom:8px"><div class="box"><small>Statut</small><strong><?=h($ev['statut']??'—')?></strong></div><div class="box"><small>Total</small><strong><?=h(stagiaDocNum($ev['note_finale']??null))?> /100</strong></div><div class="box"><small>Moyenne</small><strong><?=h(stagiaDocNum(isset($ev['note_finale'])?((float)$ev['note_finale']/5):null))?> /20</strong></div><div class="box"><small>Date</small><strong><?=h(stagiaDocDateFr($ev['validated_at']??($ev['finalized_at']??($ev['created_at']??''))))?></strong></div></div>
        <?php if($scores): ?>
        <!-- Les lignes sont regroupées par catégorie afin de rendre le bulletin plus lisible. -->
        <table class="table"><tbody>
            <?php foreach($groups as $g): ?>
                <tr><th colspan="3" style="background:#f8fafc;font-size:13px"><?=h($g['key'])?>. <?=h($g['title'])?></th></tr>
                <?php $n=1; foreach($g['items'] as $s): ?>
                    <tr>
                        <td><?=h($n++)?>. <?=h(scoreLabel($s,$n-1))?></td>
                        <td style="width:120px;text-align:right;font-weight:700"><?=h(scoreValue($s))?><?=scoreMax($s)!==''?' / '.h(scoreMax($s)):''?></td>
                        <td><?=h(scoreComment($s))?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <tr>
                <th style="text-align:right">TOTAL</th>
                <th style="text-align:right"><?=h(stagiaDocNum($ev['note_finale']??null))?> /100</th>
                <th></th>
            </tr>
            <tr>
                <th style="text-align:right">MOYENNE</th>
                <th style="text-align:right"><?=h(stagiaDocNum(isset($ev['note_finale'])?((float)$ev['note_finale']/5):null))?> /20</th>
                <th></th>
            </tr>
        </tbody></table>
        <?php else: ?><!-- Lignes vierges prévues quand aucun score détaillé n'a été saisi. --><div class="lines"></div><div class="lines"></div><div class="lines"></div><?php endif; ?>
        <div class="section"><div class="section-title">III. Remarques éventuelles</div><div class="field"><b>Appréciation</b> <?=h($ev['appreciation']??'—')?></div><div class="field"><b>Points forts</b> <?=h($ev['points_forts']??'—')?></div><div class="field"><b>Axes d’amélioration</b> <?=h($ev['axes_amelioration']??'—')?></div></div>
        <div class="section"><div class="grid"><div class="field"><b>Fait à</b> <?=h($c['host_city']??($c['host_ville']??''))?></div><div class="field"><b>Le</b> <?=h(stagiaDocDateFr($ev['validated_at']??($ev['finalized_at']??($ev['created_at']??date('Y-m-d')))))?></div></div></div>
        <?php else: ?><div class="lines"></div><div class="lines"></div><div class="lines"></div><?php endif; ?>
    </div>
    <div class="signature"><?=docSignBlock($brand,'Nom et signature de l’encadreur')?></div>

<?php elseif($type==='rapport'): ?>
    <!-- Modèle 3 : synthèse finale de l'assiduité, du journal, de l'évaluation et de la décision. -->
    <div class="section"><div class="section-title">II. Synthèse finale</div>
        <div class="summary"><div class="box"><small>Présence</small><strong><?=h($as['presence_rate']!==null?stagiaDocNum($as['presence_rate']).' %':'—')?></strong></div><div class="box"><small>Journaux validés</small><strong><?=h($logs['valides'])?> / <?=h($logs['total'])?></strong></div><div class="box"><small>Note finale</small><strong><?=h($result['final_score']??($ev['note_finale']??'—'))?><?=($result||$ev)?' /100':''?></strong></div><div class="box"><small>Décision</small><strong><?=h($result['decision']??'—')?></strong></div></div>
    </div>
    <div class="section"><div class="section-title">III. Détails du parcours</div>
        <table class="table"><tbody>
            <tr><th>Présences pointées</th><td><?=h($as['total'])?> jour(s), dont <?=h($as['present'])?> présent(s), <?=h($as['retard'])?> retard(s), <?=h($as['absent'])?> absence(s).</td></tr>
            <tr><th>Journaux de stage</th><td><?=h($logs['valides'])?> validé(s), <?=h($logs['soumis'])?> soumis, <?=h($logs['rejetes'])?> rejeté(s), total <?=h($logs['total'])?>.</td></tr>
            <tr><th>Évaluation</th><td><?= $ev?'Statut '.h($ev['statut']).', note '.h(stagiaDocNum($ev['note_finale']??null)).' /100':'Aucune évaluation validée enregistrée.' ?></td></tr>
            <tr><th>Transmission</th><td><?= $result?('Résultat '.$result['transmission_statut'].' — transmis le '.stagiaDocDateFr($result['transmitted_at']??'')):'Résultat non transmis.' ?></td></tr>
        </tbody></table>
    </div>
    <div class="section"><div class="section-title">IV. Appréciation générale</div><div class="decision"><?=h($result['appreciation']??($ev['appreciation']??'Rapport généré sur base des données disponibles dans STAGIA-RDC.'))?></div></div>
    <div class="signature"><?=docSignBlock($brand,'Responsable autorisé')?></div>
<?php endif; ?>

    <!-- Pied de page commun, positionné en bas de la feuille A4. -->
    <div class="footer"><span>STAGIA-RDC</span><span><?=h($docFooter)?></span><span><?=h($brand['website']?:'www.stagia-rdc.cd')?></span></div>
</div></body></html>
