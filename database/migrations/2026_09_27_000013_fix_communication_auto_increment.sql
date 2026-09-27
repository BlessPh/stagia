-- Corrige deux colonnes id dont l'attribut AUTO_INCREMENT peut manquer apres un import mysqldump.
ALTER TABLE `taches_asynchrones`
    MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sujets_forums`
    MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
