-- ==============================================================================
-- Migration: ตารางเก็บค่าตั้งค่าระบบกลาง (สำหรับ Vercel Serverless & Cloud)
-- วิธีใช้งาน: นำ SQL นี้ไปรันใน Supabase Cloud -> SQL Editor 1 ครั้ง
-- ==============================================================================

CREATE TABLE IF NOT EXISTS public.rx_app_settings (
    key VARCHAR(100) PRIMARY KEY,
    value JSONB NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE public.rx_app_settings IS 'เก็บการตั้งค่าระบบกลาง (เช่น ปีงบประมาณ, การตั้งค่า MOPH Notify) สำหรับรันบน Vercel Serverless';

ALTER TABLE public.rx_app_settings ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "rx_admin_manage_app_settings" ON public.rx_app_settings;
CREATE POLICY "rx_admin_manage_app_settings" ON public.rx_app_settings
    FOR ALL TO authenticated
    USING (public.fn_rx_is_admin())
    WITH CHECK (public.fn_rx_is_admin());
