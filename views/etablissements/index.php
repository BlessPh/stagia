<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';

$pageTitle='Établissements';
$activePage='etablissements';

$q=trim($_GET['q']??'');
$type=$_GET['type']??'';
$statut=$_GET['statut']??'';
$page=max(1,(int)($_GET['page']??1));
$limit=10;
$offset=($page-1)*$limit;

$types=['UNIVERSITE','INSTITUT_SUPERIEUR','ECOLE_PROFESSIONNELLE','CENTRE_FORMATION','ENTREPRISE','MINISTERE','HOPITAL','ONG','SOCIETE_PRIVEE','AUTRE'];
$statuts=['EN_ATTENTE','VALIDE','SUSPENDU','REJETE'];

if(!in_array($type,$types,true)) $type='';
if(!in_array($statut,$statuts,true)) $statut='';

$where=[];
$params=[];

if($q!==''){
    $where[]='(nom LIKE :q OR code LIKE :q OR ville LIKE :q OR province LIKE :q)';
    $params['q']="%$q%";
}

if($type!==''){
    $where[]='type_etablissement=:type';
    $params['type']=$type;
}

if($statut!==''){
    $where[]='statut=:statut';
    $params['statut']=$statut;
}

$sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';

$count=$pdo->prepare("SELECT COUNT(*) FROM etablissements$sqlWhere");
$count->execute($params);
$total=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($total/$limit));

$stmt=$pdo->prepare("SELECT * FROM etablissements$sqlWhere ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$etablissements=$stmt->fetchAll();

function libelleType($type){
    return match($type){
        'UNIVERSITE'=>'Université',
        'INSTITUT_SUPERIEUR'=>'Institut supérieur',
        'ECOLE_PROFESSIONNELLE'=>'École professionnelle',
        'CENTRE_FORMATION'=>'Centre de formation',
        'SOCIETE_PRIVEE'=>'Société privée',
        'MINISTERE'=>'Ministère',
        'HOPITAL'=>'Hôpital',
        'ENTREPRISE'=>'Entreprise',
        'ONG'=>'ONG',
        default=>'Autre'
    };
}

function badgeStatut($statut){
    return match($statut){
        'VALIDE'=>['success','Validé'],
        'EN_ATTENTE'=>['warning','En attente'],
        'SUSPENDU'=>['secondary','Suspendu'],
        'REJETE'=>['danger','Rejeté'],
        default=>['secondary',$statut]
    };
}

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
    <?php if(isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>Établissement enregistré avec succès.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="page-heading">
    <div>
        <h1>Établissements</h1>
        <p>Gestion des structures enregistrées sur STAGIA-RDC.</p>
    </div>

    <a href="create.php" class="btn btn-primary-stagia">
        <i class="bi bi-plus-lg me-1"></i> Nouvel établissement
    </a>
</div>

<div class="dashboard-card establishment-card">

    <div class="establishment-toolbar">
        <form method="GET" class="row g-2 w-100">

            <div class="col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Rechercher par nom, code, ville..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>

            <div class="col-md-4 col-lg-3">
                <select name="type" class="form-select">
                    <option value="">Tous les types</option>
                    <?php foreach($types as $t): ?>
                        <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= libelleType($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 col-lg-2">
                <select name="statut" class="form-select">
                    <option value="">Tous les statuts</option>
                    <option value="EN_ATTENTE" <?= $statut==='EN_ATTENTE'?'selected':'' ?>>En attente</option>
                    <option value="VALIDE" <?= $statut==='VALIDE'?'selected':'' ?>>Validé</option>
                    <option value="SUSPENDU" <?= $statut==='SUSPENDU'?'selected':'' ?>>Suspendu</option>
                    <option value="REJETE" <?= $statut==='REJETE'?'selected':'' ?>>Rejeté</option>
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
            <thead>
                <tr>
                    <th>Établissement</th>
                    <th>Type</th>
                    <th>Localisation</th>
                    <th>Contact</th>
                    <th>Statut</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if(!$etablissements): ?>

                <tr>
                    <td colspan="6">
                        <div class="table-empty">
                            <i class="bi bi-buildings"></i>
                            <strong>Aucun établissement trouvé</strong>
                            <span>Les structures enregistrées apparaîtront ici.</span>
                        </div>
                    </td>
                </tr>

            <?php else: foreach($etablissements as $e):
                [$badge,$label]=badgeStatut($e['statut']);
            ?>

                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="institution-avatar"><i class="bi bi-building"></i></div>
                            <div>
                                <strong class="table-main-text"><?= htmlspecialchars($e['nom']) ?></strong>
                                <small class="table-sub-text"><?= htmlspecialchars($e['code']) ?></small>
                            </div>
                        </div>
                    </td>

                    <td><?= libelleType($e['type_etablissement']) ?></td>

                    <td>
                        <?= htmlspecialchars($e['ville']?:'-') ?>
                        <?php if($e['province']): ?>
                            <small class="table-sub-text"><?= htmlspecialchars($e['province']) ?></small>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($e['telephone']?:'-') ?>
                        <?php if($e['email']): ?>
                            <small class="table-sub-text"><?= htmlspecialchars($e['email']) ?></small>
                        <?php endif; ?>
                    </td>

                    <td><span class="badge text-bg-<?= $badge ?>"><?= $label ?></span></td>

                    <td class="text-center">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>

                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="show.php?id=<?= $e['id'] ?>"><i class="bi bi-eye me-2"></i>Consulter</a></li>
                                <li><a class="dropdown-item" href="edit.php?id=<?= $e['id'] ?>"><i class="bi bi-pencil me-2"></i>Modifier</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#"><i class="bi bi-trash me-2"></i>Supprimer</a></li>
                            </ul>
                        </div>
                    </td>
                </tr>

            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer">
        <span>
            <?= $total?($offset+1):0 ?> à <?= min($offset+$limit,$total) ?> sur <?= $total ?> établissement<?= $total>1?'s':'' ?>
        </span>

        <?php if($pages>1): ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">

                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                    <a class="page-link" href="?<?= http_build_query(['q'=>$q,'type'=>$type,'statut'=>$statut,'page'=>$page-1]) ?>">&lsaquo;</a>
                </li>

                <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
                    <li class="page-item <?= $i===$page?'active':'' ?>">
                        <a class="page-link" href="?<?= http_build_query(['q'=>$q,'type'=>$type,'statut'=>$statut,'page'=>$i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                    <a class="page-link" href="?<?= http_build_query(['q'=>$q,'type'=>$type,'statut'=>$statut,'page'=>$page+1]) ?>">&rsaquo;</a>
                </li>

            </ul>
        </nav>
        <?php endif; ?>
    </div>

</div>
</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>