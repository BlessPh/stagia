<?php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);


/* =========================================================
   CSRF
========================================================= */

if(empty($_SESSION['csrf'])){
    $_SESSION['csrf']=bin2hex(
        random_bytes(32)
    );
}


$pageTitle='Mes documents';
$activePage='student-documents';

require_once __DIR__.'/../../includes/app-header.php';

?>


<style>

/* =========================================================
   DOCUMENTS OFFICIELS
========================================================= */

.document-card{
    background:#fff;
    border:1px solid #e1e7ee;
    border-radius:13px;
    padding:18px;
    margin-bottom:14px;
    transition:.2s
}

.document-card:hover{
    box-shadow:0 5px 18px rgba(0,0,0,.05)
}

.document-icon{
    width:50px;
    height:50px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    background:#fff1e8;
    color:#ef6c00;
    flex-shrink:0
}

.document-info{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-top:16px
}

.document-box{
    border:1px solid #e5eaf0;
    border-radius:9px;
    padding:10px 12px
}

.document-box small{
    display:block;
    color:#64748b;
    margin-bottom:4px
}

.document-box strong{
    font-size:13px;
    color:#334155
}

.document-reference{
    font-family:monospace;
    color:#ef6c00;
    font-weight:700
}


/* =========================================================
   ESPACE PERSONNEL
========================================================= */

.personal-space{
    margin-top:20px
}

.personal-space-info{
    display:flex;
    align-items:center;
    gap:12px
}

.personal-space-icon{
    width:42px;
    height:42px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eef6ff;
    color:#0757a3;
    font-size:20px;
    flex-shrink:0
}

.personal-file{
    display:flex;
    align-items:center;
    gap:10px
}

.personal-file-icon{
    width:38px;
    height:38px;
    border-radius:8px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0
}

.personal-file-title{
    font-weight:600;
    color:#334155;
    font-size:13px
}

.personal-file-name{
    color:#8996a3;
    font-size:10px;
    margin-top:2px;
    max-width:260px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis
}

.personal-empty{
    padding:38px 20px;
    text-align:center;
    color:#718096
}

.personal-empty i{
    font-size:34px;
    display:block;
    margin-bottom:8px
}


/* =========================================================
   UPLOAD
========================================================= */

.upload-zone{
    border:2px dashed #dbe3ea;
    border-radius:11px;
    padding:20px;
    text-align:center;
    background:#fafcfd;
    transition:.2s
}

.upload-zone:hover{
    border-color:#0757a3;
    background:#f6faff
}

.upload-zone i{
    font-size:28px;
    color:#0757a3
}

.upload-zone p{
    font-size:11px;
    color:#718096;
    margin:6px 0 12px
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:900px){

    .document-info{
        grid-template-columns:repeat(2,1fr)
    }

}

@media(max-width:560px){

    .document-info{
        grid-template-columns:1fr
    }

    .stagia-list-toolbar{
        align-items:stretch!important
    }

}

</style>



<main class="dashboard-content">


<!-- =========================================================
     EN-TÊTE
========================================================= -->

<div class="stagia-page-head">

    <div>

        <h1>
            Mes documents
        </h1>

        <p>
            Consultez vos documents officiels et conservez
            vos documents personnels dans votre espace STAGIA-RDC.
        </p>

    </div>

</div>



<!-- =========================================================
     KPI
========================================================= -->

<div class="stagia-kpi-grid">


    <!-- DOCUMENTS OFFICIELS -->

    <div class="stagia-kpi-card">

        <div>

            <span>
                DOCUMENTS OFFICIELS
            </span>

            <strong id="statTotal">
                0
            </strong>

            <small>
                Documents générés
            </small>

        </div>

        <div class="stagia-kpi-icon kpi-blue">

            <i class="bi bi-files"></i>

        </div>

    </div>


    <!-- ATTESTATIONS -->

    <div class="stagia-kpi-card">

        <div>

            <span>
                ATTESTATIONS
            </span>

            <strong id="statAttestations">
                0
            </strong>

            <small>
                Attestations de stage
            </small>

        </div>

        <div class="stagia-kpi-icon kpi-green">

            <i class="bi bi-patch-check"></i>

        </div>

    </div>


    <!-- CERTIFICATS -->

    <div class="stagia-kpi-card">

        <div>

            <span>
                CERTIFICATS
            </span>

            <strong id="statCertificates">
                0
            </strong>

            <small>
                Certificats disponibles
            </small>

        </div>

        <div class="stagia-kpi-icon kpi-purple">

            <i class="bi bi-award"></i>

        </div>

    </div>


    <!-- CONVENTIONS -->

    <div class="stagia-kpi-card">

        <div>
            <span>CONVENTIONS</span>
            <strong id="statConventions">0</strong>
            <small>Conventions signées</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-file-earmark-sign"></i>
        </div>

    </div>


    <!-- PERSONNELS -->

    <div class="stagia-kpi-card">

        <div>

            <span>
                MES FICHIERS
            </span>

            <strong id="statPersonal">
                0
            </strong>

            <small>
                Documents personnels
            </small>

        </div>

        <div class="stagia-kpi-icon kpi-orange">

            <i class="bi bi-folder2-open"></i>

        </div>

    </div>


</div>



<!-- =========================================================
     DOCUMENTS OFFICIELS
========================================================= -->

<div class="stagia-list-card">


    <div class="stagia-list-toolbar">


        <div>

            <h5 class="mb-1">

                <i class="bi bi-patch-check me-1"></i>

                Documents officiels

            </h5>

            <small class="text-muted">

                Attestations, certificats et conventions signées disponibles dans STAGIA-RDC.

            </small>

        </div>


        <div style="max-width:320px;width:100%">

            <div class="input-group">

                <span class="input-group-text
                             bg-white
                             border-end-0">

                    <i class="bi bi-search"></i>

                </span>

                <input
                    type="search"
                    id="search"
                    class="form-control border-start-0"
                    placeholder="Rechercher un document..."
                >

            </div>

        </div>


    </div>



    <div id="documentContainer">

        <div class="text-center py-5">

            <div class="spinner-border
                        spinner-border-sm
                        me-2">
            </div>

            Chargement des documents...

        </div>

    </div>


</div>



<!-- =========================================================
     ESPACE PERSONNEL
========================================================= -->

<div class="stagia-list-card personal-space">


    <div class="stagia-list-toolbar">


        <div class="personal-space-info">


            <div class="personal-space-icon">

                <i class="bi bi-folder2-open"></i>

            </div>


            <div>

                <h5 class="mb-1">
                    Mon espace personnel
                </h5>

                <small class="text-muted">

                    Conservez ici vos documents utiles :
                    identité, documents académiques,
                    documents de stage ou administratifs.

                </small>

            </div>


        </div>



        <button
            type="button"
            class="btn btn-primary-stagia"
            id="btnAddDocument"
        >

            <i class="bi bi-cloud-arrow-up me-1"></i>

            Ajouter un document

        </button>


    </div>



    <div id="personalDocuments">

        <div class="text-center py-5">

            <div class="spinner-border
                        spinner-border-sm
                        me-2">
            </div>

            Chargement de votre espace...

        </div>

    </div>


</div>


</main>



<!-- =========================================================
     MODAL AJOUT DOCUMENT PERSONNEL
========================================================= -->

<div
    class="modal fade"
    id="personalDocumentModal"
    tabindex="-1"
>


<div class="modal-dialog modal-dialog-centered">


<div class="modal-content border-0 shadow">


<form
    id="personalDocumentForm"
    enctype="multipart/form-data"
>


    <!-- HEADER -->

    <div class="modal-header">


        <div>

            <h5 class="modal-title">
                Ajouter un document
            </h5>

            <small class="text-muted">

                Le document sera conservé
                dans votre espace personnel.

            </small>

        </div>


        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal">
        </button>


    </div>



    <!-- BODY -->

    <div class="modal-body">


        <input
            type="hidden"
            name="csrf"
            value="<?= htmlspecialchars(
                $_SESSION['csrf'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >



        <!-- TITRE -->

        <div class="mb-3">


            <label class="form-label">

                Titre du document
                <span class="text-danger">*</span>

            </label>


            <input
                type="text"
                name="titre"
                id="personalDocumentTitle"
                class="form-control"
                maxlength="180"
                placeholder="Ex. Carte d'étudiant"
                required
            >


        </div>



        <!-- CATÉGORIE -->

        <div class="mb-3">


            <label class="form-label">
                Catégorie
            </label>


            <select
                name="categorie"
                class="form-select"
            >

                <option value="IDENTITE">
                    Identité
                </option>

                <option value="ACADEMIQUE">
                    Académique
                </option>

                <option value="STAGE">
                    Stage
                </option>

                <option value="ADMINISTRATIF">
                    Administratif
                </option>

                <option
                    value="AUTRE"
                    selected
                >
                    Autre
                </option>

            </select>


        </div>



        <!-- FICHIER -->

        <div class="upload-zone">


            <i class="bi bi-cloud-arrow-up"></i>


            <p>

                Sélectionnez un fichier
                PDF, JPG, PNG, DOC ou DOCX.

                <br>

                Taille maximale :
                <strong>8 Mo</strong>

            </p>


            <input
                type="file"
                name="document"
                id="personalDocumentFile"
                class="form-control"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                required
            >


        </div>


    </div>



    <!-- FOOTER -->

    <div class="modal-footer">


        <button
            type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal"
        >

            Annuler

        </button>


        <button
            type="submit"
            id="personalDocumentSave"
            class="btn btn-primary-stagia"
        >

            <i class="bi bi-cloud-arrow-up me-1"></i>

            Enregistrer

        </button>


    </div>


</form>


</div>
</div>
</div>



<script>

document.addEventListener(
    'DOMContentLoaded',
    ()=>{


/* =========================================================
   CONFIGURATION
========================================================= */

const BASE_URL=
    '<?= BASE_URL ?>';


const CSRF=
    '<?= $_SESSION['csrf'] ?>';


const $=
    id=>
        document.getElementById(id);


let officialItems=[];

let personalItems=[];



/* =========================================================
   MODAL
========================================================= */

const personalModal=
    new bootstrap.Modal(
        $('personalDocumentModal')
    );


const personalForm=
    $('personalDocumentForm');



/* =========================================================
   CHARGEMENT GLOBAL
========================================================= */

async function chargerTout(){


    await Promise.allSettled([

        chargerDocumentsOfficiels(),

        chargerDocumentsPersonnels()

    ]);

}



/* =========================================================
   DOCUMENTS OFFICIELS
========================================================= */

async function chargerDocumentsOfficiels(){


    try{


        const [r,c]=await Promise.all([
            STAGIA.request(
                BASE_URL+
                '/actions/etudiants/student-documents-list.php'
            ),
            STAGIA.request(
                BASE_URL+
                '/actions/etudiants/student-convention-list.php'
            )
        ]);


        officialItems=[
            ...(r.data.items||[]),
            ...(c.data.items||[])
        ];


        /* KPI */

        $('statTotal').textContent=
            officialItems.length;


        $('statAttestations').textContent=

            officialItems.filter(

                x=>
                    x.type_document===
                    'ATTESTATION_STAGE'

            ).length;


        $('statCertificates').textContent=

            officialItems.filter(

                x=>
                    x.type_document===
                    'CERTIFICAT_STAGE'

            ).length;


        $('statConventions').textContent=

            officialItems.filter(

                x=>
                    x.type_document===
                    'CONVENTION_STAGE'

            ).length;


        afficherDocumentsOfficiels();


    }catch(e){


        $('documentContainer').innerHTML=`

            <div class="text-center
                        py-5
                        text-danger">

                <i class="bi bi-exclamation-circle
                          fs-3
                          d-block
                          mb-2">
                </i>

                ${STAGIA.escape(
                    e.message
                )}

            </div>
        `;

    }

}



/* =========================================================
   RECHERCHE OFFICIELS
========================================================= */

function afficherDocumentsOfficiels(){


    const q=
        $('search')
        .value
        .trim()
        .toLowerCase();


    const list=
        officialItems.filter(
            x=>{


                if(!q)
                    return true;


                return [

                    x.reference,

                    x.campaign_code,

                    x.campaign_title,

                    x.host_name,

                    x.unit_name,
                    x.stage_type_label,
                    x.title,

                    documentLabel(
                        x.type_document
                    )

                ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(q);

            }
        );


    renderDocumentsOfficiels(
        list
    );

}



/* =========================================================
   RENDER OFFICIELS
========================================================= */

function renderDocumentsOfficiels(
    list
){


    if(!list.length){


        $('documentContainer').innerHTML=`

            <div class="text-center
                        py-5
                        text-muted">

                <i class="bi bi-file-earmark
                          fs-1
                          d-block
                          mb-3">
                </i>

                <h5>
                    Aucun document officiel
                </h5>

                <p class="mb-0">

                    Vos attestations et certificats
                    apparaîtront ici après validation
                    de vos stages.

                </p>

            </div>
        `;


        return;
    }


    $('documentContainer').innerHTML=

        `<div class="p-3">`

        +

        list.map(
            x=>{


                const isConvention=
                    x.type_document==='CONVENTION_STAGE';

                const active=
                    x.statut==='GENERE';

                const fileUrl=
                    isConvention
                    ?BASE_URL+
                        '/actions/stages/convention-file.php?token='+
                        encodeURIComponent(x.uuid)
                    :BASE_URL+
                        '/actions/stages/certificate-pdf.php?token='+
                        encodeURIComponent(x.uuid);

                const firstLabel=
                    isConvention
                    ?'Type de stage'
                    :'Service';

                const firstValue=
                    isConvention
                    ?(x.stage_type_label||'-')
                    :(x.unit_name||'-');

                const thirdLabel=
                    isConvention
                    ?'Version'
                    :'Note finale';

                const thirdValue=
                    isConvention
                    ?('v'+Number(x.version||1))
                    :(
                        x.note_finale!==null
                        &&
                        x.note_finale!==undefined
                       ?Number(x.note_finale).toLocaleString('fr-FR',{
                        minimumFractionDigits:2,
                        maximumFractionDigits:2
                    })+' / 20'
                        :'-'
                    );


                return `

                <div class="document-card">


                    <div class="d-flex
                                justify-content-between
                                align-items-start
                                gap-3
                                flex-wrap">


                        <div class="d-flex gap-3">


                            <div class="document-icon">

                                <i class="${
                                    documentIcon(
                                        x.type_document
                                    )
                                }"></i>

                            </div>


                            <div>


                                <div class="small
                                            text-muted
                                            mb-1">

                                    ${
                                        STAGIA.escape(
                                            documentLabel(
                                                x.type_document
                                            )
                                        )
                                    }

                                </div>


                                <h5 class="mb-1">

                                    ${
                                        STAGIA.escape(
                                            x.campaign_title
                                            ||'-'
                                        )
                                    }

                                </h5>


                                <div class="small
                                            text-muted">

                                    <i class="bi
                                              bi-hospital
                                              me-1">
                                    </i>

                                    ${
                                        STAGIA.escape(
                                            x.host_name
                                            ||'-'
                                        )
                                    }

                                </div>


                                <div class="document-reference
                                            mt-2">

                                    ${
                                        STAGIA.escape(
                                            x.reference
                                            ||'-'
                                        )
                                    }

                                </div>


                            </div>


                        </div>



                        <div class="text-end">


                            ${
                                active

                                ?`

                                <span class="badge bg-success">

                                    <i class="bi
                                              bi-check-circle
                                              me-1">
                                    </i>

                                    VALIDE

                                </span>

                                `

                                :`

                                <span class="badge bg-secondary">

                                    ANNULÉ

                                </span>

                                `
                            }


                            ${
                                active

                                ?`

                                <div class="mt-2">


                                    <a
                                        href="${fileUrl}"

                                        target="_blank"

                                        class="btn
                                               btn-sm
                                               btn-outline-primary"
                                    >

                                        <i class="bi bi-eye me-1"></i>

                                        Voir

                                    </a>


                                    <a
                                        href="${fileUrl}&download=1"

                                        class="btn
                                               btn-sm
                                               btn-outline-secondary"
                                    >

                                        <i class="bi
                                                  bi-download
                                                  me-1">
                                        </i>

                                        Télécharger

                                    </a>


                                </div>

                                `

                                :''
                            }


                        </div>


                    </div>



                    <div class="document-info">


                        <div class="document-box">

                            <small>
                                ${firstLabel}
                            </small>

                            <strong>
                                ${STAGIA.escape(firstValue)}
                            </strong>

                        </div>


                        <div class="document-box">

                            <small>
                                Période
                            </small>

                            <strong>

                                ${formatDate(
                                    x.date_debut
                                )}

                                →

                                ${formatDate(
                                    x.date_fin
                                )}

                            </strong>

                        </div>


                        <div class="document-box">

                            <small>
                                ${thirdLabel}
                            </small>

                            <strong>
                                ${STAGIA.escape(String(thirdValue))}
                            </strong>

                        </div>


                        <div class="document-box">

                            <small>
                                Date d'émission
                            </small>

                            <strong>

                                ${formatDate(
                                    x.generated_at
                                )}

                            </strong>

                        </div>


                    </div>


                </div>

                `;

            }
        ).join('')

        +

        `</div>`;

}



/* =========================================================
   DOCUMENTS PERSONNELS
========================================================= */

async function chargerDocumentsPersonnels(){


    const container=
        $('personalDocuments');


    try{


        const r=
            await STAGIA.request(

                BASE_URL+
                '/actions/etudiants/student-personal-document-list.php'

            );


        personalItems=
            r.data.items||[];


        $('statPersonal').textContent=
            personalItems.length;


        afficherDocumentsPersonnels();


    }catch(e){


        container.innerHTML=`

            <div class="text-center
                        py-5
                        text-danger">

                <i class="bi
                          bi-exclamation-circle
                          fs-3
                          d-block
                          mb-2">
                </i>

                ${
                    STAGIA.escape(
                        e.message
                    )
                }

            </div>
        `;

    }

}



/* =========================================================
   RENDER PERSONNELS
========================================================= */

function afficherDocumentsPersonnels(){


    const container=
        $('personalDocuments');


    if(!personalItems.length){


        container.innerHTML=`

            <div class="personal-empty">

                <i class="bi bi-folder2"></i>

                <h6>
                    Votre espace personnel est vide
                </h6>

                <p class="mb-0">

                    Ajoutez vos documents pour
                    les retrouver facilement dans
                    votre compte STAGIA-RDC.

                </p>

            </div>
        `;


        return;
    }


    container.innerHTML=`

        <div class="table-responsive">


            <table class="table
                          stagia-modern-table
                          align-middle
                          mb-0">


                <thead>

                <tr>

                    <th>
                        DOCUMENT
                    </th>

                    <th>
                        CATÉGORIE
                    </th>

                    <th>
                        TAILLE
                    </th>

                    <th>
                        AJOUTÉ LE
                    </th>

                    <th class="text-center">
                        ACTIONS
                    </th>

                </tr>

                </thead>


                <tbody>


                ${
                    personalItems.map(
                        x=>`

                        <tr>


                            <td>


                                <div class="personal-file">


                                    <div class="personal-file-icon">

                                        <i class="${
                                            fileIcon(
                                                x.extension
                                            )
                                        }"></i>

                                    </div>


                                    <div>


                                        <div class="personal-file-title">

                                            ${
                                                STAGIA.escape(
                                                    x.titre
                                                    ||'Document'
                                                )
                                            }

                                        </div>


                                        <div class="personal-file-name">

                                            ${
                                                STAGIA.escape(
                                                    x.nom_original
                                                    ||''
                                                )
                                            }

                                        </div>


                                    </div>


                                </div>


                            </td>



                            <td>


                                <span class="badge
                                             bg-light
                                             text-dark
                                             border">

                                    ${
                                        STAGIA.escape(
                                            categorieLabel(
                                                x.categorie
                                            )
                                        )
                                    }

                                </span>


                            </td>



                            <td>

                                ${
                                    formatSize(
                                        x.taille
                                    )
                                }

                            </td>



                            <td>

                                ${
                                    formatDate(
                                        x.created_at
                                    )
                                }

                            </td>



                            <td class="text-center">


                                <!-- VOIR -->

                                <a
                                    href="${
                                        BASE_URL
                                    }/actions/etudiants/student-personal-document-file.php?token=${
                                        encodeURIComponent(
                                            x.uuid
                                        )
                                    }"

                                    target="_blank"

                                    class="btn
                                           btn-sm
                                           btn-outline-primary"

                                    title="Voir"
                                >

                                    <i class="bi bi-eye"></i>

                                </a>


                                <!-- TÉLÉCHARGER -->

                                <a
                                    href="${
                                        BASE_URL
                                    }/actions/etudiants/student-personal-document-file.php?token=${
                                        encodeURIComponent(
                                            x.uuid
                                        )
                                    }&download=1"

                                    class="btn
                                           btn-sm
                                           btn-outline-secondary"

                                    title="Télécharger"
                                >

                                    <i class="bi bi-download"></i>

                                </a>


                                <!-- SUPPRIMER -->

                                <button
                                    type="button"

                                    class="btn
                                           btn-sm
                                           btn-outline-danger
                                           btn-delete-personal"

                                    data-token="${
                                        STAGIA.escape(
                                            x.uuid
                                        )
                                    }"

                                    title="Supprimer"
                                >

                                    <i class="bi bi-trash"></i>

                                </button>


                            </td>


                        </tr>

                        `
                    ).join('')
                }


                </tbody>


            </table>


        </div>
    `;


    /* BOUTONS SUPPRIMER */

    container
        .querySelectorAll(
            '.btn-delete-personal'
        )
        .forEach(
            btn=>{

                btn.onclick=
                    ()=>supprimerDocumentPersonnel(
                        btn.dataset.token
                    );

            }
        );

}



/* =========================================================
   OUVRIR MODAL
========================================================= */

$('btnAddDocument').onclick=
    ()=>{


        personalForm.reset();


        personalModal.show();


        setTimeout(
            ()=>{

                $('personalDocumentTitle')
                    .focus();

            },
            300
        );

    };



/* =========================================================
   UPLOAD
========================================================= */

/* =========================================================
   UPLOAD DOCUMENT PERSONNEL
========================================================= */

personalForm.addEventListener(
    'submit',
    async e=>{

        e.preventDefault();

        const btn=
            document.getElementById(
                'personalDocumentSave'
            );

        const formData=
            new FormData(
                personalForm
            );

        const file=
            formData.get(
                'document'
            );


        if(
            !file ||
            !file.name
        ){

            STAGIA.toast(
                'Veuillez sélectionner un fichier.',
                'danger'
            );

            return;
        }


        if(
            file.size >
            8*1024*1024
        ){

            STAGIA.toast(
                'Le fichier ne peut pas dépasser 8 Mo.',
                'danger'
            );

            return;
        }


        STAGIA.loading(
            btn,
            true
        );


        try{

            const response=
                await fetch(

                    BASE_URL+
                    '/actions/etudiants/student-personal-document-upload.php',

                    {
                        method:'POST',

                        body:formData,

                        credentials:'same-origin',

                        headers:{
                            'X-Requested-With':
                                'XMLHttpRequest'
                        }
                    }

                );


            let raw=
                await response.text();


            raw=
                raw.replace(
                    /^\uFEFF/,
                    ''
                );


            console.log(
                'UPLOAD RESPONSE :',
                raw
            );


            if(
                !raw.trim()
            ){

                throw new Error(
                    'Réponse vide du serveur. HTTP '+
                    response.status
                );
            }


            let r;


            try{

                r=
                    JSON.parse(
                        raw
                    );

            }catch(error){

                throw new Error(
                    'Réponse PHP invalide : '+
                    raw.substring(
                        0,
                        1500
                    )
                );
            }


            if(
                !response.ok ||
                r.success!==true
            ){

                throw new Error(
                    r.message||
                    'Erreur HTTP '+
                    response.status
                );
            }


            personalModal.hide();

            personalForm.reset();


            STAGIA.toast(
                r.message||
                'Document ajouté.'
            );


            await chargerDocumentsPersonnels();


        }catch(e){

            alert(
                e.message
            );

            STAGIA.toast(
                e.message,
                'danger'
            );


        }finally{

            STAGIA.loading(
                btn,
                false
            );
        }

    }
);



/* =========================================================
   SUPPRIMER DOCUMENT PERSONNEL
========================================================= */

async function supprimerDocumentPersonnel(
    uuid
){


    if(
        !STAGIA.confirm(
            'Supprimer définitivement ce document ?'
        )
    ){

        return;
    }


    const data=
        new FormData();


    data.append(
        'csrf',
        CSRF
    );


    data.append(
        'uuid',
        uuid
    );


    try{


        const r=
            await STAGIA.post(

                BASE_URL+
                '/actions/etudiants/student-personal-document-delete.php',

                data

            );


        STAGIA.toast(
            r.message
        );


        await chargerDocumentsPersonnels();


    }catch(e){


        STAGIA.toast(
            e.message,
            'danger'
        );

    }

}



/* =========================================================
   LIBELLÉ DOCUMENT OFFICIEL
========================================================= */

function documentLabel(
    type
){


    return {

        ATTESTATION_STAGE:
            'Attestation de stage',

        CERTIFICAT_STAGE:
            'Certificat de stage',

        CONVENTION_STAGE:
            'Convention de stage'

    }[type]
    ||
    'Document';

}



/* =========================================================
   ICÔNE DOCUMENT OFFICIEL
========================================================= */

function documentIcon(
    type
){


    return {

        ATTESTATION_STAGE:
            'bi bi-file-earmark-check',

        CERTIFICAT_STAGE:
            'bi bi-award',

        CONVENTION_STAGE:
            'bi bi-file-earmark-sign'

    }[type]
    ||
    'bi bi-file-earmark';

}



/* =========================================================
   CATÉGORIE PERSONNELLE
========================================================= */

function categorieLabel(
    value
){


    return {

        IDENTITE:
            'Identité',

        ACADEMIQUE:
            'Académique',

        STAGE:
            'Stage',

        ADMINISTRATIF:
            'Administratif',

        AUTRE:
            'Autre'

    }[value]
    ||
    value
    ||
    'Autre';

}



/* =========================================================
   ICÔNE FICHIER
========================================================= */

function fileIcon(
    extension
){


    const ext=
        String(
            extension
            ||''
        )
        .toLowerCase();


    return {

        pdf:
            'bi bi-file-earmark-pdf text-danger',

        jpg:
            'bi bi-file-earmark-image text-primary',

        jpeg:
            'bi bi-file-earmark-image text-primary',

        png:
            'bi bi-file-earmark-image text-primary',

        doc:
            'bi bi-file-earmark-word text-primary',

        docx:
            'bi bi-file-earmark-word text-primary'

    }[ext]
    ||
    'bi bi-file-earmark text-secondary';

}



/* =========================================================
   TAILLE
========================================================= */

function formatSize(
    bytes
){


    bytes=
        Number(
            bytes
            ||0
        );


    if(
        bytes<1024
    ){

        return bytes+' o';
    }


    if(
        bytes<
        1024*1024
    ){

        return (
            bytes/1024
        ).toFixed(1)
        +' Ko';
    }


    return (
        bytes/
        (1024*1024)
    ).toFixed(1)
    +' Mo';

}



/* =========================================================
   DATE
========================================================= */

function formatDate(
    value
){


    if(!value)
        return '-';


    const dateValue=
        String(value)
        .substring(
            0,
            10
        );


    const parts=
        dateValue
        .split('-');


    if(
        parts.length!==3
    ){

        return dateValue;
    }


    return (
        parts[2]
        +'/'+
        parts[1]
        +'/'+
        parts[0]
    );

}



/* =========================================================
   RECHERCHE
========================================================= */

$('search')
    .addEventListener(
        'input',
        afficherDocumentsOfficiels
    );



/* =========================================================
   INITIALISATION
========================================================= */

chargerTout();


});

</script>


<?php

require_once __DIR__.'/../../includes/app-footer.php';

?>