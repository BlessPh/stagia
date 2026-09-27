<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/stage-statistics-export.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

try{
    $data=stageStatsExportData($pdo);
    $book=new Spreadsheet();
    $book->removeSheetByIndex(0);

    $headerStyle=[
        'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
        'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'172033']],
        'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],
        'borders'=>['bottom'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FF751F']]]
    ];

    $titleStyle=[
        'font'=>['bold'=>true,'size'=>16,'color'=>['rgb'=>'172033']]
    ];

    $makeSheet=function(
        string $name,
        array $headers,
        array $rows,
        array $keys
    )use($book,$headerStyle,$titleStyle){
        $sheet=$book->createSheet();
        $sheet->setTitle(substr($name,0,31));

        $sheet->setCellValue('A1','STAGIA-RDC — '.$name);
        $sheet->getStyle('A1')->applyFromArray($titleStyle);
        $sheet->mergeCells('A1:'.Coordinate::stringFromColumnIndex(max(1,count($headers))).'1');

        $rowNo=3;
        foreach($headers as $i=>$h)
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i+1).$rowNo,$h);

        $lastCol=Coordinate::stringFromColumnIndex(max(1,count($headers)));
        $sheet->getStyle("A$rowNo:{$lastCol}{$rowNo}")->applyFromArray($headerStyle);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A$rowNo:{$lastCol}{$rowNo}");

        foreach($rows as $r){
            $rowNo++;
            foreach($keys as $i=>$key)
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i+1).$rowNo,stageStatsExcelSafe($r[$key]??''));
        }

        if($rowNo>=4){
            $sheet->getStyle("A4:{$lastCol}{$rowNo}")
                ->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        }

        foreach(range(1,count($headers)) as $c){
            $letter=Coordinate::stringFromColumnIndex($c);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }

        $sheet->getRowDimension(3)->setRowHeight(22);
        return $sheet;
    };

    /* SYNTHÈSE */
    $s=$book->createSheet();
    $s->setTitle('Synthèse');
    $s->setCellValue('A1','STAGIA-RDC — Rapport statistique des stages');
    $s->getStyle('A1')->applyFromArray($titleStyle);
    $s->mergeCells('A1:D1');

    $r=3;
    $s->setCellValue("A$r",'Filtres appliqués');
    $s->getStyle("A$r")->getFont()->setBold(true);
    foreach($data['filters']['labels'] as $label=>$value){
        $r++;
        $s->setCellValue("A$r",$label);
        $s->setCellValue("B$r",stageStatsExcelSafe($value));
    }

    $r+=2;
    $s->setCellValue("A$r",'Indicateur');
    $s->setCellValue("B$r",'Valeur');
    $s->getStyle("A$r:B$r")->applyFromArray($headerStyle);

    foreach($data['kpi'] as $label=>$value){
        $r++;
        $s->setCellValue("A$r",$label);
        $s->setCellValue("B$r",stageStatsExcelSafe($value));
    }
    $s->getColumnDimension('A')->setWidth(32);
    $s->getColumnDimension('B')->setWidth(28);

    $makeSheet(
        'Stagiaires',
        ['Code STAGIA','Stagiaire','Campagne','Type de stage','Établissement formation','Accueil','Début','Fin','Présence %','Progression %','Évaluation','Convention','Statut'],
        $data['students'],
        ['stagia_code','student_name','campaign_title','stage_type','university_name','host_name','date_debut','date_fin','attendance_rate','task_progress','evaluation_status','convention_status','statut']
    );

    $makeSheet(
        'Campagnes',
        ['Code','Campagne','Type de stage','Établissement formation','Stagiaires','Confirmés','Terminés','Annulés'],
        $data['campaigns'],
        ['code','titre','stage_type','university_name','students','confirmed','finished','cancelled']
    );

    $makeSheet(
        'Accueil',
        ['Code','Établissement d’accueil','Stagiaires','Campagnes','Confirmés','Terminés'],
        $data['hosts'],
        ['code','nom','students','campaigns','confirmed','finished']
    );

    $makeSheet(
        'Présences',
        ['Code STAGIA','Stagiaire','Campagne','Accueil','Service','Date','Arrivée','Départ','Statut','Retard (min)','Source','Justification','Observation'],
        $data['attendance'],
        ['stagia_code','student_name','campaign_title','host_name','unit_name','date_presence','heure_arrivee','heure_depart','statut','minutes_retard','source','justification','observation']
    );

    $makeSheet(
        'Tâches',
        ['Code STAGIA','Stagiaire','Campagne','Accueil','Service','Tâche','Priorité','Échéance','Statut','Début','Terminée le','Validée le','Commentaire stagiaire','Commentaire encadreur'],
        $data['tasks'],
        ['stagia_code','student_name','campaign_title','host_name','unit_name','titre','priorite','date_echeance','statut','started_at','completed_at','validated_at','commentaire_stagiaire','commentaire_encadreur']
    );

    $makeSheet(
        'Évaluations',
        ['Code STAGIA','Stagiaire','Campagne','Accueil','Service','Type','Statut','Note finale','Appréciation','Points forts','Axes amélioration','Évaluée le','Validée le','Finalisée le'],
        $data['evaluations'],
        ['stagia_code','student_name','campaign_title','host_name','unit_name','type_evaluation','statut','note_finale','appreciation','points_forts','axes_amelioration','evaluated_at','validated_at','finalized_at']
    );

    $makeSheet(
        'Conventions',
        ['Référence','Titre','Version','Statut','Code STAGIA','Stagiaire','Campagne','Établissement formation','Accueil','Début','Fin','Émission','Signature étudiant','Signature formation','Signature accueil','Signée le','Archivée le'],
        $data['conventions'],
        ['reference','titre','version','statut','stagia_code','student_name','campaign_title','university_name','host_name','date_debut','date_fin','date_emission','date_signature_etudiant','date_signature_universite','date_signature_accueil','signed_at','archived_at']
    );

    $book->setActiveSheetIndex(0);

    $filename='stagia-rapport-stages-'.stageStatsExportFileSuffix($data).'.xlsx';
    while(ob_get_level())ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    header('X-Content-Type-Options: nosniff');

    $writer=new Xlsx($book);
    $writer->save('php://output');
    $book->disconnectWorksheets();
    exit;
}catch(Throwable $e){
    http_response_code(500);
    exit('Export Excel impossible : '.$e->getMessage());
}
