(() => {
 'use strict';
 const app=document.getElementById('geo-gestion');if(!app)return;
 const el=id=>document.getElementById(id),form=el('geo-form'),dialog=el('geo-dialog');
 let parent='',state=null,request=0,busy=false,editing=false;
 const labels={PROVINCE:'Province',VILLE:'Ville',TERRITOIRE:'Territoire',COMMUNE:'Commune',SECTEUR:'Secteur',CHEFFERIE:'Chefferie'};
 const button=(text,run)=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.addEventListener('click',run);return b;};
 async function api(url,options){const r=await fetch(url,options);if(r.redirected)throw new Error('Session expirée. Reconnectez-vous.');const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'Opération impossible.');return j;}
 async function load(next=parent){
  const ticket=++request;el('geo-status').textContent='Chargement…';el('geo-ajouter').disabled=true;
  const url=new URL(app.dataset.url,location.href);if(next)url.searchParams.set('parent_id',next);
  try{
   const j=await api(url,{headers:{Accept:'application/json'}});if(ticket!==request)return;
   parent=String(next);state=j.data;el('geo-status').textContent='';el('geo-ajouter').disabled=!state.types.length;
   const nav=el('geo-chemin');nav.replaceChildren(button('RDC',()=>load('')));
   for(const r of state.chemin)nav.append(button(r.nom,()=>load(String(r.id))));
   el('geo-recherche').value='';render();
   el('geo-incomplets').textContent=`${state.a_completer} demande(s) avec une localisation à compléter. Consultez les précisions dans les fiches d’adhésion.`;
   el('geo-journal').replaceChildren();for(const r of state.journal){const li=document.createElement('li');li.textContent=`${r.cree_le} — ${r.localite} : ${r.action} (${r.acteur||'compte supprimé'})`;el('geo-journal').append(li);}
  }catch(e){if(ticket===request){state=null;el('geo-liste').replaceChildren();el('geo-status').textContent=e instanceof SyntaxError?'Réponse inattendue. Vérifiez votre session et l’installation.':e.message;}}
 }
 function render(){
  if(!state)return;const q=el('geo-recherche').value.toLocaleLowerCase('fr');const rows=state.items.filter(r=>r.nom.toLocaleLowerCase('fr').includes(q));
  const body=el('geo-liste');body.replaceChildren();
  for(const r of rows){const tr=document.createElement('tr');for(const value of [r.nom,labels[r.type],Number(r.actif)?'Active':'Désactivée']){const td=document.createElement('td');td.textContent=value;tr.append(td);}
   const actions=document.createElement('td');if(['PROVINCE','VILLE','TERRITOIRE'].includes(r.type))actions.append(button('Subdivisions',()=>load(String(r.id))));actions.append(button('Modifier',()=>edit(r)));tr.append(actions);body.append(tr);}
  el('geo-total').textContent=rows.length?`${rows.length} localité(s) affichée(s).`:'Aucune localité à ce niveau. Ajoutez une subdivision vérifiée.';
 }
 function edit(row=null){
  if(!state)return;editing=!!row;form.reset();el('geo-form-status').textContent='';
  form.elements.id.value=row?.id||'';form.elements.parent_id.value=row?row.parent_id||'':parent;form.elements.version.value=row?.version||'';
  const type=form.elements.type;type.replaceChildren();for(const t of row?[row.type]:state.types)type.add(new Option(labels[t],t));type.disabled=editing;
  form.elements.nom.value=row?.nom||'';form.elements.source.value=row?.source||'';form.elements.actif.value=String(row?.actif??1);
  el('geo-titre').textContent=row?'Modifier la localité':'Ajouter une localité';el('geo-parent-info').textContent='Parent : '+(state.chemin.map(r=>r.nom).join(' / ')||'RDC');
  dialog.showModal();form.elements.nom.focus();
 }
 el('geo-ajouter').addEventListener('click',()=>edit());el('geo-recherche').addEventListener('input',render);el('geo-refresh').addEventListener('click',()=>load());
 el('geo-annuler').addEventListener('click',()=>{if(!busy)dialog.close();});dialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();});
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(busy)return;busy=true;const data=new FormData(form);data.set('type',form.elements.type.value);data.set('csrf',app.dataset.csrf);
  const controls=[...form.elements];for(const c of controls)c.disabled=true;el('geo-form-status').textContent='Enregistrement…';
  try{const j=await api(app.dataset.url,{method:'POST',body:data,headers:{Accept:'application/json'}});dialog.close();await load();if(state)el('geo-status').textContent=j.message;}
  catch(err){el('geo-form-status').textContent=err instanceof SyntaxError?'Réponse inattendue. Reconnectez-vous puis vérifiez la fiche avant de réessayer.':err.message;}
  finally{busy=false;for(const c of controls)c.disabled=false;form.elements.type.disabled=editing;}
 });
 load();
})();
