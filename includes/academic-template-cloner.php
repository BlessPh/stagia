<?php
/**
 * Applique à un établissement le modèle académique choisi par STAGIA.
 *
 * IMPORTANT :
 * - le type d'établissement (UNIVERSITE, INSTITUT_SUPERIEUR, ...) ne suffit
 *   pas à déterminer sa structure réelle ;
 * - le Super Admin doit choisir explicitement le modèle académique applicable ;
 * - deux UNIVERSITE peuvent donc recevoir des modèles différents.
 *
 * $templateId doit être l'id de academic_structure_templates sélectionné
 * par STAGIA lors de la validation/configuration de l'établissement.
 */
function applyAcademicTemplate(
    PDO $pdo,
    int $etablissementId,
    array $type,
    ?int $templateId=null
):void{
    if(!(int)($type['academic_enabled']??0))return;

    $templateId=(int)($templateId??($type['academic_template_id']??0));
    if($templateId<1)
        throw new RuntimeException(
            "Aucun modèle académique n'a été affecté à cet établissement. ".
            "Le Super Admin doit choisir sa structure STAGIA avant l'activation."
        );

    /* Le modèle choisi doit appartenir au type de l'établissement. */
    $s=$pdo->prepare("
        SELECT *
        FROM academic_structure_templates
        WHERE id=?
          AND type_etablissement=?
          AND actif=1
        LIMIT 1
    ");
    $s->execute([$templateId,$type['code']]);
    $tpl=$s->fetch(PDO::FETCH_ASSOC);

    if(!$tpl)
        throw new RuntimeException(
            "Le modèle académique sélectionné est introuvable, inactif ".
            "ou incompatible avec le type « ".($type['libelle']??$type['code'])." »."
        );

    $tid=(int)$tpl['id'];
    $userId=(int)($_SESSION['user_id']??0)?:null;

    /* Ne jamais appliquer deux fois une configuration structurelle. */
    $s=$pdo->prepare("
        SELECT source_template_id
        FROM etablissement_academic_settings
        WHERE etablissement_id=?
        LIMIT 1
    ");
    $s->execute([$etablissementId]);
    $existing=$s->fetchColumn();

    if($existing!==false)
        throw new RuntimeException(
            "Cet établissement possède déjà une configuration académique. ".
            "Utilisez le workflow de reconfiguration au lieu de recloner le modèle."
        );

    /* Types d'unités réellement autorisés par CE modèle. */
    $allowedTypes=[];
    if((int)$tpl['unite_academique_active']===1){
        $s=$pdo->prepare("
            SELECT id,type_unite,libelle,ordre
            FROM academic_structure_template_unit_types
            WHERE template_id=? AND actif=1
            ORDER BY ordre,id
        ");
        $s->execute([$tid]);
        $templateUnitTypes=$s->fetchAll(PDO::FETCH_ASSOC);

        foreach($templateUnitTypes as $u)
            $allowedTypes[strtoupper(trim((string)$u['type_unite']))]=$u;

        if(!$allowedTypes)
            throw new RuntimeException(
                "Le modèle active les unités académiques mais aucun type d'unité n'est autorisé."
            );
    }else{
        $templateUnitTypes=[];
    }

    /* Configuration effective de l'établissement. */
    $pdo->prepare("
        INSERT INTO etablissement_academic_settings(
            etablissement_id,source_template_id,source_template_version,
            unite_academique_active,unite_academique_obligatoire,unite_parentale_autorisee,
            departement_active,departement_obligatoire,
            filiere_active,filiere_obligatoire,
            filiere_directe_etablissement_autorisee,filiere_directe_unite_autorisee,
            option_specialite_active,option_specialite_obligatoire,
            promotion_active,promotion_obligatoire,
            configuration,personnalise,configuration_statut,
            configuration_completed_at,configuration_completed_by_user_id,
            applied_at,updated_by_user_id
        )
        SELECT ?,id,version_no,
               unite_academique_active,unite_academique_obligatoire,unite_parentale_autorisee,
               departement_active,departement_obligatoire,
               filiere_active,filiere_obligatoire,
               filiere_directe_etablissement_autorisee,filiere_directe_unite_autorisee,
               option_specialite_active,option_specialite_obligatoire,
               promotion_active,promotion_obligatoire,
               configuration,0,'TERMINEE',NOW(),?,NOW(),?
        FROM academic_structure_templates
        WHERE id=?
    ")->execute([$etablissementId,$userId,$userId,$tid]);

    /* Copier uniquement les types explicitement autorisés par le modèle choisi. */
    if($templateUnitTypes){
        $ins=$pdo->prepare("
            INSERT INTO etablissement_academic_unit_types(
                etablissement_id,source_template_unit_type_id,
                type_unite,libelle,ordre,actif,ajoute_localement
            ) VALUES(?,?,?,?,?,1,0)
        ");

        foreach($templateUnitTypes as $u)
            $ins->execute([
                $etablissementId,
                (int)$u['id'],
                strtoupper(trim((string)$u['type_unite'])),
                $u['libelle'],
                (int)$u['ordre']
            ]);
    }

    /* =========================================================
       UNITÉS ACADÉMIQUES
       Ex.: FACULTE ou SECTION selon le modèle choisi.
       Si le modèle n'utilise aucune unité, cette étape est vide.
    ========================================================= */
    $unitMap=[];

    if((int)$tpl['unite_academique_active']===1){
        $s=$pdo->prepare("
            SELECT *
            FROM academic_template_units
            WHERE template_id=? AND actif=1
            ORDER BY ordre,id
        ");
        $s->execute([$tid]);
        $pending=$s->fetchAll(PDO::FETCH_ASSOC);

        foreach($pending as $u){
            $unitType=strtoupper(trim((string)$u['type_unite']));
            if(!isset($allowedTypes[$unitType]))
                throw new RuntimeException(
                    "Le modèle contient l'unité « {$u['nom']} » de type « $unitType », ".
                    "mais ce type n'est pas autorisé par ce modèle."
                );
        }

        $insertUnit=$pdo->prepare("
            INSERT INTO facultes(
                etablissement_id,type_unite,parent_id,
                source_template_unit_id,ajoute_localement,
                validation_statut,code,nom,actif
            ) VALUES(?,?,?,?,0,'NATIONAL',?,?,1)
        ");

        while($pending){
            $next=[];$progress=false;

            foreach($pending as $u){
                $parentTpl=(int)($u['parent_id']??0);

                if($parentTpl&&!isset($unitMap[$parentTpl])){
                    $next[]=$u;
                    continue;
                }

                $insertUnit->execute([
                    $etablissementId,
                    strtoupper(trim((string)$u['type_unite'])),
                    $parentTpl?$unitMap[$parentTpl]:null,
                    (int)$u['id'],
                    $u['code'],
                    $u['nom']
                ]);

                $unitMap[(int)$u['id']]=(int)$pdo->lastInsertId();
                $progress=true;
            }

            if(!$progress&&$next)
                throw new RuntimeException(
                    "Hiérarchie invalide dans les unités du modèle académique."
                );

            $pending=$next;
        }
    }

    /* =========================================================
       DÉPARTEMENTS
       unit_id peut être NULL pour une université sans facultés.
    ========================================================= */
    $depMap=[];
    if((int)$tpl['departement_active']===1){
        $s=$pdo->prepare("
            SELECT *
            FROM academic_template_departments
            WHERE template_id=? AND actif=1
            ORDER BY ordre,id
        ");
        $s->execute([$tid]);

        $ins=$pdo->prepare("
            INSERT INTO departements(
                etablissement_id,faculte_id,source_template_department_id,
                ajoute_localement,validation_statut,code,nom,actif
            ) VALUES(?,?,?,0,'NATIONAL',?,?,1)
        ");

        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $d){
            $unit=(int)($d['unit_id']??0);

            if($unit&&!isset($unitMap[$unit]))
                throw new RuntimeException(
                    "Le département « {$d['nom']} » référence une unité absente du modèle appliqué."
                );

            $ins->execute([
                $etablissementId,
                $unit?$unitMap[$unit]:null,
                (int)$d['id'],
                $d['code'],
                $d['nom']
            ]);

            $depMap[(int)$d['id']]=(int)$pdo->lastInsertId();
        }
    }

    /* =========================================================
       FILIÈRES / PROGRAMMES
    ========================================================= */
    $programMap=[];
    if((int)$tpl['filiere_active']===1){
        $s=$pdo->prepare("
            SELECT *
            FROM academic_template_programs
            WHERE template_id=? AND actif=1
            ORDER BY ordre,id
        ");
        $s->execute([$tid]);

        $ins=$pdo->prepare("
            INSERT INTO filieres(
                etablissement_id,faculte_id,departement_id,
                source_template_program_id,ajoute_localement,
                validation_statut,curriculum_reference_id,
                preparatory_level_enabled,code,nom,duree_annees,actif
            ) VALUES(?,?,?,?,0,'NATIONAL',?,?,?,?,?,1)
        ");

        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $p){
            $unit=(int)($p['unit_id']??0);
            $dep=(int)($p['department_id']??0);

            if($unit&&!isset($unitMap[$unit]))
                throw new RuntimeException(
                    "La filière « {$p['nom']} » référence une unité absente."
                );

            if($dep&&!isset($depMap[$dep]))
                throw new RuntimeException(
                    "La filière « {$p['nom']} » référence un département absent."
                );

            $ins->execute([
                $etablissementId,
                $unit?$unitMap[$unit]:null,
                $dep?$depMap[$dep]:null,
                (int)$p['id'],
                $p['curriculum_reference_id'],
                $p['preparatory_level_enabled'],
                $p['code'],
                $p['nom'],
                $p['duree_annees']
            ]);

            $programMap[(int)$p['id']]=(int)$pdo->lastInsertId();
        }
    }

    /* =========================================================
       OPTIONS / SPÉCIALITÉS
    ========================================================= */
    if((int)$tpl['option_specialite_active']===1){
        $s=$pdo->prepare("
            SELECT *
            FROM academic_template_options
            WHERE template_id=? AND actif=1
            ORDER BY ordre,id
        ");
        $s->execute([$tid]);

        $ins=$pdo->prepare("
            INSERT INTO options_specialites(
                etablissement_id,filiere_id,source_template_option_id,
                ajoute_localement,validation_statut,code,nom,actif
            ) VALUES(?,?,?,0,'NATIONAL',?,?,1)
        ");

        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $o){
            $pid=(int)$o['program_id'];

            if(!isset($programMap[$pid]))
                throw new RuntimeException(
                    "L'option « {$o['nom']} » référence une filière absente."
                );

            $ins->execute([
                $etablissementId,
                $programMap[$pid],
                (int)$o['id'],
                $o['code'],
                $o['nom']
            ]);
        }
    }
}


function applyHostUnitTemplate(PDO $pdo,int $etablissementId,array $type):void{
    if(!(int)($type['host_enabled']??0))return;

    $s=$pdo->prepare("
        SELECT *
        FROM host_unit_templates
        WHERE establishment_type_code=? AND actif=1
        ORDER BY ordre,id
    ");
    $s->execute([$type['code']]);
    $pending=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$pending)return;

    $map=[];
    $insert=$pdo->prepare("
        INSERT INTO host_units(
            host_etablissement_id,parent_id,source_template_host_unit_id,
            ajoute_localement,validation_statut,code,nom,type,
            description,capacite,actif
        ) VALUES(?,?,?,0,'NATIONAL',?,?,?,?,?,1)
    ");

    while($pending){
        $next=[];$progress=false;

        foreach($pending as $u){
            $parentTpl=(int)($u['parent_id']??0);

            if($parentTpl&&!isset($map[$parentTpl])){
                $next[]=$u;
                continue;
            }

            $insert->execute([
                $etablissementId,
                $parentTpl?$map[$parentTpl]:null,
                $u['id'],
                $u['code'],
                $u['nom'],
                $u['type'],
                $u['description'],
                $u['capacite']
            ]);

            $map[(int)$u['id']]=(int)$pdo->lastInsertId();
            $progress=true;
        }

        if(!$progress&&$next)
            throw new RuntimeException(
                "Hiérarchie invalide dans le modèle de services / unités."
            );

        $pending=$next;
    }
}
