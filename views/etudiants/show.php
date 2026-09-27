<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

$etablissementId=currentEtablissementId($pdo);

if(!$etablissementId)
    exit('Aucun établissement associé.');

$enrollmentId=(int)($_GET['id']??0);

if(!$enrollmentId)
    exit('Étudiant invalide.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Dossier étudiant';
$activePage='etudiants';

require_once __DIR__.'/../../includes/app-header.php';
?>


<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->
<div class="stagia-page-head">

    <div>

        <a href="<?= BASE_URL ?>/views/etudiants/index.php"
           class="detail-back">

            <i class="bi bi-arrow-left"></i>
            Liste des étudiants

        </a>

        <h1>Dossier étudiant</h1>

        <p>
            Consultation du dossier académique et administratif
            de l'étudiant.
        </p>

    </div>

</div>


<!-- =========================================================
     PROFIL
========================================================= -->
<div class="stagia-list-card mb-4">

    <div class="p-4">

        <div class="row align-items-center g-4">


            <!-- AVATAR -->
            <div class="col-md-auto text-center">

                <div id="studentAvatar"
                     class="rounded-circle
                            d-flex
                            align-items-center
                            justify-content-center
                            bg-light
                            border"
                     style="width:90px;height:90px;font-size:28px;font-weight:700">

                    --

                </div>

            </div>


            <!-- IDENTITÉ -->
            <div class="col">

                <div class="d-flex
                            align-items-center
                            gap-2
                            flex-wrap">

                    <h3 id="studentName"
                        class="mb-0">

                        Chargement...

                    </h3>


                    <span id="studentStatus"
                          class="badge bg-secondary">

                        -

                    </span>

                </div>


                <div class="text-muted mt-1">

                    Identifiant STAGIA :

                    <strong id="studentStagiaCode">
                        -
                    </strong>

                </div>


                <!-- COMPTE UTILISATEUR -->
                <div class="mt-3">

                    <button type="button"
                            id="btnActivateStudent"
                            class="btn btn-sm btn-outline-success d-none">

                        <i class="bi bi-person-check me-1"></i>
                        Activer le compte étudiant

                    </button>


                    <span id="studentAccountStatus"
                          class="badge bg-success d-none">

                        <i class="bi bi-check-circle me-1"></i>
                        Compte utilisateur créé

                    </span>

                </div>

            </div>


            <!-- MATRICULE -->
            <div class="col-md-auto">

                <div class="text-md-end">

                    <small class="text-muted">
                        Matricule
                    </small>

                    <div id="studentMatricule"
                         class="fw-bold fs-5">

                        -

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     INFORMATIONS
========================================================= -->
<div class="row g-3 mb-4">


    <div class="col-md-4">

        <div class="stagia-list-card h-100 p-3">

            <small class="text-muted d-block mb-1">
                E-mail
            </small>

            <strong id="studentEmail">
                -
            </strong>

        </div>

    </div>


    <div class="col-md-4">

        <div class="stagia-list-card h-100 p-3">

            <small class="text-muted d-block mb-1">
                Téléphone
            </small>

            <strong id="studentPhone">
                -
            </strong>

        </div>

    </div>


    <div class="col-md-4">

        <div class="stagia-list-card h-100 p-3">

            <small class="text-muted d-block mb-1">
                E-mail institutionnel
            </small>

            <strong id="studentInstitutionEmail">
                -
            </strong>

        </div>

    </div>

</div>


<!-- =========================================================
     ONGLETS
========================================================= -->
<div class="stagia-list-card">

    <div class="border-bottom">

        <ul class="nav nav-tabs px-3 pt-3"
            id="studentTabs">


            <li class="nav-item">

                <button class="nav-link active"
                        data-bs-toggle="tab"
                        data-bs-target="#tabParcours"
                        type="button">

                    <i class="bi bi-mortarboard me-1"></i>
                    Parcours

                </button>

            </li>


            <li class="nav-item">

                <button class="nav-link"
                        data-bs-toggle="tab"
                        data-bs-target="#tabDocuments"
                        type="button">

                    <i class="bi bi-file-earmark-text me-1"></i>
                    Documents

                </button>

            </li>


            <li class="nav-item">

                <button class="nav-link"
                        data-bs-toggle="tab"
                        data-bs-target="#tabNotes"
                        type="button">

                    <i class="bi bi-journal-check me-1"></i>
                    Notes

                </button>

            </li>


            <li class="nav-item">

                <button class="nav-link"
                        data-bs-toggle="tab"
                        data-bs-target="#tabStages"
                        type="button">

                    <i class="bi bi-briefcase me-1"></i>
                    Stages

                </button>

            </li>


        </ul>

    </div>


    <div class="tab-content">


        <!-- =====================================================
             PARCOURS
        ====================================================== -->
        <div class="tab-pane fade show active"
             id="tabParcours">

            <div class="table-responsive">

                <table class="table stagia-modern-table mb-0">

                    <thead>

                    <tr>

                        <th>Année académique</th>

                        <th>Filière</th>

                        <th>Promotion</th>

                        <th>Statut</th>

                        <th>Période</th>

                    </tr>

                    </thead>

                    <tbody id="studentPathBody">

                    <tr>

                        <td colspan="5"
                            class="text-center py-5">

                            <div class="spinner-border
                                        spinner-border-sm me-2">
                            </div>

                            Chargement...

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- =====================================================
             DOCUMENTS
        ====================================================== -->
        <div class="tab-pane fade"
             id="tabDocuments">

            <div class="table-responsive">

                <table class="table stagia-modern-table mb-0">

                    <thead>

                    <tr>

                        <th>Document</th>

                        <th>Type</th>

                        <th>Statut</th>

                        <th>Date</th>

                    </tr>

                    </thead>

                    <tbody id="studentDocumentsBody">

                    <tr>

                        <td colspan="4"
                            class="text-center py-5 text-muted">

                            Ouvrez l'onglet pour charger
                            les documents.

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- =====================================================
             NOTES
        ====================================================== -->
        <div class="tab-pane fade"
             id="tabNotes">

            <div class="table-responsive">

                <table class="table stagia-modern-table mb-0">

                    <thead>

                    <tr>

                        <th>Matière</th>

                        <th>Évaluation</th>

                        <th>Note</th>

                        <th>Date</th>

                    </tr>

                    </thead>

                    <tbody id="studentNotesBody">

                    <tr>

                        <td colspan="4"
                            class="text-center py-5 text-muted">

                            Ouvrez l'onglet pour charger
                            les notes.

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- =====================================================
             STAGES
        ====================================================== -->
        <div class="tab-pane fade"
             id="tabStages">

            <div class="p-5 text-center text-muted">

                <i class="bi bi-briefcase
                          fs-1
                          d-block
                          mb-3">
                </i>

                <h5>Stages de l'étudiant</h5>

                <p class="mb-0">

                    Les candidatures, réservations,
                    placements et stages seront affichés ici.

                </p>

            </div>

        </div>


    </div>

</div>

</main>



<!-- =========================================================
     MODAL CRÉATION COMPTE ÉTUDIANT
========================================================= -->
<div class="modal fade"
     id="studentAccountModal"
     tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content border-0 shadow">


<form id="studentAccountForm">


<div class="modal-header">

    <div>

        <h5 class="modal-title">
            Activer le compte étudiant
        </h5>

        <small id="accountStudentName"
               class="text-muted">
        </small>

    </div>


    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal">
    </button>

</div>


<div class="modal-body">


    <div class="alert alert-light border small">

        <i class="bi bi-info-circle me-1"></i>

        STAGIA créera un compte personnel lié
        au profil existant de l'étudiant.

    </div>


    <input type="hidden"
           name="csrf"
           value="<?= htmlspecialchars($_SESSION['csrf']) ?>">


    <input type="hidden"
           name="student_id"
           id="accountStudentId">


    <div class="mb-3">

        <label class="form-label">
            Identifiant STAGIA
        </label>

        <input type="text"
               id="accountIdentifier"
               class="form-control"
               readonly>

    </div>


    <div class="mb-3">

        <label class="form-label">
            E-mail d'activation *
        </label>

        <input type="email"
               name="email"
               id="accountEmail"
               class="form-control"
               required>

    </div>


    <div>

        <label class="form-label">
            Téléphone
        </label>

        <input type="text"
               name="telephone"
               id="accountTelephone"
               class="form-control">

    </div>


</div>


<div class="modal-footer">

    <button type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal">

        Annuler

    </button>


    <button type="submit"
            id="accountSaveBtn"
            class="btn btn-primary-stagia">

        <i class="bi bi-person-check me-1"></i>
        Créer le compte

    </button>

</div>


</form>

</div>
</div>
</div>



<!-- =========================================================
     MODAL LIEN ACTIVATION
========================================================= -->
<div class="modal fade"
     id="studentActivationLinkModal"
     tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content border-0 shadow">


<div class="modal-header">

    <div>

        <h5 class="modal-title">
            Compte étudiant créé
        </h5>

        <small class="text-muted">
            Transmettez le lien à l'étudiant.
        </small>

    </div>


    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal">
    </button>

</div>


<div class="modal-body">


    <div class="alert alert-success">

        <i class="bi bi-check-circle me-1"></i>

        Le compte a été créé avec succès.

    </div>


    <div class="mb-3">

        <label class="form-label">
            Identifiant
        </label>

        <input type="text"
               id="studentCreatedIdentifier"
               class="form-control"
               readonly>

    </div>


    <div>

        <label class="form-label">
            Lien d'activation
        </label>


        <div class="input-group">

            <input type="text"
                   id="studentActivationLink"
                   class="form-control"
                   readonly>


            <button type="button"
                    id="btnCopyStudentActivation"
                    class="btn btn-outline-secondary">

                <i class="bi bi-copy"></i>

            </button>

        </div>

    </div>


</div>

</div>
</div>
</div>



<script>
document.addEventListener('DOMContentLoaded',()=>{


    const BASE_URL='<?= BASE_URL ?>';

    const enrollmentId=<?= $enrollmentId ?>;

    const $=id=>document.getElementById(id);


    const accountModal=
        new bootstrap.Modal(
            $('studentAccountModal')
        );


    const activationLinkModal=
        new bootstrap.Modal(
            $('studentActivationLinkModal')
        );


    const accountForm=
        $('studentAccountForm');


    let currentStudent=null;

    let currentAcademicEnrollmentId=null;

    let documentsLoaded=false;

    let notesLoaded=false;



    /* =====================================================
       CHARGER DOSSIER
    ====================================================== */
    async function chargerStudent(){


        try{


            const r=await STAGIA.request(

                BASE_URL+
                '/actions/etudiants/student-detail.php?id='+
                enrollmentId

            );


            const s=
                r.data.student||
                r.data.enrollment||
                r.data;


            const parcours=
                r.data.parcours||
                r.data.academic_path||
                r.data.academic_enrollments||
                [];


            currentStudent=s;


            /*
             * ID PROFIL GLOBAL
             */
            currentStudent.student_id=
                s.student_id||
                s.profile_id||
                s.id;


            afficherProfil(s);

            afficherParcours(parcours);


        }catch(e){


            STAGIA.toast(
                e.message,
                'danger'
            );


        }

    }



    /* =====================================================
       PROFIL
    ====================================================== */
    function afficherProfil(s){


        const fullName=[
            s.nom,
            s.postnom,
            s.prenom
        ]
        .filter(Boolean)
        .join(' ');


        $('studentName').textContent=
            fullName||'-';


        $('studentStagiaCode').textContent=
            s.stagia_code||'-';


        $('studentMatricule').textContent=
            s.matricule||'-';


        $('studentEmail').textContent=
            s.email||'-';


        $('studentPhone').textContent=
            s.telephone||'-';


        $('studentInstitutionEmail').textContent=
            s.email_institutionnel||'-';


        const status=
            s.enrollment_statut||
            s.statut||
            '-';


        $('studentStatus').textContent=
            status;


        $('studentStatus').className=
            'badge bg-'+(
                ['ACTIF','EN_COURS'].includes(status)
                    ?'success'
                    :'secondary'
            );


        /*
         * AVATAR
         */
        const initials=
            [
                s.nom?.charAt(0),
                s.prenom?.charAt(0)
            ]
            .filter(Boolean)
            .join('')
            .toUpperCase();


        $('studentAvatar').textContent=
            initials||'ET';


        /*
         * COMPTE
         */
        const hasAccount=
            Number(s.user_id||0)>0;


        $('btnActivateStudent')
            .classList.toggle(
                'd-none',
                hasAccount
            );


        $('studentAccountStatus')
            .classList.toggle(
                'd-none',
                !hasAccount
            );

    }



    /* =====================================================
       PARCOURS
    ====================================================== */
    function afficherParcours(items){


        const body=
            $('studentPathBody');


        if(!items.length){


            body.innerHTML=`

                <tr>

                    <td colspan="5"
                        class="text-center
                               py-5
                               text-muted">

                        <i class="bi bi-mortarboard
                                  fs-3
                                  d-block
                                  mb-2">
                        </i>

                        Aucun parcours académique.

                    </td>

                </tr>

            `;


            return;

        }


        const active=
            items.find(x=>
                x.statut==='EN_COURS'
            )||items[0];


        currentAcademicEnrollmentId=
            active.id||
            active.academic_enrollment_id||
            null;


        body.innerHTML=
            items.map(x=>{


                const statut=
                    x.statut||'-';


                return `

                <tr>

                    <td>
                        ${STAGIA.escape(
                            x.annee_academique||
                            x.annee||
                            '-'
                        )}
                    </td>


                    <td>
                        ${STAGIA.escape(
                            x.filiere||
                            x.filiere_nom||
                            '-'
                        )}
                    </td>


                    <td>

                        <strong>

                            ${STAGIA.escape(
                                x.promotion||
                                x.promotion_nom||
                                '-'
                            )}

                        </strong>

                    </td>


                    <td>

                        <span class="badge bg-${
                            statut==='EN_COURS'
                                ?'success'
                                :statut==='TERMINE'
                                    ?'primary'
                                    :'secondary'
                        }">

                            ${STAGIA.escape(statut)}

                        </span>

                    </td>


                    <td>

                        ${formatDate(
                            x.date_debut
                        )}

                        ${x.date_fin
                            ?' - '+formatDate(x.date_fin)
                            :''}

                    </td>

                </tr>

                `;

            }).join('');

    }



    /* =====================================================
       DOCUMENTS
    ====================================================== */
 async function chargerDocuments(){

    if(documentsLoaded)
        return;

    const body=$('studentDocumentsBody');

    body.innerHTML=`
        <tr>
            <td colspan="4"
                class="text-center py-5">

                <div class="spinner-border
                            spinner-border-sm me-2">
                </div>

                Chargement...

            </td>
        </tr>
    `;

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-document-list.php?enrollment_id='+
            enrollmentId
        );

        const items=
            r.data.documents||
            r.data.items||
            [];

        documentsLoaded=true;


        if(!items.length){

            body.innerHTML=`
                <tr>
                    <td colspan="4"
                        class="text-center py-5 text-muted">

                        <i class="bi bi-file-earmark-x
                                  fs-3
                                  d-block
                                  mb-2">
                        </i>

                        Aucun document enregistré.

                    </td>
                </tr>
            `;

            return;
        }


        body.innerHTML=items.map(x=>{

            const titre=
                x.titre||
                'Document';

            const nomFichier=
                x.nom_fichier||
                '-';

            const typeDocument=
                x.type_document||
                x.type_code||
                '-';

            const format=
                x.format_fichier||
                x.mime_type||
                '';

            const statut=
                x.statut||
                'ACTIF';


            return `

                <tr>

                    <!-- DOCUMENT -->
                    <td>

                        <div class="fw-semibold">
                            ${STAGIA.escape(titre)}
                        </div>

                        <small class="text-muted">

                            <i class="bi bi-paperclip me-1"></i>

                            ${STAGIA.escape(nomFichier)}

                        </small>

                    </td>


                    <!-- TYPE -->
                    <td>

                        <div class="fw-semibold">
                            ${STAGIA.escape(typeDocument)}
                        </div>

                        ${
                            format
                            ?`
                                <small class="text-muted">
                                    ${STAGIA.escape(format)}
                                </small>
                            `
                            :''
                        }

                    </td>


                    <!-- STATUT -->
                    <td>

                        <span class="badge bg-${
                            statut==='ACTIF'
                                ?'success'
                                :'secondary'
                        }">

                            ${STAGIA.escape(statut)}

                        </span>

                    </td>


                    <!-- DATE -->
                    <td>

                        ${formatDate(
                            x.created_at
                        )}

                    </td>

                </tr>

            `;

        }).join('');


    }catch(e){

        body.innerHTML=`
            <tr>

                <td colspan="4"
                    class="text-center py-5 text-danger">

                    <i class="bi bi-exclamation-circle me-1"></i>

                    ${STAGIA.escape(e.message)}

                </td>

            </tr>
        `;

    }
}



    /* =====================================================
       NOTES
    ====================================================== */
    async function chargerNotes(){


        if(
            notesLoaded||
            !currentAcademicEnrollmentId
        )
            return;


        const body=
            $('studentNotesBody');


        body.innerHTML=`

            <tr>

                <td colspan="4"
                    class="text-center py-5">

                    <div class="spinner-border
                                spinner-border-sm me-2">
                    </div>

                    Chargement...

                </td>

            </tr>

        `;


        try{


            const r=await STAGIA.request(

                BASE_URL+
                '/actions/etudiants/student-note-list.php?academic_enrollment_id='+
                currentAcademicEnrollmentId

            );


            const items=
                r.data.items||
                r.data.notes||
                [];


            notesLoaded=true;


            if(!items.length){


                body.innerHTML=`

                    <tr>

                        <td colspan="4"
                            class="text-center
                                   py-5
                                   text-muted">

                            Aucune note enregistrée.

                        </td>

                    </tr>

                `;


                return;

            }


            body.innerHTML=
                items.map(x=>`

                <tr>

                    <td>

                        <strong>
                            ${STAGIA.escape(
                                x.matiere||
                                x.matiere_nom||
                                '-'
                            )}
                        </strong>

                    </td>


                    <td>

                        ${STAGIA.escape(
                            x.type_evaluation||
                            x.type||
                            '-'
                        )}

                    </td>


                    <td>

                        <strong>

                            ${STAGIA.escape(
                                x.note??'-'
                            )}

                            /

                            ${STAGIA.escape(
                                x.note_sur??20
                            )}

                        </strong>

                    </td>


                    <td>

                        ${formatDate(
                            x.date_evaluation||
                            x.date_note||
                            x.created_at
                        )}

                    </td>

                </tr>

                `).join('');


        }catch(e){


            body.innerHTML=`

                <tr>

                    <td colspan="4"
                        class="text-center py-5 text-danger">

                        ${STAGIA.escape(e.message)}

                    </td>

                </tr>

            `;

        }

    }



    /* =====================================================
       OUVRIR ACTIVATION
    ====================================================== */
    $('btnActivateStudent').onclick=()=>{


        if(!currentStudent)
            return;


        accountForm.reset();


        $('accountStudentId').value=
            currentStudent.student_id;


        $('accountStudentName').textContent=
            [
                currentStudent.nom,
                currentStudent.postnom,
                currentStudent.prenom
            ]
            .filter(Boolean)
            .join(' ');


        $('accountIdentifier').value=
            currentStudent.stagia_code||'';


        $('accountEmail').value=
            currentStudent.email||
            currentStudent.email_institutionnel||
            '';


        $('accountTelephone').value=
            currentStudent.telephone||'';


        accountModal.show();

    };



    /* =====================================================
       CRÉER COMPTE
    ====================================================== */
    accountForm.onsubmit=async e=>{


        e.preventDefault();


        const btn=
            $('accountSaveBtn');


        STAGIA.loading(
            btn,
            true
        );


        try{


            const r=await STAGIA.post(

                BASE_URL+
                '/actions/etudiants/student-account-activate.php',

                accountForm

            );


            accountModal.hide();


            $('studentCreatedIdentifier').value=
                r.data.identifiant||'';


            let activationUrl=
                r.data.activation_url||'';


            if(
                activationUrl &&
                !activationUrl.startsWith('http')
            ){

                activationUrl=
                    window.location.origin+
                    activationUrl;

            }


            $('studentActivationLink').value=
                activationUrl;


            activationLinkModal.show();


            STAGIA.toast(
                r.message
            );


            await chargerStudent();


        }catch(e){


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

    };



    /* =====================================================
       COPIER LIEN
    ====================================================== */
    $('btnCopyStudentActivation').onclick=async()=>{


        const input=
            $('studentActivationLink');


        try{


            await navigator.clipboard.writeText(
                input.value
            );


        }catch(e){


            input.select();

            document.execCommand(
                'copy'
            );

        }


        STAGIA.toast(
            'Lien d’activation copié.'
        );

    };



    /* =====================================================
       CHARGEMENT LAZY DES ONGLETS
    ====================================================== */
    document
        .querySelector(
            '[data-bs-target="#tabDocuments"]'
        )
        .addEventListener(
            'shown.bs.tab',
            chargerDocuments
        );


    document
        .querySelector(
            '[data-bs-target="#tabNotes"]'
        )
        .addEventListener(
            'shown.bs.tab',
            chargerNotes
        );



    /* =====================================================
       DATE
    ====================================================== */
    function formatDate(value){


        if(!value)
            return '-';


        const raw=
            String(value)
            .substring(0,10);


        const p=
            raw.split('-');


        if(p.length!==3)
            return value;


        return `${p[2]}/${p[1]}/${p[0]}`;

    }



    /* =====================================================
       INITIALISATION
    ====================================================== */
    chargerStudent();


});
</script>


<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>