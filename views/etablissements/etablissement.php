<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

$etablissementId=(int)currentEtablissementId($pdo);
if(!$etablissementId){
    http_response_code(403);
    exit('Aucun établissement associé à votre compte.');
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$stmt=$pdo->prepare("
    SELECT e.id,e.code,e.nom,e.logo,e.type_etablissement,e.email,e.telephone,
           e.adresse,e.province,e.ville,e.numero_agrement,e.statut,
           e.created_at,e.updated_at,
           COALESCE(t.libelle,e.type_etablissement) type_libelle
    FROM etablissements e
    LEFT JOIN establishment_types t ON t.code=e.type_etablissement
    WHERE e.id=?
    LIMIT 1
");
$stmt->execute([$etablissementId]);
$etablissement=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$etablissement){
    http_response_code(404);
    exit('Établissement introuvable.');
}

$academic=contextAcademicEnabled();
$host=contextHostEnabled();
$academicSettings=$_SESSION['academic_settings']??[];
$template=null;

if($academic){
    try{
        $stmt=$pdo->prepare("
            SELECT s.source_template_id,s.source_template_version,s.personnalise,
                   s.configuration_statut,t.nom template_nom
            FROM etablissement_academic_settings s
            LEFT JOIN academic_structure_templates t ON t.id=s.source_template_id
            WHERE s.etablissement_id=?
            LIMIT 1
        ");
        $stmt->execute([$etablissementId]);
        $template=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }catch(Throwable $e){
        $template=null;
    }
}

$stats=['users'=>0,'units'=>0,'departments'=>0,'programs'=>0,'students'=>0];
try{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM etablissement_users WHERE etablissement_id=?");
    $stmt->execute([$etablissementId]);$stats['users']=(int)$stmt->fetchColumn();

    if($academic){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM facultes WHERE etablissement_id=? AND actif=1");
        $stmt->execute([$etablissementId]);$stats['units']=(int)$stmt->fetchColumn();

        $stmt=$pdo->prepare("SELECT COUNT(*) FROM departements WHERE etablissement_id=? AND actif=1");
        $stmt->execute([$etablissementId]);$stats['departments']=(int)$stmt->fetchColumn();

        $stmt=$pdo->prepare("SELECT COUNT(*) FROM filieres WHERE etablissement_id=? AND actif=1");
        $stmt->execute([$etablissementId]);$stats['programs']=(int)$stmt->fetchColumn();

        $stmt=$pdo->prepare("
            SELECT COUNT(DISTINCT sp.id)
            FROM student_profiles sp
            JOIN academic_enrollments ae ON ae.student_id=sp.id
            WHERE ae.etablissement_id=?
        ");
        $stmt->execute([$etablissementId]);$stats['students']=(int)$stmt->fetchColumn();
    }elseif($host){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM host_units WHERE host_etablissement_id=? AND actif=1");
        $stmt->execute([$etablissementId]);$stats['units']=(int)$stmt->fetchColumn();
    }
}catch(Throwable $e){
    /* Les statistiques ne doivent jamais bloquer la fiche établissement. */
}

$role=$_SESSION['role_code']??'';
$canEdit=in_array($role,['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'],true);

$flash=$_SESSION['etablissement_flash']??null;
unset($_SESSION['etablissement_flash']);

function e($v):string{
    return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
}

function statusClass(string $status):string{
    return match($status){
        'ACTIF','VALIDE'=>'success',
        'SUSPENDU','REJETE'=>'danger',
        'EN_ATTENTE'=>'warning',
        default=>'secondary'
    };
}

$logoPath=trim((string)($etablissement['logo']??''));
$logoUrl=$logoPath!==''?BASE_URL.'/'.ltrim($logoPath,'/'):null;

$pageTitle='Mon établissement';
$activePage='etablissement';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Mon établissement</h1>
        <p>Informations officielles et configuration de votre structure dans STAGIA-RDC.</p>
    </div>
    <?php if($canEdit): ?>
        <a class="btn btn-light border" href="<?= BASE_URL ?>/views/etablissements/parametres-documents.php">
            <i class="bi bi-file-earmark-text me-1"></i> Paramètres documents
        </a>
    <?php endif; ?>
</div>

<?php if($flash): ?>
<div class="alert alert-<?= e($flash['type']??'success') ?> alert-dismissible fade show">
    <?= e($flash['message']??'') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">

<div class="col-xl-4">
    <div class="stagia-list-card h-100">
        <div class="p-4 text-center">
            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                 style="width:130px;height:130px;border:1px solid #e5e7eb;border-radius:20px;background:#fff;overflow:hidden">
                <?php if($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="Logo <?= e($etablissement['nom']) ?>"
                         style="width:100%;height:100%;object-fit:contain;padding:10px"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;font-size:3rem;color:#64748b">
                        <i class="bi bi-building"></i>
                    </div>
                <?php else: ?>
                    <i class="bi bi-building" style="font-size:3rem;color:#64748b"></i>
                <?php endif; ?>
            </div>

            <h4 class="mb-1"><?= e($etablissement['nom']) ?></h4>
            <div class="text-muted mb-3"><?= e($etablissement['type_libelle']) ?></div>

            <span class="badge text-bg-<?= statusClass((string)$etablissement['statut']) ?>">
                <?= e($etablissement['statut']) ?>
            </span>

            <hr>

            <div class="text-start small">
                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span class="text-muted">Code STAGIA</span>
                    <strong><?= e($etablissement['code']) ?></strong>
                </div>
                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span class="text-muted">N° agrément</span>
                    <strong><?= e($etablissement['numero_agrement']?:'—') ?></strong>
                </div>
                <div class="d-flex justify-content-between gap-3 py-2">
                    <span class="text-muted">Depuis</span>
                    <strong><?= $etablissement['created_at']?e(date('d/m/Y',strtotime($etablissement['created_at']))):'—' ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-xl-8">

    <div class="stagia-list-card mb-3">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <h5 class="mb-1"><i class="bi bi-info-circle me-2"></i>Informations institutionnelles</h5>
                <p class="text-muted mb-0">Coordonnées officielles utilisées dans STAGIA-RDC.</p>
            </div>
            <?php if($canEdit): ?>
                <button class="btn btn-primary-stagia" type="button" data-bs-toggle="collapse" data-bs-target="#editEtablissement">
                    <i class="bi bi-pencil-square me-1"></i> Modifier
                </button>
            <?php endif; ?>
        </div>

        <div class="p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <small class="text-muted d-block">E-mail institutionnel</small>
                    <strong><?= e($etablissement['email']?:'Non renseigné') ?></strong>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">Téléphone</small>
                    <strong><?= e($etablissement['telephone']?:'Non renseigné') ?></strong>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">Province</small>
                    <strong><?= e($etablissement['province']?:'Non renseignée') ?></strong>
                </div>
                <div class="col-md-6">
                    <small class="text-muted d-block">Ville / Territoire</small>
                    <strong><?= e($etablissement['ville']?:'Non renseigné') ?></strong>
                </div>
                <div class="col-12">
                    <small class="text-muted d-block">Adresse</small>
                    <strong><?= e($etablissement['adresse']?:'Non renseignée') ?></strong>
                </div>
            </div>
        </div>
    </div>

    <?php if($canEdit): ?>
    <div class="collapse mb-3" id="editEtablissement">
        <div class="stagia-list-card">
            <div class="p-4 border-bottom">
                <h5 class="mb-1">Modifier les informations</h5>
                <p class="text-muted mb-0">Le nom, le type, le code et la structure STAGIA restent administrés par la plateforme.</p>
            </div>

            <form action="<?= BASE_URL ?>/actions/etablissements/update-self.php"
                  method="POST" enctype="multipart/form-data" class="p-4">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Logo</label>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div id="logoPreviewBox" style="width:82px;height:82px;border:1px solid #dee2e6;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden">
                                <?php if($logoUrl): ?>
                                    <img id="logoPreview" src="<?= e($logoUrl) ?>" alt="" style="width:100%;height:100%;object-fit:contain;padding:5px">
                                <?php else: ?>
                                    <img id="logoPreview" alt="" style="display:none;width:100%;height:100%;object-fit:contain;padding:5px">
                                    <i id="logoFallback" class="bi bi-building fs-2 text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" name="logo" id="logoInput" class="form-control" accept=".png,.jpg,.jpeg,.webp">
                                <small class="text-muted">PNG, JPG/JPEG ou WEBP — 2 Mo maximum.</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail institutionnel</label>
                        <input type="email" name="email" class="form-control" value="<?= e($etablissement['email']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Téléphone</label>
                        <input name="telephone" class="form-control" value="<?= e($etablissement['telephone']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Province</label>
                        <input name="province" class="form-control" value="<?= e($etablissement['province']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Ville / Territoire</label>
                        <input name="ville" class="form-control" value="<?= e($etablissement['ville']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Adresse</label>
                        <textarea name="adresse" class="form-control" rows="2"><?= e($etablissement['adresse']) ?></textarea>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary-stagia px-4" type="submit">
                            <i class="bi bi-check-lg me-1"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="stagia-list-card">
        <div class="p-4 border-bottom">
            <h5 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Configuration STAGIA</h5>
            <p class="text-muted mb-0">Cette partie est définie par la plateforme et ne peut pas être modifiée ici.</p>
        </div>

        <div class="p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Profil</small>
                    <strong>
                        <?php
                        if($academic&&$host)echo 'Académique + accueil';
                        elseif($academic)echo 'Académique';
                        elseif($host)echo 'Accueil';
                        else echo 'Institutionnel';
                        ?>
                    </strong>
                </div>

                <?php if($academic): ?>
                <div class="col-md-4">
                    <small class="text-muted d-block">Modèle académique</small>
                    <strong><?= e($template['template_nom']??'Configuration STAGIA') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Version</small>
                    <strong><?= e($template['source_template_version']??'—') ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <hr>

            <div class="row g-3 text-center">
                <div class="col-6 col-md">
                    <div class="border rounded-3 p-3 h-100">
                        <strong class="fs-4 d-block"><?= $stats['users'] ?></strong>
                        <small class="text-muted">Utilisateurs</small>
                    </div>
                </div>

                <?php if($academic): ?>
                    <div class="col-6 col-md">
                        <div class="border rounded-3 p-3 h-100">
                            <strong class="fs-4 d-block"><?= $stats['units'] ?></strong>
                            <small class="text-muted">Unités académiques</small>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="border rounded-3 p-3 h-100">
                            <strong class="fs-4 d-block"><?= $stats['departments'] ?></strong>
                            <small class="text-muted">Départements</small>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="border rounded-3 p-3 h-100">
                            <strong class="fs-4 d-block"><?= $stats['programs'] ?></strong>
                            <small class="text-muted">Filières</small>
                        </div>
                    </div>
                    <div class="col-6 col-md">
                        <div class="border rounded-3 p-3 h-100">
                            <strong class="fs-4 d-block"><?= $stats['students'] ?></strong>
                            <small class="text-muted">Étudiants</small>
                        </div>
                    </div>
                <?php elseif($host): ?>
                    <div class="col-6 col-md">
                        <div class="border rounded-3 p-3 h-100">
                            <strong class="fs-4 d-block"><?= $stats['units'] ?></strong>
                            <small class="text-muted">Services / unités</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</div>

</main>

<script>
document.getElementById('logoInput')?.addEventListener('change',function(){
    const f=this.files[0];
    if(!f)return;

    const ext=f.name.split('.').pop().toLowerCase();
    if(!['png','jpg','jpeg','webp'].includes(ext)){
        alert('Format non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.');
        this.value='';
        return;
    }

    if(f.size>2*1024*1024){
        alert('Le logo ne doit pas dépasser 2 Mo.');
        this.value='';
        return;
    }

    const img=document.getElementById('logoPreview'),
          fallback=document.getElementById('logoFallback'),
          url=URL.createObjectURL(f);

    img.src=url;
    img.style.display='block';
    if(fallback)fallback.style.display='none';
    img.onload=()=>URL.revokeObjectURL(url);
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
