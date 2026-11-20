-- ==============================================================
-- RxCore — Database Schema for Supabase Cloud (PostgreSQL 15+)
-- Phase 1: Architectural Blueprint & DDL Preparation
-- ==============================================================

-- เปิดใช้งาน UUID Extension หากยังไม่ได้เปิด
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- -------------------------------------------------------------
-- 1. ตารางข้อมูล รพ.สต. ในเครือข่าย (Health Centers - 6 แห่ง)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.health_centers (
    id SERIAL PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,       -- รหัสสถานพยาบาล 5 หลัก (เช่น 01425)
    name VARCHAR(150) NOT NULL,             -- ชื่อ รพ.สต.
    subdistrict VARCHAR(100),               -- ตำบล
    district VARCHAR(100),                  -- อำเภอ
    province VARCHAR(100),                  -- จังหวัด
    coordinator VARCHAR(150),              -- ผู้ประสานงาน / เภสัชกร / จพ.สธ.
    phone VARCHAR(30),                      -- เบอร์โทรศัพท์
    population INT DEFAULT 0,               -- จำนวนประชากรในเขตรับผิดชอบ
    status VARCHAR(20) DEFAULT 'online',    -- 'online', 'warning', 'offline'
    last_sync_at TIMESTAMPTZ,              -- เวลาที่ Sync ข้อมูลล่าสุด
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- -------------------------------------------------------------
-- 2. ตารางทะเบียนยาหลัก (Drug Master Catalog)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.drug_master (
    id SERIAL PRIMARY KEY,
    drug_code VARCHAR(30) NOT NULL UNIQUE,  -- รหัสยาภายในระบบ
    tmt_code VARCHAR(30),                   -- รหัสยามาตรฐาน TMT (Thai Medicines Terminology)
    generic_name VARCHAR(200) NOT NULL,     -- ชื่อสามัญทางยา
    trade_name VARCHAR(200),                -- ชื่อทางการค้า
    dosage VARCHAR(100),                    -- ขนาดยา
    form VARCHAR(100),                      -- รูปแบบยา (ยาเม็ด, แคปซูล, ยาน้ำ, ยาพ่น)
    category VARCHAR(100),                  -- กลุ่มยา (NCDs, Antibiotics, สามัญ ฯลฯ)
    unit VARCHAR(50) NOT NULL,              -- หน่วยนับ (เม็ด, แคปซูล, ขวด, ซอง)
    min_stock INT DEFAULT 0,                -- จุดสั่งซื้อ / สต็อกขั้นต่ำ
    status VARCHAR(20) DEFAULT 'active',    -- 'active', 'inactive'
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- -------------------------------------------------------------
-- 3. ตารางข้อมูลการจ่ายยา (Drug Dispense Records)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.drug_dispense (
    id BIGSERIAL PRIMARY KEY,
    tx_id VARCHAR(50) NOT NULL UNIQUE,      -- Transaction UUID / รหัสอ้างอิงจาก รพ.สต.
    health_center_id INT NOT NULL REFERENCES public.health_centers(id) ON DELETE CASCADE,
    dispensed_at TIMESTAMPTZ NOT NULL,      -- วันเวลาที่จ่ายยา
    hn_masked VARCHAR(50),                  -- HN ที่ผ่านการ Hash / Mask เพื่อความปลอดภัย (PDPA)
    patient_age INT,                        -- อายุผู้ป่วย
    icd10_code VARCHAR(20),                 -- รหัสโรคหลัก (ICD-10)
    drug_id INT NOT NULL REFERENCES public.drug_master(id) ON DELETE RESTRICT,
    quantity INT NOT NULL CHECK (quantity > 0), -- จำนวนที่จ่าย
    unit VARCHAR(50),                       -- หน่วย
    dosage_instruction TEXT,                -- วิธีการใช้ยา
    prescriber VARCHAR(150),                -- ผู้สั่งจ่าย / จพ.สธ.
    synced_at TIMESTAMPTZ DEFAULT NOW(),    -- เวลาที่ระบบส่วนกลาง Sync สำเร็จ
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ดัชนีเพิ่มความเร็วในการสืบค้น (Indexes)
CREATE INDEX IF NOT EXISTS idx_dispense_hc ON public.drug_dispense (health_center_id);
CREATE INDEX IF NOT EXISTS idx_dispense_date ON public.drug_dispense (dispensed_at);
CREATE INDEX IF NOT EXISTS idx_dispense_drug ON public.drug_dispense (drug_id);
CREATE INDEX IF NOT EXISTS idx_dispense_icd10 ON public.drug_dispense (icd10_code);

-- -------------------------------------------------------------
-- 4. ตารางบันทึกสถานะการซิงค์ข้อมูล (Sync Logs)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.sync_logs (
    id BIGSERIAL PRIMARY KEY,
    health_center_id INT REFERENCES public.health_centers(id) ON DELETE SET NULL,
    event_type VARCHAR(50) NOT NULL,        -- 'SYNC_SUCCESS', 'SYNC_WARNING', 'SYNC_FAILED'
    records_count INT DEFAULT 0,            -- จำนวนแถวข้อมูลที่นำเข้า
    latency_ms INT DEFAULT 0,               -- ความเร็วเครือข่าย (ms)
    status VARCHAR(20) NOT NULL,            -- 'success', 'warning', 'danger'
    message TEXT,                           -- รายละเอียดข้อความแจ้งเตือนหรือข้อผิดพลาด
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sync_logs_hc ON public.sync_logs (health_center_id);
CREATE INDEX IF NOT EXISTS idx_sync_logs_time ON public.sync_logs (created_at DESC);

-- -------------------------------------------------------------
-- 5. ตารางผู้ใช้งานและสิทธิ์ (Users & Role-Based Access)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,    -- bcrypt hashed password
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE,
    role VARCHAR(20) NOT NULL DEFAULT 'user', -- 'admin' หรือ 'user'
    health_center_id INT REFERENCES public.health_centers(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'active',    -- 'active', 'inactive', 'suspended'
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- -------------------------------------------------------------
-- 6. Row Level Security (RLS) สำหรับ Supabase
-- -------------------------------------------------------------
ALTER TABLE public.health_centers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.drug_master ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.drug_dispense ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.sync_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;

-- นโยบาย RLS พื้นฐานสำหรับ authenticated users
CREATE POLICY "Allow read for authenticated users" ON public.health_centers
    FOR SELECT TO authenticated USING (true);

CREATE POLICY "Allow read for authenticated users" ON public.drug_master
    FOR SELECT TO authenticated USING (true);

CREATE POLICY "Allow read for authenticated users" ON public.drug_dispense
    FOR SELECT TO authenticated USING (true);

CREATE POLICY "Allow read for authenticated users" ON public.sync_logs
    FOR SELECT TO authenticated USING (true);
