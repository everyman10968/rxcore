-- ==============================================================================
-- RxCore Database Migration: เพิ่มคอลัมน์ hn สำหรับข้อมูลผู้ป่วยนอก (OPD)
-- รันไฟล์นี้ใน Supabase SQL Editor เพื่ออัปเดตตาราง rx_dispense และฟังก์ชัน RPC
-- ==============================================================================

-- 1. เพิ่มคอลัมน์ hn ในตาราง rx_dispense
ALTER TABLE public.rx_dispense
ADD COLUMN IF NOT EXISTS hn VARCHAR(50) NULL;

COMMENT ON COLUMN public.rx_dispense.hn IS 'Hospital Number (เลขประจำตัวผู้ป่วย OPD)';

-- ดัชนีค้นหาตาม hn สำหรับสืบค้นประวัติการใช้ยาของผู้ป่วย
CREATE INDEX IF NOT EXISTS idx_dispense_hn ON public.rx_dispense (hn) WHERE is_deleted = FALSE;

-- 2. อัปเดตฟังก์ชัน RPC fn_sync_dispense_batch ให้รองรับการรับและ Upsert ฟิลด์ hn
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

            -- 3.2 Upsert รายการจ่ายยา (บันทึกฟิลด์ hn ด้วย)
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

    -- 5. การกระทบยอด 30 วันแบบตรวจสอบชุดคู่รหัส (hcode, hos_guid)
    IF p_reconcile_handshake IS NOT NULL THEN
        v_reconcile_hcode := p_reconcile_handshake->>'reconcile_hcode';
        v_rec_start_date := (p_reconcile_handshake->>'start_date')::DATE;
        v_rec_end_date := (p_reconcile_handshake->>'end_date')::DATE;
        v_source_query_status := p_reconcile_handshake->>'source_query_status';
        v_source_pairs_count := jsonb_array_length(p_reconcile_handshake->'source_active_pairs');
        v_expected_count := (p_reconcile_handshake->>'expected_source_count')::INT;

        IF v_reconcile_hcode IS NULL OR v_reconcile_hcode <> p_hcode THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_HCODE_MISMATCH',
                'message', 'รหัส reconcile_hcode ไม่ตรงกับสถานพยาบาลที่ยืนยันตัวตน'
            );
        ELSIF v_source_query_status IS NULL OR v_source_query_status <> 'COMPLETED' THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_SOURCE_INCOMPLETE',
                'message', 'ต้นทางดึงข้อมูลไม่สำเร็จสมบูรณ์'
            );
        ELSIF v_rec_start_date IS NULL OR v_rec_end_date IS NULL OR v_rec_start_date > v_rec_end_date THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_INVALID_DATE_RANGE',
                'message', 'ช่วงวันที่สำหรับกระทบยอดไม่ถูกต้อง'
            );
        ELSIF v_expected_count IS NOT NULL AND v_expected_count <> v_source_pairs_count THEN
            v_errors := v_errors || jsonb_build_object(
                'error_type', 'RECONCILIATION_PAYLOAD_TRUNCATED',
                'message', 'จำนวนชุดคู่รหัสที่ได้รับไม่ตรงกับยอดที่ยืนยันจากต้นทาง'
            );
        ELSE
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_source_active_pairs (
                hcode VARCHAR(5) NOT NULL,
                hos_guid TEXT NOT NULL,
                PRIMARY KEY (hcode, hos_guid)
            ) ON COMMIT DROP;

            TRUNCATE temp_source_active_pairs;

            INSERT INTO temp_source_active_pairs (hcode, hos_guid)
            SELECT 
                elem->>'hcode' AS hcode,
                elem->>'hos_guid' AS hos_guid
            FROM jsonb_array_elements(p_reconcile_handshake->'source_active_pairs') AS elem
            ON CONFLICT (hcode, hos_guid) DO NOTHING;

            IF EXISTS (
                SELECT 1 FROM temp_source_active_pairs 
                WHERE hcode IS NULL OR hcode <> p_hcode OR hos_guid IS NULL OR TRIM(hos_guid) = ''
            ) THEN
                v_errors := v_errors || jsonb_build_object(
                    'error_type', 'RECONCILIATION_SECURITY_VIOLATION',
                    'message', 'พบรายการคู่รหัสที่มี hcode ไม่ตรงกับสถานพยาบาลที่ส่งข้อมูล หรือ GUID ไม่สมบูรณ์'
                );
            ELSE
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

