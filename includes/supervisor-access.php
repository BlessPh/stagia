<?php
/** Contrôles d'accès des encadreurs aux rotations de stage. */

/** Fusionne les rôles de session multiples et le rôle principal historique. */
function stagiaSessionRoleCodes():array
{
    $codes=$_SESSION['role_codes']??[];

    if(!is_array($codes)){
        $codes=[];
    }

    if(!empty($_SESSION['role_code'])){
        $codes[]=(string)$_SESSION['role_code'];
    }

    return array_values(
        array_unique(
            array_filter(
                array_map('strval',$codes)
            )
        )
    );
}

/** Indique si l'utilisateur possède l'administration globale de la structure d'accueil. */
function stagiaIsHospitalAdmin():bool
{
    return in_array(
        'ADMIN_ACCUEIL',
        stagiaSessionRoleCodes(),
        true
    );
}

/** Charge une rotation accessible : tout l'établissement pour l'admin, affectation nominative sinon. */
function stagiaSupervisorRotation(
    PDO $pdo,
    int $rotationId,
    int $establishmentId,
    int $userId
):?array{
    $admin=stagiaIsHospitalAdmin();

    $sql="
        SELECT
            r.id,
            r.assignment_id,
            r.host_etablissement_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            r.host_unit_id,
            hu.code unit_code,
            hu.nom unit_name,
            hu.type unit_type,
            parent.nom parent_name,
            c.id campaign_id,
            c.code campaign_code,
            c.titre campaign_title
        FROM stage_rotations r
        JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id
        JOIN stage_assignments sa ON sa.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=sa.admission_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
        JOIN stage_campaigns c ON c.id=pl.campaign_id
        WHERE r.id=?
          AND r.host_etablissement_id=?
          AND r.statut<>'ANNULEE'
    ";

    $params=[$rotationId,$establishmentId];

    if(!$admin){
        $sql.="
          AND EXISTS(
              SELECT 1
              FROM stage_rotation_supervisors rs
              WHERE rs.rotation_id=r.id
                AND rs.user_id=?
                AND rs.actif=1
          )
        ";
        $params[]=$userId;
    }

    $sql.=" LIMIT 1";

    $s=$pdo->prepare($sql);
    $s->execute($params);

    $row=$s->fetch(PDO::FETCH_ASSOC);

    return $row?:null;
}

/** Exige une rotation accessible et lève une exception métier si le périmètre est insuffisant. */
function requireSupervisorRotation(
    PDO $pdo,
    int $rotationId,
    int $establishmentId,
    int $userId
):array{
    $rotation=stagiaSupervisorRotation(
        $pdo,
        $rotationId,
        $establishmentId,
        $userId
    );

    if(!$rotation){
        throw new RuntimeException(
            "Cette rotation n'est pas accessible dans votre périmètre."
        );
    }

    return $rotation;
}
