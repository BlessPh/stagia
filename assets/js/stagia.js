const STAGIA={

    async request(url,options={}){
        const headers={
            'X-Requested-With':'XMLHttpRequest',
            ...(options.headers||{})
        };

        const response=await fetch(url,{...options,headers});
        const text=await response.text();

        let data;

        try{
            data=JSON.parse(text);
        }catch(e){
            console.error('Réponse serveur :',text);
            throw new Error('Réponse serveur invalide.');
        }

        if(!response.ok || !data.success)
            throw new Error(data.message||'Une erreur est survenue.');

        return data;
    },

    post(url,form){
        const body=form instanceof HTMLFormElement
            ? new FormData(form)
            : form;

        return this.request(url,{
            method:'POST',
            body:body
        });
    },

    loading(btn,state=true){
        if(!btn) return;

        if(state){
            btn.dataset.oldHtml=btn.innerHTML;
            btn.disabled=true;
            btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Traitement...';
        }else{
            btn.disabled=false;
            btn.innerHTML=btn.dataset.oldHtml||'Enregistrer';
        }
    },

    toast(message,type='success'){
        let container=document.getElementById('stagiaToastContainer');

        if(!container){
            container=document.createElement('div');
            container.id='stagiaToastContainer';
            container.className='toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex='1090';
            document.body.appendChild(container);
        }

        const el=document.createElement('div');

        el.className=`toast align-items-center text-bg-${type} border-0`;
        el.innerHTML=`
            <div class="d-flex">
                <div class="toast-body">${this.escape(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>`;

        container.appendChild(el);

        const toast=new bootstrap.Toast(el,{delay:3500});
        toast.show();

        el.addEventListener('hidden.bs.toast',()=>el.remove());
    },

    escape(value=''){
        const div=document.createElement('div');
        div.textContent=value;
        return div.innerHTML;
    },

    confirm(message='Confirmer cette opération ?'){
        return window.confirm(message);
    }
};