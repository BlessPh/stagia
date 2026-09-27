(() => {
 'use strict';
 const box=document.getElementById('geo-adhesion');if(!box)return;
 const province=document.getElementById('geo-province'),n2=document.getElementById('geo-niveau2'),n3=document.getElementById('geo-niveau3');
 const libre=document.getElementById('geo-ville-libre'),precision=document.getElementById('geo-precision'),zone=document.getElementById('geo-ville-libre-zone'),status=document.getElementById('geo-etat'),retry=document.getElementById('geo-reessayer');
 let generation=0,controller=null,loadedProvince='',loadedN2='';
 const reset=(select,text)=>{select.replaceChildren(new Option(text,''));select.disabled=true;};
 function complement(){zone.hidden=!!n2.value;libre.disabled=!!n2.value;libre.required=!n2.value;precision.required=!!n2.value&&!n3.value&&n2.selectedOptions[0]?.dataset.type!=='COMMUNE';document.getElementById('geo-ville-libre-label').textContent=province.selectedOptions[0]?.dataset.code==='RDC-P-KINSHASA'?'Commune de Kinshasa non proposée':'Ville ou territoire non proposé';}
 async function options(select,parent,selected,signal){
  const url=new URL(box.dataset.url,location.href);url.searchParams.set('parent_id',parent);
  const r=await fetch(url,{headers:{Accept:'application/json'},signal});const j=await r.json();
  if(!r.ok||!j.success)throw new Error(j.message||'Chargement impossible.');
  reset(select,j.data.items.length?'Sélectionner (ou préciser ci-dessous)…':'Aucune subdivision renseignée');
  for(const item of j.data.items){const option=new Option(`${item.nom} — ${item.type.toLowerCase()}`,String(item.id));option.dataset.type=item.type;select.add(option);}
  select.disabled=!j.data.items.length;
  if(selected&&j.data.items.some(item=>String(item.id)===selected))select.value=selected;
 }
 async function load(keep2='',keep3=''){
  const current=++generation;controller?.abort();controller=new AbortController();const signal=controller.signal;
  loadedProvince='';loadedN2='';retry.hidden=true;status.textContent='';
  reset(n2,'Chargement…');reset(n3,'Choisissez d’abord la ville ou le territoire');complement();
  if(!province.value){reset(n2,'Choisissez d’abord la province');return;}
  box.setAttribute('aria-busy','true');status.textContent='Chargement des localités…';
  try{
   await options(n2,province.value,keep2,signal);if(current!==generation)return;
   loadedProvince=province.value;
   if(n2.value){await options(n3,n2.value,keep3,signal);if(current!==generation)return;loadedN2=n2.value;}
   status.textContent=n2.options.length===1?'Le référentiel de cette province est à compléter. Renseignez la ville ou le territoire ci-dessous.':'';
  }catch(e){if(e.name!=='AbortError'&&current===generation){status.textContent='Impossible de charger les localités. Vous pouvez réessayer ou préciser votre localisation par écrit.';retry.hidden=false;}}
  finally{if(current===generation){box.setAttribute('aria-busy','false');complement();}}
 }
 province.addEventListener('change',()=>load());
 n2.addEventListener('change',()=>load(n2.value));
 n3.addEventListener('change',complement);
 retry.addEventListener('click',()=>load(n2.value,n3.value));
 // Empêche qu'un changement de province rapide envoie les enfants de l'ancienne sélection.
 box.closest('form').addEventListener('submit',()=>{
  if(loadedProvince!==province.value){n2.disabled=true;n3.disabled=true;}
  else if(loadedN2!==n2.value)n3.disabled=true;
 });
 load(box.dataset.niveau2,box.dataset.niveau3);
})();
