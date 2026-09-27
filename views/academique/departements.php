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
    empty($settings['departement_active'])
){
    http_response_code(403);
    exit(
        'Les départements ne sont pas activés pour cet établissement.'
    );
}

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$eid=(int)($_SESSION['etablissement_id']??0);

$departmentSingular='Département';
$departmentPlural='Départements';
$parentSingular='Unité académique';
$parentPlural='Unités académiques';
$programPlural='Filières / Programmes';

if(
    $eid>0 &&
    function_exists('academicLabels') &&
    function_exists('academicLabelFromMap')
){
    $academicLabels=academicLabels(
        $pdo,
        $eid
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

    $parentSingular=
        academicLabelFromMap(
            $academicLabels,
            'UNIT',
            false,
            $parentSingular
        );

    $parentPlural=
        academicLabelFromMap(
            $academicLabels,
            'UNIT',
            true,
            $parentPlural
        );

    $programPlural=
        academicLabelFromMap(
            $academicLabels,
            'PROGRAM',
            true,
            $programPlural
        );
}

$pageTitle=$departmentPlural;
$activePage='departements';
$canManage=hasPermission(
    $pdo,
    'academic.manage'
);

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.dep-kpis{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px
}
.dep-kpi,.dep-card{
    background:#fff;
    border:1px solid #e7ebf0;
    border-radius:14px
}
.dep-kpi{padding:16px}
.dep-kpi small{
    display:block;
    color:#64748b;
    font-size:11px;
    text-transform:uppercase
}
.dep-kpi strong{
    display:block;
    font-size:27px;
    margin-top:4px
}
.dep-card{overflow:hidden}
.parent-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:12px
}
@media(max-width:900px){
    .dep-kpis{
        grid-template-columns:repeat(2,1fr)
    }
}
@media(max-width:600px){
    .dep-kpis{
        grid-template-columns:1fr
    }
}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>
            <?= htmlspecialchars($departmentPlural) ?>
        </h1>

        <p>
            Organisez les structures réelles de votre établissement.
            Les ajouts locaux sont utilisables immédiatement.
        </p>
    </div>

    <?php if($canManage): ?>

    <button
        class="btn btn-primary-stagia"
        id="addDepartmentBtn"
    >
        <i class="bi bi-plus-lg me-1"></i>
        Ajouter <?= htmlspecialchars($departmentSingular) ?>
    </button>

    <?php endif; ?>
</div>

<div class="alert alert-light border mb-3">

    <i class="bi bi-info-circle text-primary me-1"></i>

    Les structures nationales restent protégées.
    Les structures créées par votre établissement sont
    <strong>actives immédiatement</strong>
    et peuvent être modifiées localement.

</div>

<div class="dep-kpis mb-4">

    <div class="dep-kpi">
        <small>Total</small>
        <strong id="kTotal">0</strong>
    </div>

    <div class="dep-kpi">
        <small>Nationaux</small>
        <strong id="kNational">0</strong>
    </div>

    <div class="dep-kpi">
        <small>Ajouts locaux</small>
        <strong id="kLocal">0</strong>
    </div>

    <div class="dep-kpi">
        <small>Actifs</small>
        <strong id="kActive">0</strong>
    </div>

</div>

<div class="dep-card">

<div class="p-3 border-bottom">

<div class="row g-2">

    <div class="col-lg-5">

        <input
            id="search"
            class="form-control"
            placeholder="Rechercher <?= htmlspecialchars(mb_strtolower($departmentSingular)) ?>, spécialisation ou code..."
        >

    </div>

    <div class="col-lg-4">

        <select
            id="faculteFilter"
            class="form-select"
        >

            <option value="">
                Tous les rattachements
            </option>

        </select>

    </div>

    <div class="col-lg-3">

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
        <?= htmlspecialchars(mb_strtoupper($departmentSingular)) ?>
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
        <?= htmlspecialchars(mb_strtoupper($programPlural)) ?>
    </th>

    <th>
        CRÉÉ LE
    </th>

    <th class="text-end">
        ACTION
    </th>

</tr>

</thead>

<tbody id="rows">

<tr>
    <td
        colspan="7"
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
    id="departmentModal"
    tabindex="-1"
>

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content">

<form id="departmentForm">

<div class="modal-header">

    <div>

        <h5
            class="modal-title"
            id="modalTitle"
        >
            Ajouter <?= htmlspecialchars($departmentSingular) ?>
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
        id="departmentId"
    >

    <div class="mb-3">

        <label class="form-label">
            Nom *
        </label>

        <input
            name="nom"
            id="departmentName"
            class="form-control"
            maxlength="150"
            required
        >

    </div>

    <div class="mb-3">

        <label class="form-label">
            Spécialisation
            <span class="text-muted fw-normal">
                (facultatif)
            </span>
        </label>

        <input
            name="specialisation"
            id="departmentSpecialization"
            class="form-control"
            maxlength="180"
            placeholder="Ex. Chirurgie orthopédique"
        >

        <small class="text-muted">
            Information descriptive uniquement.
            Elle ne remplace pas le niveau Option / Spécialité.
        </small>

    </div>

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
            id="parentWrap"
            class="d-none"
        >

            <label class="form-label">
                Choisir le parent *
            </label>

            <select
                name="faculte_id"
                id="departmentFaculty"
                class="form-select"
            ></select>

        </div>

        <small
            class="text-muted"
            id="parentHint"
        >
            Si cette case reste décochée,
            la structure sera directement rattachée à l’établissement.
        </small>

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
      DEPARTMENT_SINGULAR=
        <?= json_encode($departmentSingular,JSON_UNESCAPED_UNICODE) ?>;

let items=[],
    facultes=[],
    timer;

const modal=
    canManage
        ?new bootstrap.Modal(
            $('departmentModal')
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


function fillFacultes(){

    const filterCurrent=
        $('faculteFilter').value;

    $('faculteFilter').innerHTML=
        '<option value="">Tous les rattachements</option>'+

        facultes.map(
            x=>`
                <option value="${x.id}">
                    ${esc(x.nom)}
                </option>
            `
        ).join('');

    $('faculteFilter').value=
        filterCurrent;


    if(canManage){

        $('departmentFaculty').innerHTML=
            '<option value="">Sélectionner...</option>'+

            facultes.map(
                x=>`
                    <option value="${x.id}">
                        ${esc(x.nom)}
                    </option>
                `
            ).join('');
    }
}


function toggleParent(){

    if(!canManage)return;

    const on=$('hasParent').checked;

    $('parentWrap')
        .classList
        .toggle(
            'd-none',
            !on
        );

    $('departmentFaculty').required=on;

    if(!on)
        $('departmentFaculty').value='';
}


async function load(){

    const q=
        new URLSearchParams();

    const search=
        $('search').value.trim();

    if(search)
        q.set(
            'search',
            search
        );

    if($('faculteFilter').value)
        q.set(
            'faculte_id',
            $('faculteFilter').value
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
                '/actions/academique/departement-list.php?'+
                q.toString()
            );

        const d=r.data;

        items=d.items||[];
        facultes=d.facultes||[];

        fillFacultes();

        $('kTotal').textContent=
            d.kpi.total||0;

        $('kNational').textContent=
            d.kpi.nationaux||0;

        $('kLocal').textContent=
            d.kpi.locaux||0;

        $('kActive').textContent=
            d.kpi.actifs||0;


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

                            ${
                                x.specialisation
                                    ?`
                                    <small class="d-block text-muted">
                                        Spécialisation :
                                        ${esc(x.specialisation)}
                                    </small>
                                    `
                                    :''
                            }

                            <small class="d-block text-muted">
                                ${esc(x.code||'—')}
                            </small>

                        </td>

                        <td>
                            ${esc(
                                x.faculte_nom||
                                'Directement sous l’établissement'
                            )}
                        </td>

                        <td>
                            ${origin(x)}
                        </td>

                        <td>
                            ${statusBadge(x)}
                        </td>

                        <td class="text-center">

                            <span class="badge bg-light text-dark border">
                                ${Number(x.programmes_count)||0}
                            </span>

                        </td>

                        <td>
                            ${esc(x.date_creation||'—')}
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
                        colspan="7"
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
                    colspan="7"
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

    $('hasParent')
        .onchange=
            toggleParent;


    $('addDepartmentBtn').onclick=()=>{

        $('departmentForm').reset();

        $('departmentId').value='';

        fillFacultes();

        $('hasParent').checked=false;

        toggleParent();

        $('modalTitle').textContent=
            'Ajouter '+
            DEPARTMENT_SINGULAR;

        modal.show();
    };


    function edit(id){

        const x=
            items.find(
                v=>
                    Number(v.id)===id
            );

        if(!x)return;


        $('departmentForm').reset();

        $('departmentId').value=
            x.id;

        $('departmentName').value=
            x.nom||'';

        $('departmentSpecialization').value=
            x.specialisation||'';

        fillFacultes();

        $('hasParent').checked=
            !!x.faculte_id;

        toggleParent();

        $('departmentFaculty').value=
            x.faculte_id||'';

        $('modalTitle').textContent=
            'Modifier '+
            DEPARTMENT_SINGULAR;

        modal.show();
    }


    $('departmentForm').onsubmit=
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
                    $('departmentId').value;

                const url=
                    id

                        ?BASE_URL+
                         '/actions/academique/departement-update.php'

                        :BASE_URL+
                         '/actions/academique/departement-store.php';


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


$('faculteFilter').onchange=
    load;

$('validationFilter').onchange=
    load;


load();

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
