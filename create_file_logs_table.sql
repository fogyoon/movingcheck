-- 파일 접근 로그 테이블 생성
CREATE TABLE IF NOT EXISTS file_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contract_id INT NOT NULL,
    action VARCHAR(50) NOT NULL DEFAULT 'view', -- 'view', 'download' 등
    ip_address VARCHAR(45) NOT NULL,
    access_time DATETIME NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_contract_id (contract_id),
    INDEX idx_access_time (access_time),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 외래 키 제약 조건 (선택사항)
-- ALTER TABLE file_access_logs 
-- ADD CONSTRAINT fk_file_logs_user 
-- FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ALTER TABLE file_access_logs 
-- ADD CONSTRAINT fk_file_logs_contract 
-- FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE; 