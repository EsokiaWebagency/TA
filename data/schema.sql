CREATE TABLE `file_import_contents` (  
    `id` INT(11)  PRIMARY KEY AUTO_INCREMENT,
    `compte` INT(11) NULL , 
    `facture` INT(11) NULL , 
    `abonne` INT(11) NULL , 
    `date` VARCHAR(11) NULL , 
    `heure` VARCHAR(11) NULL , 
    `duree_volume_reel` VARCHAR(11) NULL , 
    `duree_volume_facture` VARCHAR(11) NULL , 
    `type` VARCHAR(50) NULL
) ENGINE = InnoDB;

CREATE TABLE `appel` (  
    `id` INT(11)  PRIMARY KEY AUTO_INCREMENT,
    `abonne_id` INT(11) NULL , 
    `date` DATE NULL , 
    `heure` TIME NULL , 
    `duree_appel_reel` TIME NULL , 
    `volume_data_facture` FLOAT(10,2) NULL , 
    `type` VARCHAR(50) NULL
) ENGINE = InnoDB;