-- ==============================================================================
-- Migration: ฟังก์ชันสรุปจำนวนใบสั่งยา COUNT(DISTINCT vn) แยกตามหน่วยบริการ ย้อนหลัง 3 ปี
-- สำหรับรายงานที่ 3 (pages/report_prescriptions.php)
-- หมายเหตุ: หน้าเว็บทำงานได้ทันทีโดยไม่ต้องรันไฟล์นี้ แต่ถ้ารันไว้ใน Supabase SQL Editor
-- จะช่วยให้การนับ COUNT(DISTINCT vn) ข้ามหลายแสนแถวทำงานเสร็จในระดับมิลลิวินาที
-- ==============================================================================

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

