(function(){
'use strict';
const base=window.STAGIA_BASE_URL||'';
const lien=document.querySelector('.sidebar-menu a[href$="/views/communication/index.php"]');
if(!lien)return;
let badge=lien.querySelector('.sidebar-communication-badge');
if(!badge){badge=document.createElement('b');badge.className='sidebar-communication-badge';badge.hidden=true;lien.appendChild(badge);}
async function actualiser(){
    try{
        const reponse=await fetch(base+'/actions/communication/compteur-notifications.php',{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',cache:'no-store'});
        if(!reponse.ok)return;
        const resultat=await reponse.json(),total=Number(resultat.data?.total||0);
        badge.textContent=total>99?'99+':String(total);badge.hidden=total<1;
        lien.title=total?`${total} élément${total>1?'s':''} non lu${total>1?'s':''}`:'Communication';
    }catch(_){/* Le compteur ne doit jamais bloquer les autres modules. */}
}
actualiser();
setInterval(()=>{if(!document.hidden)actualiser();},20000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)actualiser();});
window.addEventListener('stagia:communication-lue',actualiser);
})();
