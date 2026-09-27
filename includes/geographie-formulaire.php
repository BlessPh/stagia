<?php
declare(strict_types=1);
require_once __DIR__.'/geographie.php';
$geoErreur='';$geoProvinces=[];
try{$geoProvinces=geoEnfants($pdo,null);}catch(Throwable $e){error_log('[GEOGRAPHIE FORMULAIRE] '.$e->getMessage());$geoErreur='Les localités sont momentanément indisponibles. Veuillez réessayer plus tard.';}
?>
<fieldset class="col-12" id="geo-adhesion" data-url="<?=htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8')?>/actions/geographie/options.php"
 data-niveau2="<?=old('geo_niveau2_id')?>" data-niveau3="<?=old('geo_niveau3_id')?>">
 <legend class="fs-6 fw-semibold">Localisation de l’établissement</legend>
 <div class="row g-3">
  <div class="col-md-4"><label for="geo-province" class="form-label">Province *</label>
   <select id="geo-province" name="geo_province_id" class="form-select" required>
    <option value="">Sélectionner une province…</option>
    <?php foreach($geoProvinces as $p): ?><option value="<?=(int)$p['id']?>" data-code="<?=htmlspecialchars($p['code'],ENT_QUOTES,'UTF-8')?>" <?=selected('geo_province_id',(string)$p['id'])?>><?=htmlspecialchars($p['nom'],ENT_QUOTES,'UTF-8')?></option><?php endforeach; ?>
   </select></div>
  <div class="col-md-4"><label for="geo-niveau2" class="form-label">Ville / territoire / commune</label>
   <select id="geo-niveau2" name="geo_niveau2_id" class="form-select" disabled><option value="">Choisissez d’abord la province</option></select></div>
  <div class="col-md-4"><label for="geo-niveau3" class="form-label">Commune / secteur / chefferie</label>
   <select id="geo-niveau3" name="geo_niveau3_id" class="form-select" disabled><option value="">Choisissez d’abord la ville ou le territoire</option></select></div>
  <div class="col-md-6" id="geo-ville-libre-zone"><label for="geo-ville-libre" id="geo-ville-libre-label" class="form-label">Localité non proposée (ville, territoire ou commune de Kinshasa)</label>
   <input id="geo-ville-libre" name="geo_ville_libre" class="form-control" maxlength="100" value="<?=old('geo_ville_libre')?>" aria-describedby="geo-aide"></div>
  <div class="col-md-6"><label for="geo-precision" class="form-label">Précision de localisation</label>
   <input id="geo-precision" name="geo_precision" class="form-control" maxlength="255" value="<?=old('geo_precision')?>" placeholder="Subdivision absente, quartier ou repère" aria-describedby="geo-aide"></div>
 </div>
 <p class="small text-muted mt-2" id="geo-aide">Si votre localité n’est pas proposée, renseignez-la dans les champs complémentaires. Elle sera vérifiée avec votre demande et ne sera pas ajoutée automatiquement au référentiel.</p>
 <p id="geo-etat" role="status" aria-live="polite" class="small text-danger"><?=htmlspecialchars($geoErreur,ENT_QUOTES,'UTF-8')?></p>
 <button type="button" class="btn btn-outline-dark btn-sm" id="geo-reessayer" hidden>Recharger les localités</button>
 <noscript><p>Vous pouvez choisir la province et préciser la ville et la subdivision par écrit. Activez JavaScript pour utiliser les listes dépendantes.</p></noscript>
</fieldset>
