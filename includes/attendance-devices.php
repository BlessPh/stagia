<?php
/** Utilitaires des appareils de pointage : identifiant unique et source normalisée. */
if(!function_exists('attendanceDeviceUuid')){
    /** Génère un UUID v4 cryptographiquement aléatoire pour identifier un appareil. */
    function attendanceDeviceUuid():string{
        $d=random_bytes(16);
        $d[6]=chr((ord($d[6])&15)|64);
        $d[8]=chr((ord($d[8])&63)|128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
    }
}
if(!function_exists('attendanceDeviceSource')){
    /** Traduit le type matériel en source de pointage reconnue par le domaine métier. */
    function attendanceDeviceSource(string $type):string{
        return match($type){
            'BIOMETRIE'=>'BIOMETRIE',
            'QR'=>'QR',
            'RFID_NFC'=>'RFID',
            'MOBILE'=>'MOBILE',
            default=>'APPAREIL'
        };
    }
}
