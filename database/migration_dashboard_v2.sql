-- ==============================================================================
-- Migration: Dashboard v2 (ปีงบประมาณ) + ประเภทหน่วยบริการหลัก (facility_type)
-- วิธีใช้งาน: นำ SQL ทั้งไฟล์ไปรันใน Supabase Cloud -> SQL Editor
-- ==============================================================================

-- 1. เพิ่มคอลัมน์ประเภทหน่วยบริการหลักใน public.rx_health_centers
--    'HOSPITAL' = โรงพยาบาล (เช่น รพ.แม่ข่าย), 'PCU' = รพ.สต. / หน่วยบริการปฐมภูมิ
ALTER TABLE public.rx_health_centers
    ADD COLUMN IF NOT EXISTS facility_type VARCHAR(20) NOT NULL DEFAULT 'PCU';

DO $$ BEGIN
    ALTER TABLE public.rx_health_centers
        ADD CONSTRAINT chk_health_center_facility_type
        CHECK (facility_type IN ('HOSPITAL', 'PCU'));
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

COMMENT ON COLUMN public.rx_health_centers.facility_type IS 'ประเภทหน่วยบริการหลัก: HOSPITAL = โรงพยาบาล, PCU = รพ.สต.';

-- กำหนดโรงพยาบาลค้อวัง (10968) เป็น HOSPITAL อัตโนมัติ
UPDATE public.rx_health_centers
SET facility_type = 'HOSPITAL'
WHERE hcode = '10968' OR name LIKE 'โรงพยาบาล%';

-- 2. RPC สรุปข้อมูล Dashboard ประจำปีงบประมาณความเร็วสูง (1 Query จบในระดับมิลลิวินาที)
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
