-- Add contract management fields to plots table
ALTER TABLE plots 
ADD COLUMN contract_start_date DATE DEFAULT NULL,
ADD COLUMN contract_end_date DATE DEFAULT NULL,
ADD COLUMN contract_type ENUM('perpetual', 'temporary', 'lease') DEFAULT 'perpetual',
ADD COLUMN contract_status ENUM('active', 'expired', 'renewal_needed', 'cancelled') DEFAULT 'active',
ADD COLUMN contract_notes TEXT DEFAULT NULL,
ADD COLUMN renewal_reminder_date DATE DEFAULT NULL;

-- Add contract management fields to deceased_records table
ALTER TABLE deceased_records 
ADD COLUMN contract_id VARCHAR(50) DEFAULT NULL,
ADD COLUMN contract_document_path VARCHAR(255) DEFAULT NULL,
ADD COLUMN next_of_kin_contact VARCHAR(255) DEFAULT NULL,
ADD COLUMN emergency_contact VARCHAR(255) DEFAULT NULL;
