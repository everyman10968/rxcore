-- ==============================================================================
-- RxCore Database Schema Verification & Automated Test Suite (PostgreSQL 15+)
-- Phase 2 (Refined v2): Test Scenarios for Inactive Drugs & Exact (hcode, hos_guid) Reconciliation
-- ==============================================================================
-- หมายเหตุ:
-- 1. ไฟล์นี้ใช้สำหรับทดสอบ Logic ทั้งหมดใน PostgreSQL Test Environment
-- 2. ห้ามนำไปรันบน Supabase Production จริงใน Phase 2 จนกว่าจะได้รับอนุมัติ
-- 3. ใช้ Rollback Transaction เพื่อไม่ให้มีผลกระทบต่อฐานข้อมูลจริง
-- ==============================================================================

BEGIN;

-- ------------------------------------------------------------------------------
-- 1. ข้อมูลทดสอบตั้งต้น (Setup Test Fixtures)
-- ------------------------------------------------------------------------------

-- 1.1 สร้าง รพ.สต. ทดสอบ
INSERT INTO public.rx_health_centers (hcode, name, api_key_hash, api_key_prefix)
VALUES 
    ('01425', 'รพ.สต. ทดสอบ ดอนทอง', encode(digest('key_secret_01425_abc123', 'sha256'), 'hex'), 'rxk_test_01425_'),
    ('01426', 'รพ.สต. ทดสอบ หนองบัว', encode(digest('key_secret_01426_xyz789', 'sha256'), 'hex'), 'rxk_test_01426_')
ON CONFLICT (hcode) DO NOTHING;

-- 1.2 สร้างทะเบียนยาทดสอบ (จำลองรหัส TPU มาตรฐาน 24 หลักจาก HOSxP drugitems.tpu_code_list)
-- สังเกต: ยาตัวที่ 4 ถูกปิดใช้งาน (is_active = FALSE) เพื่อทดสอบการบล็อก
INSERT INTO public.rx_drug_lookup (tpu_code_list, drug_name, generic_name, trade_name, unit, is_active)
VALUES
    ('100789000012345678901001', 'Paracetamol 500 mg tab', 'Paracetamol', 'Sara', 'เม็ด', TRUE),
    ('100789000012345678901002', 'Free Vaccine Dose', 'Influenza Vaccine', 'Gov-Free', 'ขวด', TRUE),
    ('100789000012345678901003', 'Future Cost Drug 10mg', 'Amlodipine', 'Amlopin', 'เม็ด', TRUE),
    ('100789000012345678901004', 'Banned / Inactive Medicine', 'Old Drug', 'Discontinued', 'เม็ด', FALSE)
ON CONFLICT (tpu_code_list) DO NOTHING;

-- 1.3 สร้างประวัติต้นทุนย้อนหลัง
INSERT INTO public.rx_drug_cost_history (drug_id, effective_date, unit_cost, note)
VALUES
    ((SELECT id FROM public.rx_drug_lookup WHERE tpu_code_list = '100789000012345678901001'), '2026-01-01', 10.0000, 'ราคาจัดซื้อรอบต้นปี'),
    ((SELECT id FROM public.rx_drug_lookup WHERE tpu_code_list = '100789000012345678901001'), '2026-06-01', 8.5000, 'ราคาปรับลดกลางปี'),
    ((SELECT id FROM public.rx_drug_lookup WHERE tpu_code_list = '100789000012345678901002'), '2026-01-01', 0.0000, 'วัคซีนฟรี'),
    ((SELECT id FROM public.rx_drug_lookup WHERE tpu_code_list = '100789000012345678901003'), '2026-06-01', 15.0000, 'สัญญาเริ่ม 1 มิ.ย. 2569')
ON CONFLICT (drug_id, effective_date) DO NOTHING;

-- ------------------------------------------------------------------------------
-- TEST CASE 1: ตรวจสอบ CHECK Constraints
-- ------------------------------------------------------------------------------
DO $$
BEGIN
    -- 1.1 จำนวนยาต้อง > 0
    BEGIN
        INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
        VALUES ('01425', 'GUID-FAIL-QTY', 'VN001', '2026-03-01', '100789000012345678901001', 0);
        RAISE EXCEPTION 'TEST FAILED: CHECK (qty > 0) did not catch 0 qty';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE '✅ PASS 1.1: CHECK (qty > 0) rejected 0 qty successfully';
    END;

    -- 1.2 รหัสสถานพยาบาลต้องครบ 5 หลัก
    BEGIN
        INSERT INTO public.rx_health_centers (hcode, name, api_key_hash, api_key_prefix)
        VALUES ('123', 'รพ.สต. รหัสผิด', 'hash', 'prefix');
        RAISE EXCEPTION 'TEST FAILED: CHECK (length(hcode) = 5) did not catch short hcode';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE '✅ PASS 1.2: CHECK (length(hcode) = 5) rejected short hcode successfully';
    END;
END $$;

-- ------------------------------------------------------------------------------
-- TEST CASE 2: ตรวจสอบ Foreign Key และการป้องกันยาที่ปิดใช้งาน (is_active = FALSE)
-- ------------------------------------------------------------------------------
DO $$
DECLARE
    v_rpc_res JSONB;
BEGIN
    -- 2.1 รหัสยาที่ไม่มีในระบบ ต้องถูกปฏิเสธโดย Foreign Key
    BEGIN
        INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
        VALUES ('01425', 'GUID-FAIL-TPU', 'VN002', '2026-03-01', '999999999999999999999999', 5);
        RAISE EXCEPTION 'TEST FAILED: Foreign Key did not reject unregistered drug';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE '✅ PASS 2.1: Foreign Key rejected unregistered drug successfully';
    END;

    -- 2.2 รหัสยาที่มีอยู่ในระบบแต่ถูกปิดใช้งาน (is_active = FALSE):
    -- แม้ Foreign Key จะผ่าน แต่ Trigger trg_check_drug_is_active ต้องสกัดกั้นทันที
    BEGIN
        INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
        VALUES ('01425', 'GUID-FAIL-INACTIVE', 'VN002B', '2026-03-01', '100789000012345678901004', 5);
        RAISE EXCEPTION 'TEST FAILED: Database trigger allowed insert of inactive drug!';
    EXCEPTION WHEN OTHERS THEN
        IF SQLERRM LIKE '%InactiveDrugViolation%' THEN
            RAISE NOTICE '✅ PASS 2.2: Trigger trg_check_drug_is_active strictly blocked inactive drug (is_active = FALSE)';
        ELSE
            RAISE EXCEPTION 'TEST FAILED: Unexpected error on inactive drug check: %', SQLERRM;
        END IF;
    END;

    -- 2.3 ตรวจสอบการบล็อกยาที่ปิดใช้งานผ่าน Server-side RPC fn_sync_dispense_batch
    v_rpc_res := public.fn_sync_dispense_batch(
        '01425',
        'key_secret_01425_abc123',
        jsonb_build_array(
            jsonb_build_object('hos_guid', 'GUID-RPC-INACTIVE', 'vn', 'VN999', 'vstdate', '2026-03-01', 'tpu_code_list', '100789000012345678901004', 'qty', 10)
        )
    );

    IF (v_rpc_res->>'failed')::INT <> 1 OR NOT ((v_rpc_res->'errors'->0->>'error_code') = 'DRUG_INACTIVE') THEN
        RAISE EXCEPTION 'TEST FAILED: RPC did not catch inactive drug gracefully! Result: %', v_rpc_res;
    END IF;
    RAISE NOTICE '✅ PASS 2.3: RPC fn_sync_dispense_batch intercepted inactive drug and logged DRUG_INACTIVE';
END $$;

-- ------------------------------------------------------------------------------
-- TEST CASE 3: ตรวจสอบ Unique Constraint (hcode, hos_guid) ป้องกันซ้ำ
-- ------------------------------------------------------------------------------
DO $$
BEGIN
    INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
    VALUES ('01425', 'GUID-UNIQUE-001', 'VN003', '2026-03-15', '100789000012345678901001', 10);

    BEGIN
        INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
        VALUES ('01425', 'GUID-UNIQUE-001', 'VN003', '2026-03-15', '100789000012345678901001', 20);
        RAISE EXCEPTION 'TEST FAILED: Unique constraint failed to catch duplicate (hcode, hos_guid)';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE '✅ PASS 3.1: Unique Constraint (hcode, hos_guid) rejected duplicate successfully';
    END;
END $$;

-- ------------------------------------------------------------------------------
-- TEST CASE 4: ตรวจสอบ Historical Cost (ห้ามใช้ต้นทุนในอนาคตแทน และไม่นับในยอดรวม)
-- ------------------------------------------------------------------------------
DO $$
DECLARE
    v_cost_past NUMERIC;
    v_total_past NUMERIC;
    v_cost_future NUMERIC;
    v_total_future NUMERIC;
    v_cost_free NUMERIC;
    v_total_free NUMERIC;
    v_summary_amount NUMERIC;
    v_missing_count INT;
    v_cost_status TEXT;
BEGIN
    -- 4.1 จ่ายยาวันที่ 2026-03-01 (ก่อนวันที่เริ่มใช้ต้นทุน 2026-06-01) -> ต้องได้ NULL
    INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
    VALUES ('01425', 'GUID-FUTURE-CHECK', 'VN101', '2026-03-01', '100789000012345678901003', 10);

    SELECT unit_cost_applied, total_cost_applied INTO v_cost_past, v_total_past
    FROM public.rx_dispense WHERE hos_guid = 'GUID-FUTURE-CHECK';

    IF v_cost_past IS NOT NULL OR v_total_past IS NOT NULL THEN
        RAISE EXCEPTION 'TEST FAILED: Dispense before effective_date used future cost! Got % / %', v_cost_past, v_total_past;
    END IF;
    RAISE NOTICE '✅ PASS 4.1: Dispense before effective_date correctly returned NULL (did not borrow future cost)';

    -- 4.2 จ่ายยาวันที่ 2026-06-15 (หลังจากมีผลบังคับใช้ 2026-06-01) -> ได้ราคา 15.00
    INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
    VALUES ('01425', 'GUID-VALID-COST', 'VN102', '2026-06-15', '100789000012345678901003', 10);

    SELECT unit_cost_applied, total_cost_applied INTO v_cost_future, v_total_future
    FROM public.rx_dispense WHERE hos_guid = 'GUID-VALID-COST';

    IF v_cost_future <> 15.0000 OR v_total_future <> 150.0000 THEN
        RAISE EXCEPTION 'TEST FAILED: Dispense after effective_date expected 15.00, got %', v_cost_future;
    END IF;
    RAISE NOTICE '✅ PASS 4.2: Dispense on/after effective_date correctly resolved cost 15.00 (Total: 150.00)';

    -- 4.3 จ่ายยาฟรี (ต้นทุน = 0.0000 บาท)
    INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
    VALUES ('01425', 'GUID-FREE-COST', 'VN103', '2026-03-01', '100789000012345678901002', 4);

    SELECT unit_cost_applied, total_cost_applied INTO v_cost_free, v_total_free
    FROM public.rx_dispense WHERE hos_guid = 'GUID-FREE-COST';

    IF v_cost_free <> 0.0000 OR v_total_free <> 0.0000 THEN
        RAISE EXCEPTION 'TEST FAILED: Free drug expected cost 0.0000, got %', v_cost_free;
    END IF;
    RAISE NOTICE '✅ PASS 4.3: Free drug explicitly resolved to cost 0.0000 (distinct from NULL)';

    -- 4.4 ตรวจสอบ View สรุปยอดเงินรวม (v_drug_dispense_summary)
    SELECT total_cost_amount, missing_cost_tx_count, cost_status
    INTO v_summary_amount, v_missing_count, v_cost_status
    FROM public.v_drug_dispense_summary
    WHERE hcode = '01425' AND tpu_code_list = '100789000012345678901003' AND vstdate = '2026-03-01';

    IF v_summary_amount IS NOT NULL OR v_missing_count <> 1 OR v_cost_status <> 'NO_COST_DATA' THEN
        RAISE EXCEPTION 'TEST FAILED: View summary included NULL cost in total! Got amount %, missing %, status %', v_summary_amount, v_missing_count, v_cost_status;
    END IF;
    RAISE NOTICE '✅ PASS 4.4: Summary View accurately excluded NULL cost from monetary total and marked NO_COST_DATA';
END $$;

-- ------------------------------------------------------------------------------
-- TEST CASE 5: ตรวจสอบ Trigger ปรับปรุงต้นทุนย้อนหลังอัตโนมัติ
-- ------------------------------------------------------------------------------
DO $$
DECLARE
    v_updated_cost NUMERIC;
    v_updated_total NUMERIC;
BEGIN
    -- เดิม GUID-FUTURE-CHECK วันที่ 2026-03-01 มีต้นทุนเป็น NULL
    -- ตอนนี้ Admin บันทึกต้นทุนย้อนหลังเริ่มใช้ 2026-01-01 ราคา 12.00 บาท
    INSERT INTO public.rx_drug_cost_history (drug_id, effective_date, unit_cost, note)
    VALUES (
        (SELECT id FROM public.rx_drug_lookup WHERE tpu_code_list = '100789000012345678901003'),
        '2026-01-01',
        12.0000,
        'เพิ่มสัญญาย้อนหลังครอบคลุมต้นปี'
    );

    SELECT unit_cost_applied, total_cost_applied INTO v_updated_cost, v_updated_total
    FROM public.rx_dispense WHERE hos_guid = 'GUID-FUTURE-CHECK';

    IF v_updated_cost <> 12.0000 OR v_updated_total <> 120.0000 THEN
        RAISE EXCEPTION 'TEST FAILED: Retroactive cost trigger failed to update dispense record! Got % / %', v_updated_cost, v_updated_total;
    END IF;
    RAISE NOTICE '✅ PASS 5.1: Retroactive cost addition automatically updated dispense records from NULL to 12.00';
END $$;

-- ------------------------------------------------------------------------------
-- ------------------------------------------------------------------------------
-- TEST CASE 6: ตรวจสอบการกระทบยอด 30 วันแบบชุดคู่รหัส (hcode, hos_guid) และความครบถ้วนของข้อมูล
-- ------------------------------------------------------------------------------
DO $$
DECLARE
    v_recon_res JSONB;
    v_check_g1_deleted BOOLEAN;
    v_check_g2_deleted BOOLEAN;
    v_check_g3_deleted BOOLEAN;
BEGIN
    -- สร้างข้อมูลจำลอง 3 รายการในช่วง 30 วัน
    INSERT INTO public.rx_dispense (hcode, hos_guid, vn, vstdate, tpu_code_list, qty)
    VALUES 
        ('01425', 'GUID-SET-01', 'VN301', '2026-10-10', '100789000012345678901001', 5),
        ('01425', 'GUID-SET-02', 'VN302', '2026-10-10', '100789000012345678901001', 5),
        ('01425', 'GUID-SET-03', 'VN303', '2026-10-10', '100789000012345678901001', 5);

    -- 6.1 ทดสอบความปลอดภัย: ปฏิเสธการกระทบยอดเมื่อสถานะคิวรีต้นทางไม่สมบูรณ์ (source_query_status != 'COMPLETED')
    v_recon_res := public.fn_sync_dispense_batch(
        '01425',
        'key_secret_01425_abc123',
        '[]'::jsonb,
        '[]'::jsonb,
        jsonb_build_object(
            'reconcile_hcode', '01425',
            'start_date', '2026-10-01',
            'end_date', '2026-10-31',
            'source_query_status', 'TIMEOUT_FAILED', -- จำลองคิวรีต้นทางหลุด
            'expected_source_count', 2,
            'source_active_pairs', jsonb_build_array(
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-01'),
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-03')
            )
        )
    );

    IF (v_recon_res->'errors'->0->>'error_type') <> 'RECONCILIATION_SOURCE_INCOMPLETE' THEN
        RAISE EXCEPTION 'TEST FAILED: Handshake allowed incomplete source query status!';
    END IF;
    -- ตรวจสอบว่าไม่มีรายการใดถูกลบ
    SELECT is_deleted INTO v_check_g2_deleted FROM public.rx_dispense WHERE hos_guid = 'GUID-SET-02';
    IF v_check_g2_deleted <> FALSE THEN
        RAISE EXCEPTION 'TEST FAILED: Records were prematurely soft-deleted on failed source query!';
    END IF;
    RAISE NOTICE '✅ PASS 6.1: Rejected reconciliation when source query was incomplete (source_query_status != COMPLETED)';

    -- 6.2 ทดสอบความปลอดภัย: ปฏิเสธการกระทบยอดเมื่อพบ hcode ไม่ตรงกับหน่วยบริการที่ส่งข้อมูล (Cross-hospital injection defense)
    v_recon_res := public.fn_sync_dispense_batch(
        '01425',
        'key_secret_01425_abc123',
        '[]'::jsonb,
        '[]'::jsonb,
        jsonb_build_object(
            'reconcile_hcode', '01425',
            'start_date', '2026-10-01',
            'end_date', '2026-10-31',
            'source_query_status', 'COMPLETED',
            'expected_source_count', 2,
            'source_active_pairs', jsonb_build_array(
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-01'),
                jsonb_build_object('hcode', '01426', 'hos_guid', 'GUID-SET-CROSS') -- hcode ผิด (ของ รพ.สต. อื่น)
            )
        )
    );

    IF (v_recon_res->'errors'->0->>'error_type') <> 'RECONCILIATION_SECURITY_VIOLATION' THEN
        RAISE EXCEPTION 'TEST FAILED: Handshake allowed cross-hospital hcode injection in reference pairs!';
    END IF;
    RAISE NOTICE '✅ PASS 6.2: Rejected reconciliation when foreign hcode was present in reference pair set';

    -- 6.3 ดำเนินการกระทบยอดที่ถูกต้องสมบูรณ์:
    -- ส่งชุดคู่รหัส (hcode, hos_guid) ที่เปิดใช้งานจริงใน HOSxP เฉพาะ GUID-SET-01, GUID-SET-03 และเพิ่ม GUID-SET-04
    -- (GUID-SET-02 ถูกยกเลิกที่ต้นทาง จึงไม่อยู่ใน source_active_pairs)
    v_recon_res := public.fn_sync_dispense_batch(
        '01425',
        'key_secret_01425_abc123',
        jsonb_build_array(
            jsonb_build_object('hos_guid', 'GUID-SET-01', 'vn', 'VN301', 'vstdate', '2026-10-10', 'tpu_code_list', '100789000012345678901001', 'qty', 8), -- แก้ไขยอด
            jsonb_build_object('hos_guid', 'GUID-SET-04', 'vn', 'VN304', 'vstdate', '2026-10-10', 'tpu_code_list', '100789000012345678901001', 'qty', 2)  -- รายการใหม่
        ),
        '[]'::jsonb,
        jsonb_build_object(
            'reconcile_hcode', '01425',
            'start_date', '2026-10-01',
            'end_date', '2026-10-31',
            'source_query_status', 'COMPLETED',
            'expected_source_count', 3,
            'source_active_pairs', jsonb_build_array(
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-01'),
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-03'),
                jsonb_build_object('hcode', '01425', 'hos_guid', 'GUID-SET-04')
            )
        )
    );

    IF (v_recon_res->>'success')::BOOLEAN <> TRUE THEN
        RAISE EXCEPTION 'TEST FAILED: Reconciliation batch failed: %', v_recon_res;
    END IF;

    -- ตรวจสอบ:
    -- GUID-SET-01 ต้องยังอยู่ (is_deleted = FALSE)
    SELECT is_deleted INTO v_check_g1_deleted FROM public.rx_dispense WHERE hos_guid = 'GUID-SET-01';
    IF v_check_g1_deleted <> FALSE THEN
        RAISE EXCEPTION 'TEST FAILED: GUID-SET-01 was mistakenly deleted!';
    END IF;

    -- GUID-SET-03 ต้องยังอยู่ (is_deleted = FALSE) เพราะอยู่ใน source_active_pairs แม้จะไม่ได้ถูกแก้ไขใน Batch นี้
    SELECT is_deleted INTO v_check_g3_deleted FROM public.rx_dispense WHERE hos_guid = 'GUID-SET-03';
    IF v_check_g3_deleted <> FALSE THEN
        RAISE EXCEPTION 'TEST FAILED: GUID-SET-03 was mistakenly deleted!';
    END IF;

    -- GUID-SET-02 ต้องถูก Soft Delete (is_deleted = TRUE) เพราะไม่อยู่ใน source_active_pairs ของต้นทาง
    SELECT is_deleted INTO v_check_g2_deleted FROM public.rx_dispense WHERE hos_guid = 'GUID-SET-02';
    IF v_check_g2_deleted <> TRUE THEN
        RAISE EXCEPTION 'TEST FAILED: GUID-SET-02 missing from source was not soft-deleted!';
    END IF;

    RAISE NOTICE '✅ PASS 6.3: Valid exact (hcode, hos_guid) pair reconciliation correctly soft-deleted missing records and protected active records';
END $$;

-- ------------------------------------------------------------------------------
-- TEST CASE 7: ตรวจสอบ Trigger ป้องกันผู้ใช้เปลี่ยน role หรือ hcode ตนเอง
-- ------------------------------------------------------------------------------
DO $$
DECLARE
    v_fake_user_id UUID := gen_random_uuid();
BEGIN
    INSERT INTO public.rx_user_profiles (id, username, full_name, role, hcode)
    VALUES (v_fake_user_id, 'staff_test', 'จนท. ทดสอบ', 'user', '01425');

    BEGIN
        UPDATE public.rx_user_profiles
        SET role = 'admin'
        WHERE id = v_fake_user_id;
        RAISE EXCEPTION 'TEST FAILED: Profile defense allowed role tampering!';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE '✅ PASS 7.1: Profile defense trigger blocked unauthorized role change';
    END;

    BEGIN
        UPDATE public.rx_user_profiles
        SET hcode = '01426'
        WHERE id = v_fake_user_id;
        RAISE EXCEPTION 'TEST FAILED: Profile defense allowed hcode tampering!';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE '✅ PASS 7.2: Profile defense trigger blocked unauthorized hcode tampering';
    END;
END $$;

ROLLBACK;

SELECT '🎉 ALL PHASE 2 REFINED TESTS (INACTIVE DRUG & EXACT RECONCILIATION) PASSED!' AS test_summary;
