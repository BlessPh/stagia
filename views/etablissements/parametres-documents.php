<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-branding.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);

$etablissementId=(int)currentEtablissementId($pdo);
if(!$etablissementId){http_response_code(403);exit('Aucun établissement associé à votre compte.');}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$stmt=$pdo->prepare("SELECT * FROM etablissements WHERE id=? LIMIT 1");
$stmt->execute([$etablissementId]);
$e=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$e){http_response_code(404);exit('Établissement introuvable.');}

$brand=stagiaDocumentBrand($pdo,$etablissementId);
$flash=$_SESSION['document_settings_flash']??null;unset($_SESSION['document_settings_flash']);
function v(array $e,string $k,string $d=''):string{return htmlspecialchars((string)($e[$k]??$d),ENT_QUOTES,'UTF-8');}
function fileUrl(?string $path):string{return stagiaDocPublicUrl($path);}

$pageTitle='Paramètres documents';
$activePage='stage-documents-settings';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.doc-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.doc-preview{border:1px solid #e5eaf0;border-radius:14px;background:#fff;padding:18px}.doc-file-box{width:120px;height:86px;border:1px solid #e5eaf0;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden}.doc-file-box img{width:100%;height:100%;object-fit:contain;padding:6px}.doc-mini-paper{border:1px solid #e5eaf0;border-radius:14px;padding:16px;background:#fff;max-width:520px}.doc-mini-head{display:flex;gap:14px;align-items:center;border-bottom:2px solid #f97316;padding-bottom:12px}.doc-mini-logo{width:64px;height:50px;object-fit:contain}.doc-mini-title{text-align:center;font-weight:800;margin:18px auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px;max-width:340px}.doc-mini-lines{height:9px;background:#eef2f7;border-radius:20px;margin:9px 0}.doc-mini-sign{text-align:right;margin-top:24px}.doc-mini-sign span{display:inline-block;width:160px;border-top:1px solid #334155;padding-top:6px}@media(max-width:900px){.doc-settings-grid{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Paramètres documents</h1><p>Logo, cachet, signature et textes utilisés dans les documents officiels.</p></div>
    <a class="btn btn-light border" href="<?=BASE_URL?>/views/etablissements/etablissement.php"><i class="bi bi-arrow-left me-1"></i>Retour</a>
</div>

<?php if($flash): ?><div class="alert alert-<?=v($flash,'type','success')?> alert-dismissible fade show"><?=v($flash,'message')?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="alert alert-light border"><i class="bi bi-info-circle me-1 text-primary"></i> Cette page n’écrase pas les documents existants. Elle ajoute seulement des paramètres utilisés lors des prochains affichages/impressions.</div>

<form action="<?=BASE_URL?>/actions/etablissements/update-document-settings.php" method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
<div class="doc-settings-grid">
    <div class="stagia-list-card">
        <div class="p-4 border-bottom"><h5 class="mb-1">Textes institutionnels</h5><small class="text-muted">Ces libellés apparaissent dans l’entête et la signature des documents.</small></div>
        <div class="p-4 row g-3">
            <div class="col-12"><label class="form-label">Secrétariat / service émetteur</label><input class="form-control" name="document_secretariat" value="<?=v($e,'document_secretariat','SECRÉTARIAT GÉNÉRAL À LA RECHERCHE')?>"></div>
            <div class="col-md-6"><label class="form-label">Faculté</label><input class="form-control" name="document_faculte" value="<?=v($e,'document_faculte','FACULTÉ DE MÉDECINE')?>"></div>
            <div class="col-md-6"><label class="form-label">Département / service</label><input class="form-control" name="document_departement" value="<?=v($e,'document_departement')?>"></div>
            <div class="col-md-6"><label class="form-label">Fonction du signataire</label><input class="form-control" name="document_signataire_fonction" value="<?=v($e,'document_signataire_fonction','Autorité académique')?>"></div>
            <div class="col-md-6"><label class="form-label">Nom du signataire</label><input class="form-control" name="document_signataire_nom" value="<?=v($e,'document_signataire_nom')?>"></div>
            <div class="col-12"><label class="form-label">Slogan</label><input class="form-control" name="document_slogan" value="<?=v($e,'document_slogan','Former aujourd’hui pour un Congo meilleur demain')?>"></div>
            <div class="col-12"><label class="form-label">Texte pied de page</label><input class="form-control" name="document_footer" value="<?=v($e,'document_footer','Stages pour un avenir meilleur')?>"></div>
        </div>
    </div>

    <div class="stagia-list-card">
        <div class="p-4 border-bottom"><h5 class="mb-1">Cachet et signature</h5><small class="text-muted">Facultatif. PNG/JPG/WEBP, 2 Mo maximum.</small></div>
        <div class="p-4">
            <div class="mb-4">
                <label class="form-label fw-semibold">Signature officielle</label>
                <div class="d-flex gap-3 align-items-center flex-wrap"><div class="doc-file-box"><?php if(!empty($e['document_signature'])): ?><img src="<?=v(['x'=>fileUrl($e['document_signature'])],'x')?>"><?php else: ?><i class="bi bi-pen fs-2 text-muted"></i><?php endif; ?></div><div class="flex-grow-1"><input type="file" name="document_signature" class="form-control" accept=".png,.jpg,.jpeg,.webp"><small class="text-muted">Laissez vide pour garder la signature actuelle.</small></div></div>
            </div>
            <div>
                <label class="form-label fw-semibold">Cachet</label>
                <div class="d-flex gap-3 align-items-center flex-wrap"><div class="doc-file-box"><?php if(!empty($e['document_cachet'])): ?><img src="<?=v(['x'=>fileUrl($e['document_cachet'])],'x')?>"><?php else: ?><i class="bi bi-patch-check fs-2 text-muted"></i><?php endif; ?></div><div class="flex-grow-1"><input type="file" name="document_cachet" class="form-control" accept=".png,.jpg,.jpeg,.webp"><small class="text-muted">Laissez vide pour garder le cachet actuel.</small></div></div>
            </div>
        </div>
    </div>
</div>

<div class="stagia-list-card mt-3">
    <div class="p-4 border-bottom"><h5 class="mb-1">Aperçu rapide</h5><small class="text-muted">Aperçu indicatif de l’entête utilisée sur les documents.</small></div>
    <div class="p-4">
        <div class="doc-mini-paper">
            <div class="doc-mini-head"><img class="doc-mini-logo" src="<?=stagiaDocEsc($brand['logo_url'])?>" alt=""><div><strong><?=stagiaDocEsc($brand['name'])?></strong><br><small class="text-muted"><?=stagiaDocEsc($brand['address'])?></small></div></div>
            <div class="text-center mt-3"><strong><?=v($e,'document_secretariat','SECRÉTARIAT GÉNÉRAL À LA RECHERCHE')?></strong><br><span><?=v($e,'document_faculte','FACULTÉ DE MÉDECINE')?></span></div>
            <div class="doc-mini-title">TITRE DU DOCUMENT</div><div class="doc-mini-lines"></div><div class="doc-mini-lines" style="width:85%"></div><div class="doc-mini-lines" style="width:75%"></div><div class="doc-mini-sign"><strong><?=v($e,'document_signataire_fonction','Autorité académique')?></strong><br><span><?=v($e,'document_signataire_nom','Nom, signature et cachet')?></span></div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-3"><a href="<?=BASE_URL?>/views/etablissements/etablissement.php" class="btn btn-light border">Annuler</a><button class="btn btn-primary-stagia px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
</form>
</main>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
