-- ==============================================================================
-- RxCore Database Schema for Supabase Cloud (PostgreSQL 15+)
-- Phase 2 (Refined v3): Composite (hcode, hos_guid) Reconciliation & Active Drug Defense
-- ==============================================================================
-- ข้อกำหนดสำคัญ:
-- 1. ไฟล์นี้จัดเตรียมสำหรับนำไปรันใน Supabase Cloud SQL Editor เมื่อได้รับอนุมัติ
-- 2. ห้ามนำไปรันบนระบบจริงหรือเชื่อมโยง HOSxP จริงใน Phase 2
-- 3. รองรับการเชื่อมต่อแบบ Role-Based Security (Admin / User)
-- 4. Sync Agent ต้องยืนยันตัวตนผ่าน API Key Hash ประจำ รพ.สต. ห้ามใช้ service_role key โดยตรง
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- 0. EXTENSIONS & ENUMS
-- ------------------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

DO $$ BEGIN
    CREATE TYPE rx_sync_status_enum AS ENUM ('success', 'failed', 'partial', 'running');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE rx_moph_notify_enum AS ENUM ('pending', 'sent', 'failed', 'skipped');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE rx_user_role_enum AS ENUM ('admin', 'user');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

-- ------------------------------------------------------------------------------
-- 1. ตารางข้อมูล รพ.สต. ในเครือข่าย 6 แห่ง (Health Centers & API Key Hashes)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_health_centers (
    id SERIAL PRIMARY KEY,
    hcode VARCHAR(5) NOT NULL UNIQUE,       -- รหัสสถานพยาบาล 5 หลัก (เช่น 01425)
    name VARCHAR(150) NOT NULL,             -- ชื่อ รพ.สต.
    facility_type VARCHAR(20) NOT NULL DEFAULT 'PCU', -- ประเภทหน่วยบริการหลัก: HOSPITAL = โรงพยาบาล, PCU = รพ.สต.
    subdistrict VARCHAR(100),               -- ตำบล
    district VARCHAR(100),                  -- อำเภอ
    province VARCHAR(100) DEFAULT 'ตัวอย่างจังหวัด', -- จังหวัด
    coordinator VARCHAR(150),              -- ผู้ประสานงาน / เจ้าหน้าที่ รพ.สต.
    phone VARCHAR(50),                      -- เบอร์โทรศัพท์ติดต่อ
    population INT DEFAULT 0 CHECK (population >= 0),
    api_key_hash VARCHAR(255) NOT NULL,     -- SHA-256 Hash ของ API Key (ห้ามเก็บ Plain Text)
    api_key_prefix VARCHAR(20) NOT NULL,    -- Prefix แสดงบนหน้า Admin เช่น rxk_live_01425_...
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_sync_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_hcode_len CHECK (length(trim(hcode)) = 5),
    CONSTRAINT chk_health_center_facility_type CHECK (facility_type IN ('HOSPITAL', 'PCU'))
);

COMMENT ON TABLE public.rx_health_centers IS 'ข้อมูล รพ.สต. ทั้ง 6 แห่ง พร้อมข้อมูลระบุตัวตนและ API Key Hash';
COMMENT ON COLUMN public.rx_health_centers.api_key_hash IS 'SHA-256 Hash ของ API Key ประจำศูนย์ ป้องกันการรั่วไหล';
COMMENT ON COLUMN public.rx_health_centers.api_key_prefix IS 'Prefix คีย์สำหรับตรวจสอบในหน้าจอ Admin โดยไม่เผย Secret Key';

-- ------------------------------------------------------------------------------
-- 2. ตารางทะเบียนยามาตรฐานกลาง (Drug Master Lookup — อ้างอิงโครงสร้าง kw_drug_type)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_drug_lookup (
    id SERIAL PRIMARY KEY,
    icode INT UNIQUE,                           -- รหัสยา HOSxP (ตรงกับ kw_drug_type.icode)
    tpu_code_list VARCHAR(100) NOT NULL UNIQUE, -- รหัส TPU มาตรฐาน (ตรงกับ kw_drug_type.tpu_code_list)
    drug_name VARCHAR(255) NOT NULL,            -- ชื่อยาและความแรง (ตรงกับ kw_drug_type.drugname)
    drug_type_name VARCHAR(100),                -- หมวดหมู่/กลุ่มยา (ตรงกับ kw_drug_type.drug_type_name เช่น HT, DM, ATB drug)
    generic_name VARCHAR(255),                  -- ชื่อสามัญทางยา (Optional)
    trade_name VARCHAR(255),                    -- ชื่อทางการค้า (Optional)
    unitcost NUMERIC(10, 2) DEFAULT 0.00 CHECK (unitcost >= 0),   -- ต้นทุนต่อหน่วยจากทะเบียนยา (ตรงกับ kw_drug_type.unitcost)
    unitprice NUMERIC(10, 2) DEFAULT 0.00 CHECK (unitprice >= 0), -- ราคาขายต่อหน่วยจากทะเบียนยา (ตรงกับ kw_drug_type.unitprice)
    unit VARCHAR(50) NOT NULL,                  -- หน่วยนับ (ตรงกับ kw_drug_type.units เช่น เม็ด, แค็บซูล, ขวด)
    is_active BOOLEAN NOT NULL DEFAULT TRUE,    -- สถานะการเปิดใช้งาน (หาก FALSE จะห้ามจ่ายยาใหม่)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_tpu_code_not_empty CHECK (length(trim(tpu_code_list)) > 0),
    CONSTRAINT chk_drug_name_not_empty CHECK (length(trim(drug_name)) > 0),
    CONSTRAINT chk_unit_not_empty CHECK (length(trim(unit)) > 0)
);

COMMENT ON TABLE public.rx_drug_lookup IS 'ทะเบียนยามาตรฐานกลาง อ้างอิงจากตาราง kw_drug_type (112 รายการ) จับคู่กับ HOSxP ผ่าน tpu_code_list และ icode';
COMMENT ON COLUMN public.rx_drug_lookup.icode IS 'รหัสยา HOSxP 7 หลัก จากตาราง kw_drug_type.icode';
COMMENT ON COLUMN public.rx_drug_lookup.tpu_code_list IS 'รหัส TPU อ้างอิงหลัก หากรายการจ่ายยาไม่มีรหัสนี้จะถูกปฏิเสธโดย Foreign Key';
COMMENT ON COLUMN public.rx_drug_lookup.drug_type_name IS 'กลุ่มยาจากตาราง kw_drug_type.drug_type_name';
COMMENT ON COLUMN public.rx_drug_lookup.unitcost IS 'ต้นทุนต่อหน่วยตั้งต้นจากตาราง kw_drug_type.unitcost';
COMMENT ON COLUMN public.rx_drug_lookup.unitprice IS 'ราคาขายต่อหน่วยจากตาราง kw_drug_type.unitprice';
COMMENT ON COLUMN public.rx_drug_lookup.is_active IS 'หากเป็น FALSE จะถูกบล็อกไม่ให้บันทึกรายการจ่ายยาใหม่ทั้งในระดับ Trigger และ RPC';

CREATE INDEX IF NOT EXISTS idx_drug_lookup_icode ON public.rx_drug_lookup (icode);
CREATE INDEX IF NOT EXISTS idx_drug_lookup_tpu ON public.rx_drug_lookup (tpu_code_list);
CREATE INDEX IF NOT EXISTS idx_drug_lookup_name ON public.rx_drug_lookup (drug_name);
CREATE INDEX IF NOT EXISTS idx_drug_lookup_type ON public.rx_drug_lookup (drug_type_name);
CREATE INDEX IF NOT EXISTS idx_drug_lookup_active ON public.rx_drug_lookup (is_active);

-- View เทียบเท่าโครงสร้างตาราง kw_drug_type แบบ 1 ต่อ 1 (Prefix rx_ ป้องกันชนกับระบบอื่น)
CREATE OR REPLACE VIEW public.v_rx_kw_drug_type AS
SELECT
    icode,
    drug_name AS drugname,
    tpu_code_list,
    drug_type_name,
    unitcost,
    unitprice,
    unit AS units,
    is_active
FROM public.rx_drug_lookup
ORDER BY icode ASC;

COMMENT ON VIEW public.v_rx_kw_drug_type IS 'View แสดงทะเบียนยาในรูปแบบคอลัมน์ตรงกับตาราง kw_drug_type ของโรงพยาบาลค้อวัง';

-- Alias View เพื่อความเข้ากันได้ย้อนหลัง
CREATE OR REPLACE VIEW public.v_kw_drug_type AS
SELECT * FROM public.v_rx_kw_drug_type;

-- ------------------------------------------------------------------------------
-- 3. ตารางประวัติต้นทุนซื้อยาต่อหน่วย (Drug Cost History)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_drug_cost_history (
    id SERIAL PRIMARY KEY,
    drug_id INT NOT NULL REFERENCES public.rx_drug_lookup(id) ON DELETE RESTRICT,
    effective_date DATE NOT NULL,           -- วันที่เริ่มใช้ต้นทุนนี้
    unit_cost NUMERIC(12, 4) NOT NULL CHECK (unit_cost >= 0), -- ต้นทุนต่อหน่วย (บาท) >= 0 (0 = ฟรี/บริจาค)
    note VARCHAR(255),                      -- หมายเหตุ เช่น สัญญาจัดซื้อ, เลขที่ PO
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_drug_cost_effective UNIQUE (drug_id, effective_date)
);

COMMENT ON TABLE public.rx_drug_cost_history IS 'ประวัติต้นทุนซื้อยาต่อหน่วย พร้อมวันที่เริ่มมีผลบังคับใช้ (Historical Cost)';

CREATE INDEX IF NOT EXISTS idx_cost_history_search ON public.rx_drug_cost_history (drug_id, effective_date DESC);

-- ------------------------------------------------------------------------------
-- 4. ฟังก์ชันดึงต้นทุนย้อนหลังตามวันที่จ่ายยา (Historical Cost Resolution)
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_get_drug_unit_cost(
    p_tpu_code VARCHAR,
    p_dispense_date DATE
) RETURNS NUMERIC(12, 4)
LANGUAGE plpgsql
SECURITY DEFINER
STABLE
AS $$
DECLARE
    v_unit_cost NUMERIC(12, 4);
BEGIN
    -- ค้นหาต้นทุนล่าสุดที่มีผลบังคับใช้ ณ วันที่จ่ายยา (effective_date <= p_dispense_date)
    SELECT ch.unit_cost INTO v_unit_cost
    FROM public.rx_drug_cost_history ch
    JOIN public.rx_drug_lookup dl ON dl.id = ch.drug_id
    WHERE dl.tpu_code_list = p_tpu_code
      AND ch.effective_date <= p_dispense_date
    ORDER BY ch.effective_date DESC
    LIMIT 1;

    -- หากไม่มีต้นทุนที่มีผลบังคับใช้ ณ วันที่จ่ายยา ให้คืนค่า NULL ทันที (ห้ามดึงราคาในอนาคต)
    RETURN v_unit_cost;
END;
$$;

COMMENT ON FUNCTION public.fn_get_drug_unit_cost IS 'ค้นหาต้นทุนย้อนหลัง ณ วันที่จ่ายยา หากไม่มีต้นทุนที่มีผล ณ วันนั้นจะคืนค่า NULL (ห้ามใช้ราคาอนาคต)';

-- ------------------------------------------------------------------------------
-- 5. ตารางรายการจ่ายยา (Drug Dispense Records)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_dispense (
    id BIGSERIAL PRIMARY KEY,
    hcode VARCHAR(5) NOT NULL REFERENCES public.rx_health_centers(hcode) ON DELETE RESTRICT,
    hos_guid VARCHAR(100) NOT NULL,         -- GUID รายการจ่ายยาจาก HOSxP
    vn VARCHAR(50) NOT NULL,                -- Visit Number
    hn VARCHAR(50) NULL,                    -- Hospital Number (เลขประจำตัวผู้ป่วย OPD)
    vstdate DATE NOT NULL,                  -- วันที่จ่ายยา / วันที่รับบริการ
    tpu_code_list VARCHAR(50) NOT NULL REFERENCES public.rx_drug_lookup(tpu_code_list) ON DELETE RESTRICT,
    qty NUMERIC(10, 2) NOT NULL CHECK (qty > 0), -- จำนวนจ่าย > 0
    unit_cost_applied NUMERIC(12, 4) NULL,       -- ต้นทุนต่อหน่วย (NULL = ไม่มีต้นทุน ณ วันที่จ่าย, 0 = ต้นทุน 0 บาท)
    total_cost_applied NUMERIC(14, 4) NULL,      -- มูลค่ารวม (NULL หากไม่มีต้นทุน และไม่รวมในยอดรวม)
    patient_age INT CHECK (patient_age >= 0 AND patient_age <= 150), -- อายุผู้ป่วย (ข้อมูลสถิติรายงาน ไม่ใช่ PII)
    icd10 VARCHAR(20),                      -- รหัสโรคหลัก (สำหรับสรุปรายงานการใช้ยาตามกลุ่มโรค)
    is_deleted BOOLEAN NOT NULL DEFAULT FALSE, -- แฟล็ก Soft Delete (คงประวัติการจ่ายยาไว้เพื่อความปลอดภัยทางการแพทย์)
    deleted_at TIMESTAMPTZ NULL,            -- เวลาที่ถูกทำเครื่องหมายว่ายกเลิก/ลบ
    synced_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- ป้องกันรายการจ่ายยาซ้ำด้วย Unique Constraint บนคู่ (hcode, hos_guid)
    CONSTRAINT uq_dispense_hcode_hos_guid UNIQUE (hcode, hos_guid),
    
    -- ตรวจสอบความถูกต้องของข้อมูล (Validation Constraints)
    CONSTRAINT chk_dispense_hcode_len CHECK (length(trim(hcode)) = 5),
    CONSTRAINT chk_dispense_hos_guid_not_empty CHECK (length(trim(hos_guid)) > 0),
    CONSTRAINT chk_dispense_vn_not_empty CHECK (length(trim(vn)) > 0),
    CONSTRAINT chk_dispense_vstdate_valid CHECK (vstdate <= CURRENT_DATE + INTERVAL '1 day')
);

COMMENT ON TABLE public.rx_dispense IS 'ข้อมูลการจ่ายยาจาก 6 รพ.สต. ไม่มีข้อมูลระบุตัวบุคคล (De-identified) และมี Unique Constraint (hcode, hos_guid)';
COMMENT ON CONSTRAINT uq_dispense_hcode_hos_guid ON public.rx_dispense IS 'ป้องกันการซิงค์ข้อมูลซ้ำด้วยคู่รหัสสถานพยาบาลและ GUID (hcode, hos_guid)';

CREATE INDEX IF NOT EXISTS idx_dispense_vstdate_active ON public.rx_dispense (vstdate DESC) WHERE is_deleted = FALSE;
CREATE INDEX IF NOT EXISTS idx_dispense_hcode_date ON public.rx_dispense (hcode, vstdate DESC) WHERE is_deleted = FALSE;
CREATE INDEX IF NOT EXISTS idx_dispense_tpu ON public.rx_dispense (tpu_code_list);
CREATE INDEX IF NOT EXISTS idx_dispense_synced ON public.rx_dispense (synced_at DESC);

-- ------------------------------------------------------------------------------
-- 5.1 TRIGGER: ป้องกันการจ่ายยาที่อยู่ในสถานะปิดใช้งาน (is_active = FALSE)
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.trg_fn_check_drug_is_active()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_is_active BOOLEAN;
BEGIN
    SELECT is_active INTO v_is_active
    FROM public.rx_drug_lookup
    WHERE tpu_code_list = NEW.tpu_code_list;

    IF v_is_active IS NULL THEN
        RAISE EXCEPTION 'DrugLookupError: ไม่พบรหัสยา % ในทะเบียนกลาง', NEW.tpu_code_list;
    ELSIF v_is_active = FALSE THEN
        RAISE EXCEPTION 'InactiveDrugViolation: รหัสยา % อยู่ในสถานะปิดใช้งาน (is_active = FALSE) ไม่อนุญาตให้บันทึกรายการจ่ายยาเข้าสู่ระบบ', NEW.tpu_code_list;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_check_drug_is_active ON public.rx_dispense;
CREATE TRIGGER trg_check_drug_is_active
    BEFORE INSERT OR UPDATE OF tpu_code_list
    ON public.rx_dispense
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_fn_check_drug_is_active();

COMMENT ON FUNCTION public.trg_fn_check_drug_is_active IS 'Trigger ป้องกันการเพิ่ม/แก้ไขรายการจ่ายยาที่ใช้รหัสยาที่ปิดใช้งาน (is_active = FALSE)';

-- ------------------------------------------------------------------------------
-- 5.2 TRIGGER: คำนวณต้นทุนย้อนหลังอัตโนมัติเมื่อเพิ่มหรือแก้ไขรายการจ่ายยา
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.trg_fn_apply_dispense_cost()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.unit_cost_applied := public.fn_get_drug_unit_cost(NEW.tpu_code_list, NEW.vstdate);
    
    IF NEW.unit_cost_applied IS NOT NULL THEN
        NEW.total_cost_applied := ROUND(NEW.qty * NEW.unit_cost_applied, 4);
    ELSE
        NEW.total_cost_applied := NULL;
    END IF;
    
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_apply_dispense_cost ON public.rx_dispense;
CREATE TRIGGER trg_apply_dispense_cost
    BEFORE INSERT OR UPDATE OF tpu_code_list, qty, vstdate
    ON public.rx_dispense
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_fn_apply_dispense_cost();

-- ------------------------------------------------------------------------------
-- 6. TRIGGER: อัปเดตต้นทุนย้อนหลังเมื่อมีการแก้ไขต้นทุนในภายหลัง
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.trg_fn_recalculate_dispense_on_cost_change()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_tpu_code VARCHAR(50);
    v_target_drug_id INT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        v_target_drug_id := OLD.drug_id;
    ELSE
        v_target_drug_id := NEW.drug_id;
    END IF;

    SELECT tpu_code_list INTO v_tpu_code
    FROM public.rx_drug_lookup
    WHERE id = v_target_drug_id;

    IF v_tpu_code IS NOT NULL THEN
        UPDATE public.rx_dispense d
        SET 
            unit_cost_applied = public.fn_get_drug_unit_cost(d.tpu_code_list, d.vstdate),
            total_cost_applied = CASE 
                WHEN public.fn_get_drug_unit_cost(d.tpu_code_list, d.vstdate) IS NOT NULL 
                THEN ROUND(d.qty * public.fn_get_drug_unit_cost(d.tpu_code_list, d.vstdate), 4)
                ELSE NULL 
            END,
            updated_at = NOW()
        WHERE d.tpu_code_list = v_tpu_code
          AND d.is_deleted = FALSE;
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_recalculate_cost_on_history_change ON public.rx_drug_cost_history;
CREATE TRIGGER trg_recalculate_cost_on_history_change
    AFTER INSERT OR UPDATE OR DELETE
    ON public.rx_drug_cost_history
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_fn_recalculate_dispense_on_cost_change();

-- ------------------------------------------------------------------------------
-- 7. ตารางประวัติการ Sync (Sync Audit Logs & MOPH Notify Status)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_sync_log (
    id BIGSERIAL PRIMARY KEY,
    hcode VARCHAR(5) NOT NULL REFERENCES public.rx_health_centers(hcode) ON DELETE RESTRICT,
    sync_status rx_sync_status_enum NOT NULL DEFAULT 'running',
    total_records INT NOT NULL DEFAULT 0 CHECK (total_records >= 0),
    success_records INT NOT NULL DEFAULT 0 CHECK (success_records >= 0),
    failed_records INT NOT NULL DEFAULT 0 CHECK (failed_records >= 0),
    error_details JSONB,
    moph_notify_status rx_moph_notify_enum NOT NULL DEFAULT 'pending',
    moph_notify_message TEXT,
    moph_notify_sent_at TIMESTAMPTZ,
    sync_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sync_completed_at TIMESTAMPTZ,
    duration_ms INT CHECK (duration_ms >= 0),
    agent_version VARCHAR(50),
    client_ip VARCHAR(50),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE public.rx_sync_log IS 'ประวัติและสถานะการซิงค์ข้อมูลจาก รพ.สต. พร้อมสถานะการแจ้งเตือน MOPH Notify';

CREATE INDEX IF NOT EXISTS idx_sync_log_hcode ON public.rx_sync_log (hcode, sync_started_at DESC);
CREATE INDEX IF NOT EXISTS idx_sync_log_status ON public.rx_sync_log (sync_status);
CREATE INDEX IF NOT EXISTS idx_sync_log_moph ON public.rx_sync_log (moph_notify_status);

-- ------------------------------------------------------------------------------
-- 8. ตารางโปรไฟล์ผู้ใช้งานและบทบาท (User Profiles & Role Defense)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.rx_user_profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    role rx_user_role_enum NOT NULL DEFAULT 'user',
    hcode VARCHAR(5) REFERENCES public.rx_health_centers(hcode) ON DELETE SET NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Trigger ป้องกันผู้ใช้งานทั่วไป (Non-Admin) เปลี่ยนแปลง role หรือ hcode ของตนเอง
CREATE OR REPLACE FUNCTION public.trg_fn_rx_protect_user_profile()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.rx_user_profiles
        WHERE id = auth.uid() AND role = 'admin' AND is_active = TRUE
    ) THEN
        IF NEW.role <> OLD.role THEN
            RAISE EXCEPTION 'Security Violation: ไม่สามารถเปลี่ยนสิทธิ์ (role) ของตนเองได้';
        END IF;
        IF NEW.hcode IS DISTINCT FROM OLD.hcode THEN
            RAISE EXCEPTION 'Security Violation: ไม่สามารถเปลี่ยนหน่วยบริการ (hcode) ของตนเองได้';
        END IF;
    END IF;
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_protect_user_profile ON public.rx_user_profiles;
CREATE TRIGGER trg_protect_user_profile
    BEFORE UPDATE ON public.rx_user_profiles
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_fn_rx_protect_user_profile();

-- ------------------------------------------------------------------------------
-- 9. HELPER FUNCTIONS สำหรับ ROW LEVEL SECURITY (RLS) & API KEY (Prefix rx_ ป้องกันชนกับระบบอื่น)
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_rx_is_admin()
RETURNS BOOLEAN
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.rx_user_profiles
        WHERE id = auth.uid() AND role = 'admin' AND is_active = TRUE
    );
$$;

CREATE OR REPLACE FUNCTION public.fn_rx_user_hcode()
RETURNS VARCHAR(5)
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    SELECT hcode FROM public.rx_user_profiles
    WHERE id = auth.uid() AND is_active = TRUE;
$$;

CREATE OR REPLACE FUNCTION public.fn_verify_health_center_api_key(
    p_hcode VARCHAR(5),
    p_plain_api_key TEXT
) RETURNS BOOLEAN
LANGUAGE plpgsql
SECURITY DEFINER
STABLE
AS $$
DECLARE
    v_expected_hash VARCHAR(255);
BEGIN
    SELECT api_key_hash INTO v_expected_hash
    FROM public.rx_health_centers
    WHERE hcode = p_hcode AND is_active = TRUE;

    IF v_expected_hash IS NULL THEN
        RETURN FALSE;
    END IF;

    RETURN (v_expected_hash = encode(digest(p_plain_api_key, 'sha256'), 'hex'));
END;
$$;

-- ------------------------------------------------------------------------------
-- 10. SERVER-SIDE RPC: กระบวนการ SYNC ข้อมูลและการกระทบยอดคู่รหัส (hcode, hos_guid)
-- ------------------------------------------------------------------------------
-- การปรับปรุงความปลอดภัยขั้นสูง:
-- 1. ยืนยันคู่รหัสสถานพยาบาล: ตรวจสอบทั้ง hcode และ hos_guid ร่วมกันเสมอ
-- 2. ยืนยันความครบถ้วนของชุดข้อมูลต้นทาง:
--    - ต้องระบุ reconcile_hcode ให้ตรงกับ p_hcode (ป้องกันการส่งข้ามศูนย์)
--    - ต้องระบุ source_query_status = 'COMPLETED' (รับรองว่าการคิวรีฝั่ง HOSxP วิ่งจนจบ ไม่หลุดกลางคัน)
--    - ต้องส่งชุดคู่รหัส source_active_pairs ที่ตรวจสอบความถูกต้องแล้ว
-- 3. ทำงานในระดับ Transaction เดียวกัน หากผิดพลาดจะ Rollback ทั้งหมด
-- 4. ใช้ Soft Delete (is_deleted = TRUE, deleted_at = NOW())
CREATE OR REPLACE FUNCTION public.fn_sync_dispense_batch(
    p_hcode VARCHAR(5),
    p_plain_api_key TEXT,
    p_records JSONB,                              -- รายการที่ต้องการ Upsert (เพิ่ม/แก้ไข)
    p_deleted_hos_guids JSONB DEFAULT '[]'::jsonb, -- รายการที่ยืนยันว่าถูกลบ/ยกเลิกต้นทางรายตัว
    p_reconcile_handshake JSONB DEFAULT NULL       -- อ็อบเจกต์ตรวจสอบชุดคู่รหัส (hcode, hos_guid) สำหรับรอบ 30 วัน
) RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_auth_ok BOOLEAN;
    v_rec JSONB;
    v_guid TEXT;
    v_inserted INT := 0;
    v_updated INT := 0;
    v_deleted INT := 0;
    v_failed INT := 0;
    v_errors JSONB := '[]'::jsonb;
    v_start_time TIMESTAMPTZ := NOW();
    v_log_id BIGINT;
    v_is_active BOOLEAN;
    
    -- ตัวแปรสำหรับการกระทบยอดชุดคู่รหัส (hcode, hos_guid)
    v_rec_start_date DATE;
    v_rec_end_date DATE;
    v_reconcile_hcode VARCHAR(5);
    v_source_query_status TEXT;
    v_source_pairs_count INT;
    v_expected_count INT;
    v_reconciled_deleted INT := 0;
BEGIN
    -- 1. ยืนยันตัวตน API Key ฝั่ง Server
    v_auth_ok := public.fn_verify_health_center_api_key(p_hcode, p_plain_api_key);
    IF NOT v_auth_ok THEN
        INSERT INTO public.rx_sync_log (hcode, sync_status, error_details, sync_started_at, sync_completed_at)
        VALUES (p_hcode, 'failed', jsonb_build_array('Unauthorized: Invalid Health Center API Key'), v_start_time, NOW());
        
        RETURN jsonb_build_object(
            'success', FALSE,
            'error', 'Authentication Failed: Invalid API Key for health center ' || p_hcode
        );
    END IF;

    -- 2. สร้าง Sync Log เบื้องต้น
    INSERT INTO public.rx_sync_log (hcode, sync_status, total_records, sync_started_at)
    VALUES (p_hcode, 'running', jsonb_array_length(p_records), v_start_time)
    RETURNING id INTO v_log_id;

    -- 3. ประมวลผลรายการจ่ายยาแบบ Upsert พร้อมตรวจสอบ is_active ของยา
    FOR v_rec IN SELECT * FROM jsonb_array_elements(p_records) LOOP
        BEGIN
            -- 3.1 ตรวจสอบสถานะของรหัสยาในทะเบียนกลาง (ตรวจทั้งการมีอยู่ และ is_active = TRUE)
            SELECT is_active INTO v_is_active
            FROM public.rx_drug_lookup
            WHERE tpu_code_list = (v_rec->>'tpu_code_list');

            IF v_is_active IS NULL THEN
                v_failed := v_failed + 1;
                v_errors := v_errors || jsonb_build_object(
                    'hos_guid', v_rec->>'hos_guid',
                    'tpu_code_list', v_rec->>'tpu_code_list',
                    'error_code', 'DRUG_NOT_FOUND',
                    'reason', 'ไม่พบรหัสยาในทะเบียนกลาง'
                );
                CONTINUE;
            ELSIF v_is_active = FALSE THEN
                v_failed := v_failed + 1;
                v_errors := v_errors || jsonb_build_object(
                    'hos_guid', v_rec->>'hos_guid',
                    'tpu_code_list', v_rec->>'tpu_code_list',
                    'error_code', 'DRUG_INACTIVE',
                    'reason', 'รหัสยานี้ถูกปิดใช้งานในทะเบียนกลาง (is_active = FALSE) ไม่อนุญาตให้จ่ายยา'
                );
                CONTINUE;
            END IF;

            -- 3.2 Upsert รายการจ่ายยา
            INSERT INTO public.rx_dispense (
                hcode, hos_guid, vn, hn, vstdate, tpu_code_list, qty,
                patient_age, icd10, is_deleted, deleted_at, synced_at
            ) VALUES (
                p_hcode,
                v_rec->>'hos_guid',
                v_rec->>'vn',
                v_rec->>'hn',
                (v_rec->>'vstdate')::DATE,
                v_rec->>'tpu_code_list',
                (v_rec->>'qty')::NUMERIC,
                (v_rec->>'patient_age')::INT,
                v_rec->>'icd10',
                FALSE,
                NULL,
                NOW()
            )
            ON CONFLICT (hcode, hos_guid) DO UPDATE SET
                vn = EXCLUDED.vn,
                hn = COALESCE(EXCLUDED.hn, rx_dispense.hn),
                vstdate = EXCLUDED.vstdate,
                tpu_code_list = EXCLUDED.tpu_code_list,
                qty = EXCLUDED.qty,
                patient_age = EXCLUDED.patient_age,
                icd10 = EXCLUDED.icd10,
                is_deleted = FALSE,
                deleted_at = NULL,
                synced_at = NOW();

            IF FOUND THEN
                v_inserted := v_inserted + 1;
            END IF;

        EXCEPTION WHEN OTHERS THEN
            v_failed := v_failed + 1;
            v_errors := v_errors || jsonb_build_object(
                'hos_guid', v_rec->>'hos_guid',
                'reason', SQLERRM
            );
        END;
    END LOOP;

    -- 4. จัดการรายการยกเลิกอย่างเจาะจง (Explicit Soft Delete)
    IF jsonb_array_length(p_deleted_hos_guids) > 0 THEN
        FOR v_guid IN SELECT jsonb_array_elements_text(p_deleted_hos_guids) LOOP
            UPDATE public.rx_dispense
            SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW()
            WHERE hcode = p_hcode AND hos_guid = v_guid AND is_deleted = FALSE;
            
            IF FOUND THEN
                v_deleted := v_deleted + 1;
            END IF;
        END LOOP;
    END IF;

    -- 5. การกระทบยอด 30 วันแบบตรวจสอบชุดคู่รหัส (hcode, hos_guid) และยืนยันความสมบูรณ์ของต้นทาง
    -- โครงสร้าง p_reconcile_handshake:
    -- {
    --   "reconcile_hcode": "01425",
    --   "start_date": "2026-09-27",
    --   "end_date": "2026-10-27",
    --   "source_query_status": "COMPLETED",
    --   "expected_source_count": 2,
    --   "source_active_pairs": [
    --      {"hcode": "01425", "hos_guid": "GUID1"},
    --      {"hcode": "01425", "hos_guid": "GUID2"}
    --   ]
    -- }
    IF p_reconcile_handshake IS NOT NULL THEN
        v_reconcile_hcode := p_reconcile_handshake->>'reconcile_hcode';
        v_rec_start_date := (p_reconcile_handshake->>'start_date')::DATE;
        v_rec_end_date := (p_reconcile_handshake->>'end_date')::DATE;
        v_source_query_status := p_reconcile_handshake->>'source_query_status';
        v_source_pairs_count := jsonb_array_length(p_reconcile_handshake->'source_active_pairs');
        v_expected_count := (p_reconcile_handshake->>'expected_source_count')::INT;

        -- ตรวจสอบเงื่อนไขความปลอดภัยและความครบถ้วนของชุดข้อมูลต้นทางก่อนดำเนินการ:
        -- 1. reconcile_hcode ต้องระบุและตรงกับ p_hcode ของสถานพยาบาลที่ยืนยันตัวตน
        -- 2. source_query_status ต้องเป็น 'COMPLETED' ยืนยันว่าคิวรีต้นทางดึงข้อมูลสำเร็จครบถ้วน ไม่หลุดกลางคัน
        -- 3. ช่วงวันที่ต้องถูกต้อง (start_date <= end_date)
        -- 4. หากมีการระบุ expected_source_count จำนวนคู่รหัสที่ส่งมาต้องตรงกัน ป้องกันข้อมูลขาดตอนระหว่างส่ง (Payload Truncation)
        IF v_reconcile_hcode IS NULL OR v_reconcile_hcode <> p_hcode THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_HCODE_MISMATCH',
                'message', 'รหัส reconcile_hcode (' || COALESCE(v_reconcile_hcode, 'NULL') || ') ไม่ตรงกับสถานพยาบาลที่ยืนยันตัวตน (' || p_hcode || ') ยกเลิกการกระทบยอดทันที'
            );
        ELSIF v_source_query_status IS NULL OR v_source_query_status <> 'COMPLETED' THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_SOURCE_INCOMPLETE',
                'message', 'ต้นทางดึงข้อมูลไม่สำเร็จสมบูรณ์ (source_query_status = ' || COALESCE(v_source_query_status, 'NULL') || ') ยกเลิกการ Soft Delete เพื่อป้องกันข้อมูลสูญหาย'
            );
        ELSIF v_rec_start_date IS NULL OR v_rec_end_date IS NULL OR v_rec_start_date > v_rec_end_date THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_INVALID_DATE_RANGE',
                'message', 'ช่วงวันที่สำหรับกระทบยอดไม่ถูกต้อง'
            );
        ELSIF v_expected_count IS NOT NULL AND v_expected_count <> v_source_pairs_count THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_PAYLOAD_TRUNCATED',
                'message', 'จำนวนชุดคู่รหัสที่ได้รับ (' || v_source_pairs_count || ') ไม่ตรงกับยอดที่ยืนยันจากต้นทาง (' || v_expected_count || ') ยกเลิกการ Soft Delete ทันที'
            );
        ELSE
            -- สร้างตารางชั่วคราวจัดเก็บชุดคู่รหัส (hcode, hos_guid)
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_source_active_pairs (
                hcode VARCHAR(5) NOT NULL,
                hos_guid TEXT NOT NULL,
                PRIMARY KEY (hcode, hos_guid)
            ) ON COMMIT DROP;

            TRUNCATE temp_source_active_pairs;

            -- นำเข้าชุดคู่รหัส โดยดึงค่า hcode และ hos_guid จากแต่ละ element ตรงๆ (ห้าม Fallback ข้ามไปเอง)
            INSERT INTO temp_source_active_pairs (hcode, hos_guid)
            SELECT 
                elem->>'hcode' AS hcode,
                elem->>'hos_guid' AS hos_guid
            FROM jsonb_array_elements(p_reconcile_handshake->'source_active_pairs') AS elem
            ON CONFLICT (hcode, hos_guid) DO NOTHING;

            -- ตรวจสอบ:
            -- 1. ทุกคู่รหัสต้องมี hcode ระบุชัดเจน และต้องตรงกับ p_hcode เท่านั้น (Cross-hospital injection defense)
            -- 2. hos_guid ต้องไม่เป็นค่าว่าง
            IF EXISTS (
                SELECT 1 FROM temp_source_active_pairs 
                WHERE hcode IS NULL OR hcode <> p_hcode OR hos_guid IS NULL OR TRIM(hos_guid) = ''
            ) THEN
                v_errors := v_errors || jsonb_build_object(
                    'error_type', 'RECONCILIATION_SECURITY_VIOLATION',
                    'message', 'พบรายการคู่รหัสที่มี hcode ไม่ตรงกับสถานพยาบาลที่ส่งข้อมูล หรือ GUID ไม่สมบูรณ์ ยกเลิกการกระทบยอดทันที'
                );
            ELSE
                -- Soft Delete เฉพาะรายการใน Cloud ที่ผูกกับ hcode และมี vstdate ในช่วงเวลา แต่คู่รหัส (d.hcode, d.hos_guid) ไม่อยู่ในชุดของต้นทาง
                UPDATE public.rx_dispense d
                SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW()
                WHERE d.hcode = p_hcode
                  AND d.vstdate BETWEEN v_rec_start_date AND v_rec_end_date
                  AND d.is_deleted = FALSE
                  AND NOT EXISTS (
                      SELECT 1 FROM temp_source_active_pairs tap
                      WHERE tap.hcode = d.hcode
                        AND tap.hos_guid = d.hos_guid
                  );

                GET DIAGNOSTICS v_reconciled_deleted = ROW_COUNT;
                v_deleted := v_deleted + v_reconciled_deleted;
            END IF;
        END IF;
    END IF;

    -- 6. สรุปผลและบันทึกประวัติการ Sync
    UPDATE public.rx_sync_log
    SET 
        sync_status = CASE WHEN v_failed = 0 THEN 'success'::rx_sync_status_enum ELSE 'partial'::rx_sync_status_enum END,
        success_records = v_inserted,
        failed_records = v_failed,
        error_details = CASE WHEN jsonb_array_length(v_errors) > 0 THEN v_errors ELSE NULL END,
        sync_completed_at = NOW(),
        duration_ms = EXTRACT(MILLISECONDS FROM (NOW() - v_start_time))::INT
    WHERE id = v_log_id;

    UPDATE public.rx_health_centers
    SET last_sync_at = NOW()
    WHERE hcode = p_hcode;

    RETURN jsonb_build_object(
        'success', TRUE,
        'hcode', p_hcode,
        'inserted_or_updated', v_inserted,
        'deleted', v_deleted,
        'failed', v_failed,
        'errors', v_errors,
        'sync_log_id', v_log_id
    );
END;
$$;

COMMENT ON FUNCTION public.fn_sync_dispense_batch IS 'RPC ปลอดภัยสำหรับ Sync Agent ส่งข้อมูลและกระทบยอดคู่รหัส (hcode, hos_guid) แบบ Soft Delete';

-- ------------------------------------------------------------------------------
-- 11. ROW LEVEL SECURITY (RLS) POLICIES (Prefix rx_ ป้องกันชนกับระบบอื่นในโปรเจกต์เดียวกัน)
-- ------------------------------------------------------------------------------
ALTER TABLE public.rx_health_centers ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rx_drug_lookup ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rx_drug_cost_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rx_dispense ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rx_sync_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rx_user_profiles ENABLE ROW LEVEL SECURITY;

-- 11.1 rx_health_centers
CREATE POLICY "rx_admin_all_health_centers" ON public.rx_health_centers
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

CREATE POLICY "rx_user_view_own_health_center" ON public.rx_health_centers
    FOR SELECT TO authenticated
    USING (hcode = public.fn_rx_user_hcode());

-- 11.2 rx_drug_lookup
CREATE POLICY "rx_authenticated_read_drug_lookup" ON public.rx_drug_lookup
    FOR SELECT TO authenticated
    USING (TRUE);

CREATE POLICY "rx_admin_manage_drug_lookup" ON public.rx_drug_lookup
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

-- 11.3 rx_drug_cost_history
CREATE POLICY "rx_authenticated_read_cost_history" ON public.rx_drug_cost_history
    FOR SELECT TO authenticated
    USING (TRUE);

CREATE POLICY "rx_admin_manage_cost_history" ON public.rx_drug_cost_history
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

-- 11.4 rx_dispense
CREATE POLICY "rx_admin_select_all_dispense" ON public.rx_dispense
    FOR SELECT TO authenticated
    USING (public.fn_rx_is_admin());

CREATE POLICY "rx_user_select_own_dispense" ON public.rx_dispense
    FOR SELECT TO authenticated
    USING (hcode = public.fn_rx_user_hcode() AND is_deleted = FALSE);

CREATE POLICY "rx_admin_manage_dispense" ON public.rx_dispense
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

-- 11.5 rx_sync_log
CREATE POLICY "rx_admin_select_all_sync_log" ON public.rx_sync_log
    FOR SELECT TO authenticated
    USING (public.fn_rx_is_admin());

CREATE POLICY "rx_user_select_own_sync_log" ON public.rx_sync_log
    FOR SELECT TO authenticated
    USING (hcode = public.fn_rx_user_hcode());

CREATE POLICY "rx_admin_manage_sync_log" ON public.rx_sync_log
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

-- 11.6 rx_user_profiles
CREATE POLICY "rx_user_read_own_profile" ON public.rx_user_profiles
    FOR SELECT TO authenticated
    USING (id = auth.uid());

CREATE POLICY "rx_user_update_own_display_info" ON public.rx_user_profiles
    FOR UPDATE TO authenticated
    USING (id = auth.uid())
    WITH CHECK (id = auth.uid());

CREATE POLICY "rx_admin_manage_all_profiles" ON public.rx_user_profiles
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());

-- ------------------------------------------------------------------------------
-- 12. VIEW สรุปรายงานการใช้ยา (ไม่นับรวมมูลค่ารายการที่ไม่มีต้นทุน)
-- ------------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.v_rx_drug_dispense_summary AS
SELECT 
    d.hcode,
    hc.name AS health_center_name,
    d.vstdate,
    dl.icode,
    d.tpu_code_list,
    dl.drug_name,
    dl.drug_type_name,
    dl.unit,
    COUNT(d.id) AS dispense_tx_count,
    SUM(d.qty) AS total_qty,
    SUM(d.total_cost_applied) AS total_cost_amount,
    COUNT(CASE WHEN d.unit_cost_applied IS NOT NULL THEN 1 END) AS cost_recorded_tx_count,
    COUNT(CASE WHEN d.unit_cost_applied IS NULL THEN 1 END) AS missing_cost_tx_count,
    CASE 
        WHEN COUNT(CASE WHEN d.unit_cost_applied IS NULL THEN 1 END) = 0 THEN 'COST_RECORDED'
        WHEN COUNT(CASE WHEN d.unit_cost_applied IS NOT NULL THEN 1 END) = 0 THEN 'NO_COST_DATA'
        ELSE 'PARTIAL_COST'
    END AS cost_status
FROM public.rx_dispense d
JOIN public.rx_health_centers hc ON hc.hcode = d.hcode
JOIN public.rx_drug_lookup dl ON dl.tpu_code_list = d.tpu_code_list
WHERE d.is_deleted = FALSE
GROUP BY d.hcode, hc.name, d.vstdate, dl.icode, d.tpu_code_list, dl.drug_name, dl.drug_type_name, dl.unit;

COMMENT ON VIEW public.v_rx_drug_dispense_summary IS 'รายงานสรุปการจ่ายยา ไม่นับรวมมูลค่ารายการที่ไม่มีต้นทุน พร้อมระบุ cost_status ชัดเจน';

-- Alias View เพื่อความเข้ากันได้ย้อนหลัง
CREATE OR REPLACE VIEW public.v_drug_dispense_summary AS
SELECT * FROM public.v_rx_drug_dispense_summary;

-- ------------------------------------------------------------------------------
-- 13. RPC สรุปจำนวนใบสั่งยา COUNT(DISTINCT vn) แยกตามหน่วยบริการ ย้อนหลัง 3 ปี
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_rx_report_prescriptions_3y(
    p_y1_start DATE,
    p_y1_end DATE,
    p_y2_start DATE,
    p_y2_end DATE,
    p_y3_start DATE,
    p_y3_end DATE,
    p_hcode VARCHAR DEFAULT NULL
)
RETURNS TABLE (
    hcode VARCHAR,
    y1_vn_count BIGINT,
    y1_item_count BIGINT,
    y2_vn_count BIGINT,
    y2_item_count BIGINT,
    y3_vn_count BIGINT,
    y3_item_count BIGINT
)
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    SELECT
        hc.hcode,
        COUNT(DISTINCT d.vn) FILTER (WHERE d.vstdate BETWEEN p_y1_start AND p_y1_end) AS y1_vn_count,
        COUNT(d.id)          FILTER (WHERE d.vstdate BETWEEN p_y1_start AND p_y1_end) AS y1_item_count,
        COUNT(DISTINCT d.vn) FILTER (WHERE d.vstdate BETWEEN p_y2_start AND p_y2_end) AS y2_vn_count,
        COUNT(d.id)          FILTER (WHERE d.vstdate BETWEEN p_y2_start AND p_y2_end) AS y2_item_count,
        COUNT(DISTINCT d.vn) FILTER (WHERE d.vstdate BETWEEN p_y3_start AND p_y3_end) AS y3_vn_count,
        COUNT(d.id)          FILTER (WHERE d.vstdate BETWEEN p_y3_start AND p_y3_end) AS y3_item_count
    FROM public.rx_health_centers hc
    LEFT JOIN public.rx_dispense d
        ON d.hcode = hc.hcode
       AND d.is_deleted = FALSE
       AND (
           (d.vstdate BETWEEN p_y1_start AND p_y1_end)
           OR (d.vstdate BETWEEN p_y2_start AND p_y2_end)
           OR (d.vstdate BETWEEN p_y3_start AND p_y3_end)
       )
    WHERE hc.is_active = TRUE
      AND (p_hcode IS NULL OR p_hcode = '' OR hc.hcode = p_hcode)
    GROUP BY hc.hcode
    ORDER BY hc.hcode ASC;
$$;

COMMENT ON FUNCTION public.fn_rx_report_prescriptions_3y IS 'สรุปจำนวนใบสั่งยาผู้ป่วยนอก COUNT(DISTINCT vn) แยกตามหน่วยบริการ เปรียบเทียบย้อนหลัง 3 ปีงบประมาณ';

-- ------------------------------------------------------------------------------
-- 14. RPC สรุปข้อมูล Dashboard ประจำปีงบประมาณ
-- ------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_rx_dashboard_stats(
    p_start_date DATE,
    p_end_date DATE
)
RETURNS JSONB
LANGUAGE sql
SECURITY DEFINER
STABLE
AS $$
    WITH fy_rows AS (
        SELECT
            d.hcode,
            d.vn,
            d.vstdate,
            TO_CHAR(d.vstdate, 'YYYY-MM') AS ym,
            d.tpu_code_list,
            d.qty,
            d.total_cost_applied
        FROM public.rx_dispense d
        WHERE d.is_deleted = FALSE
          AND d.vstdate BETWEEN p_start_date AND p_end_date
    ),
    center_agg AS (
        SELECT
            hc.hcode,
            hc.name,
            hc.facility_type,
            hc.is_active,
            hc.last_sync_at,
            MAX(r.vstdate) AS latest_vstdate,
            COUNT(DISTINCT r.vn) AS vn_count,
            COUNT(r.vn) AS tx_count,
            COALESCE(SUM(r.total_cost_applied), 0)::NUMERIC(14, 2) AS total_cost
        FROM public.rx_health_centers hc
        LEFT JOIN fy_rows r ON r.hcode = hc.hcode
        GROUP BY hc.hcode, hc.name, hc.facility_type, hc.is_active, hc.last_sync_at
        ORDER BY CASE WHEN hc.facility_type = 'HOSPITAL' THEN 0 ELSE 1 END, hc.hcode ASC
    ),
    monthly_agg AS (
        SELECT
            r.ym,
            r.hcode,
            COUNT(DISTINCT r.vn) AS vn_count,
            COUNT(r.vn) AS tx_count,
            COALESCE(SUM(r.total_cost_applied), 0)::NUMERIC(14, 2) AS total_cost
        FROM fy_rows r
        GROUP BY r.ym, r.hcode
        ORDER BY r.ym ASC, r.hcode ASC
    ),
    top_drugs_agg AS (
        SELECT
            r.tpu_code_list,
            COALESCE(dl.drug_name, r.tpu_code_list) AS drug_name,
            COALESCE(dl.unit, '') AS unit,
            COUNT(*) AS tx_count,
            COALESCE(SUM(r.qty), 0)::NUMERIC(14, 2) AS total_qty,
            COALESCE(SUM(r.total_cost_applied), 0)::NUMERIC(14, 2) AS total_cost
        FROM fy_rows r
        LEFT JOIN public.rx_drug_lookup dl ON dl.tpu_code_list = r.tpu_code_list
        GROUP BY r.tpu_code_list, dl.drug_name, dl.unit
        ORDER BY total_cost DESC, total_qty DESC
        LIMIT 5
    ),
    drug_group_agg AS (
        SELECT
            r.hcode,
            COALESCE(NULLIF(TRIM(dl.drug_type_name), ''), 'ไม่ระบุกลุ่มยา') AS drug_type_name,
            COUNT(*) AS tx_count,
            COALESCE(SUM(r.qty), 0)::NUMERIC(14, 2) AS total_qty
        FROM fy_rows r
        LEFT JOIN public.rx_drug_lookup dl ON dl.tpu_code_list = r.tpu_code_list
        GROUP BY r.hcode, COALESCE(NULLIF(TRIM(dl.drug_type_name), ''), 'ไม่ระบุกลุ่มยา')
        ORDER BY r.hcode ASC, total_qty DESC
    ),
    overall AS (
        SELECT
            COUNT(DISTINCT (r.hcode || '|' || r.vn)) AS total_vn_count,
            COUNT(*) AS total_tx_count,
            COUNT(DISTINCT r.tpu_code_list) AS used_drug_count,
            COALESCE(SUM(r.total_cost_applied), 0)::NUMERIC(14, 2) AS total_cost,
            COUNT(*) FILTER (WHERE r.total_cost_applied IS NULL) AS missing_cost_count
        FROM fy_rows r
    )
    SELECT jsonb_build_object(
        'centers', COALESCE((SELECT jsonb_agg(row_to_json(c)) FROM center_agg c), '[]'::jsonb),
        'monthly', COALESCE((SELECT jsonb_agg(row_to_json(m)) FROM monthly_agg m), '[]'::jsonb),
        'top_drugs', COALESCE((SELECT jsonb_agg(row_to_json(td)) FROM top_drugs_agg td), '[]'::jsonb),
        'drug_groups_by_center', COALESCE((SELECT jsonb_agg(row_to_json(dg)) FROM drug_group_agg dg), '[]'::jsonb),
        'overall', (SELECT row_to_json(o) FROM overall o)
    );
$$;

COMMENT ON FUNCTION public.fn_rx_dashboard_stats IS 'สรุปข้อมูล Dashboard ประจำปีงบประมาณ: ยอดรายหน่วยบริการ, รายเดือน 12 เดือน, Top 5 ยา และยอดรวมใบสั่งยา/มูลค่า';
