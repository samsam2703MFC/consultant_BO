-- PPWR readiness profiles for packaging materials.
-- Run manually against the application database after verifying the backup.
-- raw_material remains the single master list; PPWR data is available only for
-- rows where raw_material.is_part_of_package = 1.

CREATE TABLE raw_material_ppwr_profile (
    id INT NOT NULL AUTO_INCREMENT,
    id_raw_material INT NOT NULL,
    identification_number VARCHAR(255) NULL,
    identification_source ENUM('OWN', 'SUPPLIER') NULL,
    packaging_level ENUM('SALES', 'INNER', 'GROUPED', 'TRANSPORT', 'E_COMMERCE') NULL,
    packaging_type VARCHAR(100) NULL,
    dominant_material ENUM(
        'PLASTIC', 'PAPER_CARDBOARD', 'GLASS', 'ALUMINIUM', 'FERROUS_METAL',
        'WOOD', 'MULTIMATERIAL', 'OTHER'
    ) NULL,
    food_contact_status ENUM('YES', 'NO', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    recycled_content_percent DECIMAL(5,2) NULL,
    recycled_content_status ENUM('NOT_APPLICABLE', 'NOT_ASSESSED', 'DECLARED', 'VERIFIED') NOT NULL DEFAULT 'NOT_ASSESSED',
    recyclability_status ENUM('NOT_ASSESSED', 'ASSESSED', 'VERIFIED', 'NOT_RECYCLABLE') NOT NULL DEFAULT 'NOT_ASSESSED',
    recyclability_grade ENUM('A', 'B', 'C') NULL,
    recyclability_assessment_date DATE NULL,
    reuse_status ENUM('NOT_APPLICABLE', 'SINGLE_USE', 'REUSABLE', 'REFILL') NOT NULL DEFAULT 'NOT_APPLICABLE',
    target_reuse_cycles INT NULL,
    return_system_description VARCHAR(500) NULL,
    minimisation_status ENUM('NOT_ASSESSED', 'ASSESSED', 'VERIFIED') NOT NULL DEFAULT 'NOT_ASSESSED',
    minimisation_justification TEXT NULL,
    verification_status ENUM('DRAFT', 'REQUIRES_DATA', 'COMPLETE', 'VERIFIED') NOT NULL DEFAULT 'DRAFT',
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_raw_material_ppwr_profile_material (id_raw_material),
    KEY idx_raw_material_ppwr_profile_status (verification_status),
    CONSTRAINT fk_raw_material_ppwr_profile_material
        FOREIGN KEY (id_raw_material) REFERENCES raw_material (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE raw_material_ppwr_dimension (
    id INT NOT NULL AUTO_INCREMENT,
    id_raw_material_ppwr_profile INT NOT NULL,
    length_mm DECIMAL(12,3) NULL,
    width_mm DECIMAL(12,3) NULL,
    height_mm DECIMAL(12,3) NULL,
    outer_volume_cm3 DECIMAL(16,3) NULL,
    inner_volume_cm3 DECIMAL(16,3) NULL,
    measurement_source ENUM('SUPPLIER', 'INTERNAL_MEASUREMENT', 'SPECIFICATION', 'LEGACY_DESCRIPTION') NULL,
    measured_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_raw_material_ppwr_dimension_profile_active (id_raw_material_ppwr_profile, is_active),
    CONSTRAINT fk_raw_material_ppwr_dimension_profile
        FOREIGN KEY (id_raw_material_ppwr_profile) REFERENCES raw_material_ppwr_profile (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE raw_material_ppwr_document (
    id INT NOT NULL AUTO_INCREMENT,
    id_raw_material_ppwr_profile INT NOT NULL,
    document_type ENUM('SUPPLIER_DECLARATION', 'RECYCLED_CONTENT_CERTIFICATE', 'RECYCLABILITY_ASSESSMENT', 'DECLARATION_OF_CONFORMITY', 'OTHER') NOT NULL,
    document_number VARCHAR(255) NULL,
    issuer_name VARCHAR(255) NULL,
    issued_at DATE NULL,
    valid_until DATE NULL,
    storage_path VARCHAR(500) NULL,
    verification_status ENUM('PENDING', 'VERIFIED', 'REJECTED', 'EXPIRED') NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_raw_material_ppwr_document_profile (id_raw_material_ppwr_profile),
    KEY idx_raw_material_ppwr_document_valid_until (valid_until),
    CONSTRAINT fk_raw_material_ppwr_document_profile
        FOREIGN KEY (id_raw_material_ppwr_profile) REFERENCES raw_material_ppwr_profile (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
