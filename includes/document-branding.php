<?php
/** Branding commun des documents officiels : données établissement, logo, signature et valeurs de repli. */
/* =========================================================
   STAGIA-RDC — Branding documents officiels
   ---------------------------------------------------------
   Logo principal : établissement émetteur.
   Filigrane      : logo STAGIA-RDC très léger.
========================================================= */

if(!function_exists('stagiaDocEsc')){
    /** Échappe une valeur avant son insertion dans le HTML d'un document. */
    function stagiaDocEsc($v):string{
        return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
    }
}

if(!function_exists('stagiaDocBaseUrl')){
    /** Retourne BASE_URL sans slash terminal. */
    function stagiaDocBaseUrl():string{
        return rtrim((string)(defined('BASE_URL')?BASE_URL:''),'/');
    }
}

if(!function_exists('stagiaDocPublicUrl')){
    /** Transforme un chemin de ressource relatif en URL publique exploitable dans un document. */
    function stagiaDocPublicUrl(?string $path):string{
        $path=trim((string)$path);
        if($path==='')return '';
        if(preg_match('~^https?://~i',$path))return $path;
        if(str_starts_with($path,'/'))return $path;
        return stagiaDocBaseUrl().'/'.ltrim($path,'/');
    }
}

if(!function_exists('stagiaDocPick')){
    /** Sélectionne la première colonne non vide parmi plusieurs variantes de schéma. */
    function stagiaDocPick(array $row,array $keys,string $default=''):string{
        foreach($keys as $k){
            if(array_key_exists($k,$row) && trim((string)$row[$k])!=='')return trim((string)$row[$k]);
        }
        return $default;
    }
}

if(!function_exists('stagiaDocColumnExists')){
    /** Vérifie une colonne pour conserver la compatibilité avec les migrations incomplètes. */
    function stagiaDocColumnExists(PDO $pdo,string $table,string $column):bool{
        try{
            $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $s->execute([$table,$column]);
            return (int)$s->fetchColumn()>0;
        }catch(Throwable $e){return false;}
    }
}

if(!function_exists('stagiaDocumentBrand')){
    /** Construit l'identité visuelle complète du document avec des valeurs de secours institutionnelles. */
    function stagiaDocumentBrand(PDO $pdo,?int $etablissementId=null,array $options=[]):array{
        if(!$etablissementId && function_exists('currentEtablissementId')){
            try{$etablissementId=(int)currentEtablissementId($pdo);}catch(Throwable $e){$etablissementId=0;}
        }

        $e=[];
        if($etablissementId){
            try{
                $s=$pdo->prepare("SELECT * FROM etablissements WHERE id=? LIMIT 1");
                $s->execute([$etablissementId]);
                $e=$s->fetch(PDO::FETCH_ASSOC)?:[];
            }catch(Throwable $err){
                error_log('[DOCUMENT BRANDING] '.$err->getMessage());
            }
        }

        $name=stagiaDocPick($e,['nom','denomination','raison_sociale','name'],'NOM DE L’ÉTABLISSEMENT');
        $city=stagiaDocPick($e,['ville','city'],'');
        $province=stagiaDocPick($e,['province','region'],'');
        $commune=stagiaDocPick($e,['commune'],'');
        $country=stagiaDocPick($e,['pays','country'],'RDC');

        $address=stagiaDocPick($e,['adresse_complete','adresse','address','localisation'],'');
        $pieces=array_filter([$address,$commune,$city,$province],fn($x)=>trim((string)$x)!=='');
        $addressLine=$pieces?implode(', ',$pieces):'Adresse complète de l’établissement';

        $phone=stagiaDocPick($e,['telephone','phone','tel','contact_phone','numero_telephone'],'');
        $email=stagiaDocPick($e,['email','mail','contact_email'],'');
        $website=stagiaDocPick($e,['site_web','website','web','url'],'');

        $logo=stagiaDocPick($e,['logo','logo_url','image_logo','logo_file','logo_etablissement'],'');
        $logoUrl=stagiaDocPublicUrl($logo);
        if($logoUrl==='')$logoUrl=stagiaDocBaseUrl().'/assets/img/logo.png';

        $subtitle=$options['subtitle']??stagiaDocPick($e,['document_faculte','faculte','document_departement','departement','service','type_etablissement'],'FACULTÉ / DÉPARTEMENT');

        /* Paramètres spécifiques aux documents officiels.
           Ces champs sont optionnels : si la migration n'est pas encore appliquée,
           les valeurs par défaut gardent le fonctionnement existant. */
        $secretariat=stagiaDocPick($e,['document_secretariat','secretariat_general','secretariat','service_emetteur'],'SECRÉTARIAT GÉNÉRAL À LA RECHERCHE');
        $faculty=stagiaDocPick($e,['document_faculte','faculte','faculty'],'FACULTÉ DE MÉDECINE');
        $department=stagiaDocPick($e,['document_departement','departement','department'],'');
        $signatoryName=stagiaDocPick($e,['document_signataire_nom','signataire_nom','responsable_nom'],'');
        $signatoryFunction=stagiaDocPick($e,['document_signataire_fonction','signataire_fonction','responsable_fonction'],'');
        $motto=$options['motto']??stagiaDocPick($e,['document_slogan','slogan'],'Former aujourd’hui pour un Congo meilleur demain');
        $footerText=stagiaDocPick($e,['document_footer','footer_text'],'Stages pour un avenir meilleur');
        $cachetUrl=stagiaDocPublicUrl(stagiaDocPick($e,['document_cachet','cachet','cachet_path'],''));
        $signatureUrl=stagiaDocPublicUrl(stagiaDocPick($e,['document_signature','signature','signature_path'],''));

        return [
            'id'=>(int)($e['id']??0),
            'name'=>$name,
            'code'=>(string)($e['code']??''),
            'address'=>$addressLine,
            'city'=>$city,
            'province'=>$province,
            'country'=>$country,
            'phone'=>$phone,
            'email'=>$email,
            'website'=>$website,
            'subtitle'=>$subtitle,
            'secretariat_label'=>$secretariat,
            'faculty_label'=>$faculty,
            'department_label'=>$department,
            'signatory_name'=>$signatoryName,
            'signatory_function'=>$signatoryFunction,
            'footer_text'=>$footerText,
            'cachet_url'=>$cachetUrl,
            'signature_url'=>$signatureUrl,
            'logo_url'=>$logoUrl,
            'stagia_logo_url'=>stagiaDocBaseUrl().'/assets/img/logo.png',
            'motto'=>$motto
        ];
    }
}

if(!function_exists('stagiaDocumentDate')){
    /** Formate une date pour affichage officiel, même lorsqu'elle est absente ou invalide. */
    function stagiaDocumentDate(?string $date):string{
        $date=trim((string)$date);
        if($date==='')return '..... / ..... / ........';
        $t=strtotime($date);
        return $t?date('d/m/Y',$t):$date;
    }
}
