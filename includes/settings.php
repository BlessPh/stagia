<?php
/**
 * STAGIA-RDC - Lecture des paramètres globaux.
 * Aucun accès direct au tableau $_POST : toutes les valeurs sont centralisées ici.
 */

/** Lit un paramètre global, convertit son type et le mémorise pour la durée de la requête. */
function setting(PDO $pdo,string $key,mixed $default=null):mixed{
    static $cache=[];

    if(array_key_exists($key,$cache))return $cache[$key];

    try{
        $s=$pdo->prepare("SELECT setting_value,setting_type FROM system_settings WHERE setting_key=? LIMIT 1");
        $s->execute([$key]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)return $cache[$key]=$default;

        $value=$row['setting_value'];
        $typed=match($row['setting_type']){
            'BOOLEAN'=>(bool)(int)$value,
            'INTEGER'=>(int)$value,
            default=>$value
        };
        return $cache[$key]=$typed;
    }catch(Throwable $e){
        return $default;
    }
}

/** Point d'extension documentaire : la cache statique est automatiquement réinitialisée à la fin de la requête. */
function clearSettingsCache():void{
    /* La cache de setting() vit uniquement pendant la requête PHP courante. */
}

/** Version typée booléenne de setting(). */
function settingBool(PDO $pdo,string $key,bool $default=false):bool{
    return (bool)setting($pdo,$key,$default);
}

/** Version typée entière de setting(). */
function settingInt(PDO $pdo,string $key,int $default=0):int{
    return (int)setting($pdo,$key,$default);
}
