<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

$labelsFile=__DIR__.'/../../includes/academic-labels.php';
if(is_file($labelsFile))
    require_once $labelsFile;

requirePermission($pdo,'academic.view');

$settings=$_SESSION['academic_settings']??[];

if(
    !contextAcademicEnabled() ||
    empty($settings['filiere_active'])
){
    http_response_code(403);

    exit(
        'Les filières / programmes ne sont pas activés pour cet établissement.'
    );
}

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$eid=(int)($_SESSION['etablissement_id']??0);

$programSingular='Filière / Programme';
$programPlural='Filières / Programmes';
$departmentSingular='Département';
$departmentPlural='Départements';
$unitSingular='Unité académique';
$unitPlural='Unités académiques';
$optionPlural='Options / Spécialités';
$promotionPlural='Promotions';

if(
    $eid>0 &&
    function_exists('academicLabels') &&
    function_exists('academicLabelFromMap')
){
    $academicLabels=academicLabels(
        $pdo,
        $eid
    );

    $programSingular=
        academicLabelFromMap(
            $academicLabels,
            'PROGRAM',
            false,
            $programSingular
        );

    $programPlural=
        academicLabelFromMap(
            $academicLabels,
            'PROGRAM',
            true,
            $programPlural
        );

    $departmentSingular=
        academicLabelFromMap(
            $academicLabels,
            'DEPARTMENT',
            false,
            $departmentSingular
        );

    $departmentPlural=
        academicLabelFromMap(
            $academicLabels,
            'DEPARTMENT',
            true,
            $departmentPlural
        );

    $unitSingular=
        academicLabelFromMap(
            $academicLabels,
            'UNIT',
            false,
            $unitSingular
        );

    $unitPlural=
        academicLabelFromMap(
            $academicLabels,
            'UNIT',
            true,
            $unitPlural
        );

    $optionPlural=
        academicLabelFromMap(
            $academicLabels,
            'OPTION',
            true,
            $optionPlural
        );

    $promotionPlural=
        academicLabelFromMap(
            $academicLabels,
            'PROMOTION',
            true,
            $promotionPlural
        );
}

$pageTitle=$programPlural;
$activePage='filieres';
$canManage=hasPermission(
    $pdo,
    'academic.manage'
);

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.prog-kpis{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px
}
.prog-kpi,.prog-card{
    background:#fff;
    border:1px solid #e7ebf0;
    border-radius:14px
}
.prog-kpi{padding:16px}
.prog-kpi small{
    display:block;
    color:#64748b;
    font-size:11px;
    text-transform:uppercase
}
.prog-kpi strong{
    display:block;
    font-size:27px;
    margin-top:4px
}
.prog-card{overflow:hidden}
.parent-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:12px
}
@media(max-width:900px){
    .prog-kpis{
        grid-template-columns:repeat(2,1fr)
    }
}
@media(max-width:600px){
    .prog-kpis{
        grid-template-columns:1fr
    }
}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">

    <div>

        <h1>
            <?= htmlspecialchars($programPlural) ?>
        </h1>

        <p>
            Organisez les programmes réels de votre établissement.
            Les ajouts locaux sont utilisables immédiatement.
        </p>

    </div>

    <?php if($canManage): ?>

    <button
        class="btn btn-primary-stagia"
        id="addProgramBtn"
    >
        <i class="bi bi-plus-lg me-1"></i>
        Ajouter <?= htmlspecialchars($programSingular) ?>
    </button>

    <?php endif; ?>

</div>

<div class="alert alert-light border mb-3">

    <i class="bi bi-info-circle text-primary me-1"></i>

    Les éléments du référentiel national restent protégés.
    Les programmes créés par votre établissement deviennent
    <strong>actifs immédiatement</strong>.

</div>

<div class="prog-kpis mb-4">

    <div class="prog-kpi">
        <small>Total</small>
        <strong id="kTotal">0</strong>
    </div>

    <div class="prog-kpi">
        <small>Nationaux</small>
        <strong id="kNational">0</strong>
    </div>

    <div class="prog-kpi">
        <small>Ajouts locaux</small>
        <strong id="kLocal">0</strong>
    </div>

    <div class="prog-kpi">
        <small>Actifs</small>
        <strong id="kActive">0</strong>
    </div>

</div>

<div class="prog-card">

<div class="p-3 border-bottom">

<div class="row g-2">

    <div class="col-lg-5">

        <input
            id="search"
            class="form-control"
            placeholder="Rechercher..."
        >

    </div>

    <div class="col-lg-3">

        <select
            id="attachmentFilter"
            class="form-select"
        >
            <option value="">
                Tous les rattachements
            </option>

            <option value="DEPARTEMENT">
                <?= htmlspecialchars($departmentPlural) ?>
            </option>

            <option value="UNITE">
                <?= htmlspecialchars($unitPlural) ?>
            </option>

            <option value="ETABLISSEMENT">
                Établissement
            </option>

        </select>

    </div>

    <div class="col-lg-4">

        <select
            id="validationFilter"
            class="form-select"
        >
            <option value="">
                Tous les statuts
            </option>

            <option value="NATIONAL">
                National
            </option>

            <option value="VALIDE_LOCAL">
                Local
            </option>

            <option value="INTEGRE_REFERENTIEL">
                Intégré national
            </option>

        </select>

    </div>

</div>

</div>

<div class="table-responsive">

<table class="table stagia-modern-table align-middle mb-0">

<thead>

<tr>

    <th>
        <?= htmlspecialchars(mb_strtoupper($programSingular)) ?>
    </th>

    <th>
        CURSUS
    </th>

    <th>
        RATTACHEMENT
    </th>

    <th>
        ORIGINE
    </th>

    <th>
        STATUT
    </th>

    <th class="text-center">
        <?= htmlspecialchars(mb_strtoupper($optionPlural)) ?>
    </th>

    <th class="text-center">
        <?= htmlspecialchars(mb_strtoupper($promotionPlural)) ?>
    </th>

    <th class="text-end">
        ACTION
    </th>

</tr>

</thead>

<tbody id="rows">

<tr>
    <td
        colspan="8"
        class="text-center py-5 text-muted"
    >
        Chargement...
    </td>
</tr>

</tbody>

</table>

</div>

</div>

</main>

<?php if($canManage): ?>

<div
    class="modal fade"
    id="programModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content">

<form id="programForm">

<div class="modal-header">

    <div>

        <h5
            class="modal-title"
            id="modalTitle"
        >
            Ajouter <?= htmlspecialchars($programSingular) ?>
        </h5>

        <small class="text-muted">
            Structure locale de votre établissement.
        </small>

    </div>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="modal"
    ></button>

</div>

<div class="modal-body">

    <input
        type="hidden"
        name="csrf"
        value="<?= htmlspecialchars($_SESSION['csrf']) ?>"
    >

    <input
        type="hidden"
        name="id"
        id="programId"
    >

    <div class="row g-3">

        <div class="col-md-6">

            <label class="form-label">
                Nom *
            </label>

            <input
                name="nom"
                id="programName"
                class="form-control"
                maxlength="150"
                required
            >

        </div>

        <div class="col-md-6">

            <label class="form-label">
                Référentiel de cursus *
            </label>

            <select
                name="curriculum_reference_id"
                id="curriculum"
                class="form-select"
                required
            ></select>

            <small class="text-muted">
                Le cursus de référence reste commun à STAGIA.
            </small>

        </div>

        <div class="col-12">

            <div class="parent-box">

                <div class="form-check form-switch mb-2">

                    <input
                        type="hidden"
                        name="has_parent"
                        value="0"
                    >

                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="has_parent"
                        value="1"
                        id="hasParent"
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="hasParent"
                    >
                        Cette structure a un parent
                    </label>

                </div>

                <div
                    id="parentFields"
                    class="row g-2 d-none"
                >

                    <div class="col-md-5">

                        <label class="form-label">
                            Type du parent *
                        </label>

                        <select
                            name="parent_type"
                            id="parentType"
                            class="form-select"
                        ></select>

                    </div>

                    <div class="col-md-7">

                        <label class="form-label">
                            Parent *
                        </label>

                        <select
                            name="parent_id"
                            id="parentId"
                            class="form-select"
                        ></select>

                    </div>

                </div>

                <small
                    class="text-muted"
                    id="parentHint"
                >
                    Si la case reste décochée,
                    le programme sera directement rattaché à l’établissement.
                </small>

            </div>

        </div>

        <div class="col-md-4">

            <label class="form-label">
                Durée (années)
            </label>

            <input
                type="number"
                min="1"
                max="20"
                name="duree_annees"
                id="duration"
                class="form-control"
            >

        </div>

        <div class="col-md-8 d-flex align-items-end">

            <div class="form-check form-switch mb-2">

                <input
                    type="hidden"
                    name="preparatory_level_enabled"
                    value="0"
                >

                <input
                    class="form-check-input"
                    type="checkbox"
                    name="preparatory_level_enabled"
                    value="1"
                    id="prep"
                >

                <label
                    class="form-check-label"
                    for="prep"
                >
                    Activer le niveau préparatoire L0
                    si le cursus le permet
                </label>

            </div>

        </div>

        <div class="col-12">

            <label class="form-label">
                Description
                <span class="text-muted fw-normal">
                    (facultatif)
                </span>
            </label>

            <textarea
                name="description"
                id="description"
                class="form-control"
                rows="3"
            ></textarea>

        </div>

    </div>

</div>

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
        class="btn btn-primary-stagia"
        id="saveBtn"
    >
        <i class="bi bi-check-lg me-1"></i>
        Enregistrer
    </button>

</div>

</form>

</div>

</div>

</div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id),
      esc=STAGIA.escape,
      canManage=<?= $canManage?'true':'false' ?>,
      PROGRAM_SINGULAR=
        <?= json_encode($programSingular,JSON_UNESCAPED_UNICODE) ?>,
      DEPARTMENT_SINGULAR=
        <?= json_encode($departmentSingular,JSON_UNESCAPED_UNICODE) ?>,
      UNIT_SINGULAR=
        <?= json_encode($unitSingular,JSON_UNESCAPED_UNICODE) ?>;

let items=[],
    units=[],
    departments=[],
    curricula=[],
    rules={},
    timer;

const modal=
    canManage
        ?new bootstrap.Modal(
            $('programModal')
        )
        :null;


function statusBadge(x){

    const map={
        NATIONAL:[
            'National',
            'bg-primary-subtle text-primary'
        ],

        VALIDE_LOCAL:[
            'Local',
            'bg-success-subtle text-success'
        ],

        INTEGRE_REFERENTIEL:[
            'Intégré national',
            'bg-info-subtle text-info'
        ],

        EN_ATTENTE:[
            'Ancien — à normaliser',
            'bg-warning-subtle text-warning'
        ],

        REFUSE:[
            'Refusé',
            'bg-danger-subtle text-danger'
        ]
    };

    const m=
        map[x.validation_statut]||
        [
            x.validation_statut||'—',
            'bg-light text-dark'
        ];

    return `
        <span class="badge ${m[1]}">
            ${esc(m[0])}
        </span>
    `;
}


function origin(x){

    return Number(x.ajoute_localement)===1

        ?'<span class="badge bg-light text-dark border">Local</span>'

        :'<span class="badge bg-primary">STAGIA national</span>';
}


function attachmentLabel(x){

    if(x.rattachement_type==='DEPARTEMENT'){

        let html=`
            <strong>
                ${esc(DEPARTMENT_SINGULAR)}
            </strong>

            <small class="d-block text-muted">
                ${esc(x.departement_nom||'—')}
            </small>
        `;

        if(x.unite_nom){

            html+=`
                <small class="d-block text-muted">
                    ${esc(x.unite_nom)}
                </small>
            `;
        }

        return html;
    }


    if(x.rattachement_type==='UNITE'){

        return `
            <strong>
                ${esc(UNIT_SINGULAR)}
            </strong>

            <small class="d-block text-muted">
                ${esc(x.unite_nom||'—')}
            </small>
        `;
    }


    return `
        <strong>
            Établissement
        </strong>
    `;
}


function fillFormLists(){

    if(!canManage)
        return;


    $('curriculum').innerHTML=

        '<option value="">Sélectionner...</option>'+

        curricula.map(
            x=>`
                <option value="${x.id}">
                    ${esc(x.code)} — ${esc(x.nom)}
                    ${x.domaine?` · ${esc(x.domaine)}`:''}
                </option>
            `
        ).join('');


    const parentTypes=[];


    if(rules.allow_department){

        parentTypes.push(
            [
                'DEPARTEMENT',
                DEPARTMENT_SINGULAR
            ]
        );
    }


    if(rules.allow_unit){

        parentTypes.push(
            [
                'UNITE',
                UNIT_SINGULAR
            ]
        );
    }


    $('parentType').innerHTML=

        '<option value="">Sélectionner...</option>'+

        parentTypes.map(
            x=>`
                <option value="${x[0]}">
                    ${esc(x[1])}
                </option>
            `
        ).join('');
}


function fillParents(selected=''){

    if(!canManage)
        return;


    const type=
        $('parentType').value;


            if(type==='DEPARTEMENT'){

                $('parentId').innerHTML=

                    '<option value="">Sélectionner...</option>'+

                departments.map(
            x=>`
                <option value="${x.id}">
                    ${esc(x.nom)}
                </option>
            `
        ).join('');

    }else if(type==='UNITE'){

        $('parentId').innerHTML=

            '<option value="">Sélectionner...</option>'+

            units.map(
                x=>`
                    <option value="${x.id}">
                        ${esc(x.nom)}
                    </option>
                `
            ).join('');

    }else{

        $('parentId').innerHTML=
            '<option value="">Sélectionner le type du parent...</option>';
    }


    if(selected)
        $('parentId').value=
            String(selected);
}


function toggleParent(){

    if(!canManage)
        return;


    const on=
        $('hasParent').checked;


    $('parentFields')
        .classList
        .toggle(
            'd-none',
            !on
        );


    $('parentType').required=
        on;

    $('parentId').required=
        on;


    if(!on){

        $('parentType').value='';

        $('parentId').innerHTML=
            '<option value="">Aucun</option>';
    }
}


async function load(){

    const q=
        new URLSearchParams();


    if($('search').value.trim())

        q.set(
            'search',
            $('search').value.trim()
        );


    if($('attachmentFilter').value)

        q.set(
            'rattachement',
            $('attachmentFilter').value
        );


    if($('validationFilter').value)

        q.set(
            'validation',
            $('validationFilter').value
        );


    try{

        const r=
            await STAGIA.request(
                BASE_URL+
                '/actions/academique/filiere-list.php?'+
                q.toString()
            );

        const d=r.data;


        items=d.items||[];
        units=d.units||[];
        departments=d.departments||[];
        curricula=d.curricula||[];
        rules=d.rules||{};


        $('kTotal').textContent=
            d.kpi.total||0;

        $('kNational').textContent=
            d.kpi.nationaux||0;

        $('kLocal').textContent=
            d.kpi.locaux||0;

        $('kActive').textContent=
            d.kpi.actifs||0;


        fillFormLists();


        $('rows').innerHTML=

            items.length

            ?items.map(x=>{

                const editable=
                    canManage &&
                    Number(x.ajoute_localement)===1 &&
                    x.validation_statut!=='REFUSE';


                return `
                    <tr>

                        <td>

                            <strong>
                                ${esc(x.nom)}
                            </strong>

                            <small class="d-block text-muted">
                                ${esc(x.code||'—')}
                                ${
                                    x.duree_annees
                                        ?` · ${Number(x.duree_annees)} an(s)`
                                        :''
                                }
                            </small>

                        </td>

                        <td>

                            <strong>
                                ${esc(x.curriculum_code||'—')}
                            </strong>

                            <small class="d-block text-muted">
                                ${esc(x.curriculum_nom||'Non classé')}
                            </small>

                        </td>

                        <td>
                            ${attachmentLabel(x)}
                        </td>

                        <td>
                            ${origin(x)}
                        </td>

                        <td>
                            ${statusBadge(x)}
                        </td>

                        <td class="text-center">

                            <span class="badge bg-light text-dark border">
                                ${Number(x.nb_options)||0}
                            </span>

                        </td>

                        <td class="text-center">

                            <span class="badge bg-light text-dark border">
                                ${Number(x.nb_promotions)||0}
                            </span>

                        </td>

                        <td class="text-end">

                            ${
                                editable

                                ?`
                                <button
                                    class="btn btn-sm btn-outline-primary edit-btn"
                                    data-id="${x.id}"
                                    title="Modifier"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>
                                `

                                :'<span class="text-muted">—</span>'
                            }

                        </td>

                    </tr>
                `;

            }).join('')

            :`
                <tr>
                    <td
                        colspan="8"
                        class="text-center py-5 text-muted"
                    >
                        Aucun élément.
                    </td>
                </tr>
            `;


        document
            .querySelectorAll('.edit-btn')
            .forEach(
                b=>
                    b.onclick=
                        ()=>edit(
                            Number(
                                b.dataset.id
                            )
                        )
            );


    }catch(e){

        $('rows').innerHTML=`
            <tr>
                <td
                    colspan="8"
                    class="text-center py-5 text-danger"
                >
                    ${esc(e.message)}
                </td>
            </tr>
        `;

        STAGIA.toast(
            e.message,
            'danger'
        );
    }
}


if(canManage){

    $('hasParent').onchange=
        toggleParent;


    $('parentType').onchange=
        ()=>fillParents();


    $('addProgramBtn').onclick=()=>{

        $('programForm').reset();

        $('programId').value='';

        fillFormLists();

        $('hasParent').checked=false;

        toggleParent();

        $('modalTitle').textContent=
            'Ajouter '+
            PROGRAM_SINGULAR;

        modal.show();
    };


    function edit(id){

        const x=
            items.find(
                v=>
                    Number(v.id)===id
            );

        if(!x)
            return;


        $('programForm').reset();

        $('programId').value=
            x.id;

        fillFormLists();


        $('programName').value=
            x.nom||'';

        $('curriculum').value=
            x.curriculum_reference_id||'';

        $('duration').value=
            x.duree_annees||'';

        $('prep').checked=
            Number(
                x.preparatory_level_enabled
            )===1;

        $('description').value=
            x.description||'';


        const hasParent=
            x.rattachement_type!=='ETABLISSEMENT';


        $('hasParent').checked=
            hasParent;


        toggleParent();


        if(hasParent){

            $('parentType').value=
                x.rattachement_type;

            fillParents(
                x.rattachement_type==='DEPARTEMENT'
                    ?x.departement_id
                    :x.faculte_id
            );
        }


        $('modalTitle').textContent=
            'Modifier '+
            PROGRAM_SINGULAR;


        modal.show();
    }


    $('programForm').onsubmit=
        async e=>{

            e.preventDefault();


            STAGIA.loading(
                $('saveBtn'),
                true
            );


            try{

                const fd=
                    new FormData(
                        e.currentTarget
                    );


                const id=
                    $('programId').value;


                const url=

                    id

                    ?BASE_URL+
                     '/actions/academique/filiere-update.php'

                    :BASE_URL+
                     '/actions/academique/filiere-store.php';


                const r=
                    await STAGIA.post(
                        url,
                        fd
                    );


                STAGIA.toast(
                    r.message
                );


                modal.hide();


                await load();


            }catch(e){

                STAGIA.toast(
                    e.message,
                    'danger'
                );

            }finally{

                STAGIA.loading(
                    $('saveBtn'),
                    false
                );
            }
        };
}


$('search').oninput=()=>{

    clearTimeout(timer);

    timer=setTimeout(
        load,
        300
    );
};


$('attachmentFilter').onchange=
    load;


$('validationFilter').onchange=
    load;


load();

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
