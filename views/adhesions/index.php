<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';

$pageTitle="Demandes d'adhésion";$activePage='adhesions';

$q=trim($_GET['q']??'');
$statut=array_key_exists('statut',$_GET)?trim($_GET['statut']):'SOUMISE';
$page=max(1,(int)($_GET['page']??1));$limit=10;$offset=($page-1)*$limit;

$statuts=['','SOUMISE','EN_EXAMEN','A_COMPLETER','VALIDEE','REJETEE'];
if(!in_array($statut,$statuts,true))$statut='SOUMISE';

$where=[];$params=[];
if($q!==''){$where[]="(reference LIKE :q OR nom_etablissement LIKE :q OR responsable_nom LIKE :q OR responsable_email LIKE :q)";$params['q']="%$q%";}
if($statut!==''){$where[]="statut=:statut";$params['statut']=$statut;}
$sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';

$s=$pdo->prepare("SELECT COUNT(*) FROM demandes_adhesion$sqlWhere");$s->execute($params);$total=(int)$s->fetchColumn();
$pages=max(1,(int)ceil($total/$limit));
$s=$pdo->prepare("SELECT * FROM demandes_adhesion$sqlWhere ORDER BY id DESC LIMIT $limit OFFSET $offset");$s->execute($params);$demandes=$s->fetchAll();

function e($v){return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');}
function badgeAdhesion($s){return match($s){
    'SOUMISE'=>['primary','En attente'],'EN_EXAMEN'=>['info','En examen'],'A_COMPLETER'=>['warning','À compléter'],
    'VALIDEE'=>['success','Validée'],'REJETEE'=>['danger','Rejetée'],default=>['secondary',$s]
};}

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
<div class="page-heading">
    <div><h1>Demandes d'adhésion</h1><p>Examiner les établissements souhaitant rejoindre STAGIA-RDC.</p></div>
    <span class="date-badge"><i class="bi bi-inbox me-1"></i><?= $total ?> demande<?= $total>1?'s':'' ?></span>
</div>

<div class="dashboard-card establishment-card">
<div class="establishment-toolbar">
<form method="GET" class="row g-2 w-100">
    <div class="col-lg-7"><div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input name="q" class="form-control" placeholder="Référence, établissement, responsable..." value="<?= e($q) ?>">
    </div></div>

    <div class="col-md-8 col-lg-3">
        <select name="statut" class="form-select">
            <option value="SOUMISE" <?= $statut==='SOUMISE'?'selected':'' ?>>En attente</option>
            <option value="EN_EXAMEN" <?= $statut==='EN_EXAMEN'?'selected':'' ?>>En examen</option>
            <option value="A_COMPLETER" <?= $statut==='A_COMPLETER'?'selected':'' ?>>À compléter</option>
            <option value="VALIDEE" <?= $statut==='VALIDEE'?'selected':'' ?>>Validées</option>
            <option value="REJETEE" <?= $statut==='REJETEE'?'selected':'' ?>>Rejetées</option>
            <option value="" <?= $statut===''?'selected':'' ?>>Tous les statuts</option>
        </select>
    </div>

    <div class="col-md-4 col-lg-2 d-flex gap-2">
        <button class="btn btn-primary-stagia flex-fill"><i class="bi bi-funnel"></i></button>
        <a href="index.php" class="btn btn-light border"><i class="bi bi-arrow-clockwise"></i></a>
    </div>
</form>
</div>

<div class="table-responsive">
<table class="table stagia-table align-middle mb-0">
<thead><tr><th>Référence</th><th>Établissement</th><th>Responsable</th><th>Localisation</th><th>Date</th><th>Statut</th><th class="text-center">Actions</th></tr></thead>
<tbody>
<?php if(!$demandes): ?>
<tr><td colspan="7"><div class="table-empty"><i class="bi bi-inbox"></i><strong>Aucune demande en attente</strong><span>Les nouvelles demandes apparaîtront ici.</span></div></td></tr>
<?php else: foreach($demandes as $d):[$badge,$label]=badgeAdhesion($d['statut']); ?>
<tr>
    <td><strong class="table-main-text"><?= e($d['reference']) ?></strong></td>
    <td><strong class="table-main-text"><?= e($d['nom_etablissement']) ?></strong><small class="table-sub-text"><?= e($d['type_etablissement']) ?></small></td>
    <td><?= e(trim(($d['responsable_prenom']??'').' '.$d['responsable_nom'])) ?><small class="table-sub-text"><?= e($d['responsable_email']) ?></small></td>
    <td><?= e($d['ville']?:'-') ?><small class="table-sub-text"><?= e($d['province']?:'') ?></small></td>
    <td><?= date('d/m/Y',strtotime($d['created_at'])) ?></td>
    <td><span class="badge text-bg-<?= $badge ?>"><?= $label ?></span></td>
    <td class="text-center"><a href="show.php?id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="table-footer">
    <span><?= $total?($offset+1):0 ?> à <?= min($offset+$limit,$total) ?> sur <?= $total ?></span>
    <?php if($pages>1): ?><nav><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(['q'=>$q,'statut'=>$statut,'page'=>$page-1]) ?>">&lsaquo;</a></li>
        <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
        <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(['q'=>$q,'statut'=>$statut,'page'=>$i]) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(['q'=>$q,'statut'=>$statut,'page'=>$page+1]) ?>">&rsaquo;</a></li>
    </ul></nav><?php endif; ?>
</div>
</div>
</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>