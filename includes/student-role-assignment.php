<?php

/**
 * Garantit qu'un compte lie a un profil etudiant possede une affectation
 * STAGIAIRE active dans le RBAC moderne.
 *
 * Cette operation est idempotente et ne restaure pas silencieusement une
 * ancienne affectation revoquee.
 */
function ensureStudentRoleAssignment(
    PDO $pdo,
    int $userId,
    ?int $etablissementId,
    ?int $assignedBy=null
):int {
    if($userId<=0){
        throw new InvalidArgumentException('Compte étudiant invalide.');
    }

    $stmt=$pdo->prepare("SELECT id FROM roles WHERE code='STAGIAIRE' AND actif=1 LIMIT 1");
    $stmt->execute();
    $roleId=(int)$stmt->fetchColumn();

    if($roleId<=0){
        throw new RuntimeException('Le rôle STAGIAIRE est introuvable ou inactif.');
    }

    $stmt=$pdo->prepare("
        SELECT id
        FROM role_assignments
        WHERE user_id=?
          AND role_id=?
          AND actif=1
          AND (starts_at IS NULL OR starts_at<=NOW())
          AND (ends_at IS NULL OR ends_at>=NOW())
        ORDER BY principal DESC,id
        LIMIT 1
    ");
    $stmt->execute([$userId,$roleId]);
    $assignmentId=(int)$stmt->fetchColumn();

    if($assignmentId<=0){
        $stmt=$pdo->prepare("
            SELECT COUNT(*)
            FROM role_assignments
            WHERE user_id=?
              AND actif=1
              AND principal=1
              AND (starts_at IS NULL OR starts_at<=NOW())
              AND (ends_at IS NULL OR ends_at>=NOW())
        ");
        $stmt->execute([$userId]);
        $principal=(int)$stmt->fetchColumn()===0?1:0;

        $stmt=$pdo->prepare("
            INSERT INTO role_assignments(
                user_id,role_id,scope_type,scope_id,etablissement_id,
                principal,actif,assigned_by
            ) VALUES(?,?,'SELF',?,?,?,1,?)
        ");
        $stmt->execute([
            $userId,
            $roleId,
            $userId,
            $etablissementId?:null,
            $principal,
            $assignedBy?:null
        ]);
        $assignmentId=(int)$pdo->lastInsertId();
    }

    /* Compatibilite avec les ecrans qui consultent encore users.role_id. */
    $stmt=$pdo->prepare("UPDATE users SET role_id=? WHERE id=? AND (role_id IS NULL OR role_id=0)");
    $stmt->execute([$roleId,$userId]);

    return $assignmentId;
}
