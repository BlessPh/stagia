<?php
declare(strict_types=1);

final class StageLogbookValidationException extends RuntimeException
{
}

function stageLogbookNormalizeDate(string $date):string
{
    $date=trim($date);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $errors=DateTimeImmutable::getLastErrors();

    if(
        !$parsed ||
        ($errors!==false && ((int)$errors['warning_count']>0 || (int)$errors['error_count']>0)) ||
        $parsed->format('Y-m-d')!==$date
    ){
        throw new StageLogbookValidationException('La date du journal est invalide.');
    }

    return $date;
}

function stageLogbookValidateRotationDate(
    string $date,
    ?string $startDate,
    ?string $endDate,
    ?string $rotationStatus,
    ?string $today=null
):string{
    $date=stageLogbookNormalizeDate($date);
    $today=$today!==null?stageLogbookNormalizeDate($today):date('Y-m-d');
    $startDate=trim((string)$startDate);
    $endDate=trim((string)$endDate);
    $rotationStatus=strtoupper(trim((string)$rotationStatus));

    if(!in_array($rotationStatus,['ACTIVE','TERMINEE'],true)){
        throw new StageLogbookValidationException(
            'Cette rotation n’est pas disponible pour le journal.'
        );
    }

    if(
        $startDate==='' ||
        $endDate==='' ||
        $date<$startDate ||
        $date>$endDate ||
        $date>$today
    ){
        throw new StageLogbookValidationException(
            'La date du journal doit être comprise dans la période de la rotation et ne peut pas être future.'
        );
    }

    return $date;
}

function stageLogbookRotationDateIsValid(
    string $date,
    ?string $startDate,
    ?string $endDate,
    ?string $rotationStatus,
    ?string $today=null
):bool{
    try{
        stageLogbookValidateRotationDate(
            $date,
            $startDate,
            $endDate,
            $rotationStatus,
            $today
        );
        return true;
    }catch(StageLogbookValidationException){
        return false;
    }
}

function stageLogbookIsDuplicateError(Throwable $error):bool
{
    return $error instanceof PDOException &&
        (string)$error->getCode()==='23000' &&
        (int)($error->errorInfo[1]??0)===1062;
}

function stageLogbookEnsureNoDuplicate(
    PDO $pdo,
    int $studentId,
    int $rotationId,
    string $date,
    int $excludedEntryId=0,
    bool $lock=false
):void{
    $sql="
        SELECT id
        FROM stage_logbook_entries
        WHERE student_id=?
          AND rotation_id=?
          AND date_journal=?
    ";
    $params=[$studentId,$rotationId,$date];

    if($excludedEntryId>0){
        $sql.=' AND id<>?';
        $params[]=$excludedEntryId;
    }

    $sql.=' LIMIT 1';
    if($lock)$sql.=' FOR UPDATE';

    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);

    if($stmt->fetchColumn()){
        throw new StageLogbookValidationException(
            'Une entrée de journal existe déjà pour cette rotation à cette date.'
        );
    }
}
