/* STAGIA — Bibliothèque numérique. Aucun contenu utilisateur inséré sans échappement. */
(() => {
 'use strict';
 const root=document.getElementById('bibliotheque');if(!root)return;
 const base=root.dataset.base,csrf=root.dataset.csrf,$=id=>document.getElementById(id);
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const views=['catalogue','favoris','depots','validation','gestion','archives','corbeille'];
 const state={tab:views.includes(location.hash.slice(1))?location.hash.slice(1):'catalogue',page:1,data:null,request:0};
 const labels={brouillon:'Brouillon',soumis:'En validation',publie:'Publié',rejete:'À corriger',archive:'Archivé',corbeille:'Corbeille'};
 const size=n=>(Number(n)/1048576).toFixed(1)+' Mo';
 const status=m=>{$('bib-status').textContent=m;};
 async function request(url,options={}){
   const r=await fetch(base+'/actions/bibliotheque/'+url,{credentials:'same-origin',cache:'no-store',...options});
   if(r.redirected)throw Error('Session expirée. Reconnectez-vous.');
   let j;try{j=await r.json();}catch{throw Error('Réponse serveur invalide. Vérifiez votre session ou la migration SQL.');}
   if(!r.ok||!j.success)throw Error(j.message||'Opération impossible.');
   return j;
 }
 async function load(){
   const seq=++state.request,params=new URLSearchParams(new FormData($('bib-filtres')));
   params.set('onglet',state.tab);params.set('page',state.page);
   $('bib-resultats').setAttribute('aria-busy','true');status('Chargement…');
   try{
     const {data}=await request('api.php?'+params);if(seq!==state.request)return;
     state.data=data;
     if(!$('bib-categories').dataset.loaded){
       $('bib-categories').insertAdjacentHTML('beforeend',data.categories.map(x=>'<option value="'+Number(x.id)+'">'+esc(x.nom)+'</option>').join(''));
       $('bib-categories').dataset.loaded='1';
     }
     $('bib-validation').hidden=!data.gestion;
     $('bib-validation').textContent='Espace de validation ('+Number(data.compteurs.soumis)+')';
     $('bib-gestion').hidden=!data.gestion;
     $('bib-validation-info').hidden=!data.gestion||!['gestion','validation'].includes(state.tab);
     $('bib-compteurs').innerHTML=['soumis','publie','rejete','archive'].map(s=>'<span>'+esc(labels[s])+' : <strong>'+Number(data.compteurs[s])+'</strong></span>').join('');
     $('bib-resultats').innerHTML=data.items.length?data.items.map(d=>`<article class="bib-card">
       <span class="bib-badge">${esc(d.categorie)}</span><h2>${esc(d.titre)}</h2>
       <small>${esc(d.auteur)} · ${esc(d.annee||'Année non renseignée')}</small>
       <p>${esc(d.resume.slice(0,130))}${d.resume.length>130?'…':''}</p>
       <small>${esc(labels[d.statut])} · ${d.visibilite==='globale'?'Tout STAGIA':esc(d.etablissement_nom||'Établissement')} · ${size(d.taille)}</small>
       <small>Déposé par ${esc(d.deposant)} · ${Number(d.telechargement_autorise)?'Téléchargement autorisé':'Lecture dans STAGIA'}</small>
       <small>${Number(d.lectures)} ouvertures · ${Number(d.telechargements)} téléchargements</small>
       <div class="bib-card-actions"><button data-open="${Number(d.id)}">Consulter</button>
       ${d.statut==='publie'?`<button data-fav="${Number(d.id)}" aria-label="Favori" aria-pressed="${!!Number(d.favori)}">${Number(d.favori)?'★':'☆'}</button>`:''}</div></article>`).join(''):
       '<div class="bib-empty"><h2>Aucun document ici</h2><p>Essayez une autre recherche ou proposez une ressource.</p></div>';
     status(data.total+' document(s)');$('bib-page').textContent='Page '+state.page+' / '+Math.max(1,Math.ceil(data.total/12));
     $('bib-prev').disabled=state.page===1;$('bib-next').disabled=state.page*12>=data.total;
   }catch(e){if(seq===state.request){status(e.message);$('bib-resultats').innerHTML='';}}
   finally{if(seq===state.request)$('bib-resultats').setAttribute('aria-busy','false');}
 }
 function dialog(title,html){$('bib-dialog-title').textContent=title;$('bib-dialog-body').innerHTML=html;if(!$('bib-dialog').open)$('bib-dialog').showModal();}
 async function action(id,name,extra={}){
   const form=new FormData();Object.entries({id,action:name,csrf,...extra}).forEach(([k,v])=>form.set(k,v));
   return request('api.php',{method:'POST',body:form});
 }
 async function detail(id){
   try{
     const {data:{document:d}}=await request('api.php?id='+id);
     const buttons=[];
     const btn=(name,label)=>buttons.push(`<button data-operation="${name}" data-id="${Number(id)}" data-revision="${Number(d.revision)}">${label}</button>`);
     if(d.modifiable)buttons.push('<button id="bib-edit">Modifier la notice</button>');
     if(['brouillon','rejete'].includes(d.statut))btn('soumettre','Soumettre à validation');
     if(d.proprietaire&&d.statut==='soumis')btn('retirer','Retirer pour correction');
     if(d.gestion&&d.statut==='soumis'){btn('publier','Valider et publier');btn('rejeter','Demander une correction');}
     if(d.regle_telechargement)buttons.push(`<button data-operation="telechargement" data-id="${Number(id)}" data-revision="${Number(d.revision)}" data-autorise="${Number(d.telechargement_autorise)?0:1}">${Number(d.telechargement_autorise)?'Interdire':'Autoriser'} le téléchargement</button>`);
     if(d.gestion&&d.statut==='publie')btn('archiver','Archiver');
     if(d.gestion&&d.statut!=='corbeille'||d.modifiable&&['brouillon','rejete'].includes(d.statut))btn('supprimer','Mettre à la corbeille');
     if(['archive','corbeille'].includes(d.statut))btn('restaurer','Restaurer en brouillon');
     const url=base+'/actions/bibliotheque/fichier.php?id='+Number(id);
     dialog(d.titre,`<p class="bib-meta">${esc(d.auteur)} · ${esc(d.langue)} · ${esc(d.annee||'')} · ${esc(labels[d.statut])}</p>
       <p class="bib-summary">${esc(d.resume)}</p><p>Mots-clés : ${esc(d.mots_cles||'—')}</p>
       <p>Droits de diffusion : ${esc(d.licence)}</p>
       <p>Public après validation : <strong>${d.visibilite==='globale'?'Tous les utilisateurs STAGIA':'Les membres de l’établissement concerné'}</strong>.</p>
       <p>${Number(d.telechargement_autorise)?'Téléchargement autorisé par le déposant.':'Téléchargement désactivé par le déposant. La lecture reste accessible aux personnes autorisées.'}</p>
       ${d.motif?'<p class="bib-message">Motif : '+esc(d.motif)+'</p>':''}
       <div class="bib-details-actions">${buttons.join('')}</div><p id="bib-detail-status" role="status"></p>
       ${d.statut!=='corbeille'?`${d.telechargeable?'<p><a href="'+esc(url)+'&telecharger=1">Télécharger ('+size(d.taille)+')</a></p>':''}
       <iframe class="bib-preview" title="Lecture du document" src="${esc(url)}"></iframe>`:''}
       ${d.historique.length?'<details class="bib-history"><summary>Historique des décisions (50 dernières actions)</summary><ol>'+d.historique.map(h=>{
         const names={depot:'Dépôt',soumettre:'Soumission',publier:'Validation et publication',rejeter:'Correction demandée',retirer:'Soumission retirée',modification:'Notice modifiée',archiver:'Archivage',supprimer:'Mise à la corbeille',restaurer:'Restauration',droit_telechargement:'Droit de téléchargement modifié'};
         let info={};try{info=typeof h.details==='string'?JSON.parse(h.details):h.details;}catch{}
         return '<li>'+esc(names[h.action]||h.action)+'<small>'+esc(h.acteur)+' · '+esc(h.cree_le)+'</small>'+(info?.motif?'<p>'+esc(info.motif)+'</p>':'')+(h.action==='droit_telechargement'?'<small>'+ (Number(info?.autorise)?'Autorisé':'Interdit')+'</small>':'')+'</li>';
       }).join('')+'</ol></details>':''}`);
     if($('bib-edit'))$('bib-edit').onclick=()=>edit(d);
     await action(id,'notification_lue');await notifications(false);
   }catch(e){status(e.message);}
 }
 function edit(d={}){
   if(!state.data){status('Attendez le chargement du catalogue.');return;}
   const input=(name,label,max=255,type='text')=>`<label>${label}<input name="${name}" type="${type}" maxlength="${max}" value="${esc(d[name]||'')}" ${['titre','auteur','licence'].includes(name)?'required':''}></label>`;
   dialog(d.id?'Modifier la notice':'Proposer un document',`<form id="bib-form"><div class="bib-form-grid">
    ${input('titre','Titre')}${input('auteur','Auteur(s)',200)}
    <label>Catégorie<select name="categorie_id" required>${state.data.categories.map(x=>`<option value="${Number(x.id)}" ${Number(d.categorie_id)===Number(x.id)?'selected':''}>${esc(x.nom)}</option>`).join('')}</select></label>
    ${input('annee','Année',4,'number')}
    <label>Langue<input name="langue" value="${esc(d.langue||'Français')}" maxlength="50" required></label>
    ${input('mots_cles','Mots-clés séparés par des virgules',500)}
    <label class="bib-full">Résumé<textarea name="resume" rows="4" maxlength="10000" required>${esc(d.resume||'')}</textarea></label>
    ${input('licence','Licence / autorisation de diffusion')}
    <label>Diffusion<select name="visibilite"><option value="globale">Tous les utilisateurs STAGIA</option>
    <option value="etablissement" ${d.visibilite==='etablissement'?'selected':''}>Mon établissement</option></select></label>
    <label>Établissement<select name="etablissement_id"><option value="">Sélectionner…</option>${state.data.organisations.map(x=>`<option value="${Number(x.id)}" ${Number(d.etablissement_id)===Number(x.id)?'selected':''}>${esc(x.nom)}</option>`).join('')}</select></label>
    ${!d.id?'<label class="bib-full">Fichier (20 Mo maximum)<input name="fichier" type="file" accept=".pdf,.txt,.jpg,.jpeg,.png,.webp" required></label><label class="bib-full bib-check"><input type="checkbox" name="droits" value="1" required><span>Je dispose des droits nécessaires pour partager ce document.</span></label>':''}
    ${!d.id?'<label class="bib-full bib-check"><input type="checkbox" name="telechargement_autorise" value="1"><span>J’autorise le téléchargement par les lecteurs autorisés. Je pourrai retirer cette autorisation depuis la fiche.</span></label>':''}
    </div><p id="bib-form-status" class="bib-message" role="status"></p>
    <div class="bib-form-actions"><button class="bib-primary">Enregistrer en brouillon</button><small> Une validation est nécessaire avant publication.</small></div></form>`);
   const f=$('bib-form'),org=f.elements.etablissement_id,scope=f.elements.visibilite;
   function scopeChange(){org.disabled=scope.value==='globale';org.required=!org.disabled;}
   scope.onchange=scopeChange;scopeChange();
   f.onsubmit=async e=>{
     e.preventDefault();const submit=f.querySelector('button');submit.disabled=true;
     const body=new FormData(f);body.set('csrf',csrf);body.set('action',d.id?'modifier':'deposer');if(d.id){body.set('id',d.id);body.set('revision',d.revision);}
     try{const r=await request('api.php',{method:'POST',body});$('bib-dialog').close();state.tab=d.id&&d.gestion?'gestion':'depots';state.page=1;tabs();await load();status(r.message);}
     catch(err){$('bib-form-status').textContent=err.message;}finally{submit.disabled=false;}
   };
 }
 function tabs(){
   root.querySelectorAll('[data-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.tab===state.tab)));
   const active=['gestion','depots'].includes(state.tab);
   $('bib-etat-label').hidden=!active;$('bib-filtres').elements.etat.disabled=!active;
   history.replaceState(null,'','#'+state.tab);
 }
 root.addEventListener('click',async e=>{
   const b=e.target.closest('button');if(!b)return;
   if(b.dataset.tab){state.tab=b.dataset.tab;state.page=1;if(state.tab==='catalogue')$('bib-filtres').reset();tabs();load();}
   if(b.dataset.open)detail(b.dataset.open);
   if(b.dataset.fav){b.disabled=true;try{await action(b.dataset.fav,'favori');await load();}catch(err){status(err.message);}finally{b.disabled=false;}}
   if(b.dataset.operation){
     let motif='';if(b.dataset.operation==='rejeter'){motif=prompt('Motif de correction :');if(motif===null)return;}
     if(!confirm('Confirmer cette opération ?'))return;
     b.disabled=true;try{const result=await action(b.dataset.id,b.dataset.operation,{motif,revision:b.dataset.revision,autorise:b.dataset.autorise||'0'});$('bib-dialog').close();if(b.dataset.operation==='publier'){state.tab='catalogue';state.page=1;$('bib-filtres').reset();tabs();}await load();status(b.dataset.operation==='publier'?'Document publié dans le catalogue partagé. '+Number(result.data.notifies)+' notification(s) ciblée(s).':'Opération enregistrée.');}
     catch(err){$('bib-detail-status').textContent=err.message;}finally{b.disabled=false;}
   }
 });
 $('bib-fermer').onclick=()=>$('bib-dialog').close();
 async function notifications(open=false){
   try{
     const {data}=await request('api.php?action=notifications');
     $('bib-alertes').textContent='Notifications ('+data.items.filter(x=>!x.lue_le).length+')';
     if(open)dialog('Nouvelles publications',data.items.length?data.items.map(x=>'<article class="bib-card"><h3>'+esc(x.titre)+'</h3><small>'+esc(x.cree_le)+(x.lue_le?' · Lue':' · Nouvelle')+'</small><button data-open="'+Number(x.document_id)+'">Ouvrir le document</button></article>').join(''):'<p>Aucune notification de publication accessible.</p>');
   }catch(e){if(open)status(e.message);}
 }
 $('bib-alertes').onclick=()=>notifications(true);
 $('bib-reset').onclick=()=>{$('bib-filtres').reset();state.page=1;load();};
 $('bib-deposer').onclick=()=>edit();
 $('bib-filtres').onsubmit=e=>{e.preventDefault();state.page=1;load();};
 $('bib-prev').onclick=()=>{state.page--;load();};$('bib-next').onclick=()=>{state.page++;load();};
 tabs();load().then(()=>{const id=Number(new URLSearchParams(location.search).get('document'));if(id>0)detail(id);});notifications();
 setInterval(()=>{if(!document.hidden){notifications();if(state.tab==='catalogue'&&!$('bib-dialog').open)load();}},30000);
})();
