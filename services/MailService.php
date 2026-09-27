<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__.'/../vendor/autoload.php';

class MailService
{
    private static string $lastError='';

    public static function getLastError():string
    {
        return self::$lastError;
    }

    private static function mailer():PHPMailer
    {
        $config=require __DIR__.'/../config/mail.php';
        if($config['username']==='' || $config['password']==='' || $config['from_email']===''){
            throw new RuntimeException('Configuration SMTP incomplète.');
        }

        $mail=new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host=$config['host'];
        $mail->SMTPAuth=true;
        $mail->Username=$config['username'];
        $mail->Password=$config['password'];
        $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port=$config['port'];
        $mail->CharSet='UTF-8';
        $mail->setFrom($config['from_email'],$config['from_name']);
        return $mail;
    }

    public static function envoyerActivation(string $email,string $nom,string $lien):bool
    {
        try{
            self::$lastError='';
            $mail=self::mailer();
            $mail->addAddress($email,$nom);
            $mail->isHTML(true);
            $mail->Subject='Activation de votre compte STAGIA-RDC';
            $nomSafe=htmlspecialchars($nom,ENT_QUOTES,'UTF-8');
            $lienSafe=htmlspecialchars($lien,ENT_QUOTES,'UTF-8');
            $mail->Body="
                <h2>Bienvenue sur STAGIA-RDC</h2>
                <p>Bonjour <strong>{$nomSafe}</strong>,</p>
                <p>Votre compte a été créé.</p>
                <p><a href=\"{$lienSafe}\">Activer mon compte</a></p>
                <p>Ce lien est valable pendant 48 heures.</p>
                <p>STAGIA-RDC</p>
            ";
            $mail->AltBody="Bonjour {$nom},\n\nActivez votre compte STAGIA-RDC :\n{$lien}\n\nCe lien est valable pendant 48 heures.";
            $mail->send();
            return true;
        }catch(Throwable $e){
            self::$lastError=$e->getMessage();
            error_log('[SMTP ACTIVATION] '.self::$lastError);
            return false;
        }
    }

    public static function envoyerReinitialisationMotDePasse(
        string $email,
        string $nom,
        string $lien
    ):bool{
        try{
            self::$lastError='';
            $mail=self::mailer();
            $mail->addAddress($email,$nom);
            $mail->isHTML(true);
            $mail->Subject='Réinitialisation de votre mot de passe STAGIA-RDC';
            $nomSafe=htmlspecialchars($nom,ENT_QUOTES,'UTF-8');
            $lienSafe=htmlspecialchars($lien,ENT_QUOTES,'UTF-8');
            $mail->Body="
                <h2>Réinitialisation du mot de passe</h2>
                <p>Bonjour <strong>{$nomSafe}</strong>,</p>
                <p>Une demande de réinitialisation a été reçue pour votre compte STAGIA-RDC.</p>
                <p><a href=\"{$lienSafe}\">Choisir un nouveau mot de passe</a></p>
                <p>Ce lien est valable pendant 60 minutes et ne peut être utilisé qu'une seule fois.</p>
                <p>Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.</p>
            ";
            $mail->AltBody="Bonjour {$nom},\n\nRéinitialisez votre mot de passe STAGIA-RDC :\n{$lien}\n\nCe lien est valable pendant 60 minutes et ne peut être utilisé qu'une seule fois.";
            $mail->send();
            return true;
        }catch(Throwable $e){
            self::$lastError=$e->getMessage();
            error_log('[SMTP PASSWORD RESET] '.self::$lastError);
            return false;
        }
    }
}
